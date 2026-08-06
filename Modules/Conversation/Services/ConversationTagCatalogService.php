<?php

namespace Modules\Conversation\Services;

use Modules\Conversation\Models\Tag;
use Illuminate\Support\Collection;

class ConversationTagCatalogService
{
    /** Tạo danh sách payload cho toàn bộ tài nguyên. */
    public function payloads(): array
    {
        return $this->defaults()
            ->map(fn (Tag $tag): array => $this->payload($tag))->values()->all();
    }

    /** Lấy danh sách giá trị mặc định của hệ thống. */
    public function defaults(): Collection
    {
        Tag::ensureDefaults();
        return Tag::query()->where('is_default', true)->orderByDesc('is_default')->orderBy('name')->get();
    }

    /** Chuyển tài nguyên thành payload dùng cho API hoặc giao diện. */
    public function payload(Tag $tag): array
    {
        return [
            'id' => (int) $tag->id,
            'name' => $tag->name,
            'color' => $tag->color ?: '#2563eb',
            'is_default' => (bool) $tag->is_default,
            'update_url' => route('crm.conversation-tags.update', $tag),
            'delete_url' => route('crm.conversation-tags.destroy', $tag),
        ];
    }
}
