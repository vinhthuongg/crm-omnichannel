<?php

namespace Modules\Message\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\Conversation\Models\Conversation;

class ConversationMessageQueryService
{
    /** Lấy cửa sổ tin nhắn quanh vị trí được yêu cầu. */
    public function window(Conversation $conversation, int $beforeId, int $afterId, int $limit): array
    {
        $limit = min(max($limit, 1), 50);
        $query = $conversation->messages()->with(['sender', 'conversation.customer']);
        if ($beforeId > 0) $messages = $query->where('id', '<', $beforeId)->latest()->limit($limit)->get()->reverse()->values();
        elseif ($afterId > 0) $messages = $query->where('id', '>', $afterId)->oldest()->limit($limit)->get();
        else $messages = $query->latest()->limit($limit)->get()->reverse()->values();
        $oldestId = (int) ($messages->first()?->id ?? 0);
        return ['messages' => $messages, 'oldest_id' => $oldestId,
            'has_more' => $afterId <= 0 && $oldestId > 0 && $conversation->messages()->where('id', '<', $oldestId)->exists()];
    }

    /** Phân trang tin nhắn trước hoặc sau một ID mốc trong hội thoại. */
    public function paginate(Conversation $conversation, ?int $beforeId, ?int $afterId, int $perPage): LengthAwarePaginator
    {
        return $conversation->messages()->with('sender')
            ->when($beforeId, fn (Builder $query): Builder => $query->where('id', '<', $beforeId))
            ->when($afterId, fn (Builder $query): Builder => $query->where('id', '>', $afterId))
            ->latest('id')->paginate(min(max($perPage, 1), 100));
    }
}
