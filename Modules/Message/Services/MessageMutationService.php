<?php

namespace Modules\Message\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Conversation\Models\Conversation;
use Modules\Message\Events\MessageDeletedEvent;
use Modules\Message\Events\MessageUpdatedEvent;
use Modules\Message\Models\Message;

class MessageMutationService
{
    /** Hủy job gửi nếu tin còn queued hoặc đánh dấu tin đã thu hồi trong CRM. */
    public function recall(Message $message, User $user): array
    {
        $cancelled = in_array((string) $message->outbound_status, ['queued', 'sending'], true) && blank($message->external_message_id) && blank($message->sent_at);
        $message->forceFill(['content' => null, 'attachments' => null, 'message_type' => 'recalled', 'recalled_at' => now(),
            'recalled_by_user_id' => $user->id, 'outbound_status' => $cancelled ? 'cancelled' : $message->outbound_status,
            'outbound_error' => $cancelled ? 'Tin nhan da duoc thu hoi truoc khi gui sang Facebook.' : $message->outbound_error])->save();
        $message->loadMissing(['sender', 'conversation.customer']);
        event(new MessageUpdatedEvent($message));
        return ['message' => $message, 'cancelled' => $cancelled];
    }

    /** Xóa mềm một tin thuộc hội thoại, ghi người xóa và phát sự kiện realtime. */
    public function delete(Conversation $conversation, Message $message, User $user): int
    {
        $id = (int) $message->id;
        $message->forceFill(['deleted_by_user_id' => $user->id])->save();
        $message->delete();
        $conversation->forceFill(['last_message_at' => $conversation->messages()->latest()->value('created_at')])->save();
        event(new MessageDeletedEvent((int) $conversation->id, [$id]));
        return $id;
    }

    /** Xóa toàn bộ tin trong hội thoại và phát sự kiện yêu cầu client dọn timeline. */
    public function clear(Conversation $conversation, User $user): void
    {
        if (! $conversation->messages()->exists()) return;
        DB::transaction(function () use ($conversation, $user): void {
            $conversation->messages()->update(['deleted_by_user_id' => $user->id]);
            $conversation->messages()->delete();
            $conversation->forceFill(['last_message_at' => null, 'last_read_at' => now(), 'unread_messages_count' => 0])->save();
        });
        event(new MessageDeletedEvent((int) $conversation->id, [], true));
    }

    /** Xóa hội thoại cùng tin liên quan và phát sự kiện để loại hội thoại khỏi inbox. */
    public function deleteConversation(Conversation $conversation): int
    {
        $id = (int) $conversation->id;
        DB::transaction(function () use ($conversation): void { $conversation->messages()->withTrashed()->forceDelete(); $conversation->tags()->detach(); $conversation->delete(); });
        event(new MessageDeletedEvent($id, [], true, true));
        return $id;
    }
}
