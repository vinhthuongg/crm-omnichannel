<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tạo hoặc bổ sung bảng `conversations`; lưu các trường `automation_state` và các khóa/index cần thiết. */
    public function up(): void
    {
        Schema::dropIfExists('botpress_conversation_links');

        Schema::table('conversations', function (Blueprint $table): void {
            if (Schema::hasColumn('conversations', 'automation_state')) {
                $table->dropColumn('automation_state');
            }
        });
    }

    /** Hoàn tác các cột, khóa hoặc bảng `conversations` đã được migration này tạo. */
    public function down(): void
    {
        // The removed integration and its credentials cannot be restored safely.
    }
};
