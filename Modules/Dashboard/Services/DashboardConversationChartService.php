<?php

namespace Modules\Dashboard\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Modules\Conversation\Services\ConversationVisibilityService;
use Modules\Conversation\Support\ConversationStatus;

class DashboardConversationChartService
{
    /** Nhận ConversationVisibilityService để kiểm tra phạm vi truy cập; DashboardPeriodFactory để tạo dữ liệu theo cấu hình. */
    public function __construct(
        private readonly ConversationVisibilityService $visibility,
        private readonly DashboardPeriodFactory $periods,
    ) {
    }

    /** Tạo dữ liệu biểu đồ trạng thái, xu hướng, kênh và số hội thoại theo ngày. */
    public function build(User $user, string $period): array
    {
        return [
            'closure' => $this->closureData($user),
            'messages' => $this->dailyConversations($user, 14),
            'conversation_status' => $this->statusByPeriod($user, $period),
            'trend' => $this->yearTrend($user),
            'channels' => $this->channelConversations($user),
        ];
    }

    /** Tạo truy vấn hội thoại giới hạn theo quyền của người dùng dashboard. */
    private function visible(User $user): Builder
    {
        return $this->visibility->visibleFor($user);
    }

    /** Tổng hợp số hội thoại theo các trạng thái mở, chờ và đóng. */
    private function closureData(User $user): array
    {
        return collect([
            'open' => ConversationStatus::IN_PROGRESS,
            'pending' => ConversationStatus::WAITING,
            'closed' => ConversationStatus::CLOSED,
        ])->map(fn (string $status, string $label): array => [
            'label' => ucfirst($label),
            'value' => $this->visible($user)->where('status', $status)->count(),
        ])->values()->all();
    }

    /** Đếm hội thoại mới theo từng ngày để vẽ biểu đồ. */
    private function dailyConversations(User $user, int $days): array
    {
        return collect(range($days - 1, 0))->map(function (int $offset) use ($user): array {
            $day = today()->subDays($offset);

            return ['date' => $day->toDateString(), 'value' => $this->visible($user)->whereDate('created_at', $day)->count()];
        })->all();
    }

    /** Tổng hợp trạng thái hội thoại theo các bucket của kỳ báo cáo. */
    private function statusByPeriod(User $user, string $period): array
    {
        return $this->periods->buckets($period)->map(function (array $bucket) use ($user): array {
            $query = fn (): Builder => $this->visible($user)->whereBetween('created_at', [$bucket['start'], $bucket['end']]);

            return [
                'period' => $bucket['label'],
                'open' => $query()->where('status', ConversationStatus::IN_PROGRESS)->count(),
                'pending' => $query()->where('status', ConversationStatus::WAITING)->count(),
                'closed' => $query()->where('status', ConversationStatus::CLOSED)->count(),
            ];
        })->all();
    }

    /** Tổng hợp số lượng hội thoại theo từng năm để biểu diễn xu hướng. */
    private function yearTrend(User $user): array
    {
        return $this->periods->years()->map(fn (int $year): array => [
            'year' => (string) $year,
            'value' => $this->visible($user)->whereYear('created_at', $year)->count(),
        ])->all();
    }

    /** Đếm hội thoại theo từng kênh liên lạc đang hỗ trợ. */
    private function channelConversations(User $user): array
    {
        return collect(['facebook', 'zalo'])->map(function (string $channel) use ($user): array {
            $count = $this->visible($user)->where(function (Builder $query) use ($channel): void {
                $query->whereHas('customer.channels', fn (Builder $channels) => $channels->where('channel', $channel))
                    ->orWhereHas('messages', fn (Builder $messages) => $messages->where('channel', $channel));
            })->count();

            return ['label' => ucfirst($channel), 'value' => $count];
        })->filter(fn (array $item): bool => $item['value'] > 0)->values()->all();
    }
}
