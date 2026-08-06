<?php

namespace Modules\Conversation\Actions;

use App\Models\User;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\ConversationService;

class CloseConversationAction
{
    /** Nhận ConversationService để phân công, đổi trạng thái và đồng bộ nhãn hội thoại. */
    public function __construct(private readonly ConversationService $service)
    {
    }

    /** Đóng hội thoại và ghi nhận người cùng thời điểm đóng. */
    public function execute(Conversation $conversation, User $actor): Conversation
    {
        return $this->service->close($conversation, $actor);
    }

    /** Đánh dấu hội thoại đã giải quyết và lưu resolved_at. */
    public function resolve(Conversation $conversation, User $actor): Conversation
    {
        return $this->service->resolve($conversation, $actor);
    }

    /** Mở lại hội thoại đã đóng để tiếp tục xử lý. */
    public function reopen(Conversation $conversation, User $actor): Conversation
    {
        return $this->service->reopen($conversation, $actor);
    }
}
