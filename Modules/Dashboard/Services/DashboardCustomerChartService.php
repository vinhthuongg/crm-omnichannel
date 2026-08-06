<?php

namespace Modules\Dashboard\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Modules\Conversation\Services\ConversationVisibilityService;
use Modules\Customer\Models\Customer;
use Modules\Customer\Models\CustomerTag;

class DashboardCustomerChartService
{
    /** Nhận ConversationVisibilityService để kiểm tra phạm vi truy cập. */
    public function __construct(private readonly ConversationVisibilityService $visibility)
    {
    }

    /** Tạo biểu đồ top nhãn và chuỗi hoạt động của khách hàng nổi bật. */
    public function build(User $user): array
    {
        return ['tags' => $this->tagAllocation($user), 'customer_activity' => $this->topCustomerActivity($user)];
    }

    /** Thống kê các nhãn khách hàng được sử dụng nhiều nhất. */
    private function tagAllocation(User $user): array
    {
        return CustomerTag::query()->orderBy('name')->get()->map(function (CustomerTag $tag) use ($user): array {
            $count = $this->visibility->visibleFor($user)
                ->whereHas('customer.tags', fn (Builder $query): Builder => $query->whereKey($tag->id))
                ->count();

            return ['tag' => $tag->name, 'value' => $count];
        })->sortByDesc('value')->take(5)->values()->all();
    }

    /** Tạo chuỗi hoạt động theo ngày của khách hàng có nhiều hội thoại nhất. */
    private function topCustomerActivity(User $user): array
    {
        $visibleIds = $this->visibility->visibleFor($user)->select('id');
        $customer = Customer::query()->withCount(['conversations' => fn (Builder $query) => $query->whereIn('conversations.id', clone $visibleIds)])
            ->orderByDesc('conversations_count')->first();

        if (! $customer) {
            return [];
        }

        return collect(range(9, 0))->map(function (int $offset) use ($customer, $user): array {
            $day = today()->subDays($offset);

            return [
                'date' => $day->format('j M'),
                'value' => $this->visibility->visibleFor($user)->where('customer_id', $customer->id)->whereDate('created_at', $day)->count(),
            ];
        })->all();
    }
}
