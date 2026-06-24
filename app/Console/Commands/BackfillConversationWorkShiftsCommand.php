<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\WorkShiftService;

class BackfillConversationWorkShiftsCommand extends Command
{
    protected $signature = 'work-shifts:backfill-conversations {--current : Use current active shift for unassigned conversations}';

    protected $description = 'Attach missing work shifts to unassigned open conversations.';

    public function handle(WorkShiftService $shifts): int
    {
        $shift = $shifts->currentShift();

        if (! $shift) {
            $this->warn('No active shift found for current time: '.now()->toDateTimeString());

            return self::SUCCESS;
        }

        $count = Conversation::query()
            ->where('status', 'open')
            ->whereNull('assigned_to')
            ->whereNull('work_shift_id')
            ->update([
                'work_shift_id' => $shift->id,
                'updated_at' => now(),
            ]);

        $this->info("Attached {$count} conversations to shift {$shift->id} ({$shift->name}).");

        return self::SUCCESS;
    }
}
