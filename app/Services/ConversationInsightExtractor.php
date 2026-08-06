<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Message\Models\Message;

class ConversationInsightExtractor
{
    /** Trích xuất các insight có cấu trúc từ nội dung hội thoại. */
    public function extract(Collection $messages): array
    {
        $text = Str::of($messages->pluck('content')->filter()->implode(' '))->ascii()->lower()
            ->replaceMatches('/\s+/', ' ')->trim()->toString();
        return ['vehicle' => $this->vehicle($text), 'intents' => $this->intents($text),
            'phones' => $this->phones($messages), 'advisors' => $this->advisors($messages)];
    }

    /** Trích xuất thông tin xe khách hàng đang quan tâm. */
    private function vehicle(string $text): ?string
    {
        $vehicles = ['Camry' => ['camry'], 'Vios' => ['vios'], 'Corolla Cross' => ['corolla cross', 'cross'],
            'Yaris Cross' => ['yaris cross'], 'Veloz Cross' => ['veloz'], 'Avanza Premio' => ['avanza'],
            'Raize' => ['raize'], 'Fortuner' => ['fortuner'], 'Innova Cross' => ['innova'], 'Hilux' => ['hilux'],
            'Land Cruiser Prado' => ['prado'], 'Land Cruiser' => ['land cruiser'], 'Alphard' => ['alphard']];
        foreach ($vehicles as $vehicle => $aliases) foreach ($aliases as $alias) if (str_contains($text, $alias)) return $vehicle;
        return null;
    }

    /** Nhận diện và tổng hợp các ý định nghiệp vụ trong hội thoại. */
    private function intents(string $text): array
    {
        $patterns = ['mua xe' => '/\b(mua|quan tam|tham khao|tu van)\b/', 'bao gia' => '/(bao gia|gia|lan banh|bao nhieu|nhieu tien)/',
            'tra gop' => '/(tra gop|vay|ngan hang|lai suat|tra truoc)/', 'lai thu' => '/(lai thu|test drive|chay thu)/',
            'dat lich' => '/(dat lich|hen|lich hen|sap xep)/', 'dich vu' => '/(bao duong|sua chua|dich vu|phu tung)/',
            'uu dai' => '/(khuyen mai|uu dai|giam gia|ctkm)/'];
        return collect($patterns)->filter(fn (string $pattern): int|false => preg_match($pattern, $text))->keys()->values()->all();
    }

    /** Trích xuất các số điện thoại xuất hiện trong nội dung. */
    private function phones(Collection $messages): array
    {
        $phones = [];
        foreach ($messages as $message) {
            if (! $message instanceof Message || ! preg_match_all('/(?:\+?84|0)(?:[\s.\-]?\d){8,10}/', (string) $message->content, $matches)) continue;
            foreach ($matches[0] as $match) {
                $phone = preg_replace('/[^\d+]/', '', $match);
                if (! $phone) continue;
                $phones = array_values(array_filter($phones, fn (string $item): bool => $item !== $phone));
                $phones[] = $phone;
            }
        }
        return $phones;
    }

    /** Trích xuất hoặc định dạng danh sách nhân viên tư vấn liên quan. */
    private function advisors(Collection $messages): array
    {
        return $messages->filter(fn (Message $message): bool => in_array($message->sender_type, ['user', 'system'], true))
            ->map(fn (Message $message): string => $message->sender_type === 'system' ? 'Bot' : trim((string) ($message->sender?->name ?: 'Nhan vien')))
            ->filter()->unique()->take(3)->values()->all();
    }
}
