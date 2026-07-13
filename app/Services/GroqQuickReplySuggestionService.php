<?php

namespace App\Services;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Log;

class GroqQuickReplySuggestionService
{
    private const MAX_REPLIES = 13;
    private const MAX_TITLE_LENGTH = 20;

    public function __construct(private readonly Http $http)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('services.groq.enabled', false)
            && trim((string) config('services.groq.api_key', '')) !== '';
    }

    public function forBotMessage(string $botMessage, array $context = []): array
    {
        $botMessage = trim($botMessage);

        if (! $this->enabled() || $botMessage === '') {
            return [];
        }

        try {
            $startedAt = microtime(true);
            $response = $this->http
                ->baseUrl(rtrim((string) config('services.groq.base_url'), '/'))
                ->acceptJson()
                ->asJson()
                ->withToken(trim((string) config('services.groq.api_key')))
                ->timeout((float) config('services.groq.timeout', 1.2))
                ->connectTimeout((float) config('services.groq.connect_timeout', 0.5))
                ->post('/chat/completions', [
                    'model' => trim((string) config('services.groq.model')),
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $this->systemPrompt(),
                        ],
                        [
                            'role' => 'user',
                            'content' => json_encode([
                                'bot_message' => mb_substr($botMessage, 0, 900),
                                'recent_messages' => array_slice($context['messages'] ?? [], -6),
                                'status' => $context['status'] ?? null,
                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ],
                    ],
                    'temperature' => 0.45,
                    'max_completion_tokens' => 520,
                    'response_format' => ['type' => 'json_object'],
                ]);

            if ($response->failed()) {
                Log::warning('Groq quick reply generation failed', [
                    'status' => $response->status(),
                    'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                    'body' => mb_substr($response->body(), 0, 1200),
                ]);

                return [];
            }

            $text = trim((string) data_get($response->json(), 'choices.0.message.content', ''));
            $items = $this->decodeItems($text);

            if ($items === []) {
                Log::warning('Groq quick reply generation returned empty payload', [
                    'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                    'text' => mb_substr($text, 0, 1200),
                ]);

                return [];
            }

            Log::info('Groq quick replies generated', [
                'count' => count($items),
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                'titles' => array_column($items, 'title'),
            ]);

            return $items;
        } catch (\Throwable $exception) {
            Log::warning('Groq quick reply generation exception', [
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Bạn tạo quick reply cho Messenger của Toyota Kiên Giang.

Trả về JSON duy nhất:
{"quick_replies":[{"title":"...","payload":"..."}]}

Luật:
- Tạo 6-10 nút, tiếng Việt có dấu.
- title tối đa 20 ký tự, là câu khách có thể bấm, không phải danh mục cứng. Ví dụ: "Trả trước 150tr?", "Hồ sơ cần gì?", "Mai lái thử được?".
- payload là một câu đầy đủ nói rõ ý định khách khi bấm.
- Bám sát tin nhắn bot vừa gửi và 6 tin gần nhất. Không nhảy sang chủ đề khác.
- Nếu đang nói về mẫu xe nào thì giữ đúng mẫu xe đó.
- Nếu bot xin số điện thoại hoặc cần liên hệ, có thể tạo nút "Gửi số cho em".
- Không bịa giá, ưu đãi, lãi suất. Nếu thiếu dữ liệu thì hỏi làm rõ.
- Không lặp ý giữa các nút.
PROMPT;
    }

    private function decodeItems(string $text): array
    {
        $decoded = json_decode($text, true);

        if (! is_array($decoded) && preg_match('/\{[\s\S]*\}/', $text, $matches)) {
            $decoded = json_decode($matches[0], true);
        }

        if (! is_array($decoded)) {
            return [];
        }

        $items = $decoded['quick_replies'] ?? $decoded['quickReplies'] ?? $decoded['items'] ?? $decoded;

        if (! is_array($items)) {
            return [];
        }

        return collect($items)
            ->map(function (mixed $item): ?array {
                if (! is_array($item)) {
                    return null;
                }

                $title = trim((string) ($item['title'] ?? $item['label'] ?? $item['text'] ?? ''));
                $payload = trim((string) ($item['payload'] ?? $item['value'] ?? $item['text'] ?? $title));

                if ($title === '') {
                    return null;
                }

                return [
                    'content_type' => 'text',
                    'title' => mb_substr($title, 0, self::MAX_TITLE_LENGTH),
                    'payload' => mb_substr($payload !== '' ? $payload : $title, 0, 1000),
                ];
            })
            ->filter()
            ->unique('title')
            ->take(self::MAX_REPLIES)
            ->values()
            ->all();
    }
}
