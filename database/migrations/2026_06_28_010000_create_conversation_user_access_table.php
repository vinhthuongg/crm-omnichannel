<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_user_access', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('first_handled_at')->nullable();
            $table->timestamps();
            $table->unique(['conversation_id', 'user_id']);
        });

        DB::table('conversations')
            ->whereNotNull('assigned_to')
            ->orderBy('id')
            ->select(['id', 'assigned_to', 'claimed_at', 'created_at', 'updated_at'])
            ->chunkById(200, function ($conversations): void {
                foreach ($conversations as $conversation) {
                    DB::table('conversation_user_access')->updateOrInsert(
                        [
                            'conversation_id' => $conversation->id,
                            'user_id' => $conversation->assigned_to,
                        ],
                        [
                            'first_handled_at' => $conversation->claimed_at ?: $conversation->created_at,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    );
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_user_access');
    }
};
