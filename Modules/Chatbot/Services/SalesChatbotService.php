<?php

namespace Modules\Chatbot\Services;

use App\Support\InitialMessageTemplate;
use Modules\Chatbot\DTO\ChatbotReply;
use Modules\Conversation\Models\Conversation;
use Modules\Customer\Models\Customer;
use Modules\Customer\Models\CustomerTag;
use Modules\Message\DTO\InboundMessageData;

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
            return null;
        }

        $payload = $this->quickReplyPayload($data);
        $flow = $payload !== '' ? $this->knowledgeBase->flow($payload) : null;
        $state = (array) ($conversation->automation_state ?? []);
        $content = trim((string) $data->content);

        if ($flow) {
            $this->startFlow($conversation, $customer, $payload, $flow);

            return new ChatbotReply(
                content: $flow['question'],
                clientMessageKey: 'auto-flow-'.$conversation->id.'-'.$payload.'-question',
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
                clientMessageKey: 'auto-flow-'.$conversation->id.'-ask-phone',
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
                    clientMessageKey: 'auto-flow-'.$conversation->id.'-completed',
                );
            }
        }

        $menu = InitialMessageTemplate::serviceMenuFor($conversation);

        if ($menu === '') {
            return null;
        }

        return new ChatbotReply(
            content: $menu,
            quickReplies: $this->knowledgeBase->quickReplies(),
            clientMessageKey: 'auto-service-menu-'.$conversation->id,
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
        $query = trim(($state['search_prefix'] ?? $label).' '.$detail);
        $matches = $this->vectors->search($query);
        $context = collect($matches)->pluck('text')->take(5)->implode("\n");

        try {
            $answer = $this->nim->chat(
                'Bạn là trợ lý tư vấn Toyota Kiên Giang. Chỉ dùng dữ liệu trong CONTEXT. Trả lời ngắn, lịch sự, không bịa số liệu. Luôn kết thúc bằng câu xin số điện thoại/Zalo để nhân viên gọi ngay.',
                "NHU CẦU: {$label}\nKHÁCH NHẮN: {$detail}\nCONTEXT:\n{$context}",
            );
        } catch (\Throwable) {
            $answer = null;
        }

        if ($answer) {
            return $answer;
        }

        $summary = $context !== ''
            ? "Dạ em đã ghi nhận {$detail}. Theo dữ liệu hiện có:\n".$this->compactContext($context)
            : "Dạ em đã ghi nhận nhu cầu {$detail} của Anh/Chị.";

        return $summary."\n\nAnh/Chị cho em xin số điện thoại hoặc Zalo, bên em sẽ gọi ngay để tư vấn và gửi thông tin chính xác ạ.";
    }

    private function compactContext(string $context): string
    {
        return collect(explode("\n", $context))
            ->filter()
            ->take(3)
            ->map(fn (string $line): string => '- '.trim($line))
            ->implode("\n");
    }

    private function quickReplyPayload(InboundMessageData $data): string
    {
        return (string) data_get($data->metadata, 'raw.message.quick_reply.payload', '');
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
