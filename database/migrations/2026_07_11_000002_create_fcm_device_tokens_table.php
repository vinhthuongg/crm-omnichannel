<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tạo hoặc bổ sung bảng `fcm_device_tokens`; lưu các trường `user_id`, `token`, `platform`, `device_id`, `app_version`, `last_used_at` và các khóa/index cần thiết. */
    public function up(): void
    {
        Schema::create('fcm_device_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 512)->unique();
            $table->string('platform', 20)->nullable();
            $table->string('device_id')->nullable();
            $table->string('app_version')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'platform']);
        });
    }

    /** Hoàn tác các cột, khóa hoặc bảng `fcm_device_tokens` đã được migration này tạo. */
    public function down(): void
    {
        Schema::dropIfExists('fcm_device_tokens');
    }
};
