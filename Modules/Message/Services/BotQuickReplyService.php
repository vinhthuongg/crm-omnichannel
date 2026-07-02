<?php

namespace Modules\Message\Services;

class BotQuickReplyService
{
    /**
     * @return array<string, mixed>|null
     */
    public function attachmentFor(string $message): ?array
    {
        $quickReplies = $this->suggestionsFor($message);

        if ($quickReplies === []) {
            return null;
        }

        return [
            'type' => 'quick_reply',
            'name' => 'bot_suggested_replies',
            'quick_replies' => $quickReplies,
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function suggestionsFor(string $message): array
    {
        $text = mb_strtolower($this->removeAccents($message));
        $suggestions = [];

        if ($this->containsAny($text, ['bao gia', 'gia xe', 'gia lan banh', 'niem yet', 'camry', 'vios', 'veloz', 'raize', 'fortuner'])) {
            $suggestions = [
                ['Bao gia lan banh', 'Báo giá lăn bánh'],
                ['Khuyen mai hien co', 'Khuyến mãi'],
                ['Tra gop', 'Trả góp'],
                ['Lai thu', 'Lái thử'],
                ['De lai so dien thoai', 'Để lại SĐT'],
            ];
        } elseif ($this->containsAny($text, ['tra gop', 'vay', 'lai suat', 'ngan hang', 'tra truoc', 'ho so'])) {
            $suggestions = [
                ['Tinh tra gop', 'Tính trả góp'],
                ['Ho so can gi', 'Hồ sơ cần gì'],
                ['Lai suat hien co', 'Lãi suất'],
                ['De lai so dien thoai', 'Để lại SĐT'],
            ];
        } elseif ($this->containsAny($text, ['lai thu', 'dat lich', 'showroom', 'hen', 'lich'])) {
            $suggestions = [
                ['Dat lich lai thu', 'Đặt lịch lái thử'],
                ['Sang mai duoc khong', 'Sáng mai được không'],
                ['Gui dia chi showroom', 'Địa chỉ showroom'],
                ['De lai so dien thoai', 'Để lại SĐT'],
            ];
        } elseif ($this->containsAny($text, ['so dien thoai', 'sdt', 'zalo', 'lien he', 'goi lai'])) {
            $suggestions = [
                ['Toi se de lai sdt', 'Để lại SĐT'],
                ['Tu van qua Zalo', 'Tư vấn Zalo'],
                ['Goi lai cho toi', 'Gọi lại cho tôi'],
            ];
        } else {
            $suggestions = [
                ['Bao gia lan banh', 'Báo giá'],
                ['Tra gop', 'Trả góp'],
                ['Lai thu', 'Lái thử'],
                ['Khuyen mai', 'Khuyến mãi'],
            ];
        }

        return collect($suggestions)
            ->unique(fn (array $suggestion): string => $suggestion[0])
            ->take(5)
            ->map(fn (array $suggestion): array => [
                'content_type' => 'text',
                'title' => mb_substr($suggestion[1], 0, 20),
                'payload' => 'CRM_SUGGESTION_'.strtoupper(str_replace([' ', '-'], '_', $this->removeAccents($suggestion[0]))),
            ])
            ->values()
            ->all();
    }

    /**
     * @param array<int, string> $needles
     */
    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function removeAccents(string $value): string
    {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return $converted !== false ? $converted : $value;
    }
}
