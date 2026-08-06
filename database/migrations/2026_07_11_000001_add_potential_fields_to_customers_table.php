<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /** Tạo hoặc bổ sung bảng `customers`; lưu các trường `is_potential`, `email`, `potential_marked_at`, `potential_marked_by`, `users` và các khóa/index cần thiết. */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            if (! Schema::hasColumn('customers', 'is_potential')) {
                $table->boolean('is_potential')->default(false)->index()->after('email');
            }

            if (! Schema::hasColumn('customers', 'potential_marked_at')) {
                $table->timestamp('potential_marked_at')->nullable()->after('is_potential');
            }

            if (! Schema::hasColumn('customers', 'potential_marked_by')) {
                $table->foreignId('potential_marked_by')
                    ->nullable()
                    ->after('potential_marked_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    /** Hoàn tác các cột, khóa hoặc bảng `customers` đã được migration này tạo. */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            if (Schema::hasColumn('customers', 'potential_marked_by')) {
                $table->dropConstrainedForeignId('potential_marked_by');
            }

            if (Schema::hasColumn('customers', 'potential_marked_at')) {
                $table->dropColumn('potential_marked_at');
            }

            if (Schema::hasColumn('customers', 'is_potential')) {
                $table->dropColumn('is_potential');
            }
        });
    }
};
