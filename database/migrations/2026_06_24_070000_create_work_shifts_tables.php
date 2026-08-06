<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tạo hoặc bổ sung bảng `work_shifts`, `work_shift_user`, `conversations`; lưu các trường `name`, `starts_at`, `ends_at`, `is_active`, `work_shift_id`, `work_shifts`, `user_id`, `users`, `assigned_to`, `claimed_at` và các khóa/index cần thiết. */
    public function up(): void
    {
        Schema::create('work_shifts', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->dateTime('starts_at')->index();
            $table->dateTime('ends_at')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('work_shift_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_shift_id')->constrained('work_shifts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['work_shift_id', 'user_id']);
        });

        Schema::table('conversations', function (Blueprint $table): void {
            if (! Schema::hasColumn('conversations', 'work_shift_id')) {
                $table->foreignId('work_shift_id')->nullable()->after('assigned_to')->constrained('work_shifts')->nullOnDelete();
            }

            if (! Schema::hasColumn('conversations', 'claimed_at')) {
                $table->timestamp('claimed_at')->nullable()->after('work_shift_id');
            }
        });
    }

    /** Hoàn tác các cột, khóa hoặc bảng `work_shifts`, `work_shift_user`, `conversations` đã được migration này tạo. */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            if (Schema::hasColumn('conversations', 'claimed_at')) {
                $table->dropColumn('claimed_at');
            }

            if (Schema::hasColumn('conversations', 'work_shift_id')) {
                $table->dropConstrainedForeignId('work_shift_id');
            }
        });

        Schema::dropIfExists('work_shift_user');
        Schema::dropIfExists('work_shifts');
    }
};
