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

        if ($this->isGreeting($content)) {
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

        if ($topic === '' && $model !== '' && $this->isVehicleBuyingIntent($detail)) {
            $topic = 'PRICE_BY_AREA';
            $label = 'Bao gia lan banh';
        }

        $query = trim(($state['search_prefix'] ?? $label).' '.$detail.' '.$model);
        $directMatches = $this->knowledgeBase->contextDocuments($query, $topic);

        if ($directMatches === [] && $this->needsSpecificVehicle($topic, $detail)) {
            return $this->askForVehicleModel($state);
        }

        $matches = $directMatches;
        if ($matches !== []) {
            return $this->structuredVehicleAnswer($conversation, $state, $detail, $matches);
        }

        $context = collect($matches)->pluck('text')->implode("\n");

        if ($context === '') {
            return $this->askForVehicleModel($state);
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
        $matches = collect($this->knowledgeBase->contextDocuments($query, 'INSTALLMENT_LOAN'))
            ->where('source', 'ctrinh_tragop')
            ->values();

        if ($matches->isEmpty()) {
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

        return in_array($normalized, ['hi', 'hello', 'helo', 'alo', 'chao', 'xin chao', 'em oi', 'shop oi', 'tu van'], true);
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
