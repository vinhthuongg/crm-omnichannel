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
                            'content' => "Tin nhắn gần nhất của bot:\n".$botMessage,
                        ],
                    ],
                    'temperature' => 0.7,
                    'max_completion_tokens' => 900,
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
Bạn là trợ lý tạo quick reply cho CRM Toyota Kiên Giang.

Nhiệm vụ: đọc tin nhắn gần nhất của bot và tạo 8 đến 13 quick replies. Các nút phải giống câu khách hàng thật sự sẽ bấm để hỏi tiếp, không phải danh mục khô cứng.

Chỉ trả về JSON object đúng format:
{
  "quick_replies": [
    {"title": "Câu ngắn đủ ý", "payload": "Ý định đầy đủ của khách khi bấm nút"}
  ]
}

Quy tắc:
- Viết tiếng Việt có dấu đầy đủ.
- Tạo tối đa 13 nút, tối thiểu 8 nút nếu đủ ngữ cảnh.
- title tối đa 20 ký tự để Messenger hiển thị được. Nếu ý dài, tự viết lại thành câu ngắn tự nhiên.
- title được phép ngắn nhưng không mất nghĩa. Không viết kiểu danh mục một từ như "Báo giá", "Trả góp", "Khuyến mãi", "Lái thử".
- title phải là câu hỏi hoặc câu nói tự nhiên của khách, ví dụ: "Bản nào hợp anh?", "Trả trước 150tr?", "Còn màu trắng không?", "Mai lái thử được?", "Giấy tờ cần gì?".
- payload phải là một câu đầy đủ, có dấu, nói rõ ý định của khách và giữ đúng ngữ cảnh bot vừa trả lời.
- 60-70% nút phải bám sát nhu cầu chính trong tin nhắn bot vừa nói.
- 30-40% nút còn lại là nhu cầu liên quan hợp lý: phiên bản, màu xe, giá lăn bánh, ưu đãi, trả góp, hồ sơ, lái thử, đặt lịch, bảo dưỡng, kỹ thuật, để lại số điện thoại.
- Nếu bot đang nói về một mẫu xe cụ thể, payload phải giữ mẫu xe đó. Không tự đổi sang mẫu xe khác.
- Nếu bot đang nói về kỹ thuật hoặc cứu hộ, ưu tiên các nút hỏi lỗi, đặt lịch kiểm tra, gọi kỹ thuật, gửi số điện thoại.
- Nếu bot đang nói về giá hoặc ưu đãi, ưu tiên các nút hỏi phiên bản, màu, lăn bánh, trả góp, thời gian nhận xe.
- Nếu bot đang hỏi xin số điện thoại, tạo một nút có ý định gửi số điện thoại để CRM có thể dùng payload chia sẻ số nếu phù hợp.
- Không bịa giá, ưu đãi, lãi suất. Nếu cần số liệu, payload chỉ nên yêu cầu tư vấn chi tiết hoặc hỏi thêm thông tin.
- Không lặp ý giữa các nút.
- Giọng điệu lịch sự, tự nhiên, đúng kiểu khách chat với tư vấn viên Toyota.

Ví dụ tốt:
{"title":"Bản nào hợp anh?","payload":"Khách muốn được tư vấn phiên bản phù hợp với nhu cầu sử dụng và ngân sách của mình."}
{"title":"Trả trước 150tr?","payload":"Khách muốn hỏi nếu trả trước khoảng 150 triệu thì phương án trả góp cho mẫu xe đang quan tâm sẽ như thế nào."}
{"title":"Còn màu trắng không?","payload":"Khách muốn hỏi mẫu xe đang quan tâm còn màu trắng tại Toyota Kiên Giang không."}
{"title":"Mai lái thử được?","payload":"Khách muốn đặt lịch lái thử vào ngày mai cho mẫu xe đang quan tâm."}
{"title":"Gửi số cho em","payload":"Khách muốn gửi số điện thoại để Toyota Kiên Giang liên hệ tư vấn chi tiết."}

Ví dụ xấu cần tránh:
{"title":"Báo giá","payload":"Báo giá"}
{"title":"Trả góp","payload":"Trả góp"}
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
