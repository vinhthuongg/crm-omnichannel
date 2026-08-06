<?php

namespace App\Services;

use App\Models\User;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Models\Tag;
use Modules\Conversation\Services\ConversationTagCatalogService;
use Modules\Conversation\Services\WorkShiftService;
use Modules\Conversation\Support\ConversationStatus;

class MessengerConversationPresenter
{
    /** Nhận ConversationInsightSummaryService để tóm tắt nhu cầu và thông tin khách hàng; ConversationTagCatalogService để cung cấp danh mục nhãn mặc định; MessengerCustomerPresenter để định dạng dữ liệu đầu ra; WorkShiftService để xác định ca trực và thành viên đang hoạt động. */
    public function __construct(
        private readonly ConversationInsightSummaryService $insights,
        private readonly ConversationTagCatalogService $tags,
        private readonly MessengerCustomerPresenter $customers,
        private readonly WorkShiftService $shifts,
    ) {
    }

    /** Tạo payload chi tiết hội thoại gồm khách hàng, tin nhắn, nhãn và quyền thao tác. */
    public function detail(Conversation $conversation, $messages, bool $hasOlderMessages, string $activeChannel, User $user): array
    {
        $conversation->refresh();
        $conversation->loadMissing(['customer.channels', 'customer.notes.user', 'customer.tags', 'assignee', 'tags']);

        return ['data' => [
            'id' => (int) $conversation->id,
            'customer_id' => (int) $conversation->customer_id,
            'customer_name' => $conversation->customer?->name ?? 'Customer',
            'customer_avatar' => $conversation->customer?->avatar,
            'customer_phone' => $conversation->customer?->phone,
            'customer_email' => $conversation->customer?->email,
            'facebook_profile_url' => $this->customers->facebookProfileUrl($conversation),
            'customer_contact' => $this->customers->contact($conversation),
            'customer_public_details' => $this->customers->publicDetails($conversation),
            'customer_update_url' => route('crm.conversations.customer.update', $conversation),
            'customer_notes_url' => route('crm.conversations.customer-notes.store', $conversation),
            'customer_tags_url' => route('crm.conversations.customer-tags.store', $conversation),
            'customer_notes' => $this->customers->notes($conversation),
            'customer_tags' => $this->customers->tags($conversation),
            'conversation_status' => $this->status($conversation),
            'conversation_summary' => $this->insights->summarize($conversation),
            'all_customer_tags' => $this->tags->payloads(),
            'facebook_page_id' => $conversation->facebook_page_id,
            'assignee_name' => $conversation->assignee?->name,
            'assigned_to' => $conversation->assigned_to,
            'unread_messages_count' => (int) $conversation->unread_messages_count,
            'is_unread' => (int) $conversation->unread_messages_count > 0,
            'can_claim' => ! $conversation->assigned_to && ! $user->can('conversation.view_all')
                && $this->shifts->userBelongsToShift($user, $conversation->queue_shift_id),
            'can_reply' => $user->can('conversation.view_all') || (int) $conversation->assigned_to === (int) $user->id,
            'can_assign' => $user->can('conversation.assign') || $user->can('conversation.transfer'),
            'active_channel' => $activeChannel,
            'created_at' => $conversation->created_at?->toISOString(),
            'messages_url' => route('crm.conversations.messages.index', $conversation),
            'read_url' => route('crm.conversations.read', $conversation),
            'stream_url' => route('crm.conversations.messages.stream', $conversation),
            'reply_suggestions_url' => route('crm.conversations.reply-suggestions', $conversation),
            'send_url' => route('crm.conversations.messages.store', $conversation),
            'delete_url' => route('crm.conversations.destroy', $conversation),
            'clear_messages_url' => route('crm.conversations.messages.clear', $conversation),
            'attachments_url' => route('crm.conversations.attachments.store', $conversation),
            'claim_url' => route('crm.conversations.claim', $conversation),
            'assign_url' => route('crm.conversations.assign', $conversation),
            'tags_url' => route('crm.conversations.tags.store', $conversation),
            'broadcast_channel' => 'private-crm.conversation.'.$conversation->id,
            'tags' => $conversation->tags->map(fn (Tag $tag): array => [
                'id' => (int) $tag->id, 'name' => $tag->name, 'color' => $tag->color,
            ])->values()->all(),
            'messages' => array_values(is_array($messages) ? $messages : $messages->all()),
            'meta' => [
                'has_older_messages' => $hasOlderMessages,
                'oldest_message_id' => (int) data_get($messages, '0.id', 0),
                'last_message_id' => (int) data_get($messages, (string) max(count($messages) - 1, 0).'.id', 0),
            ],
        ]];
    }

    /** Định dạng trạng thái thành dữ liệu hiển thị. */
    private function status(Conversation $conversation): array
    {
        $status = ConversationStatus::normalize($conversation->status);
        return ['key' => $status, 'name' => ConversationStatus::label($status), 'color' => ConversationStatus::color($status)];
    }
}
