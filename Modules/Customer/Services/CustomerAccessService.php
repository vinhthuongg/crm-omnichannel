<?php

namespace Modules\Customer\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\Conversation\Services\ConversationVisibilityService;
use Modules\Customer\Models\Customer;

class CustomerAccessService
{
    /** Nhận ConversationVisibilityService để kiểm tra phạm vi truy cập. */
    public function __construct(private readonly ConversationVisibilityService $visibility) {}

    /** Lọc và phân trang khách hàng thuộc các hội thoại người dùng được phép xem. */
    public function paginate(User $user, string $search, ?bool $potential, int $perPage): LengthAwarePaginator
    {
        return Customer::query()->with(['channels', 'tags', 'potentialMarkedBy'])
            ->whereIn('id', $this->visibility->visibleFor($user)->select('customer_id'))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $keyword = "%{$search}%";
                $query->where(fn (Builder $query) => $query->where('name', 'like', $keyword)
                    ->orWhere('phone', 'like', $keyword)->orWhere('email', 'like', $keyword)
                    ->orWhereHas('channels', fn (Builder $channel): Builder => $channel->where('external_id', 'like', $keyword)));
            })->when($potential !== null, fn (Builder $query): Builder => $query->where('is_potential', $potential))
            ->latest('updated_at')->paginate(min(max($perPage, 1), 100));
    }

    /** Kiểm tra khách hàng có thuộc phạm vi người dùng được truy cập hay không. */
    public function accessible(User $user, Customer $customer): Customer
    {
        if (! $this->visibility->visibleFor($user)->where('customer_id', $customer->id)->exists()) {
            throw new AuthorizationException;
        }
        return $customer->load(['channels', 'tags', 'potentialMarkedBy']);
    }
}
