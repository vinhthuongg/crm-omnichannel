<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Conversation\Models\Tag;

return new class extends Migration
{
    /** Giữ nhãn sở thích mặc định và loại nhãn trạng thái cũ khỏi hội thoại, khách hàng. */
    public function up(): void
    {
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

        $legacyTagIds = DB::table('tags')
            ->whereIn('name', Tag::LEGACY_STATUS_TAGS)
            ->pluck('id')
            ->all();

        if ($legacyTagIds !== []) {
            DB::table('conversation_tag')->whereIn('tag_id', $legacyTagIds)->delete();
            DB::table('tags')->whereIn('id', $legacyTagIds)->delete();
        }

        $legacyCustomerTagIds = DB::table('customer_tags')
            ->whereIn('name', Tag::LEGACY_STATUS_TAGS)
            ->pluck('id')
            ->all();

        if ($legacyCustomerTagIds !== []) {
            DB::table('customer_customer_tag')->whereIn('customer_tag_id', $legacyCustomerTagIds)->delete();
            DB::table('customer_tags')->whereIn('id', $legacyCustomerTagIds)->delete();
        }

        DB::table('tags')
            ->whereNotIn('name', array_keys(Tag::DEFAULTS))
            ->where('is_default', true)
            ->update(['is_default' => false, 'updated_at' => now()]);
    }

    /** Khôi phục hai nhãn trạng thái Tư vấn và Chờ đợi vào bảng `tags`. */
    public function down(): void
    {
        foreach ([
            Tag::DEFAULT_CONSULTING => '#e11d48',
            Tag::DEFAULT_WAITING => '#f59e0b',
        ] as $name => $color) {
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
    }
};
