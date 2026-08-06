<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tạo hoặc bổ sung bảng `conversations`; lưu các trường `unread_messages_count`, `last_message_at`, `last_read_at` và các khóa/index cần thiết. */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->unsignedInteger('unread_messages_count')->default(0)->after('last_message_at');
            $table->timestamp('last_read_at')->nullable()->after('unread_messages_count');
        });
    }

    /** Hoàn tác các cột, khóa hoặc bảng `conversations` đã được migration này tạo. */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropColumn(['unread_messages_count', 'last_read_at']);
        });
    }
};
