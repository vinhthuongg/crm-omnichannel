<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Modules\Conversation\Models\Conversation;

class NimReplySuggestionService
{
    /** Nhận NimConversationContextBuilder để tạo dữ liệu theo cấu hình; NimChatClient để giao tiếp với dịch vụ bên ngoài; NimSuggestionParser để tách phản hồi NIM thành danh sách gợi ý. */
    public function __construct(private readonly NimConversationContextBuilder $contexts, private readonly NimChatClient $client,
        private readonly NimSuggestionParser $parser) {}

    /** Tạo danh sách câu trả lời gợi ý dựa trên ngữ cảnh hội thoại. */
    public function suggest(Conversation $conversation): array
    {
        $apiKey = trim((string) config('services.nim.api_key'));
        if ($apiKey === '') return [];
        $context = $this->contexts->build($conversation);
        if ($context['conversation'] === []) return [];
        try {
            $response = $this->client->complete($apiKey, $context);
            if (! $response->successful()) {
                Log::warning('NIM reply suggestion failed', ['conversation_id' => $conversation->id,
                    'status' => $response->status(), 'body' => $response->body()]);
                return [];
            }
            return $this->parser->parse((string) Arr::get($response->json(), 'choices.0.message.content', ''));
        } catch (\Throwable $e) {
            Log::warning('NIM reply suggestion exception', ['conversation_id' => $conversation->id, 'error' => $e->getMessage()]);
            return [];
        }
    }
}
