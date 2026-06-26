<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->unsignedInteger('unread_messages_count')->default(0)->after('last_message_at');
            $table->timestamp('last_read_at')->nullable()->after('unread_messages_count');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropColumn(['unread_messages_count', 'last_read_at']);
        });
    }
};
