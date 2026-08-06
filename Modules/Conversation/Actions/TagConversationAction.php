<?php

namespace Modules\Conversation\Actions;

use App\Models\User;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\ConversationService;

class TagConversationAction
{
    /** Nhận ConversationService để phân công, đổi trạng thái và đồng bộ nhãn hội thoại. */
    public function __construct(private readonly ConversationService $service)
    {
    }

    /** Đồng bộ nhãn đầu vào lên hội thoại và ghi nhận người thực hiện. */
    public function execute(Conversation $conversation, array $tags, User $actor): Conversation
    {
        return $this->service->syncTags($conversation, $tags, $actor);
    }
}
