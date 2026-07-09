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

        if (! $currentShift) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $query) use ($user, $currentShift): void {
            $query->where(function (Builder $query) use ($user, $currentShift): void {
                $query->where('assigned_to', $user->id)
                    ->where(function (Builder $shiftQuery) use ($currentShift): void {
                        $shiftQuery->where('owner_shift_id', $currentShift->id)
                            ->orWhere('queue_shift_id', $currentShift->id);
                    });
            });
            $query->orWhere(function (Builder $query) use ($user, $currentShift): void {
                $query->whereHas('handledUsers', fn (Builder $handlers) => $handlers->whereKey($user->id))
                    ->where(function (Builder $shiftQuery) use ($currentShift): void {
                        $shiftQuery->where('owner_shift_id', $currentShift->id)
                            ->orWhere('queue_shift_id', $currentShift->id);
                    });
            });
            $query->orWhere(function (Builder $query) use ($user, $currentShift): void {
                $query->whereNull('assigned_to')
                    ->where('queue_shift_id', $currentShift->id)
                    ->whereHas('queueShift.agents', fn (Builder $agents) => $agents->whereKey($user->id));
            });
        });
    }

    public function canView(User $user, Conversation $conversation): bool
    {
        if ($user->can('conversation.view_all')) {
            return true;
        }

        $currentShift = $this->shifts->currentShiftFor($user);

        if (! $currentShift) {
            return false;
        }

        $belongsToConversationShift = in_array((int) $currentShift->id, array_filter([
            $conversation->owner_shift_id ? (int) $conversation->owner_shift_id : null,
            $conversation->queue_shift_id ? (int) $conversation->queue_shift_id : null,
        ]), true);

        if ($belongsToConversationShift && (int) $conversation->assigned_to === (int) $user->id) {
            return true;
        }

        if ($belongsToConversationShift && $conversation->handledUsers()->whereKey($user->id)->exists()) {
            return true;
        }

        return ! $conversation->assigned_to
            && $this->shifts->userIsInCurrentShift($user, $conversation->queue_shift_id);
    }
}
