<?php

namespace Modules\Conversation\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Modules\Conversation\Models\Conversation;

class ConversationVisibilityService
{
    /** Tạo truy vấn hội thoại người dùng được xem dựa trên quyền, phân công, lịch sử xử lý và ca trực. */
    public function visibleFor(User $user): Builder
    {
        $query = Conversation::query();

        if ($user->can('conversation.view_all')) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($user): void {
            $query->where('assigned_to', $user->id);
            $query->orWhereHas('handledUsers', fn (Builder $handlers) => $handlers->whereKey($user->id));
            $query->orWhere(function (Builder $query) use ($user): void {
                $query->whereNull('assigned_to')
                    ->whereHas('queueShift.agents', fn (Builder $agents) => $agents->whereKey($user->id));
            });
        });
    }

    /** Kiểm tra một hội thoại có xuất hiện trong phạm vi truy vấn của người dùng hay không. */
    public function canView(User $user, Conversation $conversation): bool
    {
        if ($user->can('conversation.view_all')) {
            return true;
        }

        if ((int) $conversation->assigned_to === (int) $user->id) {
            return true;
        }

        if ($conversation->handledUsers()->whereKey($user->id)->exists()) {
            return true;
        }

        return ! $conversation->assigned_to
            && $conversation->queueShift()->whereHas('agents', fn (Builder $agents) => $agents->whereKey($user->id))->exists();
    }
}
