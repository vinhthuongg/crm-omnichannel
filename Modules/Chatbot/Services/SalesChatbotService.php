<?php

namespace Modules\Chatbot\Services;

use App\Support\InitialMessageTemplate;
use Illuminate\Support\Facades\Log;
use Modules\Chatbot\DTO\ChatbotReply;
use Modules\Conversation\Models\Conversation;
use Modules\Customer\Models\Customer;
use Modules\Customer\Models\CustomerTag;
use Modules\Message\DTO\InboundMessageData;
use Modules\Message\Models\Message;

class SalesChatbotService
{
    public function __construct(
        private readonly VehicleKnowledgeBase $knowledgeBase,
        private readonly VectorIndexService $vectors,
        private readonly NvidiaNimClient $nim,
    ) {
    }

    public function replyFor(Conversation $conversation, Customer $customer, InboundMessageData $data): ?ChatbotReply
    {
        if (! config('chatbot.enabled', true)) {
            Log::warning('Chatbot reply disabled by config', [
                'conversation_id' => $conversation->id,
                'channel' => $data->channel,
            ]);

            return null;
        }

        $payload = $this->quickReplyPayload($data);
        $flow = $payload !== ''
            ? $this->knowledgeBase->flow($payload)
            : $this->knowledgeBase->flowFromText((string) $data->content);

        return $this->reply(
            $conversation,
            $customer,
            trim((string) $data->content),
            $flow,
            $data->externalMessageId ?: md5((string) $data->content),
        );
    }

    public function replyForMessage(Conversation $conversation, Customer $customer, Message $message): ?ChatbotReply
    {
        if (! config('chatbot.enabled', true)) {
            Log::warning('Chatbot reply disabled by config', [
                'conversation_id' => $conversation->id,
                'channel' => $message->channel,
            ]);

            return null;
        }

        return $this->reply(
            $conversation,
            $customer,
            trim((string) $message->content),
            $this->knowledgeBase->flowFromText((string) $message->content),
            $message->external_message_id ?: (string) $message->id,
        );
    }

    private function reply(Conversation $conversation, Customer $customer, string $content, ?array $flow, string $source): ?ChatbotReply
    {
        $state = (array) ($conversation->automation_state ?? []);

        if ($flow) {
            $payload = (string) ($flow['topic'] ?? 'GENERAL');
            $this->startFlow($conversation, $customer, $payload, $flow);

            return new ChatbotReply(
                content: $flow['question'],
                clientMessageKey: $this->replyKey($conversation, $source, $payload.'-question'),
            );
        }

        if ($this->isGreeting($content)) {
            return $this->greetingReply($conversation, $state, $source);
        }

        if ($content !== '' && $this->shouldChatNormally($content, $state)) {
            return new ChatbotReply(
                content: $this->conversationalAnswer($conversation, $this->resetStaleFlowForChat($conversation, $state), $content),
                clientMessageKey: $this->replyKey($conversation, $source, 'normal-chat'),
            );
        }

        if (($state['step'] ?? '') === 'awaiting_detail' && $content !== '') {
            $conversation->forceFill([
                'automation_state' => [
                    ...$state,
                    'detail' => $content,
                    'step' => 'awaiting_phone',
                    'detail_received_at' => now()->toISOString(),
                ],
            ])->save();

            return new ChatbotReply(
                content: $this->detailAnswer($conversation, $state, $content),
                clientMessageKey: $this->replyKey($conversation, $source, 'ask-phone'),
            );
        }

        if (($state['step'] ?? '') === 'awaiting_payment_method' && $content !== '') {
            if ($this->wantsInstallment($content)) {
                return new ChatbotReply(
                    content: $this->installmentAnswer($conversation, $state, $content),
                    clientMessageKey: $this->replyKey($conversation, $source, 'installment'),
                );
            }

            if ($this->wantsCash($content)) {
                $conversation->forceFill([
                    'automation_state' => [
                        ...$state,
                        'step' => 'awaiting_phone',
                        'payment_method' => 'cash',
                        'payment_method_received_at' => now()->toISOString(),
                    ],
                ])->save();

                return new ChatbotReply(
                    content: "Dạ em đã nắm Anh/Chị muốn trả thẳng ạ.\n\nAnh/Chị vui lòng để lại số điện thoại hoặc Zalo, Toyota Kiên Giang sẽ gọi lại để xác nhận báo giá lăn bánh, ưu đãi và tình trạng xe chính xác nhất ạ.",
                    clientMessageKey: $this->replyKey($conversation, $source, 'cash'),
                );
            }
        }

        if (($state['step'] ?? '') === 'awaiting_phone'
            && ($state['payment_method'] ?? '') === 'installment'
            && $this->downPaymentAmount($content) !== '') {
            return new ChatbotReply(
                content: $this->installmentAnswer($conversation, $state, $content),
                clientMessageKey: $this->replyKey($conversation, $source, 'installment-down-payment'),
            );
        }

        if (filled($customer->phone)) {
            $this->tagCustomer($customer, 'Da co so dien thoai', '#16a34a');

            if (filled($state['label'] ?? null)) {
                $this->tagCustomer($customer, (string) $state['label'], '#2563eb');
            }

            if (($state['step'] ?? '') === 'awaiting_phone') {
                $conversation->forceFill([
                    'automation_state' => [
                        ...$state,
                        'step' => 'completed',
                        'phone' => $customer->phone,
                        'completed_at' => now()->toISOString(),
                    ],
                ])->save();

                return new ChatbotReply(
                    content: 'Toyota Kiên Giang đã nhận số điện thoại của Anh/Chị. Bên em sẽ gọi lại ngay để hỗ trợ ạ.',
                    clientMessageKey: $this->replyKey($conversation, $source, 'completed'),
                );
            }
        }

        if ($content !== '') {
            return new ChatbotReply(
                content: $this->detailAnswer($conversation, $state ?: ['label' => 'Tu van', 'search_prefix' => 'Toyota giá xe khuyến mãi tư vấn'], $content),
                clientMessageKey: $this->replyKey($conversation, $source, 'continuous'),
            );
        }

        $menu = InitialMessageTemplate::serviceMenuFor($conversation);

        if ($menu === '') {
            return null;
        }

        return new ChatbotReply(
            content: $menu,
            quickReplies: $this->knowledgeBase->quickReplies(),
            clientMessageKey: $this->replyKey($conversation, $source, 'menu'),
        );
    }

    private function startFlow(Conversation $conversation, Customer $customer, string $payload, array $flow): void
    {
        $conversation->forceFill([
            'automation_state' => [
                'topic' => $payload,
                'label' => $flow['label'],
                'search_prefix' => $flow['search_prefix'] ?? $flow['label'],
                'step' => 'awaiting_detail',
                'started_at' => now()->toISOString(),
            ],
        ])->save();

        $this->tagCustomer($customer, $flow['label'], '#2563eb');
    }

    private function detailAnswer(Conversation $conversation, array $state, string $detail): string
    {
        $label = (string) ($state['label'] ?? 'Tu van');
        $topic = (string) ($state['topic'] ?? '');
        $model = $this->conversationModel($state, $detail);

        if ($this->wantsInstallment($detail)) {
            $topic = 'INSTALLMENT_LOAN';
            $label = 'Vay tra gop';
        }

        if ($topic === '' && $model !== '' && ($this->isVehicleBuyingIntent($detail) || $this->isCommercialDataIntent($detail))) {
            $topic = 'PRICE_BY_AREA';
            $label = 'Bao gia lan banh';
        }

        $query = trim(($state['search_prefix'] ?? $label).' '.$detail.' '.$model);
        $directMatches = $this->knowledgeBase->contextDocuments($query, $topic);

        Log::info('Chatbot detail answer resolved', [
            'conversation_id' => $conversation->id,
            'topic' => $topic,
            'label' => $label,
            'model' => $model,
            'detail' => $detail,
            'matches' => count($directMatches),
            'state_step' => $state['step'] ?? null,
        ]);

        if ($directMatches === []) {
            if ($model === '' && $this->needsSpecificVehicle($topic, $detail)) {
                return $this->askForVehicleModel($state);
            }

            if ($this->isCommercialDataIntent($detail) || $this->isCommercialTopic($topic)) {
                return $this->noVerifiedCommercialDataAnswer($state, $detail, $topic, $model);
            }

            return $this->conversationalAnswer($conversation, $state, $detail);
        }

        $matches = $directMatches;
        if ($matches !== []) {
            return $this->structuredVehicleAnswer($conversation, $state, $detail, $matches);
        }

        $context = collect($matches)->pluck('text')->implode("\n");

        if ($context === '') {
            return $this->conversationalAnswer($conversation, $state, $detail);
        }

        try {
            $answer = $this->nim->chat(
                $this->systemPrompt(),
                "NHU CẦU: {$label}\nKHÁCH NHẮN: {$detail}\nYÊU CẦU BẮT BUỘC: Nếu có dữ liệu giá xe trong CONTEXT thì phải báo giá. Nếu có dữ liệu khuyến mãi/ưu đãi cùng mẫu xe trong CONTEXT thì phải báo luôn khuyến mãi.\nCONTEXT:\n{$context}",
            );
        } catch (\Throwable) {
            $answer = null;
        }

        if ($answer) {
            return $answer;
        }

        $summary = $context !== ''
            ? "Dạ em gửi Anh/Chị thông tin tham khảo cho {$detail}:\n".$this->compactContext($context)
            : "Dạ em đã ghi nhận nhu cầu {$detail} của Anh/Chị.";

        return $summary."\n\nAnh/Chị vui lòng để lại số điện thoại hoặc Zalo, Toyota Kiên Giang sẽ gọi lại ngay để tư vấn chi tiết và xác nhận báo giá/ưu đãi chính xác nhất ạ.";
    }

    private function conversationalAnswer(Conversation $conversation, array $state, string $content): string
    {
        $model = $this->conversationModel($state, $content);
        $fallback = $model !== ''
            ? "Dạ em nghe Anh/Chị ạ. Với mẫu {$model}, em có thể hỗ trợ mình xem giá lăn bánh, ưu đãi, trả góp hoặc tư vấn phiên bản phù hợp.\n\nAnh/Chị muốn em kiểm tra phần nào trước ạ?"
            : "Dạ em nghe Anh/Chị ạ. Em rất sẵn lòng hỗ trợ mình.\n\nNếu Anh/Chị đang xem xe Toyota, Anh/Chị có thể nhắn nhu cầu như đi gia đình, đi làm, cần xe tiết kiệm, muốn trả góp hoặc mẫu xe đang quan tâm. Em sẽ tư vấn từng bước cho mình ạ.";

        try {
            $answer = $this->nim->chat(
                $this->conversationalPrompt(),
                "KHACH_NHAN: ".trim($content)."\nMAU_XE_DANG_QUAN_TAM: ".($model !== '' ? $model : 'chua co')."\nNGU_CANH_TRUOC: ".json_encode($state, JSON_UNESCAPED_UNICODE),
            );
        } catch (\Throwable) {
            $answer = null;
        }

        $answer = trim((string) $answer);

        return $answer !== ''
            ? $this->guardCommercialClaims($answer, $fallback)
            : $fallback;
    }

    private function noVerifiedCommercialDataAnswer(array $state, string $detail, string $topic, string $model): string
    {
        if ($model === '' && $this->needsSpecificVehicle($topic, $detail)) {
            return $this->askForVehicleModel($state);
        }

        $target = $model !== '' ? "cho mẫu {$model}" : 'theo nhu cầu này';

        return "Dạ em đã nhận thông tin của Anh/Chị.\n\nRiêng phần giá xe, khuyến mãi và trả góp {$target}, hiện em chưa thấy dữ liệu xác thực phù hợp trong hệ thống nên em không tự báo số để tránh sai thông tin.\n\nAnh/Chị vui lòng để lại số điện thoại hoặc Zalo, Toyota Kiên Giang sẽ kiểm tra trực tiếp và phản hồi thông tin chính xác cho mình ạ.";
    }

    private function guardCommercialClaims(string $answer, string $fallback): string
    {
        $normalized = str($answer)->lower()->ascii()->squish()->toString();
        $hasUnsafeNumber = preg_match('/\b\d{2,4}([.,]\d{3}){1,}\b/u', $answer) === 1
            || preg_match('/\b\d{1,4}\s*(trieu|ty|%|phan tram|dong)\b/u', $normalized) === 1;

        if ($hasUnsafeNumber && (
            str_contains($normalized, 'gia')
            || str_contains($normalized, 'khuyen mai')
            || str_contains($normalized, 'uu dai')
            || str_contains($normalized, 'tra gop')
            || str_contains($normalized, 'lai suat')
        )) {
            return $fallback;
        }

        return $answer;
    }

    private function isCommercialTopic(string $topic): bool
    {
        return in_array($topic, ['PRICE_BY_AREA', 'PROMOTIONS', 'INSTALLMENT_LOAN', 'VEHICLE_AVAILABILITY'], true);
    }

    private function isCommercialDataIntent(string $content): bool
    {
        $normalized = str($content)->lower()->ascii()->squish()->toString();

        return str_contains($normalized, 'gia')
            || str_contains($normalized, 'bao gia')
            || str_contains($normalized, 'lan banh')
            || str_contains($normalized, 'khuyen mai')
            || str_contains($normalized, 'uu dai')
            || str_contains($normalized, 'giam gia')
            || str_contains($normalized, 'tra gop')
            || str_contains($normalized, 'lai suat')
            || str_contains($normalized, 'vay')
            || str_contains($normalized, 'ngan hang')
            || str_contains($normalized, 'mau xe')
            || str_contains($normalized, 'giao xe')
            || str_contains($normalized, 'con xe')
            || str_contains($normalized, 'tinh trang xe');
    }

    private function shouldChatNormally(string $content, array $state): bool
    {
        $normalized = str($content)->lower()->ascii()->squish()->toString();

        if ($this->isCommercialDataIntent($content)
            || $this->wantsInstallment($content)
            || $this->wantsCash($content)
            || $this->downPaymentAmount($content) !== ''
            || $this->conversationModel([], $content) !== ''
            || preg_match('/(?:\+?84|0)(?:[\s.\-()]?\d){8,10}/', $content)) {
            return false;
        }

        $normalChatPhrases = [
            'cho anh hoi',
            'cho chi hoi',
            'hoi chut',
            'hoi 1 chut',
            'duoc khong',
            'duoc ko',
            'ok',
            'oke',
            'cam on',
            'thank',
            'de anh xem',
            'de chi xem',
            'tu tu',
            'chua ro',
            'anh dang xem',
            'chi dang xem',
        ];

        foreach ($normalChatPhrases as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return true;
            }
        }

        $words = array_values(array_filter(explode(' ', $normalized)));

        if (count($words) <= 4 && ! $this->isVehicleBuyingIntent($content)) {
            return true;
        }

        return ($state['step'] ?? '') !== ''
            && ! $this->isVehicleBuyingIntent($content);
    }

    private function resetStaleFlowForChat(Conversation $conversation, array $state): array
    {
        $nextState = array_filter([
            'greeted_at' => $state['greeted_at'] ?? null,
            'model' => $state['model'] ?? null,
            'last_normal_chat_at' => now()->toISOString(),
        ], fn ($value): bool => filled($value));

        $conversation->forceFill([
            'automation_state' => $nextState,
        ])->save();

        return $nextState;
    }

    private function needsSpecificVehicle(string $topic, string $detail): bool
    {
        $normalized = str($detail)->lower()->ascii()->squish()->toString();

        if (preg_match('/(?:\+?84|0)(?:[\s.\-()]?\d){8,10}/', $detail)) {
            return false;
        }

        if (in_array($topic, ['PRICE_BY_AREA', 'PROMOTIONS', 'INSTALLMENT_LOAN', 'VEHICLE_AVAILABILITY', 'VERSION_CONSULTING'], true)) {
            return true;
        }

        return str_contains($normalized, 'gia')
            || str_contains($normalized, 'bao gia')
            || str_contains($normalized, 'khuyen mai')
            || str_contains($normalized, 'uu dai')
            || str_contains($normalized, 'tra gop')
            || str_contains($normalized, 'mau xe')
            || str_contains($normalized, 'phien ban');
    }

    private function structuredVehicleAnswer(Conversation $conversation, array $state, string $detail, array $matches): string
    {
        $bankLoanProcesses = collect($matches)
            ->where('source', 'quytrinh_vay_nganhang')
            ->values();
        $firstTimeBuyerScripts = collect($matches)
            ->where('source', 'format_mua_xe_lan_dau')
            ->values();
        $roadPriceScripts = collect($matches)
            ->where('source', 'format_gia_lan_banh')
            ->values();

        $prices = collect($matches)
            ->where('source', 'giaxe_json')
            ->unique(fn (array $document): string => implode('|', [
                data_get($document, 'metadata.model'),
                data_get($document, 'metadata.grade'),
                data_get($document, 'metadata.price'),
            ]))
            ->take(8)
            ->values();

        $promotions = collect($matches)
            ->where('source', 'ctkm_json')
            ->unique(fn (array $document): string => implode('|', [
                data_get($document, 'metadata.model'),
                data_get($document, 'metadata.grade'),
                data_get($document, 'metadata.discount'),
            ]))
            ->take(8)
            ->values();

        $installments = collect($matches)
            ->where('source', 'ctrinh_tragop')
            ->unique(fn (array $document): string => implode('|', [
                data_get($document, 'metadata.model'),
                data_get($document, 'metadata.package'),
                data_get($document, 'metadata.product'),
            ]))
            ->take(5)
            ->values();

        if ($prices->isEmpty() && $promotions->isEmpty() && $installments->isEmpty() && $bankLoanProcesses->isNotEmpty()) {
            return $this->bankLoanProcessAnswer($conversation, $state, $detail, $bankLoanProcesses);
        }

        if ($prices->isEmpty() && $promotions->isEmpty() && $installments->isEmpty() && $roadPriceScripts->isNotEmpty()) {
            return $this->roadPriceScriptAnswer($conversation, $state, $detail, $roadPriceScripts);
        }

        if ($firstTimeBuyerScripts->isNotEmpty()
            && ($prices->isEmpty() || $this->isProductConsultingIntent($detail))) {
            return $this->firstTimeBuyerAnswer($conversation, $state, $detail, $firstTimeBuyerScripts);
        }

        $model = (string) (
            data_get($prices->first(), 'metadata.model')
            ?: data_get($promotions->first(), 'metadata.model')
            ?: data_get($installments->first(), 'metadata.model')
            ?: $detail
        );

        $lines = [
            "Dạ em gửi Anh/Chị thông tin tham khảo cho mẫu {$model} theo dữ liệu hiện có của Toyota Kiên Giang:",
        ];

        if ($prices->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Giá niêm yết:';

            foreach ($prices as $document) {
                $grade = (string) data_get($document, 'metadata.grade', '');
                $price = (string) data_get($document, 'metadata.price', '');
                $color = (string) data_get($document, 'metadata.color', '');
                $suffix = $color !== '' ? " ({$color})" : '';

                $lines[] = "- {$model} {$grade}{$suffix}: {$price} đồng";
            }
        }

        if ($promotions->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Khuyến mãi/ưu đãi hiện hành:';

            foreach ($promotions as $document) {
                $grade = (string) data_get($document, 'metadata.grade', '');
                $discount = (int) data_get($document, 'metadata.discount', 0);
                $discountText = $discount > 0 ? number_format($discount, 0, ',', '.').' đồng' : 'theo chương trình hiện hành';

                $lines[] = "- {$model} {$grade}: ưu đãi {$discountText}";
            }
        } else {
            $lines[] = '';
            $lines[] = 'Hiện em chưa thấy dữ liệu khuyến mãi riêng cho mẫu xe này trong file chương trình.';
        }

        $lines[] = '';
        if ($installments->isNotEmpty()) {
            $lines[] = 'Chương trình trả góp tham khảo:';

            foreach ($installments as $document) {
                $product = (string) data_get($document, 'metadata.product', '');
                $phaseOne = (string) data_get($document, 'metadata.phase_one', '');
                $phaseTwo = (string) data_get($document, 'metadata.phase_two', '');
                $months = (string) data_get($document, 'metadata.months', '');

                $lines[] = '- '.$this->installmentPackageTitle($model, $product);
                $lines[] = "  Sản phẩm: {$product}";

                if ($phaseOne !== '') {
                    $lines[] = "  Lãi suất giai đoạn 1: {$phaseOne}";
                }

                if ($phaseTwo !== '') {
                    $lines[] = "  Lãi suất giai đoạn 2: {$phaseTwo}";
                }

                if ($months !== '') {
                    $lines[] = "  Thời gian vay áp dụng/tối thiểu: {$months} tháng";
                }
            }

            $lines[] = '';
            $lines[] = 'Giá lăn bánh và phương án trả góp thực tế còn phụ thuộc khu vực đăng ký, số tiền trả trước, thời hạn vay và phê duyệt của đơn vị tài chính.';
            $this->appendBankLoanProcessSummary($lines, $bankLoanProcesses);
            $lines[] = 'Anh/Chị vui lòng để lại số điện thoại hoặc Zalo, Toyota Kiên Giang sẽ gọi lại để tính phương án trả góp chi tiết cho mình ạ.';

            $nextState = [
                ...$state,
                'step' => 'awaiting_phone',
                'model' => $model,
                'payment_method' => 'installment',
                'quoted_at' => now()->toISOString(),
            ];
        } else {
            $lines[] = 'Giá lăn bánh còn phụ thuộc khu vực đăng ký, phiên bản, màu xe và chương trình tại thời điểm ký hợp đồng.';
            $this->appendRoadPriceSummary($lines, $roadPriceScripts);
            $lines[] = 'Anh/Chị muốn trả góp hay trả thẳng để em kiểm tra thêm ưu đãi phù hợp cho Anh/Chị ạ?';

            $nextState = [
                ...$state,
                'step' => 'awaiting_payment_method',
                'model' => $model,
                'quoted_at' => now()->toISOString(),
            ];
        }

        $conversation->forceFill([
            'automation_state' => $nextState,
        ])->save();

        return implode("\n", $lines);
    }

    private function wantsInstallment(string $content): bool
    {
        $normalized = str($content)->lower()->ascii()->squish()->toString();

        if (str_contains($normalized, 'khong gop')
            || str_contains($normalized, 'ko gop')
            || str_contains($normalized, 'khong tra gop')) {
            return false;
        }

        return str_contains($normalized, 'tra gop')
            || preg_match('/\bgop\b/', $normalized) === 1
            || str_contains($normalized, 'vay')
            || str_contains($normalized, 'lai suat')
            || str_contains($normalized, 'ngan hang')
            || str_contains($normalized, 'installment');
    }

    private function wantsCash(string $content): bool
    {
        $normalized = str($content)->lower()->ascii()->squish()->toString();

        return str_contains($normalized, 'tra thang')
            || str_contains($normalized, 'tien mat')
            || str_contains($normalized, 'mua thang')
            || str_contains($normalized, 'khong gop');
    }

    private function downPaymentAmount(string $content): string
    {
        $normalized = str($content)->lower()->ascii()->squish()->toString();

        if (preg_match('/\b(\d{1,4})\s*(tr|trieu|trieu dong|triệu|triệu đồng)\b/u', $normalized, $matches)) {
            return $matches[1].' triệu đồng';
        }

        if (preg_match('/\b(\d{1,3})(?:[.,]\d{3}){2,}\b/', $normalized, $matches)) {
            return $matches[1].' đồng';
        }

        return '';
    }

    private function installmentAnswer(Conversation $conversation, array $state, string $content): string
    {
        $model = (string) ($state['model'] ?? '');
        if ($model === '') {
            $model = (string) collect($this->knowledgeBase->detectModels($content))->first();
        }

        if ($model === '') {
            return $this->askForVehicleModel(['label' => 'Vay tra gop']);
        }

        $query = trim('tra gop lai suat '.$model.' '.$content);
        $documents = collect($this->knowledgeBase->contextDocuments($query, 'INSTALLMENT_LOAN'));
        $bankLoanProcesses = $documents
            ->where('source', 'quytrinh_vay_nganhang')
            ->values();
        $matches = $documents
            ->where('source', 'ctrinh_tragop')
            ->values();

        if ($matches->isEmpty()) {
            if ($bankLoanProcesses->isNotEmpty()) {
                return $this->bankLoanProcessAnswer($conversation, $state, $content, $bankLoanProcesses);
            }

            return "Dạ em đã nhận nhu cầu trả góp của Anh/Chị.\n\nHiện em chưa thấy chương trình lãi suất phù hợp với mẫu {$model} trong file dữ liệu trả góp. Anh/Chị vui lòng để lại số điện thoại hoặc Zalo, Toyota Kiên Giang sẽ kiểm tra trực tiếp với bộ phận tài chính và phản hồi lại ngay ạ.";
        }

        $lines = [
            'Dạ em gửi Anh/Chị thông tin trả góp tham khảo theo chương trình hiện có:',
            '',
        ];

        foreach ($matches->take(5) as $document) {
            $product = (string) data_get($document, 'metadata.product', '');
            $phaseOne = (string) data_get($document, 'metadata.phase_one', '');
            $phaseTwo = (string) data_get($document, 'metadata.phase_two', '');
            $months = (string) data_get($document, 'metadata.months', '');

            $lines[] = '- '.$this->installmentPackageTitle($model, $product);
            $lines[] = "  Sản phẩm: {$product}";

            if ($phaseOne !== '') {
                $lines[] = "  Lãi suất giai đoạn 1: {$phaseOne}";
            }

            if ($phaseTwo !== '') {
                $lines[] = "  Lãi suất giai đoạn 2: {$phaseTwo}";
            }

            if ($months !== '') {
                $lines[] = "  Thời gian vay áp dụng/tối thiểu: {$months} tháng";
            }
        }

        $lines[] = '';
        $lines[] = 'Thông tin trên là tham khảo theo chương trình trong file dữ liệu. Hồ sơ thực tế còn phụ thuộc số tiền trả trước, thời hạn vay và phê duyệt của đơn vị tài chính.';
        $this->appendBankLoanProcessSummary($lines, $bankLoanProcesses);
        $lines[] = 'Anh/Chị vui lòng để lại số điện thoại hoặc Zalo, Toyota Kiên Giang sẽ gọi lại để tính phương án trả góp chi tiết cho mình ạ.';

        $conversation->forceFill([
            'automation_state' => [
                ...$state,
                'step' => 'awaiting_phone',
                'payment_method' => 'installment',
                'payment_method_received_at' => now()->toISOString(),
            ],
        ])->save();

        return implode("\n", $lines);
    }

    private function roadPriceScriptAnswer(Conversation $conversation, array $state, string $content, $scripts): string
    {
        $document = $this->selectRoadPriceScriptDocument($content, $scripts);
        $title = (string) data_get($document, 'metadata.title', 'Giá lăn bánh');
        $answer = (string) data_get($document, 'metadata.answer', '');
        $questions = (array) data_get($document, 'metadata.questions', []);
        $costItems = (array) data_get($document, 'metadata.cost_items', []);
        $qa = (array) data_get($document, 'metadata.qa', []);
        $sampleCost = (array) data_get($document, 'metadata.sample_cost', []);
        $followUp = (string) data_get($document, 'metadata.follow_up', '');
        $lines = [
            "Dạ, em gửi Anh/Chị thông tin về {$title}:",
        ];

        if ($answer !== '') {
            $lines[] = '';
            $lines[] = $answer;
        }

        $matchedQa = $this->matchedProductAnswer($content, $qa);
        if ($matchedQa !== '') {
            $lines[] = '';
            $lines[] = $matchedQa;
        }

        if ($costItems !== []) {
            $lines[] = '';
            $lines[] = 'Giá lăn bánh thường gồm:';
            foreach ($costItems as $item) {
                $lines[] = '- '.trim((string) $item);
            }
        }

        if ($sampleCost !== []) {
            $lines[] = '';
            $lines[] = 'Khi tính chi tiết, em sẽ kiểm tra các khoản:';
            foreach ($sampleCost as $key => $value) {
                if (is_array($value)) {
                    $value = implode(', ', array_map('strval', $value));
                }
                $lines[] = '- '.str_replace('_', ' ', (string) $key).': '.trim((string) $value);
            }
        }

        if ($questions !== []) {
            $lines[] = '';
            $lines[] = 'Để em tính sát nhất cho mình, Anh/Chị cho em xin thêm:';
            foreach (array_slice($questions, 0, 2) as $question) {
                $lines[] = '- '.trim((string) $question);
            }
        }

        if ($followUp !== '') {
            $lines[] = '';
            $lines[] = $followUp;
        }

        $conversation->forceFill([
            'automation_state' => [
                ...$state,
                'topic' => 'PRICE_BY_AREA',
                'label' => 'Bao gia lan banh',
                'step' => 'awaiting_detail',
                'road_price_situation' => data_get($document, 'metadata.situation'),
                'road_price_step' => data_get($document, 'metadata.step'),
                'road_price_at' => now()->toISOString(),
            ],
        ])->save();

        return implode("\n", array_values(array_filter($lines, fn (string $line): bool => $line !== '')));
    }

    private function selectRoadPriceScriptDocument(string $content, $scripts): array
    {
        $normalized = str($content)->lower()->ascii()->squish()->toString();
        $step = match (true) {
            str_contains($normalized, 'la gi')
                || str_contains($normalized, 'khac gi')
                || str_contains($normalized, 'cao hon')
                || str_contains($normalized, 'niem yet') => '2',
            str_contains($normalized, 'truoc ba')
                || str_contains($normalized, 'bao hiem')
                || str_contains($normalized, 'phi')
                || str_contains($normalized, 'phat sinh') => '3',
            str_contains($normalized, 'tra gop')
                || str_contains($normalized, 'tra truoc') => '5',
            str_contains($normalized, 'bao gia nhanh')
                || str_contains($normalized, 'tron goi')
                || str_contains($normalized, 'uu dai') => '1',
            default => '1',
        };

        return (array) (
            $scripts->firstWhere('metadata.step', $step)
            ?? $scripts->first()
            ?? []
        );
    }

    private function appendRoadPriceSummary(array &$lines, $scripts): void
    {
        if ($scripts->isEmpty()) {
            return;
        }

        $lines[] = '';
        $lines[] = 'Để tính giá lăn bánh chính xác, em sẽ kiểm tra thêm:';
        $lines[] = '- Tỉnh/thành đăng ký để áp đúng thuế trước bạ và phí biển số.';
        $lines[] = '- Hình thức thanh toán: trả thẳng hay trả góp.';
        $lines[] = '- Các khoản bảo hiểm, đăng kiểm, bảo trì đường bộ và phí hồ sơ nếu có.';
    }

    private function firstTimeBuyerAnswer(Conversation $conversation, array $state, string $content, $scripts): string
    {
        $document = $this->selectFirstTimeBuyerDocument($content, $scripts);
        $situation = (string) data_get($document, 'metadata.situation', 'tinh_huong_1');
        $title = (string) data_get($document, 'metadata.title', 'Tư vấn mua xe lần đầu');
        $opening = (string) data_get($document, 'metadata.staff_opening', '');
        $followUp = (string) data_get($document, 'metadata.staff_follow_up', '');
        $needQuestions = (array) data_get($document, 'metadata.need_questions', []);
        $suggestions = (array) data_get($document, 'metadata.vehicle_suggestions', []);
        $qa = (array) data_get($document, 'metadata.product_qa', []);
        $commonQuestions = (array) data_get($document, 'metadata.common_questions', []);
        $nextAction = (string) data_get($document, 'metadata.next_action', '');
        $lines = [];

        if ($opening !== '') {
            $lines[] = $opening;
        } else {
            $lines[] = "Dạ em hỗ trợ Anh/Chị theo hướng {$title} ạ.";
        }

        if ($followUp !== '') {
            $lines[] = '';
            $lines[] = $followUp;
        }

        $matchedQa = $this->matchedProductAnswer($content, $qa);
        if ($matchedQa !== '') {
            $lines[] = '';
            $lines[] = $matchedQa;
        }

        if ($needQuestions !== []) {
            $lines[] = '';
            $lines[] = 'Để em tư vấn đúng nhu cầu, Anh/Chị cho em xin thêm vài thông tin:';
            foreach (array_slice($needQuestions, 0, 3) as $question) {
                $lines[] = '- '.trim((string) $question);
            }
        }

        if ($suggestions !== []) {
            $lines[] = '';
            $lines[] = 'Gợi ý ban đầu để Anh/Chị tham khảo:';
            foreach ($suggestions as $item) {
                $name = (string) ($item['ten_xe'] ?? '');
                $description = (string) ($item['dac_diem'] ?? '');
                $lines[] = "- {$name}: {$description}";
            }
        }

        if ($commonQuestions !== []) {
            $lines[] = '';
            $lines[] = 'Một số điểm sản phẩm em có thể kiểm tra thêm cho mình:';
            foreach (array_slice($commonQuestions, 0, 5) as $question) {
                $lines[] = '- '.trim((string) $question);
            }
        }

        if ($nextAction !== '') {
            $lines[] = '';
            $lines[] = $nextAction;
        }

        $lines[] = '';
        $lines[] = 'Anh/Chị nhắn giúp em nhu cầu sử dụng chính hoặc mẫu xe đang phân vân, em sẽ lọc mẫu phù hợp và gửi tiếp giá/ưu đãi nếu mình cần ạ.';

        $conversation->forceFill([
            'automation_state' => [
                ...$state,
                'topic' => 'VERSION_CONSULTING',
                'label' => 'Tu van phien ban',
                'step' => $situation === 'tinh_huong_1' ? 'awaiting_detail' : ($state['step'] ?? 'awaiting_detail'),
                'first_time_buyer_situation' => $situation,
                'first_time_buyer_step' => data_get($document, 'metadata.step'),
                'first_time_buyer_at' => now()->toISOString(),
            ],
        ])->save();

        return implode("\n", array_values(array_filter($lines, fn (string $line): bool => $line !== '')));
    }

    private function selectFirstTimeBuyerDocument(string $content, $scripts): array
    {
        $normalized = str($content)->lower()->ascii()->squish()->toString();
        $situation = match (true) {
            str_contains($normalized, 'chua biet')
                || str_contains($normalized, 'lan dau')
                || str_contains($normalized, 'khong biet chon')
                || str_contains($normalized, 'tu van tu dau') => 'tinh_huong_1',
            str_contains($normalized, 'ban top')
                || str_contains($normalized, 'ban thuong')
                || str_contains($normalized, 'khac nhau')
                || str_contains($normalized, 'camera')
                || str_contains($normalized, 'tiet kiem')
                || str_contains($normalized, 'giao xe')
                || str_contains($normalized, 'tra thang') => 'tinh_huong_2',
            default => '',
        };

        if ($situation !== '') {
            return (array) ($scripts->firstWhere('metadata.situation', $situation) ?? $scripts->first() ?? []);
        }

        return (array) ($scripts->first() ?? []);
    }

    private function matchedProductAnswer(string $content, array $qa): string
    {
        if ($qa === []) {
            return '';
        }

        $normalized = str($content)->lower()->ascii()->squish()->toString();

        foreach ($qa as $item) {
            $question = (string) ($item['khach_hang'] ?? '');
            $answer = (string) ($item['nhan_vien'] ?? '');
            $questionNormalized = str($question)->lower()->ascii()->squish()->toString();

            if ($answer !== '' && collect(explode(' ', $normalized))
                ->filter(fn (string $word): bool => strlen($word) >= 4)
                ->contains(fn (string $word): bool => str_contains($questionNormalized, $word))) {
                return $answer;
            }
        }

        return '';
    }

    private function isProductConsultingIntent(string $content): bool
    {
        $normalized = str($content)->lower()->ascii()->squish()->toString();
        $keywords = [
            'lan dau',
            'chua biet',
            'chon xe',
            'tu van tu dau',
            'di gia dinh',
            'di lam',
            'camera',
            'cam bien',
            'man hinh',
            'ghe da',
            'ghe ni',
            'tui khi',
            'abs',
            'phanh',
            'gam cao',
            'tiet kiem',
            'cua gio',
            'ban top',
            'ban thuong',
            'khac nhau',
            'so tu dong',
            'so san',
        ];

        foreach ($keywords as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function bankLoanProcessAnswer(Conversation $conversation, array $state, string $content, $processes): string
    {
        $document = $this->selectBankLoanProcessDocument($content, $processes);
        $title = (string) data_get($document, 'metadata.title', 'Quy trình vay ngân hàng liên kết');
        $answer = (string) data_get($document, 'metadata.answer', '');
        $followUp = (string) data_get($document, 'metadata.follow_up', '');
        $lines = [
            "Dạ, em gửi Anh/Chị thông tin về {$title}:",
            '',
        ];

        if ($answer !== '') {
            $lines[] = $answer;
        }

        foreach ($this->bankLoanProcessBullets($document) as $line) {
            $lines[] = $line;
        }

        if ($followUp !== '') {
            $lines[] = '';
            $lines[] = $followUp;
        }

        $lines[] = '';
        $lines[] = 'Anh/Chị cho em xin số điện thoại hoặc Zalo, bên em sẽ chuyển nhân viên tài chính hỗ trợ kiểm tra hồ sơ và phương án ngân hàng phù hợp cho mình ạ.';

        $conversation->forceFill([
            'automation_state' => [
                ...$state,
                'topic' => 'INSTALLMENT_LOAN',
                'label' => 'Vay tra gop',
                'payment_method' => 'installment',
                'step' => 'awaiting_phone',
                'bank_loan_process_step' => data_get($document, 'metadata.step'),
                'bank_loan_process_at' => now()->toISOString(),
            ],
        ])->save();

        return implode("\n", array_values(array_filter($lines, fn (string $line): bool => $line !== '')));
    }

    private function selectBankLoanProcessDocument(string $content, $processes): array
    {
        $normalized = str($content)->lower()->ascii()->squish()->toString();
        $step = match (true) {
            str_contains($normalized, 'giay to') || str_contains($normalized, 'ho so') => '4',
            str_contains($normalized, 'dieu kien') || str_contains($normalized, 'no xau') || str_contains($normalized, 'thu nhap') => '3',
            str_contains($normalized, 'quy trinh') || str_contains($normalized, 'xet duyet') || str_contains($normalized, 'duyet') => '2',
            str_contains($normalized, 'tinh thu') || str_contains($normalized, 'tra truoc') || str_contains($normalized, 'hang thang') => '5',
            str_contains($normalized, 'ai ho tro') || str_contains($normalized, 'nhan vien tai chinh') => '6',
            default => '1',
        };

        return (array) ($processes->firstWhere('metadata.step', $step) ?? $processes->first() ?? []);
    }

    private function bankLoanProcessBullets(array $document): array
    {
        $lines = [];
        $processSteps = (array) data_get($document, 'metadata.process_steps', []);
        $conditions = (array) data_get($document, 'metadata.loan_conditions', []);
        $documentGroups = (array) data_get($document, 'metadata.document_groups', []);
        $summary = (array) data_get($document, 'metadata.quick_summary', []);
        $clarifyingQuestions = (array) data_get($document, 'metadata.clarifying_questions', []);

        foreach ($processSteps as $item) {
            $lines[] = '- '.trim((string) $item);
        }

        foreach ($conditions as $item) {
            $lines[] = '- '.trim((string) $item);
        }

        foreach ($documentGroups as $group => $items) {
            $label = str_replace('_', ' ', (string) $group);
            $lines[] = '- '.$label.': '.implode('; ', array_filter(array_map('strval', (array) $items)));
        }

        foreach ($summary as $item) {
            $lines[] = '- '.trim((string) $item);
        }

        if ($clarifyingQuestions !== []) {
            $lines[] = '';
            $lines[] = 'Để em chọn ngân hàng phù hợp hơn, Anh/Chị cho em biết thêm:';
            foreach (array_slice($clarifyingQuestions, 0, 3) as $question) {
                $lines[] = '- '.trim((string) $question);
            }
        }

        return array_values(array_filter($lines, fn (string $line): bool => $line !== '-'));
    }

    private function appendBankLoanProcessSummary(array &$lines, $processes): void
    {
        if ($processes->isEmpty()) {
            return;
        }

        $lines[] = '';
        $lines[] = 'Quy trình vay qua ngân hàng liên kết:';
        $lines[] = '- Bên em hỗ trợ gửi hồ sơ sang Toyota Finance hoặc ngân hàng liên kết như VPBank, MB Bank, TPBank, BIDV.';
        $lines[] = '- Ngân hàng thường xét duyệt trong 1-2 ngày làm việc.';
        $lines[] = '- Khi hồ sơ duyệt xong, Anh/Chị đóng phần trả trước, ký hồ sơ vay và nhận xe.';
    }

    private function installmentPackageTitle(string $model, string $product): string
    {
        $product = trim($product);

        return trim("Gói trả góp {$model}".($product !== '' ? " - {$product}" : ''));
    }

    private function conversationModel(array $state, string $content): string
    {
        $model = (string) collect($this->knowledgeBase->detectModels($content))->first();

        if ($model !== '') {
            return $model;
        }

        return (string) ($state['model'] ?? '');
    }

    private function isVehicleBuyingIntent(string $content): bool
    {
        $normalized = str($content)->lower()->ascii()->squish()->toString();

        return str_contains($normalized, 'mua xe')
            || str_contains($normalized, 'muon mua')
            || str_contains($normalized, 'quan tam')
            || str_contains($normalized, 'tu van')
            || str_contains($normalized, 'bao gia')
            || str_contains($normalized, 'gia xe')
            || str_contains($normalized, 'lan banh');
    }

    private function isGreeting(string $content): bool
    {
        $normalized = str($content)->lower()->ascii()->squish()->toString();

        return in_array($normalized, [
            'hi',
            'hi em',
            'hello',
            'hello em',
            'helo',
            'alo',
            'alo em',
            'chao',
            'chao em',
            'xin chao',
            'xin chao em',
            'em oi',
            'shop oi',
            'tu van',
        ], true);
    }

    private function greetingReply(Conversation $conversation, array $state, string $source): ChatbotReply
    {
        $menu = InitialMessageTemplate::serviceMenuFor($conversation);
        $alreadyGreeted = $this->hasGreeted($conversation, $state);
        $replyContent = $alreadyGreeted
            ? $this->followUpGreetingMessage($state)
            : ($menu !== '' ? $menu : $this->greetingMessage());
        $this->markGreeted($conversation, $state);

        return new ChatbotReply(
            content: $replyContent,
            quickReplies: ! $alreadyGreeted && $menu !== '' ? $this->knowledgeBase->quickReplies() : [],
            clientMessageKey: $this->replyKey($conversation, $source, 'greeting'),
        );
    }

    private function greetingMessage(): string
    {
        return "Kính chào Anh/Chị,\n\nToyota Kiên Giang rất vui được hỗ trợ Anh/Chị. Anh/Chị đang quan tâm mẫu xe hoặc nhu cầu tư vấn nào ạ?\n\nAnh/Chị có thể nhắn tên xe như Vios, Veloz Cross, Yaris Cross, Corolla Cross, Camry, Fortuner, Innova Cross, Raize hoặc Hilux để em kiểm tra giá và ưu đãi phù hợp ạ.";
    }

    private function followUpGreetingMessage(array $state): string
    {
        $model = (string) ($state['model'] ?? '');

        if ($model !== '') {
            return "Dạ em vẫn đang hỗ trợ Anh/Chị về mẫu {$model} ạ. Anh/Chị muốn em kiểm tra thêm giá lăn bánh, ưu đãi, trả góp hay tình trạng xe cho mình ạ?";
        }

        return 'Dạ em đang hỗ trợ Anh/Chị ạ. Anh/Chị muốn em kiểm tra mẫu xe hoặc nhu cầu tư vấn nào tiếp theo ạ?';
    }

    private function hasGreeted(Conversation $conversation, array $state): bool
    {
        if (filled($state['greeted_at'] ?? null)) {
            return true;
        }

        return $conversation->messages()
            ->where('sender_type', 'user')
            ->where('client_message_id', 'like', 'auto-chatbot-%')
            ->where(function ($query): void {
                $query->where('content', 'like', 'Kính chào Anh/Chị%')
                    ->orWhere('content', 'like', 'KÃ­nh chÃ o Anh/Chá»‹%');
            })
            ->exists();
    }

    private function markGreeted(Conversation $conversation, array $state): void
    {
        if (filled($state['greeted_at'] ?? null)) {
            return;
        }

        $conversation->forceFill([
            'automation_state' => [
                ...$state,
                'greeted_at' => now()->toISOString(),
            ],
        ])->save();
    }

    private function askForVehicleModel(array $state): string
    {
        $label = (string) ($state['label'] ?? 'tư vấn');

        return "Dạ em đã nhận nhu cầu {$label} của Anh/Chị. Để em kiểm tra đúng giá và chương trình ưu đãi hiện hành, Anh/Chị vui lòng cho em biết mẫu xe mình đang quan tâm ạ.\n\nVí dụ: Vios, Veloz Cross, Yaris Cross, Corolla Cross, Camry, Fortuner, Innova Cross, Raize hoặc Hilux.\n\nSau khi có mẫu xe, em sẽ gửi thông tin giá và khuyến mãi phù hợp nhất ạ.";
    }

    private function compactContext(string $context): string
    {
        return collect(explode("\n", $context))
            ->filter()
            ->take(3)
            ->map(fn (string $line): string => '- '.trim($line))
            ->implode("\n");
    }

    private function conversationalPrompt(): string
    {
        return <<<'PROMPT'
Bạn là trợ lý tư vấn của Toyota Kiên Giang.

Nhiệm vụ:
- Luôn trả lời vui vẻ, lịch sự, gần gũi, không bỏ mặc khách dù khách nói chuyện ngoài chủ đề.
- Nếu khách đùa, hỏi chuyện đời thường, nói chưa rõ nhu cầu: phản hồi tự nhiên 1-3 câu rồi khéo léo kéo về nhu cầu mua xe/tư vấn Toyota.
- Không được tự bịa giá xe, khuyến mãi, ưu đãi, lãi suất, số tiền trả trước, thời gian giao xe, màu xe còn hàng.
- Nếu khách hỏi các thông tin thương mại đó mà không có CONTEXT dữ liệu xác thực, hãy nói cần kiểm tra lại và xin số điện thoại/Zalo.
- Có thể tư vấn chung về cách chọn xe theo nhu cầu, trải nghiệm mua xe, hồ sơ cần chuẩn bị, nhưng không nêu con số cụ thể nếu không có nguồn.
- Xưng "em", gọi khách là "Anh/Chị".
- Trả lời tiếng Việt, ngắn gọn, dễ đọc, chuyên nghiệp.

Không dùng format cứng. Hãy trò chuyện tự nhiên như một tư vấn viên đang trực chat.
PROMPT;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Bạn là trợ lý tư vấn bán hàng của Toyota Kiên Giang.

Nguyên tắc:
- Chỉ dùng dữ liệu trong CONTEXT, không tự bịa giá, khuyến mãi, màu xe, thời gian giao xe.
- Giọng điệu chuyên nghiệp, lịch sự, chuẩn mực kiểu tư vấn Nhật: rõ ràng, khiêm tốn, chính xác, không phóng đại.
- Trả lời bằng tiếng Việt, xưng "em", gọi khách là "Anh/Chị".
- Format tin nhắn thành các dòng dễ đọc.
- Nếu khách hỏi giá xe hoặc báo giá lăn bánh: phải nêu giá xe tìm được và phải nêu khuyến mãi/ưu đãi cùng mẫu xe nếu CONTEXT có.
- Nếu CONTEXT có nhiều phiên bản/màu, tóm tắt tối đa 5 dòng quan trọng, tránh quá dài.
- Cuối tin luôn mời khách để lại số điện thoại hoặc Zalo để Toyota Kiên Giang gọi lại xác nhận báo giá/ưu đãi chính xác.

Format đề xuất:
Kính chào Anh/Chị,

Em gửi Anh/Chị thông tin tham khảo:
- Giá xe: ...
- Khuyến mãi/ưu đãi: ...
- Ghi chú: ...

Anh/Chị vui lòng để lại số điện thoại hoặc Zalo, Toyota Kiên Giang sẽ gọi lại ngay để tư vấn chi tiết và xác nhận thông tin chính xác nhất ạ.
PROMPT;
    }

    private function quickReplyPayload(InboundMessageData $data): string
    {
        return (string) data_get($data->metadata, 'raw.message.quick_reply.payload', '');
    }

    private function replyKey(Conversation $conversation, string $source, string $suffix): string
    {
        return 'auto-chatbot-'.$conversation->id.'-'.md5($source.'|'.$suffix);
    }

    private function tagCustomer(Customer $customer, string $name, string $color): void
    {
        $tag = CustomerTag::query()->firstOrCreate(
            ['name' => $name],
            ['color' => $color],
        );

        $customer->tags()->syncWithoutDetaching([$tag->id]);
    }
}
