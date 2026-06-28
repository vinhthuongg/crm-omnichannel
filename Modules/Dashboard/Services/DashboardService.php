<?php

namespace Modules\Dashboard\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Message\Models\Message;

class DashboardService
{
    public function overview(): array
    {
        return [
            'conversations_today' => Conversation::query()->whereDate('created_at', today())->count(),
            'new_messages_today' => Message::query()->whereDate('created_at', today())->count(),
            'unhandled' => Conversation::query()->whereIn('status', ConversationStatus::ACTIVE)->whereNull('assigned_to')->count(),
            'by_employee' => User::query()->withCount(['assignedConversations as open_conversations_count' => fn ($query) => $query->where('status', ConversationStatus::IN_PROGRESS)])->get(['id', 'name', 'email']),
            'top_fast_responders' => Message::query()
                ->select('sender_id', DB::raw('COUNT(*) as replies_count'))
                ->where('sender_type', 'user')
                ->whereDate('created_at', today())
                ->groupBy('sender_id')
                ->orderByDesc('replies_count')
                ->limit(10)
                ->get(),
        ];
    }
}
