<?php

namespace Modules\Dashboard\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\ConversationVisibilityService;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Message\Models\Message;

class DashboardService
{
    public function __construct(private readonly ConversationVisibilityService $visibility)
    {
    }

    public function overview(User $user): array
    {
        $visibleConversations = $this->visibility->visibleFor($user);

        return [
            'conversations_today' => $this->whereActivityDate(clone $visibleConversations, today())->count(),
            'new_messages_today' => $this->whereActivityDate(clone $visibleConversations, today())->count(),
            'unhandled' => (clone $visibleConversations)->whereIn('status', ConversationStatus::ACTIVE)->whereNull('assigned_to')->count(),
            'by_employee' => User::query()->withCount(['assignedConversations as open_conversations_count' => fn ($query) => $query->where('status', ConversationStatus::IN_PROGRESS)])->get(['id', 'name', 'email']),
            'top_fast_responders' => Message::query()
                ->select('sender_id', DB::raw('COUNT(*) as replies_count'))
                ->where('sender_type', 'user')
                ->whereDate('created_at', today())
                ->whereIn('conversation_id', $this->visibility->visibleFor($user)->select('id'))
                ->groupBy('sender_id')
                ->orderByDesc('replies_count')
                ->limit(10)
                ->get(),
        ];
    }

    private function whereActivityDate(Builder $query, \DateTimeInterface $day): Builder
    {
        return $query->whereDate(DB::raw('COALESCE(last_message_at, created_at)'), $day);
    }
}
