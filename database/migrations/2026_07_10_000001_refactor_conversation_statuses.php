<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE conversations MODIFY status ENUM('open','pending','waiting','in_progress','resolved','closed','reopened','waiting_customer','bot_consulting','customer_waiting') NOT NULL DEFAULT 'customer_waiting'");

        DB::table('conversations')
            ->whereIn('status', ['open', 'in_progress'])
            ->update(['status' => 'waiting_customer']);

        DB::table('conversations')
            ->whereIn('status', ['pending', 'waiting', 'reopened'])
            ->update(['status' => 'customer_waiting']);

        DB::table('conversations')
            ->where('status', 'resolved')
            ->update(['status' => 'closed']);

        DB::statement("ALTER TABLE conversations MODIFY status ENUM('closed','waiting_customer','bot_consulting','customer_waiting') NOT NULL DEFAULT 'customer_waiting'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE conversations MODIFY status ENUM('waiting','in_progress','resolved','closed','reopened','waiting_customer','bot_consulting','customer_waiting') NOT NULL DEFAULT 'waiting'");

        DB::table('conversations')
            ->where('status', 'waiting_customer')
            ->update(['status' => 'in_progress']);

        DB::table('conversations')
            ->whereIn('status', ['customer_waiting', 'bot_consulting'])
            ->update(['status' => 'waiting']);

        DB::statement("ALTER TABLE conversations MODIFY status ENUM('waiting','in_progress','resolved','closed','reopened') NOT NULL DEFAULT 'waiting'");
    }
};
