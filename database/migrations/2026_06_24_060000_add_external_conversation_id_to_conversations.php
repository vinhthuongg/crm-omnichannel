<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tạo hoặc bổ sung bảng `conversations`; lưu các trường `external_conversation_id`, `facebook_page_id` và các khóa/index cần thiết. */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            if (! Schema::hasColumn('conversations', 'external_conversation_id')) {
                $table->string('external_conversation_id')->nullable()->after('facebook_page_id')->index();
            }
        });
    }

    /** Hoàn tác các cột, khóa hoặc bảng `conversations` đã được migration này tạo. */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            if (Schema::hasColumn('conversations', 'external_conversation_id')) {
                $table->dropColumn('external_conversation_id');
            }
        });
    }
};
