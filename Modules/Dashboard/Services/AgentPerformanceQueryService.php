<?php

namespace Modules\Dashboard\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Message\Models\Message;

class AgentPerformanceQueryService
{
    /** Truy vấn nhân viên hoạt động mà người yêu cầu có quyền xem báo cáo. */
    public function agentsFor(User $requester)
    {
        return User::query()->where('is_active', true)
            ->when(! $requester->can('report.view') && ! $requester->can('user.manage'), fn (Builder $query): Builder => $query->whereKey($requester->id))
            ->where(fn (Builder $query) => $query->role(['Admin', 'CSKH', 'User'])->orWhereHas('assignedConversations'))
            ->orderBy('name')->get();
    }

    /** Đếm hội thoại và tính thời gian phản hồi của một nhân viên trong kỳ, ca được chọn. */
    public function metrics(User $agent, Carbon $start, Carbon $end, ?int $shiftId): array
    {
        $base = $this->conversations($agent, $start, $end, $shiftId);
        $total = (clone $base)->count();
        $average = (clone $base)->whereNotNull('first_response_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, created_at, first_response_at)) as average_seconds')->value('average_seconds');
        return ['total' => $total, 'responded' => (clone $base)->whereNotNull('first_response_at')->count(),
            'average_seconds' => $average === null ? null : max(0, (int) round((float) $average)),
            'phones' => (clone $base)->whereHas('customer', fn (Builder $query): Builder => $query->whereNotNull('phone')->where('phone', '!=', ''))->count(),
            'tagged' => (clone $base)->whereHas('customer.tags')->count(),
            'noted' => (clone $base)->whereHas('customer.notes', fn (Builder $query): Builder => $query->where('user_id', $agent->id)->whereBetween('created_at', [$start, $end]))->count(),
            'active' => (clone $base)->whereIn('status', ConversationStatus::ACTIVE)->count(),
            'waiting' => (clone $base)->where('status', ConversationStatus::WAITING)->count(),
            'finished' => (clone $base)->whereIn('status', [ConversationStatus::RESOLVED, ConversationStatus::CLOSED])->count(),
            'sent' => Message::query()->where('sender_type', 'user')->where('sender_id', $agent->id)->whereBetween('created_at', [$start, $end])->count()];
    }

    /** Truy vấn danh sách hội thoại người dùng được phép xem. */
    private function conversations(User $agent, Carbon $start, Carbon $end, ?int $shiftId): Builder
    {
        return Conversation::query()->where('assigned_to', $agent->id)->whereBetween('created_at', [$start, $end])
            ->when($shiftId, fn (Builder $query): Builder => $query->where(fn (Builder $query) => $query
                ->where('owner_shift_id', $shiftId)->orWhere('queue_shift_id', $shiftId)->orWhere('work_shift_id', $shiftId)));
    }
}
