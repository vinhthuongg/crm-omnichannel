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

    public function promptFor(string $message): string
    {
        $text = $this->normalized($message);

        if ($this->containsAny($text, ['tra gop', 'vay', 'lai suat', 'ngan hang', 'tra truoc', 'ho so'])) {
            return 'Anh/chi muon em ho tro tiep phan tra gop nao?';
        }

        if ($this->containsAny($text, ['bao gia', 'gia xe', 'gia lan banh', 'niem yet'])) {
            return 'Anh/chi muon xem tiep thong tin nao ve gia va uu dai?';
        }

        if ($this->containsAny($text, ['lai thu', 'dat lich', 'showroom', 'hen', 'lich'])) {
            return 'Anh/chi muon em ho tro tiep phan dat lich nao?';
        }

        if ($this->containsAny($text, ['so dien thoai', 'sdt', 'zalo', 'lien he', 'goi lai'])) {
            return 'Anh/chi muon de lai thong tin lien he theo cach nao?';
        }

        if ($this->containsAny($text, ['khuyen mai', 'uu dai', 'qua tang'])) {
            return 'Anh/chi muon xem uu dai theo nhu cau nao?';
        }

        return 'Anh/chi muon em ho tro tiep muc nao?';
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function suggestionsFor(string $message): array
    {
        $text = $this->normalized($message);
        $suggestions = [];

        if ($this->containsAny($text, ['bao gia', 'gia xe', 'gia lan banh', 'niem yet', 'camry', 'vios', 'veloz', 'raize', 'fortuner'])) {
            $suggestions = [
                ['Bao gia lan banh', 'Bao gia lan banh'],
                ['Khuyen mai hien co', 'Khuyen mai'],
                ['Tra gop', 'Tra gop'],
                ['Lai thu', 'Lai thu'],
                ['De lai so dien thoai', 'De lai SDT'],
            ];
        } elseif ($this->containsAny($text, ['tra gop', 'vay', 'lai suat', 'ngan hang', 'tra truoc', 'ho so'])) {
            $suggestions = [
                ['Tinh tra gop', 'Tinh tra gop'],
                ['Ho so can gi', 'Ho so can gi'],
                ['Lai suat hien co', 'Lai suat'],
                ['De lai so dien thoai', 'De lai SDT'],
            ];
        } elseif ($this->containsAny($text, ['lai thu', 'dat lich', 'showroom', 'hen', 'lich'])) {
            $suggestions = [
                ['Dat lich lai thu', 'Dat lich lai thu'],
                ['Sang mai duoc khong', 'Sang mai duoc khong'],
                ['Gui dia chi showroom', 'Dia chi showroom'],
                ['De lai so dien thoai', 'De lai SDT'],
            ];
        } elseif ($this->containsAny($text, ['so dien thoai', 'sdt', 'zalo', 'lien he', 'goi lai'])) {
            $suggestions = [
                ['Toi se de lai sdt', 'De lai SDT'],
                ['Tu van qua Zalo', 'Tu van Zalo'],
                ['Goi lai cho toi', 'Goi lai cho toi'],
            ];
        } elseif ($this->containsAny($text, ['khuyen mai', 'uu dai', 'qua tang'])) {
            $suggestions = [
                ['Uu dai xe moi', 'Uu dai xe moi'],
                ['Bao gia lan banh', 'Bao gia'],
                ['Tra gop', 'Tra gop'],
                ['De lai so dien thoai', 'De lai SDT'],
            ];
        } else {
            $suggestions = [
                ['Bao gia lan banh', 'Bao gia'],
                ['Tra gop', 'Tra gop'],
                ['Lai thu', 'Lai thu'],
                ['Khuyen mai', 'Khuyen mai'],
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

    private function normalized(string $value): string
    {
        return mb_strtolower($this->removeAccents($value));
    }

    private function removeAccents(string $value): string
    {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return $converted !== false ? $converted : $value;
    }
}
