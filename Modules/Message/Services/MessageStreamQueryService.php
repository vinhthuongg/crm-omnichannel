<?php

namespace Modules\Message\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\ConversationVisibilityService;
use Modules\Message\Models\Message;

class MessageStreamQueryService
{
    /** Nhận ConversationVisibilityService để kiểm tra phạm vi truy cập. */
    public function __construct(private readonly ConversationVisibilityService $visibility) {}

    /** Truy vấn hoặc định dạng dữ liệu hội thoại cho phạm vi inbox. */
    public function inbox(User $user, int $afterId, array $relations): Collection
    {
        return Message::query()->with($relations)->where('id', '>', $afterId)
            ->whereIn('conversation_id', $this->visibility->visibleFor($user)->select('id'))
            ->oldest('id')->limit(100)->get();
    }

    /** Truy vấn hoặc định dạng dữ liệu hội thoại cho phạm vi conversation. */
    public function conversation(Conversation $conversation, int $afterId, array $relations): Collection
    {
        return $conversation->messages()->with($relations)->where('id', '>', $afterId)->oldest('id')->limit(100)->get();
    }
}
