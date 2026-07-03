<?php

namespace App\Services;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Log;

class GroqQuickReplySuggestionService
{
    public function __construct(private readonly Http $http)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('services.groq.enabled', false)
            && trim((string) config('services.groq.api_key', '')) !== '';
    }

    public function forBotMessage(string $botMessage): array
    {
        $botMessage = trim($botMessage);

        if (! $this->enabled() || $botMessage === '') {
            return [];
        }

        try {
            $response = $this->http
                ->baseUrl(rtrim((string) config('services.groq.base_url'), '/'))
                ->acceptJson()
                ->asJson()
                ->withToken(trim((string) config('services.groq.api_key')))
                ->timeout((int) config('services.groq.timeout', 8))
                ->post('/chat/completions', [
                    'model' => trim((string) config('services.groq.model')),
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $this->systemPrompt(),
                        ],
                        [
                            'role' => 'user',
                            'content' => "Tin nhan gan nhat cua bot:\n".$botMessage,
                        ],
                    ],
                    'temperature' => 0.45,
                    'max_completion_tokens' => 450,
                    'response_format' => ['type' => 'json_object'],
                ]);

            if ($response->failed()) {
                Log::warning('Groq quick reply generation failed', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 1200),
                ]);

                return [];
            }

            $text = trim((string) data_get($response->json(), 'choices.0.message.content', ''));
            $items = $this->decodeItems($text);

            if ($items === []) {
                Log::warning('Groq quick reply generation returned empty payload', [
                    'text' => mb_substr($text, 0, 1200),
                ]);

                return [];
            }

            Log::warning('Groq quick replies generated', [
                'count' => count($items),
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
Bạn là trợ lý gợi ý quick reply cho CRM Toyota Kiên Giang.

Nhiệm vụ: đọc tin nhắn gần nhất của bot và tạo 3 đến 4 quick replies giống như câu khách hàng thật sự muốn hỏi hoặc trả lời tiếp.

Chỉ trả về JSON object đúng format:
{
  "quick_replies": [
    {"title": "Câu ngắn đủ ý", "payload": "Ý định đầy đủ của khách khi bấm nút"}
  ]
}

Quy tắc:
- Viết tiếng Việt có dấu đầy đủ.
- title phải là một câu hỏi hoặc câu nói tự nhiên của khách, ngắn nhưng đủ nghĩa.
- title không được bị cụt câu, không được mất chữ, không kết thúc lửng.
- title tối đa 20 ký tự để Messenger hiển thị được; nếu câu dài, hãy tự viết lại thành câu ngắn đủ ý.
- title không được là hạng mục cứng như "Báo giá", "Trả góp", "Khuyến mãi", "Lái thử" nếu đứng một mình.
- title nên giống cách khách chat thật: "Bản nào hợp anh?", "Trả trước 150tr?", "Còn màu trắng không?", "Mai lái thử được?".
- payload viết rõ ý định của khách bằng một câu đầy đủ, có dấu, giữ đúng ngữ cảnh tin nhắn bot vừa nói.
- Mỗi nút phải khác nhau về ý định: hỏi tiếp, chọn phiên bản, hỏi điều kiện, để lại thông tin, đặt lịch.
- Không lặp lại cùng một bộ nút cho mọi câu.
- Không đưa hạng mục cứng nếu không liên quan.
- Không bịa giá, ưu đãi, lãi suất. Nếu cần số liệu, payload nên hỏi tiếp hoặc yêu cầu tư vấn chi tiết.
- Giới hạn 3-4 nút.
- Giọng điệu lịch sự, tự nhiên, phù hợp tư vấn xe Toyota.

Ví dụ tốt:
{"title":"Bản nào hợp anh?","payload":"Khách muốn được tư vấn phiên bản phù hợp với nhu cầu và ngân sách của mình."}
{"title":"Trả trước 150tr?","payload":"Khách muốn hỏi nếu trả trước khoảng 150 triệu thì phương án trả góp sẽ như thế nào."}
{"title":"Mai lái thử được?","payload":"Khách muốn đặt lịch lái thử vào ngày mai cho mẫu xe đang quan tâm."}

Ví dụ xấu cần tránh:
{"title":"Báo giá","payload":"Báo giá"}
{"title":"Tư vấn trả góp V","payload":"Tư vấn trả góp Vios"}
{"title":"Khuyến mãi","payload":"Khuyến mãi"}
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

                if ($title === '' || mb_strlen($title) > 20) {
                    return null;
                }

                return [
                    'content_type' => 'text',
                    'title' => $title,
                    'payload' => mb_substr($payload !== '' ? $payload : $title, 0, 1000),
                ];
            })
            ->filter()
            ->unique('title')
            ->take(4)
            ->values()
            ->all();
    }
}
