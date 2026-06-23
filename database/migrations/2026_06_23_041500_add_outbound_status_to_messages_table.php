<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            if (! Schema::hasColumn('messages', 'outbound_status')) {
                $table->string('outbound_status', 24)->nullable()->after('external_message_id')->index();
            }

            if (! Schema::hasColumn('messages', 'outbound_error')) {
                $table->text('outbound_error')->nullable()->after('outbound_status');
            }

            if (! Schema::hasColumn('messages', 'sent_at')) {
                $table->timestamp('sent_at')->nullable()->after('outbound_error');
            }
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            if (Schema::hasColumn('messages', 'sent_at')) {
                $table->dropColumn('sent_at');
            }

            if (Schema::hasColumn('messages', 'outbound_error')) {
                $table->dropColumn('outbound_error');
            }

            if (Schema::hasColumn('messages', 'outbound_status')) {
                $table->dropColumn('outbound_status');
            }
        });
    }
};
