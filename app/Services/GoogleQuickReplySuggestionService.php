<?php

namespace App\Services;

use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Log;

class GoogleQuickReplySuggestionService
{
    public function __construct(private readonly Http $http)
    {
    }

    public function enabled(): bool
    {
        return (bool) config('services.google_ai.enabled', false)
            && trim((string) config('services.google_ai.api_key', '')) !== '';
    }

    public function forBotMessage(string $botMessage): array
    {
        $botMessage = trim($botMessage);

        if (! $this->enabled() || $botMessage === '') {
            return [];
        }

        try {
            $response = $this->http
                ->baseUrl(rtrim((string) config('services.google_ai.base_url'), '/'))
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('services.google_ai.timeout', 8))
                ->post('/v1beta/models/'.trim((string) config('services.google_ai.model')).':generateContent?key='.rawurlencode(trim((string) config('services.google_ai.api_key'))), [
                    'contents' => [
                        [
                            'role' => 'user',
                            'parts' => [
                                ['text' => $this->prompt($botMessage)],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.45,
                        'maxOutputTokens' => 450,
                        'responseMimeType' => 'application/json',
                    ],
                ]);

            if ($response->failed()) {
                Log::warning('Google AI quick reply generation failed', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 1200),
                ]);

                return [];
            }

            $text = trim((string) data_get($response->json(), 'candidates.0.content.parts.0.text', ''));
            $items = $this->decodeItems($text);

            if ($items === []) {
                Log::warning('Google AI quick reply generation returned empty payload', [
                    'text' => mb_substr($text, 0, 1200),
                ]);

                return [];
            }

            Log::warning('Google AI quick replies generated', [
                'count' => count($items),
                'titles' => array_column($items, 'title'),
            ]);

            return $items;
        } catch (\Throwable $exception) {
            Log::warning('Google AI quick reply generation exception', [
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    private function prompt(string $botMessage): string
    {
        return <<<PROMPT
Ban la tro ly goi y quick reply cho CRM Toyota Kien Giang.

Hay doc tin nhan gan nhat cua bot ben duoi va tao 3 den 4 nut quick reply that tu nhien de khach bam tiep.

Yeu cau:
- Chi tra ve JSON array, khong markdown, khong giai thich.
- Moi phan tu co dung 2 field: title va payload.
- title la cau ngan de hien tren nut Messenger, toi da 20 ky tu.
- payload la y dinh day du cua khach khi bam nut, de bot tiep tuc hoi/tra loi dung ngu canh.
- Nut phai theo dung noi dung bot vua noi, khong dua hang muc cung neu khong lien quan.
- Khong bia so lieu gia, lai suat, uu dai. Neu can so lieu, payload hay hoi tiep/y yeu cau tu van chi tiet.
- Giong dieu lich su, tu nhien, phu hop tu van xe Toyota.

Vi du output:
[
  {"title":"Xem ban tu dong","payload":"Khach muon xem thong tin phien ban Vios so tu dong."},
  {"title":"Tinh tra gop","payload":"Khach muon tinh phuong an tra gop cho mau xe dang duoc tu van."}
]

Tin nhan gan nhat cua bot:
{$botMessage}
PROMPT;
    }

    private function decodeItems(string $text): array
    {
        $decoded = json_decode($text, true);

        if (! is_array($decoded) && preg_match('/\[[\s\S]*\]/', $text, $matches)) {
            $decoded = json_decode($matches[0], true);
        }

        if (! is_array($decoded)) {
            return [];
        }

        return collect($decoded)
            ->map(function (mixed $item): ?array {
                if (! is_array($item)) {
                    return null;
                }

                $title = mb_substr(trim((string) ($item['title'] ?? $item['label'] ?? $item['text'] ?? '')), 0, 20);
                $payload = trim((string) ($item['payload'] ?? $item['value'] ?? $item['text'] ?? $title));

                if ($title === '') {
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
