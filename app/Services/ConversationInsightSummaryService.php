<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Conversation\Models\Conversation;
use Modules\Message\Models\Message;

class ConversationInsightSummaryService
{
    /**
     * @return array{text: string, facts: array<string, mixed>}
     */
    public function summarize(Conversation $conversation): array
    {
        $conversation->loadMissing(['customer', 'assignee']);

        $messages = $conversation->messages()
            ->with('sender')
            ->latest()
            ->limit(40)
            ->get()
            ->reverse()
            ->values();

        if ($messages->isEmpty()) {
            return [
                'text' => 'Chưa có đủ nội dung để tóm tắt hội thoại.',
                'facts' => [],
            ];
        }

        $customerName = $this->customerName($conversation);
        $combinedText = $this->normalizedText($messages->pluck('content')->filter()->implode(' '));
        $vehicle = $this->detectVehicle($combinedText);
        $intents = $this->detectIntents($combinedText);
        $phone = $conversation->customer?->phone ?: $this->extractPhone($messages);
        $advisors = $this->advisorNames($messages);
        $need = $this->needSentence($vehicle, $intents);
        $advisorSentence = $this->advisorSentence($advisors);
        $phoneSentence = $phone
            ? 'đã có số điện thoại '.$phone
            : 'chưa lấy được số điện thoại';

        return [
            'text' => trim($customerName.' '.$need.'. '.$advisorSentence.' và '.$phoneSentence.'.'),
            'facts' => [
                'vehicle' => $vehicle,
                'intents' => $intents,
                'phone' => $phone,
                'advisors' => $advisors,
            ],
        ];
    }

    private function customerName(Conversation $conversation): string
    {
        $name = trim((string) $conversation->customer?->name);

        if ($name === '' || preg_match('/^\d+$/', $name)) {
            return 'Khách hàng';
        }

        return $name;
    }

    private function normalizedText(string $text): string
    {
        return Str::of($text)
            ->ascii()
            ->lower()
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();
    }

    private function detectVehicle(string $text): ?string
    {
        $vehicles = [
            'Camry' => ['camry'],
            'Vios' => ['vios'],
            'Corolla Cross' => ['corolla cross', 'cross'],
            'Yaris Cross' => ['yaris cross'],
            'Veloz Cross' => ['veloz'],
            'Avanza Premio' => ['avanza'],
            'Raize' => ['raize'],
            'Fortuner' => ['fortuner'],
            'Innova Cross' => ['innova'],
            'Hilux' => ['hilux'],
            'Land Cruiser Prado' => ['prado'],
            'Land Cruiser' => ['land cruiser'],
            'Alphard' => ['alphard'],
        ];

        foreach ($vehicles as $vehicle => $aliases) {
            foreach ($aliases as $alias) {
                if (str_contains($text, $alias)) {
                    return $vehicle;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function detectIntents(string $text): array
    {
        $intents = [];

        if (preg_match('/\b(mua|quan tam|tham khao|tu van)\b/', $text)) {
            $intents[] = 'mua xe';
        }

        if (preg_match('/(bao gia|gia|lan banh|bao nhieu|nhieu tien)/', $text)) {
            $intents[] = 'báo giá';
        }

        if (preg_match('/(tra gop|vay|ngan hang|lai suat|tra truoc)/', $text)) {
            $intents[] = 'trả góp';
        }

        if (preg_match('/(lai thu|test drive|chay thu)/', $text)) {
            $intents[] = 'lái thử';
        }

        if (preg_match('/(dat lich|hen|lich hen|sap xep)/', $text)) {
            $intents[] = 'đặt lịch';
        }

        if (preg_match('/(bao duong|sua chua|dich vu|phu tung)/', $text)) {
            $intents[] = 'dịch vụ';
        }

        if (preg_match('/(khuyen mai|uu dai|giam gia|ctkm)/', $text)) {
            $intents[] = 'ưu đãi';
        }

        return array_values(array_unique($intents));
    }

    private function extractPhone(Collection $messages): ?string
    {
        foreach ($messages as $message) {
            if (! $message instanceof Message) {
                continue;
            }

            if (preg_match('/(?:\+?84|0)(?:[\s.\-]?\d){8,10}/', (string) $message->content, $matches)) {
                return preg_replace('/[^\d+]/', '', $matches[0]);
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function advisorNames(Collection $messages): array
    {
        return $messages
            ->filter(fn (Message $message): bool => in_array($message->sender_type, ['user', 'system'], true))
            ->map(function (Message $message): string {
                if ($message->sender_type === 'system') {
                    return 'Bot';
                }

                return trim((string) ($message->sender?->name ?: 'Nhân viên'));
            })
            ->filter()
            ->unique()
            ->take(3)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $intents
     */
    private function needSentence(?string $vehicle, array $intents): string
    {
        if ($intents === [] && ! $vehicle) {
            return 'đang trao đổi với Toyota Kiên Giang';
        }

        $parts = [];

        if (in_array('mua xe', $intents, true) || $vehicle) {
            $parts[] = 'muốn tham khảo'.($vehicle ? ' Toyota '.$vehicle : ' xe Toyota');
        }

        foreach (['báo giá', 'trả góp', 'lái thử', 'đặt lịch', 'dịch vụ', 'ưu đãi'] as $intent) {
            if (in_array($intent, $intents, true)) {
                $parts[] = match ($intent) {
                    'báo giá' => 'cần báo giá',
                    'trả góp' => 'quan tâm trả góp',
                    'lái thử' => 'muốn lái thử',
                    'đặt lịch' => 'muốn đặt lịch',
                    'dịch vụ' => 'cần hỗ trợ dịch vụ',
                    'ưu đãi' => 'hỏi về ưu đãi',
                };
            }
        }

        return implode(' và ', array_values(array_unique($parts)));
    }

    /**
     * @param  array<int, string>  $advisors
     */
    private function advisorSentence(array $advisors): string
    {
        if ($advisors === []) {
            return 'chưa có nhân viên tư vấn';
        }

        if (count($advisors) === 1) {
            return $advisors[0].' đã tư vấn';
        }

        $last = array_pop($advisors);

        return implode(', ', $advisors).' và '.$last.' đã tư vấn';
    }
}
