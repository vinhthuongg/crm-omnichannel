<?php

namespace Modules\Conversation\Services;

use App\Models\User;
use Modules\ActivityLog\Services\ActivityLogService;
use Modules\Conversation\Events\ConversationAssignedEvent;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Models\Tag;

class ConversationService
{
    public function __construct(private readonly ActivityLogService $activityLog)
    {
    }

    public function assign(Conversation $conversation, int $userId, User $actor): Conversation
    {
        $conversation->forceFill(['assigned_to' => $userId, 'status' => 'open'])->save();
        $this->activityLog->record($actor, 'conversation.assigned', $conversation, ['assigned_to' => $userId]);
        event(new ConversationAssignedEvent($conversation->refresh(), $actor));
        return $conversation->load(['customer.channels', 'assignee', 'tags']);
    }

    public function claim(Conversation $conversation, User $actor): Conversation
    {
        if ($conversation->assigned_to && (int) $conversation->assigned_to !== (int) $actor->id) {
            throw new \RuntimeException('Hoi thoai da co nhan vien khac nhan xu ly.');
        }

        $updated = Conversation::query()
            ->whereKey($conversation->id)
            ->where(function ($query) use ($actor): void {
                $query->whereNull('assigned_to')->orWhere('assigned_to', $actor->id);
            })
            ->update([
                'assigned_to' => $actor->id,
                'claimed_at' => now(),
                'status' => 'open',
                'updated_at' => now(),
            ]);

        if (! $updated) {
            throw new \RuntimeException('Hoi thoai da co nhan vien khac nhan xu ly.');
        }

        $conversation = $conversation->refresh();
        $this->activityLog->record($actor, 'conversation.claimed', $conversation, ['assigned_to' => $actor->id]);
        event(new ConversationAssignedEvent($conversation, $actor));

        return $conversation->load(['customer.channels', 'assignee', 'tags']);
    }

    public function close(Conversation $conversation, User $actor): Conversation
    {
        $conversation->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
        $this->activityLog->record($actor, 'conversation.closed', $conversation);
        return $conversation->load(['customer.channels', 'assignee', 'tags']);
    }

    public function syncTags(Conversation $conversation, array $tags, User $actor): Conversation
    {
        $ids = collect($tags)
            ->take(1)
            ->map(fn (array $tag) => Tag::query()->firstOrCreate(['name' => $tag['name']], ['color' => $tag['color'] ?? null])->id)
            ->all();
        $conversation->tags()->sync($ids);
        $this->activityLog->record($actor, 'conversation.tagged', $conversation, ['tags' => $ids]);
        return $conversation->load(['customer.channels', 'assignee', 'tags']);
    }
}
