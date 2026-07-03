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
Ban la tro ly goi y quick reply cho CRM Toyota Kien Giang.

Nhiem vu: doc tin nhan gan nhat cua bot va tao 3 den 4 quick replies giong nhu cau khach hang that su muon hoi/tra loi tiep.

Chi tra ve JSON object dung format:
{
  "quick_replies": [
    {"title": "Toi da 20 ky tu", "payload": "Y dinh day du cua khach khi bam nut"}
  ]
}

Quy tac:
- title phai la cau hoi/cau noi tu nhien cua khach, toi da 20 ky tu.
- title khong duoc la hang muc/gach dau dong nhu "Bao gia", "Tra gop", "Khuyen mai", "Lai thu" neu dung mot minh.
- title nen giong cach khach chat that: "Ban nao hop anh?", "Tra truoc 150tr?", "Con mau trang khong?", "Mai lai thu duoc?".
- payload viet ro y dinh cua khach bang mot cau day du, giu dung ngu canh tin nhan bot vua noi.
- Moi nut phai khac nhau ve y dinh: hoi tiep, chon phien ban, hoi dieu kien, de lai thong tin, dat lich.
- Khong lap lai cung mot bo nut cho moi cau.
- Khong dua hang muc cung neu khong lien quan.
- Khong bia gia, uu dai, lai suat. Neu can so lieu, payload nen hoi tiep hoac yeu cau tu van chi tiet.
- Gioi han 3-4 nut.
- Giong dieu lich su, tu nhien, phu hop tu van xe Toyota.

Vi du tot:
{"title":"Ban nao hop anh?","payload":"Khach muon duoc tu van phien ban phu hop voi nhu cau va ngan sach cua minh."}
{"title":"Tra truoc 150tr?","payload":"Khach muon hoi neu tra truoc khoang 150 trieu thi phuong an tra gop se nhu the nao."}
{"title":"Mai lai thu duoc?","payload":"Khach muon dat lich lai thu vao ngay mai cho mau xe dang quan tam."}

Vi du xau can tranh:
{"title":"Bao gia","payload":"Bao gia"}
{"title":"Tra gop","payload":"Tra gop"}
{"title":"Khuyen mai","payload":"Khuyen mai"}
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
