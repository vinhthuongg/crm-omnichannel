<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Conversation\Models\Tag;

return new class extends Migration
{
    public function up(): void
    {
        $defaults = Tag::DEFAULTS;

        foreach ($defaults as $name => $color) {
            DB::table('tags')->updateOrInsert(
                ['name' => $name],
                [
                    'color' => $color,
                    'is_default' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            DB::table('customer_tags')->updateOrInsert(
                ['name' => $name],
                [
                    'color' => $color,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        $defaultNames = array_keys($defaults);

        $removedTagIds = DB::table('tags')
            ->whereNotIn('name', $defaultNames)
            ->pluck('id')
            ->all();

        if ($removedTagIds !== []) {
            DB::table('conversation_tag')->whereIn('tag_id', $removedTagIds)->delete();
            DB::table('tags')->whereIn('id', $removedTagIds)->delete();
        }

        $removedCustomerTagIds = DB::table('customer_tags')
            ->whereNotIn('name', $defaultNames)
            ->pluck('id')
            ->all();

        if ($removedCustomerTagIds !== []) {
            DB::table('customer_customer_tag')->whereIn('customer_tag_id', $removedCustomerTagIds)->delete();
            DB::table('customer_tags')->whereIn('id', $removedCustomerTagIds)->delete();
        }

        DB::table('tags')
            ->whereIn('name', $defaultNames)
            ->update(['is_default' => true, 'updated_at' => now()]);
    }

    public function down(): void
    {
        //
    }
};
