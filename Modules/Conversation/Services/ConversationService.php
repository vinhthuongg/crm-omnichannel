<?php

namespace Modules\Conversation\Services;

use App\Models\User;
use Modules\Conversation\Models\Conversation;
use Modules\Message\Models\Message;

class ConversationService
{
    /** Kết hợp dịch vụ phân công, vòng đời, đồng bộ nhãn và trạng thái tin nhắn thành API hội thoại thống nhất. */
    public function __construct(private readonly ConversationAssignmentService $assignments,
        private readonly ConversationLifecycleService $lifecycle, private readonly ConversationTagSyncService $tags,
        private readonly ConversationMessageStateService $messageState) {}

    /** Giao hội thoại cho người dùng được chọn và ghi nhận người thực hiện thao tác. */
    public function assign(Conversation $conversation, int $userId, User $actor): Conversation
    {
        return $this->assignments->assign($conversation, $userId, $actor);
    }

    /** Cho phép nhân viên hiện tại nhận xử lý một hội thoại đang chờ. */
    public function claim(Conversation $conversation, User $actor): Conversation
    {
        return $this->assignments->claim($conversation, $actor);
    }

    /** Chuyển hội thoại từ người xử lý hiện tại sang một nhân viên khác. */
    public function transfer(Conversation $conversation, int $userId, User $actor): Conversation
    {
        return $this->assignments->transfer($conversation, $userId, $actor);
    }

    /** Gỡ người phụ trách để đưa hội thoại trở lại hàng chờ phân công. */
    public function release(Conversation $conversation, User $actor): Conversation
    {
        return $this->assignments->release($conversation, $actor);
    }

    /** Đánh dấu hội thoại đã được giải quyết và lưu người hoàn tất. */
    public function resolve(Conversation $conversation, User $actor): Conversation
    {
        return $this->lifecycle->resolve($conversation, $actor);
    }

    /** Đóng hội thoại và ghi nhận thời điểm kết thúc xử lý. */
    public function close(Conversation $conversation, User $actor): Conversation
    {
        return $this->lifecycle->close($conversation, $actor);
    }

    /** Mở lại hội thoại đã đóng để tiếp tục tiếp nhận và xử lý tin nhắn. */
    public function reopen(Conversation $conversation, User $actor): Conversation
    {
        return $this->lifecycle->reopen($conversation, $actor);
    }

    /** Đồng bộ tập nhãn của hội thoại và ghi activity cho thay đổi nhãn. */
    public function syncTags(Conversation $conversation, array $tags, User $actor): Conversation
    {
        return $this->tags->sync($conversation, $tags, $actor);
    }

    /** Ghi nhận tin nhắn gửi ra và cập nhật trạng thái hội thoại. */
    public function recordOutboundMessage(Conversation $conversation, Message $message, bool $isWhisper = false): void
    {
        $this->messageState->outbound($conversation, $message, $isWhisper);
    }

    /** Ghi nhận tin nhắn echo Facebook vào hội thoại tương ứng. */
    public function recordFacebookEcho(Conversation $conversation, Message $message): void
    {
        $this->messageState->facebookEcho($conversation, $message);
    }
}
