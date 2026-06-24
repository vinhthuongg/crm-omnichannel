<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facebook_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('facebook_user_id')->unique();
            $table->string('name');
            $table->string('email')->nullable();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamps();
        });

        Schema::create('facebook_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('page_id')->unique();
            $table->string('page_name');
            $table->text('page_access_token');
            $table->string('page_avatar', 1000)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'page_id']);
        });

        Schema::table('conversations', function (Blueprint $table): void {
            if (! Schema::hasColumn('conversations', 'facebook_page_id')) {
                $table->string('facebook_page_id')->nullable()->after('customer_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            if (Schema::hasColumn('conversations', 'facebook_page_id')) {
                $table->dropColumn('facebook_page_id');
            }
        });

        Schema::dropIfExists('facebook_pages');
        Schema::dropIfExists('facebook_accounts');
    }
};
