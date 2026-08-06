<?php

namespace Modules\Conversation\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Conversation\Events\ConversationClosed;
use Modules\Conversation\Events\ConversationReopened;
use Modules\Conversation\Events\ConversationResolved;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Support\ConversationAction;
use Modules\Conversation\Support\ConversationStatus;

class ConversationLifecycleService
{
    /** Nhận ConversationStateMachine để kiểm tra chuyển trạng thái hội thoại hợp lệ; ConversationActivityRecorder để ghi lịch sử phân công và thay đổi trạng thái. */
    public function __construct(private readonly ConversationStateMachine $states, private readonly ConversationActivityRecorder $activities) {}
    /** Chuyển hội thoại sang closed, lưu resolved_at và phát ConversationResolved. */
    public function resolve(Conversation $conversation, User $actor): Conversation { return $this->transition($conversation, ConversationStatus::CLOSED, ConversationAction::RESOLVE, $actor, ['resolved_at' => now()], ConversationResolved::class); }
    /** Đóng hội thoại, lưu closed_at và phát ConversationClosed. */
    public function close(Conversation $conversation, User $actor): Conversation { return $this->transition($conversation, ConversationStatus::CLOSED, ConversationAction::CLOSE, $actor, ['closed_at' => now()], ConversationClosed::class); }
    /** Mở lại hội thoại về trạng thái chờ khách và xóa các mốc đóng/giải quyết. */
    public function reopen(Conversation $conversation, User $actor): Conversation { return $this->transition($conversation, ConversationStatus::CUSTOMER_WAITING, ConversationAction::REOPEN, $actor, ['closed_at' => null], ConversationReopened::class); }

    /** Thực hiện chuyển trạng thái và ghi nhận các mốc liên quan. */
    private function transition(Conversation $conversation, string $status, string $action, User $actor, array $extra, string $event): Conversation
    {
        return DB::transaction(function () use ($conversation, $status, $action, $actor, $extra, $event): Conversation {
            $conversation = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $old = ['status' => $conversation->status];
            $this->states->assertCanTransition($conversation->status, $status);
            $conversation->forceFill([...$extra, 'status' => $status])->save();
            $conversation = $conversation->refresh()->load(['customer.channels', 'assignee', 'tags']);
            $this->activities->record($conversation, $action, $old, ['status' => $conversation->status], $actor);
            event(new $event($conversation, $actor));
            return $conversation;
        });
    }
}
