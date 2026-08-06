<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Conversation\Models\Tag;

return new class extends Migration
{
    /** Tạo hoặc bổ sung bảng `tags`; lưu các trường `is_default`, `color`, `Dang tu van`, `Khach dang doi tu van`, `name`, `tag_id`, `conversation_id` và các khóa/index cần thiết. */
    public function up(): void
    {
        Schema::table('tags', function (Blueprint $table): void {
            if (! Schema::hasColumn('tags', 'is_default')) {
                $table->boolean('is_default')->default(false)->after('color');
            }
        });

        foreach (Tag::DEFAULTS as $name => $color) {
            DB::table('tags')->updateOrInsert(
                ['name' => $name],
                [
                    'color' => $color,
                    'is_default' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        $this->moveLegacyTag('Dang tu van', Tag::DEFAULT_CONSULTING);
        $this->moveLegacyTag('Khach dang doi tu van', Tag::DEFAULT_WAITING);
    }

    /** Hoàn tác các cột, khóa hoặc bảng `tags` đã được migration này tạo. */
    public function down(): void
    {
        Schema::table('tags', function (Blueprint $table): void {
            if (Schema::hasColumn('tags', 'is_default')) {
                $table->dropColumn('is_default');
            }
        });
    }

    /** Chuyển liên kết từ nhãn cũ sang nhãn chuẩn rồi xóa nhãn trùng nếu không còn sử dụng. */
    private function moveLegacyTag(string $legacyName, string $targetName): void
    {
        $legacy = DB::table('tags')->where('name', $legacyName)->first();
        $target = DB::table('tags')->where('name', $targetName)->first();

        if (! $legacy || ! $target || (int) $legacy->id === (int) $target->id) {
            return;
        }

        DB::table('conversation_tag')
            ->where('tag_id', $legacy->id)
            ->orderBy('conversation_id')
            ->get(['conversation_id'])
            ->each(function ($row) use ($target): void {
                DB::table('conversation_tag')->insertOrIgnore([
                    'conversation_id' => $row->conversation_id,
                    'tag_id' => $target->id,
                ]);
            });

        DB::table('conversation_tag')->where('tag_id', $legacy->id)->delete();
        DB::table('tags')->where('id', $legacy->id)->delete();
    }
};
