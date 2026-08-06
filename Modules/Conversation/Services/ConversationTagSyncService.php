<?php

namespace Modules\Conversation\Services;

use App\Models\User;
use Modules\ActivityLog\Services\ActivityLogService;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Models\Tag;

class ConversationTagSyncService
{
    /** Nhận ActivityLogService để ghi lịch sử thao tác của người dùng. */
    public function __construct(private readonly ActivityLogService $activityLog) {}

    /** Chuẩn hóa tối đa một nhãn, đồng bộ pivot và ghi activity thay đổi nhãn. */
    public function sync(Conversation $conversation, array $tags, User $actor): Conversation
    {
        Tag::ensureDefaults();
        $ids = collect($tags)->take(1)->map(fn (array $tag) => trim((string) ($tag['name'] ?? '')))->filter()
            ->map(fn (string $name) => Tag::query()->where('name', $name)->value('id'))->filter()->all();
        $conversation->tags()->sync($ids);
        $this->activityLog->record($actor, 'conversation.tagged', $conversation, ['tags' => $ids]);
        return $conversation->load(['customer.channels', 'assignee', 'tags']);
    }
}
