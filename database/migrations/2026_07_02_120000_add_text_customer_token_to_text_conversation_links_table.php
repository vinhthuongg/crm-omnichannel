<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('text_conversation_links', function (Blueprint $table): void {
            if (! Schema::hasColumn('text_conversation_links', 'text_customer_access_token')) {
                $table->text('text_customer_access_token')->nullable()->after('text_customer_id');
            }

            if (! Schema::hasColumn('text_conversation_links', 'text_customer_token_expires_at')) {
                $table->timestamp('text_customer_token_expires_at')->nullable()->after('text_customer_access_token');
            }
        });
    }

    public function down(): void
    {
        Schema::table('text_conversation_links', function (Blueprint $table): void {
            if (Schema::hasColumn('text_conversation_links', 'text_customer_token_expires_at')) {
                $table->dropColumn('text_customer_token_expires_at');
            }

            if (Schema::hasColumn('text_conversation_links', 'text_customer_access_token')) {
                $table->dropColumn('text_customer_access_token');
            }
        });
    }
};
