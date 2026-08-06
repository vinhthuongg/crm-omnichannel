<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Quy đổi trạng thái cũ của `conversations` sang bốn trạng thái vận hành mới và thu gọn ENUM MySQL. */
    public function up(): void
    {
        $this->modifyMysqlStatusEnum(
            "ENUM('open','pending','waiting','in_progress','resolved','closed','reopened','waiting_customer','bot_consulting','customer_waiting')",
            'customer_waiting'
        );

        DB::table('conversations')
            ->whereIn('status', ['open', 'in_progress'])
            ->update(['status' => 'waiting_customer']);

        DB::table('conversations')
            ->whereIn('status', ['pending', 'waiting', 'reopened'])
            ->update(['status' => 'customer_waiting']);

        DB::table('conversations')
            ->where('status', 'resolved')
            ->update(['status' => 'closed']);

        $this->modifyMysqlStatusEnum(
            "ENUM('closed','waiting_customer','bot_consulting','customer_waiting')",
            'customer_waiting'
        );
    }

    /** Đổi trạng thái hội thoại mới về bộ trạng thái cũ và khôi phục ENUM MySQL trước migration. */
    public function down(): void
    {
        $this->modifyMysqlStatusEnum(
            "ENUM('waiting','in_progress','resolved','closed','reopened','waiting_customer','bot_consulting','customer_waiting')",
            'waiting'
        );

        DB::table('conversations')
            ->where('status', 'waiting_customer')
            ->update(['status' => 'in_progress']);

        DB::table('conversations')
            ->whereIn('status', ['customer_waiting', 'bot_consulting'])
            ->update(['status' => 'waiting']);

        $this->modifyMysqlStatusEnum(
            "ENUM('waiting','in_progress','resolved','closed','reopened')",
            'waiting'
        );
    }

    /** Thay ENUM status trên MySQL bằng danh sách trạng thái hội thoại mới mà không mất dữ liệu. */
    private function modifyMysqlStatusEnum(string $enum, string $default): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE conversations MODIFY status {$enum} NOT NULL DEFAULT '{$default}'");
    }
};
