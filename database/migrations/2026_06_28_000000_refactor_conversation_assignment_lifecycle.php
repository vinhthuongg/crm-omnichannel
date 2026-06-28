<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            if (! Schema::hasColumn('conversations', 'assigned_by')) {
                $table->foreignId('assigned_by')->nullable()->after('assigned_to')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('conversations', 'assigned_type')) {
                $table->string('assigned_type', 24)->nullable()->after('assigned_by')->index();
            }

            if (! Schema::hasColumn('conversations', 'owner_shift_id')) {
                $table->foreignId('owner_shift_id')->nullable()->after('claimed_at')->constrained('work_shifts')->nullOnDelete();
            }

            if (! Schema::hasColumn('conversations', 'queue_shift_id')) {
                $table->foreignId('queue_shift_id')->nullable()->after('owner_shift_id')->constrained('work_shifts')->nullOnDelete();
            }

            if (! Schema::hasColumn('conversations', 'resolved_at')) {
                $table->timestamp('resolved_at')->nullable()->after('last_read_at');
            }

            if (! Schema::hasColumn('conversations', 'first_response_at')) {
                $table->timestamp('first_response_at')->nullable()->after('resolved_at');
            }
        });

        if (Schema::hasColumn('conversations', 'work_shift_id')) {
            DB::table('conversations')
                ->whereNull('owner_shift_id')
                ->update(['owner_shift_id' => DB::raw('work_shift_id')]);
            DB::table('conversations')
                ->whereNull('queue_shift_id')
                ->update(['queue_shift_id' => DB::raw('work_shift_id')]);
        }

        $this->normalizeStatusColumn();

        Schema::create('conversation_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->string('action', 40)->index();
            $table->json('old_value')->nullable();
            $table->json('new_value')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_activities');

        Schema::table('conversations', function (Blueprint $table): void {
            foreach (['first_response_at', 'resolved_at'] as $column) {
                if (Schema::hasColumn('conversations', $column)) {
                    $table->dropColumn($column);
                }
            }

            foreach (['queue_shift_id', 'owner_shift_id', 'assigned_by'] as $column) {
                if (Schema::hasColumn('conversations', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }

            if (Schema::hasColumn('conversations', 'assigned_type')) {
                $table->dropColumn('assigned_type');
            }
        });
    }

    private function normalizeStatusColumn(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            DB::table('conversations')->where('status', 'open')->update(['status' => 'in_progress']);
            DB::table('conversations')->where('status', 'pending')->update(['status' => 'waiting']);
            return;
        }

        DB::statement("ALTER TABLE conversations MODIFY status ENUM('open','pending','closed','waiting','in_progress','resolved','reopened') NOT NULL DEFAULT 'waiting'");
        DB::table('conversations')->where('status', 'open')->whereNull('assigned_to')->update(['status' => 'waiting']);
        DB::table('conversations')->where('status', 'open')->whereNotNull('assigned_to')->update(['status' => 'in_progress']);
        DB::table('conversations')->where('status', 'pending')->update(['status' => 'waiting']);
        DB::statement("ALTER TABLE conversations MODIFY status ENUM('waiting','in_progress','resolved','closed','reopened') NOT NULL DEFAULT 'waiting'");
    }
};
