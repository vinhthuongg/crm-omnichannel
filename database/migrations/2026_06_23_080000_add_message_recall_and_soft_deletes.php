<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            if (! Schema::hasColumn('messages', 'recalled_at')) {
                $table->timestamp('recalled_at')->nullable()->after('sent_at')->index();
            }

            if (! Schema::hasColumn('messages', 'recalled_by_user_id')) {
                $table->foreignId('recalled_by_user_id')->nullable()->after('recalled_at')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('messages', 'deleted_at')) {
                $table->softDeletes()->after('recalled_by_user_id');
            }

            if (! Schema::hasColumn('messages', 'deleted_by_user_id')) {
                $table->foreignId('deleted_by_user_id')->nullable()->after('deleted_at')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            if (Schema::hasColumn('messages', 'deleted_by_user_id')) {
                $table->dropConstrainedForeignId('deleted_by_user_id');
            }

            if (Schema::hasColumn('messages', 'deleted_at')) {
                $table->dropSoftDeletes();
            }

            if (Schema::hasColumn('messages', 'recalled_by_user_id')) {
                $table->dropConstrainedForeignId('recalled_by_user_id');
            }

            if (Schema::hasColumn('messages', 'recalled_at')) {
                $table->dropColumn('recalled_at');
            }
        });
    }
};
