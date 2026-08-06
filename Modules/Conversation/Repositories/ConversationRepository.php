<?php

namespace Modules\Conversation\Repositories;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\ConversationVisibilityService;
use Modules\Conversation\Support\ConversationStatus;

class ConversationRepository
{
    /** Nhận ConversationVisibilityService để kiểm tra phạm vi truy cập. */
    public function __construct(private readonly ConversationVisibilityService $visibility)
    {
    }

    /** Lọc và phân trang hội thoại người dùng được xem theo trạng thái, kênh, nhãn và tìm kiếm. */
    public function paginateFor(User $user, array $filters = []): LengthAwarePaginator
    {
        $status = ConversationStatus::fromFilter($filters['status'] ?? null);

        return $this->visibility->visibleFor($user)
            ->with(['customer.channels', 'assignee', 'tags'])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->latest('last_message_at')
            ->paginate((int) ($filters['per_page'] ?? 20));
    }

}
