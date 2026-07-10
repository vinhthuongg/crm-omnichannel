<?php

namespace Modules\Conversation\Services;

use Illuminate\Support\Str;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Models\Tag;
use Modules\Customer\Models\Customer;
use Modules\Message\Models\Message;

class ConversationIntentService
{
    public const TAG_QUOTE = Tag::DEFAULT_QUOTE;
    public const TAG_TEST_DRIVE = Tag::DEFAULT_TEST_DRIVE;
    public const TAG_INSTALLMENT = Tag::DEFAULT_INSTALLMENT;
    public const TAG_APPOINTMENT = Tag::DEFAULT_APPOINTMENT;
    public const TAG_MAINTENANCE = 'Bảo dưỡng';
    public const TAG_PHONE = Tag::DEFAULT_PHONE;

    private const INTENTS = [
        self::TAG_QUOTE => [
            'color' => '#2563eb',
            'keywords' => ['bao gia', 'gia xe', 'lan banh', 'gia niem yet', 'khuyen mai', 'uu dai'],
        ],
        self::TAG_TEST_DRIVE => [
            'color' => '#7c3aed',
            'keywords' => ['lai thu', 'test drive', 'chay thu', 'dat lich lai thu'],
        ],
        self::TAG_INSTALLMENT => [
            'color' => '#0f766e',
            'keywords' => ['tra gop', 'vay', 'ngan hang', 'lai suat', 'tra truoc', 'ho so vay'],
        ],
        self::TAG_APPOINTMENT => [
            'color' => '#f97316',
            'keywords' => ['dat lich', 'lich hen', 'hen lich', 'ghi nhan lich', 'ghe showroom', 'den showroom', 'ngay mai', '9h sang', 'gio sang'],
        ],
        self::TAG_MAINTENANCE => [
            'color' => '#64748b',
            'keywords' => ['bao duong', 'bao tri', 'sua chua', 'xuong dich vu', 'dat lich dich vu'],
        ],
    ];

    public function classifyMessage(Message $message): void
    {
        $conversation = $message->conversation()
            ->with(['customer', 'messages' => fn ($query) => $query->latest()->limit(8), 'tags'])
            ->first();

        if (! $conversation) {
            return;
        }

        $this->syncPhone($conversation->customer, (string) $message->content);
    }

    private function matchedIntentTags(Conversation $conversation): array
    {
        $context = $conversation->messages
            ->sortBy('created_at')
            ->map(fn (Message $message): string => (string) $message->content)
            ->filter()
            ->implode("\n");
        $normalized = $this->normalize($context);
        $tags = [];

        foreach (self::INTENTS as $tag => $config) {
            foreach ($config['keywords'] as $keyword) {
                if (str_contains($normalized, $keyword)) {
                    $tags[] = $tag;
                    break;
                }
            }
        }

        if ($conversation->customer?->phone || $this->extractPhoneNumber($context)) {
            $tags[] = self::TAG_PHONE;
        }

        return array_values(array_unique($tags));
    }

    private function syncIntentTags(Conversation $conversation, array $names): void
    {
        if ($names === []) {
            return;
        }

        $tagIds = collect($names)->map(function (string $name): int {
            $tag = Tag::query()->firstOrCreate(
                ['name' => $name],
                [
                    'color' => self::INTENTS[$name]['color'] ?? '#16a34a',
                    'is_default' => false,
                ],
            );

            return (int) $tag->id;
        })->all();

        $conversation->tags()->syncWithoutDetaching($tagIds);
        $conversation->load('tags');
    }

    private function syncPhone(?Customer $customer, string $content): void
    {
        if (! $customer || filled($customer->phone)) {
            return;
        }

        $phone = $this->extractPhoneNumber($content);

        if ($phone) {
            $customer->forceFill(['phone' => $phone])->save();
        }
    }

    private function extractPhoneNumber(string $content): ?string
    {
        preg_match_all('/(?:\+?84|0)(?:[\s.\-()]?\d){8,10}/', $content, $matches);

        foreach ($matches[0] ?? [] as $candidate) {
            $normalized = preg_replace('/\D+/', '', $candidate) ?: '';

            if (str_starts_with($normalized, '84')) {
                $normalized = '0'.substr($normalized, 2);
            }

            if (preg_match('/^0\d{8,10}$/', $normalized)) {
                return $normalized;
            }
        }

        return null;
    }

    private function normalize(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9\s]+/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();
    }
}
