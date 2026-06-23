<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            if (! Schema::hasColumn('messages', 'client_message_id')) {
                $table->string('client_message_id', 80)->nullable()->after('external_message_id');
                $table->unique(['channel', 'client_message_id']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            if (Schema::hasColumn('messages', 'client_message_id')) {
                $table->dropUnique(['channel', 'client_message_id']);
                $table->dropColumn('client_message_id');
            }
        });
    }
};
