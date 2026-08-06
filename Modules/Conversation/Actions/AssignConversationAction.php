<?php

namespace Modules\Conversation\Actions;

use App\Models\User;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\ConversationService;

class AssignConversationAction
{
    /** Nhận ConversationService để phân công, đổi trạng thái và đồng bộ nhãn hội thoại. */
    public function __construct(private readonly ConversationService $service)
    {
    }

    /** Giao hội thoại cho user ID được chọn và ghi nhận người thực hiện. */
    public function execute(Conversation $conversation, int $userId, User $actor): Conversation
    {
        return $this->service->assign($conversation, $userId, $actor);
    }

    /** Chuyển hội thoại sang user ID khác sau khi kiểm tra khả năng nhận việc. */
    public function transfer(Conversation $conversation, int $userId, User $actor): Conversation
    {
        return $this->service->transfer($conversation, $userId, $actor);
    }

    /** Gỡ người phụ trách để hội thoại trở lại hàng chờ phân công. */
    public function release(Conversation $conversation, User $actor): Conversation
    {
        return $this->service->release($conversation, $actor);
    }
}
