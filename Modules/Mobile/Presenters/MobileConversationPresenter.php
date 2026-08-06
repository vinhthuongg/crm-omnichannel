<?php

namespace Modules\Mobile\Presenters;

use App\Models\User;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Models\Tag;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Customer\Models\Customer;
use Modules\Customer\Models\CustomerTag;
use Modules\Message\Models\Message;

class MobileConversationPresenter
{
    /** Định dạng dữ liệu conversation thành cấu trúc phản hồi dành cho ứng dụng mobile hoặc dashboard. */
    public function conversation(Conversation $conversation): array
    {
        $lastMessage = $conversation->relationLoaded('messages') && $conversation->messages->isNotEmpty()
            ? $conversation->messages->sortByDesc('created_at')->first()
            : $conversation->messages()->latest('created_at')->first();
        $interests = $conversation->customer ? $this->interests($conversation->customer) : collect();

        return [
            'id' => (int) $conversation->id,
            'status' => ConversationStatus::normalize($conversation->status),
            'status_label' => ConversationStatus::label($conversation->status),
            'status_color' => ConversationStatus::color($conversation->status),
            'channel' => $conversation->facebook_page_id ? 'facebook' : 'zalo',
            'facebook_page_id' => $conversation->facebook_page_id,
            'unread_messages_count' => (int) $conversation->unread_messages_count,
            'last_message_at' => $conversation->last_message_at?->toISOString(),
            'last_read_at' => $conversation->last_read_at?->toISOString(),
            'customer' => $conversation->customer ? $this->customer($conversation->customer) : null,
            'assignee' => $conversation->assignee ? $this->agent($conversation->assignee) : null,
            'tags' => $interests,
            'customer_interests' => $interests,
            'conversation_tags' => $conversation->tags->map(fn (Tag $tag): array => $this->tag($tag))->values(),
            'last_message' => $lastMessage ? $this->message($lastMessage) : null,
            'created_at' => $conversation->created_at?->toISOString(),
            'updated_at' => $conversation->updated_at?->toISOString(),
        ];
    }

    /** Định dạng dữ liệu message thành cấu trúc phản hồi dành cho ứng dụng mobile hoặc dashboard. */
    public function message(Message $message): array
    {
        return [
            'id' => (int) $message->id,
            'conversation_id' => (int) $message->conversation_id,
            'sender_type' => $message->sender_type,
            'sender_id' => $message->sender_id ? (int) $message->sender_id : null,
            'sender_name' => $message->senderName(),
            'channel' => $message->channel,
            'content' => $message->recalled_at ? null : $message->content,
            'message_type' => $message->message_type,
            'attachments' => $message->recalled_at ? [] : $this->attachments($message),
            'outbound_status' => $message->outbound_status,
            'outbound_error' => $message->outbound_error,
            'external_message_id' => $message->external_message_id,
            'is_recalled' => (bool) $message->recalled_at,
            'sent_at' => $message->sent_at?->toISOString(),
            'read_at' => $message->read_at?->toISOString(),
            'created_at' => $message->created_at?->toISOString(),
        ];
    }

    /** Định dạng dữ liệu customer thành cấu trúc phản hồi dành cho ứng dụng mobile hoặc dashboard. */
    private function customer(Customer $customer): array
    {
        return [
            'id' => (int) $customer->id, 'name' => $customer->name, 'avatar' => $customer->avatar,
            'phone' => $customer->phone, 'phone_collected_at' => $customer->phone_collected_at?->toISOString(),
            'email' => $customer->email, 'is_potential' => (bool) $customer->is_potential,
            'potential_marked_at' => $customer->potential_marked_at?->toISOString(),
            'potential_marked_by' => $customer->potential_marked_by ? (int) $customer->potential_marked_by : null,
            'potential' => [
                'is_potential' => (bool) $customer->is_potential,
                'marked_at' => $customer->potential_marked_at?->toISOString(),
                'marked_by' => $customer->relationLoaded('potentialMarkedBy') && $customer->potentialMarkedBy
                    ? $this->agent($customer->potentialMarkedBy)
                    : ($customer->potential_marked_by ? ['id' => (int) $customer->potential_marked_by] : null),
            ],
            'channels' => $customer->relationLoaded('channels') ? $customer->channels->map(fn ($channel): array => [
                'id' => (int) $channel->id, 'channel' => $channel->channel,
                'external_id' => $channel->external_id, 'metadata' => $channel->metadata,
            ])->values() : [],
            'interests' => $customer->relationLoaded('tags') ? $this->interests($customer) : [],
            'created_at' => $customer->created_at?->toISOString(),
            'updated_at' => $customer->updated_at?->toISOString(),
        ];
    }

    /** Ghép sở thích của khách trong hội thoại với ID nhãn hệ thống. */
    private function interests(Customer $customer)
    {
        $systemIds = Tag::query()->whereIn('name', $customer->tags->pluck('name')->filter()->values())->pluck('id', 'name');
        return $customer->tags->map(fn (CustomerTag $tag): array => [
            'id' => (int) $tag->id, 'customer_tag_id' => (int) $tag->id,
            'tag_id' => (int) ($systemIds[$tag->name] ?? 0), 'source' => 'customer',
            'name' => $tag->name, 'color' => $tag->color,
        ])->filter(fn (array $tag): bool => filled($tag['name']))
            ->unique(fn (array $tag): string => mb_strtolower((string) $tag['name']))->values();
    }

    /** Chuẩn hóa attachment message thành URL, tên, MIME và loại hiển thị trên mobile. */
    private function attachments(Message $message): array
    {
        return collect($message->attachments ?? [])->reject(fn (array $item): bool => ($item['type'] ?? '') === 'quick_reply')
            ->map(function (array $item): array {
                $url = (string) (data_get($item, 'payload.image_data.url') ?: data_get($item, 'payload.video_data.url')
                    ?: data_get($item, 'payload.audio_data.url') ?: data_get($item, 'url')
                    ?: data_get($item, 'payload.url') ?: data_get($item, 'payload.file_url'));
                return ['type' => (string) ($item['type'] ?? 'file'),
                    'name' => (string) ($item['name'] ?? basename((string) parse_url($url, PHP_URL_PATH))),
                    'url' => $url, 'mime_type' => (string) ($item['mime_type'] ?? ''), 'payload' => $item['payload'] ?? null];
            })->filter(fn (array $item): bool => $item['url'] !== '')->values()->all();
    }

    /** Trả hồ sơ và vai trò của nhân viên đang phụ trách hội thoại. */
    private function agent(User $user): array
    {
        return ['id' => (int) $user->id, 'name' => $user->name, 'email' => $user->email,
            'is_active' => (bool) $user->is_active,
            'roles' => $user->relationLoaded('roles') ? $user->roles->pluck('name')->values() : $user->getRoleNames()->values()];
    }

    /** Trả ID, tên, màu và trạng thái mặc định của nhãn hội thoại. */
    private function tag(Tag $tag): array
    {
        return ['id' => (int) $tag->id, 'name' => $tag->name, 'color' => $tag->color, 'is_default' => (bool) $tag->is_default];
    }
}
