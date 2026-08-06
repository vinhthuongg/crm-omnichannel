<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tạo hoặc bổ sung bảng `customers`; lưu các trường `phone_collected_at`, `phone` và các khóa/index cần thiết. */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            if (! Schema::hasColumn('customers', 'phone_collected_at')) {
                $table->timestamp('phone_collected_at')->nullable()->after('phone')->index();
            }
        });

        if (Schema::hasColumn('customers', 'phone_collected_at')) {
            DB::table('customers')
                ->whereNotNull('phone')
                ->where('phone', '<>', '')
                ->whereNull('phone_collected_at')
                ->update([
                    'phone_collected_at' => DB::raw('COALESCE(updated_at, created_at, CURRENT_TIMESTAMP)'),
                ]);
        }
    }

    /** Hoàn tác các cột, khóa hoặc bảng `customers` đã được migration này tạo. */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            if (Schema::hasColumn('customers', 'phone_collected_at')) {
                $table->dropIndex(['phone_collected_at']);
                $table->dropColumn('phone_collected_at');
            }
        });
    }
};
