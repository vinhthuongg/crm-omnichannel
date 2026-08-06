<?php

namespace Modules\Dashboard\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Conversation\Services\ConversationVisibilityService;
use Modules\Customer\Models\Customer;
use Modules\Customer\Models\CustomerTag;

class DashboardCustomerService
{
    /** Nhận ConversationVisibilityService để kiểm tra phạm vi truy cập. */
    public function __construct(private readonly ConversationVisibilityService $visibility)
    {
    }

    /** Thống kê khách hàng mới/cũ theo tháng và tạo dữ liệu donut phân bổ. */
    public function allocation(User $user): Collection
    {
        $tags = CustomerTag::query()->orderBy('name')->get()
            ->map(function (CustomerTag $tag) use ($user): CustomerTag {
                $tag->conversations_count = $this->visibility->visibleFor($user)
                    ->whereHas('customer.tags', fn (Builder $query): Builder => $query->whereKey($tag->id))
                    ->count();

                return $tag;
            })->sortByDesc('conversations_count')->take(4)->values();
        $max = max(1, (int) $tags->max('conversations_count'));

        return $tags->map(fn (CustomerTag $tag): array => [
            'name' => $tag->name,
            'value' => (int) $tag->conversations_count,
            'percent' => ((int) $tag->conversations_count / $max) * 100,
        ]);
    }

    /** Lấy các khách hàng có nhiều hội thoại được phép xem nhất. */
    public function top(User $user): Collection
    {
        return Customer::query()
            ->withCount(['conversations' => function (Builder $query) use ($user): void {
                $query->whereIn('conversations.id', $this->visibility->visibleFor($user)->select('id'));
            }])
            ->orderByDesc('conversations_count')->limit(5)->get();
    }

    /** Tạo thẻ nổi bật cho khách hàng có nhiều hội thoại nhất trong phạm vi truy cập. */
    public function highlighted(User $user): array
    {
        $customer = Customer::query()
            ->withCount(['conversations' => function (Builder $query) use ($user): void {
                $query->whereIn('conversations.id', $this->visibility->visibleFor($user)->select('id'));
            }])
            ->orderByDesc('conversations_count')->first();

        if (! $customer) {
            return [
                'name' => 'Chưa có khách hàng', 'subtitle' => 'CRM', 'initial' => 'N',
                'activities' => 0, 'bars' => collect(),
            ];
        }

        $conversationQuery = $this->visibility->visibleFor($user)->where('customer_id', $customer->id);
        $activities = (clone $conversationQuery)->count();
        $daily = collect(range(9, 0))->map(function (int $offset) use ($conversationQuery): int {
            return (clone $conversationQuery)
                ->whereDate(DB::raw('COALESCE(last_message_at, created_at)'), today()->subDays($offset))
                ->count();
        });
        $max = max(1, (int) $daily->max());

        return [
            'name' => $customer->name,
            'subtitle' => $customer->email ? 'Tài khoản khách hàng' : 'Liên hệ CRM',
            'initial' => strtoupper(substr($customer->name, 0, 1)),
            'activities' => $activities,
            'bars' => $daily->map(fn (int $value): array => [
                'value' => $value,
                'height' => 30 + (($value / $max) * 70),
            ]),
        ];
    }
}
