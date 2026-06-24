<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            if (! Schema::hasColumn('conversations', 'external_conversation_id')) {
                $table->string('external_conversation_id')->nullable()->after('facebook_page_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            if (Schema::hasColumn('conversations', 'external_conversation_id')) {
                $table->dropColumn('external_conversation_id');
            }
        });
    }
};
