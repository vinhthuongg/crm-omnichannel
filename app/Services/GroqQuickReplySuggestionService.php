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
- payload là một câu đầy đủ nói rõ ý định khách khi bấm và phải khớp tuyệt đối với title.
- Nếu title là "Thông số xe?" thì payload phải nói khách muốn xem thông số/trang bị, không được thành ưu đãi/khuyến mãi.
- Nếu title là "Ưu đãi xe này?" thì payload phải nói khách muốn xem ưu đãi/khuyến mãi, không được thành thông số.
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

                $payload = $this->payloadAlignedWithTitle($title, $payload);

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

    private function payloadAlignedWithTitle(string $title, string $payload): string
    {
        $titleIntent = $this->intent($title);
        $payloadIntent = $this->intent($payload);

        if ($titleIntent !== null && ($payloadIntent === null || $payloadIntent !== $titleIntent)) {
            return $this->payloadForIntent($titleIntent);
        }

        return $payload !== '' ? $payload : $title;
    }

    private function intent(string $text): ?string
    {
        $normalized = str($text)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9\s]+/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();

        foreach ($this->intentKeywords() as $intent => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($normalized, $keyword)) {
                    return $intent;
                }
            }
        }

        return null;
    }

    private function payloadForIntent(string $intent): string
    {
        return match ($intent) {
            'specs' => 'Khách muốn xem thông số kỹ thuật, trang bị và đặc điểm của mẫu xe đang được tư vấn.',
            'promotion' => 'Khách muốn hỏi ưu đãi và khuyến mãi hiện tại cho mẫu xe đang được tư vấn.',
            'price' => 'Khách muốn hỏi giá niêm yết hoặc giá lăn bánh của mẫu xe đang được tư vấn.',
            'finance' => 'Khách muốn hỏi phương án trả góp cho mẫu xe đang được tư vấn.',
            'documents' => 'Khách muốn biết hồ sơ và giấy tờ cần chuẩn bị để mua xe.',
            'colors' => 'Khách muốn hỏi mẫu xe đang được tư vấn còn những màu nào.',
            'test_drive' => 'Khách muốn đặt lịch lái thử hoặc hỏi điều kiện lái thử mẫu xe đang quan tâm.',
            'appointment' => 'Khách muốn đặt lịch hẹn để được Toyota Kiên Giang hỗ trợ.',
            'compare' => 'Khách muốn so sánh mẫu xe đang được tư vấn với mẫu xe khác.',
            'availability' => 'Khách muốn hỏi xe còn hàng hoặc thời gian giao xe.',
            'phone' => 'Khách muốn để lại số điện thoại để nhân viên Toyota Kiên Giang liên hệ tư vấn.',
            default => 'Khách muốn được tư vấn tiếp theo đúng nội dung nút đã chọn.',
        };
    }

    private function intentKeywords(): array
    {
        return [
            'specs' => ['thong so', 'trang bi', 'dong co', 'kich thuoc', 'noi that', 'ngoai that', 'an toan', 'tieu hao', 'option'],
            'promotion' => ['uu dai', 'khuyen mai', 'giam gia', 'qua tang', 'chuong trinh'],
            'price' => ['gia', 'lan banh', 'bao gia', 'niem yet'],
            'finance' => ['tra gop', 'lai suat', 'vay', 'tra truoc', 'ngan hang', 'gop'],
            'documents' => ['ho so', 'giay to', 'cccd', 'cmnd', 'thu tuc'],
            'colors' => ['mau', 'mau nao', 'mau xe'],
            'test_drive' => ['lai thu', 'test drive'],
            'appointment' => ['dat lich', 'lich hen', 'hen lich', 'showroom'],
            'compare' => ['so sanh', 'khac gi', 'hon gi'],
            'availability' => ['con xe', 'con hang', 'giao xe', 'co san'],
            'phone' => ['so dien thoai', 'sdt', 'gui so', 'de lai so', 'goi lai', 'lien he'],
        ];
    }
}
