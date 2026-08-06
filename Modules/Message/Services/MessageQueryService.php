<?php

namespace Modules\Message\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\ConversationVisibilityService;

class MessageQueryService
{
    /** Nhận ConversationVisibilityService để kiểm tra phạm vi truy cập. */
    public function __construct(private readonly ConversationVisibilityService $visibility) {}

    /** Kiểm tra quyền xem hội thoại rồi phân trang các tin nhắn mới nhất. */
    public function paginate(User $user, Conversation $conversation, int $perPage): LengthAwarePaginator
    {
        if (! $this->visibility->canView($user, $conversation)) throw new AuthorizationException;
        return $conversation->messages()->with('sender')->latest()->paginate(min(max($perPage, 1), 100));
    }
}
