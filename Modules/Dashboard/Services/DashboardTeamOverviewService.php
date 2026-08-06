<?php

namespace Modules\Dashboard\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Message\Models\Message;

class DashboardTeamOverviewService
{
    /** Tổng hợp tải hội thoại theo nhân viên, hiệu suất và phân bố theo ca của đội ngũ. */
    public function collect(Builder $conversations, Carbon $startsAt, Carbon $endsAt, bool $filtered): array
    {
        return [
            'by_employee' => User::query()->withCount([
                'assignedConversations as open_conversations_count' => fn (Builder $query) => $query->where('status', ConversationStatus::IN_PROGRESS),
            ])->get(['id', 'name', 'email']),
            'top_fast_responders' => Message::query()
                ->select('sender_id', DB::raw('COUNT(*) as replies_count'))
                ->where('sender_type', 'user')
                ->when(
                    $filtered,
                    fn (Builder $query): Builder => $query->whereBetween('created_at', [$startsAt, $endsAt]),
                    fn (Builder $query): Builder => $query->whereDate('created_at', today()),
                )
                ->whereIn('conversation_id', (clone $conversations)->select('id'))
                ->groupBy('sender_id')->orderByDesc('replies_count')->limit(10)->get(),
        ];
    }
}
