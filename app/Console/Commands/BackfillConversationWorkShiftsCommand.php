<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\WorkShiftService;
use Modules\Conversation\Support\ConversationStatus;

class BackfillConversationWorkShiftsCommand extends Command
{
    protected $signature = 'work-shifts:backfill-conversations {--current : Use current active shift for unassigned conversations}';

    protected $description = 'Attach missing owner and queue shifts to unassigned waiting conversations.';

    /** Gán ca trực phù hợp cho các hội thoại cũ chưa có owner/queue shift. */
    public function handle(WorkShiftService $shifts): int
    {
        $shift = $shifts->currentShift();

        if (! $shift) {
            $this->warn('No active shift found for current time: '.now()->toDateTimeString());

            return self::SUCCESS;
        }

        $count = Conversation::query()
            ->where('status', ConversationStatus::WAITING)
            ->whereNull('assigned_to')
            ->where(function ($query): void {
                $query->whereNull('owner_shift_id')
                    ->orWhereNull('queue_shift_id');
            })
            ->update([
                'work_shift_id' => $shift->id,
                'owner_shift_id' => $shift->id,
                'queue_shift_id' => $shift->id,
                'updated_at' => now(),
            ]);

        $this->info("Attached {$count} conversations to shift {$shift->id} ({$shift->name}).");

        return self::SUCCESS;
    }
}
