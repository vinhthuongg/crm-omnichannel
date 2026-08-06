<?php

namespace Modules\Dashboard\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Conversation\Services\ConversationIntentService;
use Modules\Conversation\Services\ConversationVisibilityService;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Customer\Models\Customer;

class DashboardTodayOverviewService
{
    /** Nhận dịch vụ giới hạn hội thoại theo quyền và thống kê nguồn/kênh cho tổng quan hôm nay. */
    public function __construct(
        private readonly ConversationVisibilityService $visibility,
        private readonly DashboardChannelService $channels,
    ) {
    }

    /** Tổng hợp card intent, chuỗi theo giờ và nguồn hội thoại của ngày hiện tại. */
    public function build(User $user): array
    {
        $todayStart = today()->startOfDay();
        $todayEnd = now();
        $yesterdayStart = today()->subDay()->startOfDay();
        $yesterdayEnd = today()->subDay()->endOfDay();
        $todayConversations = $this->between($this->visibility->visibleFor($user), $todayStart, $todayEnd)->count();
        $yesterdayConversations = $this->between($this->visibility->visibleFor($user), $yesterdayStart, $yesterdayEnd)->count();
        $activeConversations = $this->visibility->visibleFor($user)->where('status', ConversationStatus::IN_PROGRESS)->count();
        $newCustomers = Customer::query()->whereBetween('created_at', [$todayStart, $todayEnd])->count();
        $yesterdayCustomers = Customer::query()->whereBetween('created_at', [$yesterdayStart, $yesterdayEnd])->count();
        $phonesCollected = Customer::query()->whereNotNull('phone')->where('phone', '<>', '')->count();
        $potentialCustomers = Customer::query()->where('is_potential', true)
            ->whereHas('conversations', fn (Builder $query): Builder => $query
                ->whereIn('id', $this->visibility->visibleFor($user)->select('id')))->count();
        $yesterdayPhones = Customer::query()->whereNotNull('phone')->where('phone', '<>', '')
            ->where('created_at', '<=', $yesterdayEnd)->count();

        return [
            'header' => ['title' => 'Tổng quan hôm nay', 'subtitle' => 'Dữ liệu được cập nhật liên tục từ các kênh.'],
            'cards' => [
                ['label' => 'Tổng hội thoại', 'value' => number_format($todayConversations), 'change' => $this->signedChange($todayConversations, $yesterdayConversations), 'tone' => $todayConversations >= $yesterdayConversations ? 'good' : 'bad', 'icon' => 'forum', 'accent' => false],
                ['label' => 'Hội thoại đang xử lý', 'value' => number_format($activeConversations), 'change' => 'Cần phản hồi gấp', 'tone' => 'danger', 'icon' => 'mark_chat_unread', 'accent' => true],
                ['label' => 'Khách hàng mới', 'value' => number_format($newCustomers), 'change' => $this->signedChange($newCustomers, $yesterdayCustomers), 'tone' => $newCustomers >= $yesterdayCustomers ? 'good' : 'bad', 'icon' => 'person_add', 'accent' => false],
                ['label' => 'Khách hàng tiềm năng', 'value' => number_format($potentialCustomers), 'change' => 'Đã phân loại', 'tone' => 'potential', 'icon' => null, 'accent' => false, 'variant' => 'potential'],
                ['label' => 'SĐT đã thu thập', 'value' => number_format($phonesCollected), 'change' => $this->signedChange($phonesCollected, $yesterdayPhones), 'tone' => $phonesCollected >= $yesterdayPhones ? 'good' : 'bad', 'icon' => 'phone_in_talk', 'accent' => false],
            ],
            'intentCards' => $this->intentCards($user),
            'timeSeries' => $this->timeSeries($user),
            'sources' => $this->channels->sources($user),
        ];
    }

    /** Đếm hội thoại hôm nay theo các ý định báo giá, đặt lịch và yêu cầu gọi lại. */
    private function intentCards(User $user): array
    {
        return collect([
            ['label' => 'Khách yêu cầu báo giá', 'icon' => 'request_quote', 'tag' => ConversationIntentService::TAG_QUOTE],
            ['label' => 'Yêu cầu lái thử', 'icon' => 'directions_car', 'tag' => ConversationIntentService::TAG_TEST_DRIVE],
            ['label' => 'Quan tâm trả góp', 'icon' => 'account_balance', 'tag' => ConversationIntentService::TAG_INSTALLMENT],
            ['label' => 'Khách đặt lịch', 'icon' => 'event_available', 'tag' => ConversationIntentService::TAG_APPOINTMENT],
            ['label' => 'Đặt lịch bảo dưỡng', 'icon' => 'build', 'tag' => ConversationIntentService::TAG_MAINTENANCE],
        ])->map(fn (array $item): array => [
            'label' => $item['label'],
            'icon' => $item['icon'],
            'value' => $this->visibility->visibleFor($user)
                ->whereHas('customer.tags', fn (Builder $tags) => $tags->where('name', $item['tag']))->count(),
        ])->all();
    }

    /** Tạo chuỗi số hội thoại mới theo các mốc hai giờ từ 8 đến 20 giờ hôm nay. */
    private function timeSeries(User $user): array
    {
        return collect(range(8, 20, 2))->map(function (int $hour) use ($user): array {
            return [
                'hour' => sprintf('%02d:00', $hour),
                'value' => $this->between(
                    $this->visibility->visibleFor($user),
                    today()->setTime($hour, 0),
                    today()->setTime(min(23, $hour + 2), 0),
                )->count(),
            ];
        })->all();
    }

    /** Giới hạn truy vấn vào khoảng thời gian bắt đầu và kết thúc. */
    private function between(Builder $query, Carbon $start, Carbon $end): Builder
    {
        return $query->whereBetween(DB::raw('COALESCE(last_message_at, created_at)'), [$start, $end]);
    }

    /** Tính phần trăm tăng giảm so với kỳ trước và thêm dấu cho giá trị dương. */
    private function signedChange(int $current, int $previous): string
    {
        $change = $previous > 0 ? (int) round((($current - $previous) / $previous) * 100) : ($current > 0 ? 100 : 0);

        return ($change >= 0 ? '+' : '').$change.'%';
    }
}
