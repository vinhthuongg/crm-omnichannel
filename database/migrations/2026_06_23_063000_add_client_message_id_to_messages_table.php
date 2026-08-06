<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /** Tạo hoặc bổ sung bảng `messages`; lưu các trường `client_message_id`, `external_message_id` và các khóa/index cần thiết. */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            if (! Schema::hasColumn('messages', 'client_message_id')) {
                $table->string('client_message_id', 80)->nullable()->after('external_message_id');
                $table->unique(['channel', 'client_message_id']);
            }
        });
    }

    /** Hoàn tác các cột, khóa hoặc bảng `messages` đã được migration này tạo. */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            if (Schema::hasColumn('messages', 'client_message_id')) {
                $table->dropUnique(['channel', 'client_message_id']);
                $table->dropColumn('client_message_id');
            }
        });
    }
};
