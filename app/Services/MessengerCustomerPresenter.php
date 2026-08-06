<?php

namespace App\Services;

use Modules\Conversation\Models\Conversation;
use Modules\Customer\Models\CustomerTag;

class MessengerCustomerPresenter
{
    /** Tạo URL hồ sơ Facebook từ định danh khách hàng nếu có. */
    public function facebookProfileUrl(Conversation $conversation): ?string
    {
        $channel = $conversation->customer?->channels?->firstWhere('channel', 'facebook');
        $metadata = (array) ($channel?->metadata ?? []);
        $url = (string) (data_get($metadata, 'profile.link')
            ?: data_get($metadata, 'profile.url')
            ?: data_get($metadata, 'profile.profile_url')
            ?: data_get($metadata, 'profile_url')
            ?: data_get($metadata, 'link'));

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : '#';
    }

    /** Định dạng thông tin liên hệ chính của khách hàng. */
    public function contact(Conversation $conversation): array
    {
        $customer = $conversation->customer;
        $channel = $customer?->channels?->first();

        return [
            'name' => $customer?->name ?? '',
            'phone' => $customer?->phone ?? '',
            'email' => $customer?->email ?? '',
            'channel' => $channel?->channel ? ucfirst($channel->channel) : '',
        ];
    }

    /** Tạo danh sách thông tin khách hàng được phép hiển thị. */
    public function publicDetails(Conversation $conversation): array
    {
        $customer = $conversation->customer;
        $channel = $customer?->channels?->first();

        return collect([
            ['label' => 'Ten cong khai', 'value' => $customer?->name],
            ['label' => 'So dien thoai', 'value' => $customer?->phone],
            ['label' => 'Email', 'value' => $customer?->email],
            ['label' => 'Kenh', 'value' => $channel?->channel ? ucfirst($channel->channel) : null],
        ])->filter(fn (array $detail): bool => filled($detail['value']))->values()->all();
    }

    /** Lấy và định dạng danh sách ghi chú của khách hàng. */
    public function notes(Conversation $conversation): array
    {
        return $conversation->customer?->notes?->sortByDesc('created_at')
            ->map(fn ($note): array => [
                'id' => (int) $note->id,
                'body' => $note->body,
                'author' => $note->user?->name ?? 'Admin',
                'created_at' => $note->created_at?->format('H:i d/m/Y'),
            ])->values()->all() ?? [];
    }

    /** Lấy và định dạng danh sách nhãn liên quan. */
    public function tags(Conversation $conversation): array
    {
        return $conversation->customer?->tags?->take(1)
            ->map(fn (CustomerTag $tag): array => [
                'id' => (int) $tag->id, 'name' => $tag->name, 'color' => $tag->color,
            ])->values()->all() ?? [];
    }
}
