<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Đổi các echo Facebook từng bị gắn nhầm là Bot sang nhân viên Meta dựa trên metadata đã lưu. */
    public function up(): void
    {
        DB::table('messages')
            ->where('channel', 'facebook')
            ->where('sender_type', 'system')
            ->whereNotNull('attachments')
            ->orderBy('id')
            ->chunkById(200, function ($messages): void {
                foreach ($messages as $message) {
                    $attachments = json_decode((string) $message->attachments, true);

                    $isBusinessSuiteEcho = collect(is_array($attachments) ? $attachments : [])
                        ->contains(fn ($attachment): bool => is_array($attachment)
                            && data_get($attachment, 'name') === 'facebook_echo'
                            && (bool) data_get($attachment, 'payload.is_echo'));

                    if ($isBusinessSuiteEcho) {
                        DB::table('messages')->where('id', $message->id)->update([
                            'sender_type' => 'user',
                            'sender_id' => null,
                            'updated_at' => now(),
                        ]);
                    }
                }
            });
    }

    /** Không tự động đổi ngược vì sau migration không thể phân biệt chắc chắn nhân viên Meta với nhân viên CRM. */
    public function down(): void {}
};
