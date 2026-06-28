<?php

namespace Modules\Conversation\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Modules\Conversation\Models\Conversation;

class ConversationVisibilityService
{
    public function __construct(private readonly WorkShiftService $shifts)
    {
    }

    public function visibleFor(User $user): Builder
    {
        $query = Conversation::query();

        if ($user->can('conversation.view_all')) {
            return $query;
        }

        $currentShift = $this->shifts->currentShiftFor($user);

        return $query->where(function (Builder $query) use ($user, $currentShift): void {
            $query->where('assigned_to', $user->id);

            if ($currentShift) {
                $query->orWhere(function (Builder $query) use ($currentShift): void {
                    $query->whereNull('assigned_to')
                        ->where('queue_shift_id', $currentShift->id);
                });
            }
        });
    }

    public function canView(User $user, Conversation $conversation): bool
    {
        if ($user->can('conversation.view_all') || (int) $conversation->assigned_to === (int) $user->id) {
            return true;
        }

        return ! $conversation->assigned_to
            && $this->shifts->userIsInCurrentShift($user, $conversation->queue_shift_id);
    }
}
