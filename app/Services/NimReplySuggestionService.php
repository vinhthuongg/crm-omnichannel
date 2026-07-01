<?php

namespace App\Services;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Modules\Conversation\Models\Conversation;

class NimReplySuggestionService
{
    public function __construct(private readonly Http $http)
    {
    }

    /**
     * @return array<int, string>
     */
    public function suggest(Conversation $conversation): array
    {
        $apiKey = trim((string) config('services.nim.api_key'));

        if ($apiKey === '') {
            return [];
        }

        $conversation->loadMissing(['customer', 'tags']);

        $messages = $conversation->messages()
            ->with('sender')
            ->latest()
            ->limit(18)
            ->get()
            ->reverse()
            ->values()
            ->map(function ($message): array {
                return [
                    'role' => $message->sender_type === 'customer' ? 'customer' : 'staff',
                    'name' => $message->sender?->name ?: ($message->sender_type === 'customer' ? 'Khach hang' : 'Nhan vien'),
                    'content' => trim((string) $message->content),
                    'channel' => (string) $message->channel,
                    'time' => optional($message->created_at)->format('H:i d/m/Y'),
                ];
            })
            ->filter(fn (array $message): bool => $message['content'] !== '')
            ->values()
            ->all();

        if ($messages === []) {
            return [];
        }

        try {
            $response = $this->http
                ->connectTimeout(5)
                ->timeout(max(5, (int) config('services.nim.timeout', 12)))
                ->withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->post(rtrim((string) config('services.nim.base_url'), '/').'/chat/completions', [
                    'model' => (string) config('services.nim.model'),
                    'temperature' => 0.35,
                    'top_p' => 0.8,
                    'max_tokens' => 420,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $this->systemPrompt(),
                        ],
                        [
                            'role' => 'user',
                            'content' => json_encode([
                                'customer' => [
                                    'name' => $conversation->customer?->name,
                                    'phone' => $conversation->customer?->phone,
                                    'tags' => $conversation->tags->pluck('name')->values()->all(),
                                ],
                                'conversation' => $messages,
                            ], JSON_UNESCAPED_UNICODE),
                        ],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('NIM reply suggestion failed', [
                    'conversation_id' => $conversation->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [];
            }

            return $this->parseSuggestions((string) Arr::get($response->json(), 'choices.0.message.content', ''));
        } catch (\Throwable $exception) {
            Log::warning('NIM reply suggestion exception', [
                'conversation_id' => $conversation->id,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Bạn là trợ lý gợi ý câu trả lời cho nhân viên tư vấn Toyota Kiên Giang trong CRM.
Nhiệm vụ: đọc ngữ cảnh hội thoại và đề xuất 3 câu nhân viên có thể nhắn tiếp theo.
Không tự nhận là bot. Không tự gửi tin. Không bịa số liệu giá, khuyến mãi, trả góp, tồn kho.
Nếu khách hỏi thông tin cần số liệu nhưng context không có số liệu, gợi ý câu hỏi làm rõ hoặc xin số điện thoại/Zalo để tư vấn viên kiểm tra.
Giọng văn: tự nhiên, lịch sự, ngắn gọn, chuyên nghiệp, xưng "em" với khách, gọi khách là "Anh/Chị".
Trả lời duy nhất JSON hợp lệ dạng:
{"suggestions":["câu 1","câu 2","câu 3"]}
Mỗi câu tối đa 240 ký tự.
PROMPT;
    }

    /**
     * @return array<int, string>
     */
    private function parseSuggestions(string $content): array
    {
        $content = trim($content);
        $json = $content;

        if (preg_match('/\{.*\}/s', $content, $matches)) {
            $json = $matches[0];
        }

        $decoded = json_decode($json, true);
        $suggestions = is_array($decoded) ? (array) Arr::get($decoded, 'suggestions', []) : [];

        return collect($suggestions)
            ->filter(fn ($suggestion): bool => is_scalar($suggestion))
            ->map(fn ($suggestion): string => trim((string) $suggestion))
            ->filter()
            ->unique()
            ->take(4)
            ->values()
            ->all();
    }
}
