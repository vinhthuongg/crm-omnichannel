<?php

namespace Modules\Conversation\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Conversation\Models\Tag;

class ConversationTagManagementService
{
    /** Cập nhật tên/màu của nhãn nhưng bảo vệ các ràng buộc nhãn mặc định. */
    public function update(Tag $tag, array $data): Tag
    {
        $name = trim((string) $data['name']);
        if ($name === '') throw ValidationException::withMessages(['name' => 'Tag không được để trống.']);
        if ($tag->is_default && $name !== $tag->name) throw ValidationException::withMessages(['name' => 'Tag mặc định không được đổi tên.']);
        if (Tag::query()->where('name', $name)->whereKeyNot($tag->id)->exists()) throw ValidationException::withMessages(['name' => 'Tên tag đã tồn tại.']);
        $tag->forceFill(['name' => $name, 'color' => $data['color'] ?: '#2563eb'])->save();
        return $tag->refresh();
    }

    /** Xóa nhãn không mặc định sau khi gỡ liên kết khỏi hội thoại và khách hàng. */
    public function delete(Tag $tag): void
    {
        if ($tag->is_default || array_key_exists($tag->name, Tag::DEFAULTS)) throw ValidationException::withMessages(['tag' => 'Tag mặc định không được xóa.']);
        DB::transaction(function () use ($tag): void { $tag->conversations()->detach(); $tag->delete(); });
    }
}
