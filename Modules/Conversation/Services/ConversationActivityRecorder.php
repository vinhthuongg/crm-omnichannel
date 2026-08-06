<?php

namespace Modules\Conversation\Services;

use App\Models\User;
use Modules\ActivityLog\Services\ActivityLogService;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Models\ConversationActivity;

class ConversationActivityRecorder
{
    /** Nhận ActivityLogService để ghi lịch sử thao tác của người dùng. */
    public function __construct(private readonly ActivityLogService $activityLog) {}

    /** Ghi nhận sự kiện hoặc thay đổi trạng thái vào lịch sử hệ thống. */
    public function record(Conversation $conversation, string $action, array $old, array $new, ?User $actor,
        ?string $auditAction = null, ?array $auditContext = null): void
    {
        ConversationActivity::query()->create(['conversation_id' => $conversation->id, 'action' => $action,
            'old_value' => $old, 'new_value' => $new, 'performed_by' => $actor?->id]);
        if ($actor) $this->activityLog->record($actor, $auditAction ?? 'conversation.'.$action, $conversation, $auditContext ?? $new);
    }

    /** Tạo ảnh chụp trạng thái phục vụ activity log. */
    public function snapshot(Conversation $conversation): array
    {
        return ['assigned_to' => $conversation->assigned_to ? (int) $conversation->assigned_to : null,
            'assigned_by' => $conversation->assigned_by ? (int) $conversation->assigned_by : null,
            'assigned_type' => $conversation->assigned_type, 'claimed_at' => $conversation->claimed_at?->toISOString(),
            'status' => $conversation->status];
    }

    /** Ghi nhận người dùng đã từng xử lý hội thoại. */
    public function rememberHandler(Conversation $conversation, User $user): void
    {
        $conversation->handledUsers()->syncWithoutDetaching([$user->id => ['first_handled_at' => now(), 'updated_at' => now()]]);
    }
}
