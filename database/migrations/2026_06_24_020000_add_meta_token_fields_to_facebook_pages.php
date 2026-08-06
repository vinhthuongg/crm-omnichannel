<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tạo hoặc bổ sung bảng `facebook_pages`; lưu các trường `meta_app_id`, `page_avatar`, `subscribed_at`, `token_expires_at`, `token_status`, `valid`, `token_invalid_at`, `token_last_error` và các khóa/index cần thiết. */
    public function up(): void
    {
        Schema::table('facebook_pages', function (Blueprint $table): void {
            if (! Schema::hasColumn('facebook_pages', 'meta_app_id')) {
                $table->string('meta_app_id')->nullable()->after('page_avatar')->index();
            }

            if (! Schema::hasColumn('facebook_pages', 'subscribed_at')) {
                $table->timestamp('subscribed_at')->nullable()->after('meta_app_id');
            }

            if (! Schema::hasColumn('facebook_pages', 'token_expires_at')) {
                $table->timestamp('token_expires_at')->nullable()->after('subscribed_at');
            }

            if (! Schema::hasColumn('facebook_pages', 'token_status')) {
                $table->string('token_status', 20)->default('valid')->after('token_expires_at')->index();
            }

            if (! Schema::hasColumn('facebook_pages', 'token_invalid_at')) {
                $table->timestamp('token_invalid_at')->nullable()->after('token_status');
            }

            if (! Schema::hasColumn('facebook_pages', 'token_last_error')) {
                $table->text('token_last_error')->nullable()->after('token_invalid_at');
            }
        });
    }

    /** Hoàn tác các cột, khóa hoặc bảng `facebook_pages` đã được migration này tạo. */
    public function down(): void
    {
        Schema::table('facebook_pages', function (Blueprint $table): void {
            foreach (['token_last_error', 'token_invalid_at', 'token_status', 'token_expires_at', 'subscribed_at', 'meta_app_id'] as $column) {
                if (Schema::hasColumn('facebook_pages', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
