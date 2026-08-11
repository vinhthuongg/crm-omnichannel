<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Support\ConversationStatus;

class MessengerViewPresenter
{
    private const MESSAGE_LIMIT = 10;

    /** Nhận ConversationInsightSummaryService để tóm tắt nhu cầu và thông tin khách hàng; MessengerCustomerPresenter để định dạng dữ liệu đầu ra. */
    public function __construct(
        private readonly ConversationInsightSummaryService $summaries,
        private readonly MessengerCustomerPresenter $customers,
    ) {}

    /** Định dạng các tin nhắn thành timeline hiển thị trong Messenger. */
    public function timeline(?Conversation $conversation, User $user): Collection
    {
        if (! $conversation) {
            return collect();
        }
        $messages = $conversation->messages()->latest()->limit(self::MESSAGE_LIMIT)->get()->reverse()->values();
        $users = User::query()->whereIn('id', $messages->where('sender_type', 'user')->pluck('sender_id')->filter()->unique())->get()->keyBy('id');

        return $messages->map(function ($message) use ($conversation, $user): array {
            $name = match ($message->sender_type) {
                'customer' => $conversation->customer?->name ?? 'Customer',
                'user' => $message->senderName(), default => 'Bot',
            };

            return ['id' => $message->id, 'sender_type' => $message->sender_type, 'sender_name' => $name,
                'sender_avatar' => $message->sender_type === 'customer' ? $conversation->customer?->avatar : null,
                'is_mine' => $message->sender_type === 'system' || ($message->sender_type === 'user' && (int) $message->sender_id === (int) $user->id),
                'channel' => $message->channel, 'content' => $message->recalled_at ? null : $message->content,
                'message_type' => $message->message_type,
                'attachments' => $message->recalled_at ? [] : $this->attachments($message->attachments ?? []),
                'is_recalled' => (bool) $message->recalled_at, 'recalled_at' => $message->recalled_at, 'created_at' => $message->created_at];
        });
    }

    /** Tạo hồ sơ khách hàng của hội thoại gồm liên kết Facebook và các kênh liên hệ. */
    public function profile(?Conversation $conversation): array
    {
        if (! $conversation?->customer) {
            return ['facebook_profile_url' => '#',
                'contact' => ['name' => '', 'phone' => '', 'email' => '', 'channel' => ''], 'details' => [], 'notes' => [], 'tags' => [],
                'conversation_status' => ['key' => '', 'name' => '', 'color' => '#64748b'],
                'summary' => ['text' => 'Chưa có đủ nội dung để tóm tắt hội thoại.', 'facts' => []]];
        }
        $status = ConversationStatus::normalize($conversation->status);

        return ['facebook_profile_url' => $this->customers->facebookProfileUrl($conversation),
            'contact' => $this->customers->contact($conversation), 'details' => $this->customers->publicDetails($conversation),
            'notes' => collect($this->customers->notes($conversation))->map(fn (array $note): array => array_diff_key($note, ['id' => true]))->all(),
            'tags' => $conversation->tags->map(fn ($tag): array => ['name' => $tag->name, 'color' => $tag->color ?: '#2563eb'])->values()->all(),
            'conversation_status' => ['key' => $status, 'name' => ConversationStatus::label($status), 'color' => ConversationStatus::color($status)],
            'summary' => $this->summaries->summarize($conversation)];
    }

    /** Xác định kênh đang hoạt động của hội thoại được chọn. */
    public function activeChannel(?Conversation $conversation): string
    {
        return $conversation?->messages()->whereIn('channel', ['facebook', 'zalo'])->latest()->value('channel')
            ?? $conversation?->customer?->channels?->first()?->channel ?? 'facebook';
    }

    /** Chuẩn hóa attachment của tin nhắn thành URL, tên, MIME và loại hiển thị. */
    private function attachments(array $attachments): array
    {
        return collect($attachments)->reject(fn (array $item): bool => ($item['type'] ?? '') === 'quick_reply')
            ->map(function (array $item): array {
                $mime = (string) data_get($item, 'mime_type', '');
                $url = (string) (data_get($item, 'payload.image_data.url') ?: data_get($item, 'payload.video_data.url')
                    ?: data_get($item, 'payload.audio_data.url') ?: data_get($item, 'url') ?: data_get($item, 'payload.url') ?: data_get($item, 'payload.file_url'));
                $type = match (true) {
                    data_get($item, 'payload.image_data.url') !== null, str_starts_with($mime, 'image/') => 'image',
                    data_get($item, 'payload.video_data.url') !== null, str_starts_with($mime, 'video/') => 'video',
                    data_get($item, 'payload.audio_data.url') !== null, str_starts_with($mime, 'audio/') => 'audio',
                    filled($item['type'] ?? '') => $item['type'], default => 'file'
                };
                $name = (string) data_get($item, 'name', '');

                return array_merge($item, ['name' => $name ?: ($url ? basename((string) parse_url($url, PHP_URL_PATH)) : ucfirst($type)), 'url' => $url, 'type' => $type]);
            })->filter(fn (array $item): bool => $item['url'] !== '')->unique('url')->values()->all();
    }
}
