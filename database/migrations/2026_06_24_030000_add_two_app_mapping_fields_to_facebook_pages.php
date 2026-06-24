<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facebook_pages', function (Blueprint $table): void {
            if (! Schema::hasColumn('facebook_pages', 'facebook_user_id')) {
                $table->string('facebook_user_id')->nullable()->after('user_id')->index();
            }

            if (! Schema::hasColumn('facebook_pages', 'messenger_app_id')) {
                $table->string('messenger_app_id')->nullable()->after('meta_app_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('facebook_pages', function (Blueprint $table): void {
            if (Schema::hasColumn('facebook_pages', 'messenger_app_id')) {
                $table->dropColumn('messenger_app_id');
            }

            if (Schema::hasColumn('facebook_pages', 'facebook_user_id')) {
                $table->dropColumn('facebook_user_id');
            }
        });
    }
};
