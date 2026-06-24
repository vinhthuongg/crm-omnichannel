<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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
