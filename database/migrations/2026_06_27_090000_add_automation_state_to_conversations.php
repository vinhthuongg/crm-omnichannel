<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            if (! Schema::hasColumn('conversations', 'automation_state')) {
                $table->json('automation_state')->nullable()->after('unread_messages_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            if (Schema::hasColumn('conversations', 'automation_state')) {
                $table->dropColumn('automation_state');
            }
        });
    }
};
