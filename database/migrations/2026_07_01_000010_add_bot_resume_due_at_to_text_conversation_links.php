<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('text_conversation_links', function (Blueprint $table): void {
            if (! Schema::hasColumn('text_conversation_links', 'bot_resume_due_at')) {
                $table->timestamp('bot_resume_due_at')->nullable()->after('bot_paused_at')->index();
            }

            if (! Schema::hasColumn('text_conversation_links', 'bot_resumed_at')) {
                $table->timestamp('bot_resumed_at')->nullable()->after('bot_resume_due_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('text_conversation_links', function (Blueprint $table): void {
            if (Schema::hasColumn('text_conversation_links', 'bot_resumed_at')) {
                $table->dropColumn('bot_resumed_at');
            }

            if (Schema::hasColumn('text_conversation_links', 'bot_resume_due_at')) {
                $table->dropColumn('bot_resume_due_at');
            }
        });
    }
};
