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
                content: $this->detailAnswer($state, $content),
                clientMessageKey: $this->replyKey($conversation, $source, 'ask-phone'),
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
                content: $this->detailAnswer($state ?: ['label' => 'Tu van', 'search_prefix' => 'Toyota giá xe khuyến mãi tư vấn'], $content),
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

    private function detailAnswer(array $state, string $detail): string
    {
        $label = (string) ($state['label'] ?? 'Tu van');
        $topic = (string) ($state['topic'] ?? '');
        $query = trim(($state['search_prefix'] ?? $label).' '.$detail);
        try {
            $matches = collect($this->knowledgeBase->contextDocuments($query, $topic))
                ->merge($this->vectors->search($query, 8))
                ->unique('id')
                ->take(10)
                ->values()
                ->all();
        } catch (\Throwable) {
            $matches = $this->knowledgeBase->contextDocuments($query, $topic);
        }
        $context = collect($matches)->pluck('text')->implode("\n");

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
            ? "Kính chào Anh/Chị,\n\nEm xin gửi thông tin tham khảo cho {$detail}:\n".$this->compactContext($context)
            : "Kính chào Anh/Chị,\n\nEm đã ghi nhận nhu cầu {$detail} của Anh/Chị.";

        return $summary."\n\nAnh/Chị vui lòng để lại số điện thoại hoặc Zalo, Toyota Kiên Giang sẽ gọi lại ngay để tư vấn chi tiết và xác nhận báo giá/ưu đãi chính xác nhất ạ.";
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
