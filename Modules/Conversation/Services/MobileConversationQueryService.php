<?php

namespace Modules\Conversation\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\Conversation\Support\ConversationStatus;

class MobileConversationQueryService
{
    /** Nhận ConversationVisibilityService để kiểm tra phạm vi truy cập. */
    public function __construct(private readonly ConversationVisibilityService $visibility) {}

    /** Lọc và phân trang hội thoại mobile trong phạm vi truy cập của người dùng. */
    public function paginate(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->visibility->visibleFor($user)->with(['customer.channels', 'customer.tags', 'assignee', 'tags'])
            ->when(filled($filters['status'] ?? null), function (Builder $query) use ($filters): Builder {
                $status = ConversationStatus::fromFilter((string) $filters['status']);
                return $status ? $query->where('status', $status) : $query;
            })->when(($filters['assigned_to'] ?? 0) > 0, fn (Builder $query): Builder => $query->where('assigned_to', $filters['assigned_to']))
            ->when($filters['unread'] ?? false, fn (Builder $query): Builder => $query->where('unread_messages_count', '>', 0))
            ->when(filled($filters['q'] ?? null), function (Builder $query) use ($filters): void {
                $keyword = '%'.$filters['q'].'%';
                $query->where(fn (Builder $query) => $query->whereHas('customer', fn (Builder $customer): Builder => $customer
                    ->where('name', 'like', $keyword)->orWhere('phone', 'like', $keyword)->orWhere('email', 'like', $keyword))
                    ->orWhereHas('messages', fn (Builder $message): Builder => $message->where('content', 'like', $keyword)));
            })->latest('last_message_at')->paginate(min(max($perPage, 1), 100));
    }
}
