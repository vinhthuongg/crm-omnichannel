<?php

namespace Modules\Dashboard\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\ConversationIntentService;
use Modules\Conversation\Services\ConversationVisibilityService;

class DashboardPerformanceMetricsService
{
    /** Nhận ConversationVisibilityService để kiểm tra phạm vi truy cập. */
    public function __construct(private readonly ConversationVisibilityService $visibility) {}

    /** Nhận diện và tổng hợp các ý định nghiệp vụ trong hội thoại. */
    public function intents(User $user, ?Carbon $start = null, ?Carbon $end = null): array
    {
        $map = ['quote_requests' => ConversationIntentService::TAG_QUOTE, 'test_drive_requests' => ConversationIntentService::TAG_TEST_DRIVE,
            'installment_interests' => ConversationIntentService::TAG_INSTALLMENT, 'appointment_bookings' => ConversationIntentService::TAG_APPOINTMENT,
            'maintenance_bookings' => ConversationIntentService::TAG_MAINTENANCE];
        return collect($map)->map(fn (string $tag): int => $this->countIntent($user, $tag, $start, $end))->all();
    }

    /** Tính thời gian phản hồi trung bình của nhân viên theo phút. */
    public function averageResponseMinutes(User $user, ?Carbon $start = null, ?Carbon $end = null): float
    {
        $samples = $this->visibility->visibleFor($user)->whereNotNull('first_response_at')
            ->when($start && $end, fn (Builder $query): Builder => $query->whereBetween('first_response_at', [$start, $end]))
            ->latest('first_response_at')->limit(100)->get(['created_at', 'first_response_at'])
            ->map(fn (Conversation $conversation): int => max(1, $conversation->created_at->diffInMinutes($conversation->first_response_at)));
        return $samples->isEmpty() ? 0.0 : round($samples->avg(), 1);
    }

    /** Đếm hội thoại có activity intent tương ứng trong khoảng ngày và phạm vi quyền. */
    private function countIntent(User $user, string $tag, ?Carbon $start, ?Carbon $end): int
    {
        return $this->visibility->visibleFor($user)->whereHas('customer.tags', fn (Builder $query): Builder => $query->where('name', $tag))
            ->when($start && $end, fn (Builder $query): Builder => $query->whereBetween(DB::raw('COALESCE(last_message_at, created_at)'), [$start, $end]))->count();
    }
}
