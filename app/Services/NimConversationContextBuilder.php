<?php

namespace App\Services;

use Modules\Conversation\Models\Conversation;

class NimConversationContextBuilder
{
    /** Ghép tin nhắn gần đây, hồ sơ khách hàng và kênh thành ngữ cảnh gửi cho NIM. */
    public function build(Conversation $conversation): array
    {
        $conversation->loadMissing(['customer', 'tags']);
        $messages = $conversation->messages()->with('sender')->latest()->limit(18)->get()->reverse()->values()
            ->map(fn ($message): array => ['role' => $message->sender_type === 'customer' ? 'customer' : 'staff',
                'name' => $message->sender?->name ?: ($message->sender_type === 'customer' ? 'Khach hang' : 'Nhan vien'),
                'content' => trim((string) $message->content), 'channel' => (string) $message->channel,
                'time' => optional($message->created_at)->format('H:i d/m/Y')])
            ->filter(fn (array $message): bool => $message['content'] !== '')->values()->all();
        return ['customer' => ['name' => $conversation->customer?->name, 'phone' => $conversation->customer?->phone,
            'tags' => $conversation->tags->pluck('name')->values()->all()], 'conversation' => $messages];
    }
}
