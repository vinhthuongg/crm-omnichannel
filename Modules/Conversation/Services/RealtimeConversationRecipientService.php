<?php

namespace Modules\Conversation\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Modules\Conversation\Models\Conversation;

class RealtimeConversationRecipientService
{
    /** Nhận ConversationVisibilityService để kiểm tra phạm vi truy cập. */
    public function __construct(private readonly ConversationVisibilityService $visibility)
    {
    }

    /** Xác định danh sách người dùng cần nhận sự kiện realtime. */
    public function recipientUserIds(Conversation $conversation): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $user): bool => $this->visibility->canView($user, $conversation))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    /** Tạo danh sách kênh realtime người dùng được phép đăng ký. */
    public function channelsFor(Conversation $conversation): array
    {
        return $this->recipientUserIds($conversation)
            ->map(fn (int $userId): string => 'crm.user.'.$userId.'.conversations')
            ->all();
    }
}
