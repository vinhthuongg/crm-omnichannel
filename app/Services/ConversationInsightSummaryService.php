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
                'text' => 'Chua co du noi dung de tom tat hoi thoai.',
                'facts' => ['phones' => []],
            ];
        }

        $customerName = $this->customerName($conversation);
        $combinedText = $this->normalizedText($messages->pluck('content')->filter()->implode(' '));
        $vehicle = $this->detectVehicle($combinedText);
        $intents = $this->detectIntents($combinedText);
        $phones = $this->extractPhones($messages);
        $storedPhone = $conversation->customer?->phone;
        $latestPhone = $phones !== [] ? $phones[array_key_last($phones)] : null;
        $phone = $storedPhone ?: $latestPhone;
        $advisors = $this->advisorNames($messages);

        return [
            'text' => trim(
                $customerName.' '.$this->needSentence($vehicle, $intents).'. '.
                $this->advisorSentence($advisors).' va '.$this->phoneSentence($storedPhone, $latestPhone, $phone).'.'
            ),
            'facts' => [
                'vehicle' => $vehicle,
                'intents' => $intents,
                'phone' => $phone,
                'phones' => $phones,
                'stored_phone' => $storedPhone,
                'latest_phone' => $latestPhone,
                'advisors' => $advisors,
            ],
        ];
    }

    private function customerName(Conversation $conversation): string
    {
        $name = trim((string) $conversation->customer?->name);

        if ($name === '' || preg_match('/^\d+$/', $name)) {
            return 'Khach hang';
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
            $intents[] = 'bao gia';
        }

        if (preg_match('/(tra gop|vay|ngan hang|lai suat|tra truoc)/', $text)) {
            $intents[] = 'tra gop';
        }

        if (preg_match('/(lai thu|test drive|chay thu)/', $text)) {
            $intents[] = 'lai thu';
        }

        if (preg_match('/(dat lich|hen|lich hen|sap xep)/', $text)) {
            $intents[] = 'dat lich';
        }

        if (preg_match('/(bao duong|sua chua|dich vu|phu tung)/', $text)) {
            $intents[] = 'dich vu';
        }

        if (preg_match('/(khuyen mai|uu dai|giam gia|ctkm)/', $text)) {
            $intents[] = 'uu dai';
        }

        return array_values(array_unique($intents));
    }

    /**
     * @return array<int, string>
     */
    private function extractPhones(Collection $messages): array
    {
        $phones = [];

        foreach ($messages as $message) {
            if (! $message instanceof Message) {
                continue;
            }

            if (! preg_match_all('/(?:\+?84|0)(?:[\s.\-]?\d){8,10}/', (string) $message->content, $matches)) {
                continue;
            }

            foreach ($matches[0] as $match) {
                $phone = preg_replace('/[^\d+]/', '', $match);

                if (! $phone) {
                    continue;
                }

                $phones = array_values(array_filter($phones, fn (string $item): bool => $item !== $phone));
                $phones[] = $phone;
            }
        }

        return $phones;
    }

    /**
     * @return array<int, string>
     */
    private function advisorNames(Collection $messages): array
    {
        return $messages
            ->filter(fn (Message $message): bool => in_array($message->sender_type, ['user', 'system'], true))
            ->map(fn (Message $message): string => $message->sender_type === 'system'
                ? 'Bot'
                : trim((string) ($message->sender?->name ?: 'Nhan vien')))
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
            return 'dang trao doi voi Toyota Kien Giang';
        }

        $parts = [];

        if (in_array('mua xe', $intents, true) || $vehicle) {
            $parts[] = 'muon tham khao'.($vehicle ? ' Toyota '.$vehicle : ' xe Toyota');
        }

        foreach (['bao gia', 'tra gop', 'lai thu', 'dat lich', 'dich vu', 'uu dai'] as $intent) {
            if (in_array($intent, $intents, true)) {
                $parts[] = match ($intent) {
                    'bao gia' => 'can bao gia',
                    'tra gop' => 'quan tam tra gop',
                    'lai thu' => 'muon lai thu',
                    'dat lich' => 'muon dat lich',
                    'dich vu' => 'can ho tro dich vu',
                    'uu dai' => 'hoi ve uu dai',
                };
            }
        }

        return implode(' va ', array_values(array_unique($parts)));
    }

    /**
     * @param  array<int, string>  $advisors
     */
    private function advisorSentence(array $advisors): string
    {
        if ($advisors === []) {
            return 'chua co nhan vien tu van';
        }

        if (count($advisors) === 1) {
            return $advisors[0].' da tu van';
        }

        $last = array_pop($advisors);

        return implode(', ', $advisors).' va '.$last.' da tu van';
    }

    private function phoneSentence(?string $storedPhone, ?string $latestPhone, ?string $phone): string
    {
        if ($storedPhone && $latestPhone && $storedPhone !== $latestPhone) {
            return 'dang luu so '.$storedPhone.', khach vua gui them so '.$latestPhone;
        }

        if ($phone) {
            return 'da co so dien thoai '.$phone;
        }

        return 'chua lay duoc so dien thoai';
    }
}
