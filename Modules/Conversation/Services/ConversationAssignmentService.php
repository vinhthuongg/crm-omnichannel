<?php

namespace Modules\Conversation\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Conversation\Events\ConversationAssigned;
use Modules\Conversation\Events\ConversationClaimed;
use Modules\Conversation\Events\ConversationReleased;
use Modules\Conversation\Events\ConversationTransferred;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Support\AssignmentType;
use Modules\Conversation\Support\ConversationAction;
use Modules\Conversation\Support\ConversationStatus;

class ConversationAssignmentService
{
    /** Nhận AssignableUserService để kiểm tra nhân viên có thể nhận hội thoại; ConversationStateMachine để kiểm tra chuyển trạng thái hội thoại hợp lệ; WorkShiftService để xác định ca trực và thành viên đang hoạt động; ConversationActivityRecorder để ghi lịch sử phân công và thay đổi trạng thái. */
    public function __construct(private readonly AssignableUserService $users, private readonly ConversationStateMachine $states,
        private readonly WorkShiftService $shifts, private readonly ConversationActivityRecorder $activities) {}

    /** Kiểm tra người nhận hợp lệ rồi giao hội thoại theo kiểu phân công thủ công. */
    public function assign(Conversation $conversation, int $userId, User $actor): Conversation
    {
        $assignee = $this->users->findAssignable($userId);
        if ($conversation->assigned_to && (int) $conversation->assigned_to !== (int) $assignee->id && ! $actor->can('conversation.transfer'))
            throw new \RuntimeException('Hoi thoai da co nhan vien phu trach. Ban can quyen chuyen giao de ghi de.');
        $type = $conversation->assigned_to && (int) $conversation->assigned_to !== (int) $assignee->id ? AssignmentType::TRANSFER : AssignmentType::MANUAL;
        return $this->apply($conversation, $assignee, $actor, $type);
    }

    /** Cho nhân viên tự nhận hội thoại nếu chưa có người phụ trách hoặc ca cũ đã kết thúc. */
    public function claim(Conversation $conversation, User $actor): Conversation
    {
        $this->users->findAssignable((int) $actor->id);
        if (! $this->shifts->userBelongsToShift($actor, $conversation->queue_shift_id))
            throw new \RuntimeException('Ban khong thuoc ca truc dang chiu trach nhiem hoi thoai nay.');
        $this->states->assertCanTransition($conversation->status, ConversationStatus::CUSTOMER_WAITING);
        $updated = Conversation::query()->whereKey($conversation->id)->whereNull('assigned_to')->update([
            'assigned_to' => $actor->id, 'assigned_by' => null, 'assigned_type' => AssignmentType::CLAIM,
            'claimed_at' => now(), 'status' => ConversationStatus::CUSTOMER_WAITING, 'updated_at' => now()]);
        if (! $updated) throw new \RuntimeException('Hoi thoai da co nhan vien khac nhan xu ly.');
        $conversation = $conversation->refresh()->load(['customer.channels', 'assignee', 'tags']);
        $this->activities->rememberHandler($conversation, $actor);
        $this->activities->record($conversation, ConversationAction::CLAIM, ['assigned_to' => null], $this->activities->snapshot($conversation),
            $actor, 'conversation.claimed', ['assigned_to' => $actor->id]);
        event(new ConversationClaimed($conversation, $actor));
        return $conversation;
    }

    /** Kiểm tra người nhận mới và chuyển hội thoại khỏi nhân viên hiện tại. */
    public function transfer(Conversation $conversation, int $userId, User $actor): Conversation
    {
        if (! $actor->can('conversation.transfer')) throw new \RuntimeException('Ban khong co quyen chuyen giao hoi thoai.');
        return $this->apply($conversation, $this->users->findAssignable($userId), $actor, AssignmentType::TRANSFER);
    }

    /** Gỡ người phụ trách, đưa hội thoại về hàng chờ và lưu lịch sử người từng xử lý. */
    public function release(Conversation $conversation, User $actor): Conversation
    {
        return DB::transaction(function () use ($conversation, $actor): Conversation {
            $conversation = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $old = $this->activities->snapshot($conversation);
            $this->states->assertCanTransition($conversation->status, ConversationStatus::CUSTOMER_WAITING);
            $conversation->forceFill(['assigned_to' => null, 'assigned_by' => null, 'assigned_type' => null,
                'claimed_at' => null, 'status' => ConversationStatus::CUSTOMER_WAITING])->save();
            $conversation = $conversation->refresh()->load(['customer.channels', 'assignee', 'tags']);
            $this->activities->record($conversation, ConversationAction::RELEASE, $old, $this->activities->snapshot($conversation),
                $actor, 'conversation.released', []);
            event(new ConversationReleased($conversation, $actor));
            return $conversation;
        });
    }

    /** Áp dụng thay đổi nghiệp vụ trong transaction. */
    private function apply(Conversation $conversation, User $assignee, User $actor, string $type): Conversation
    {
        return DB::transaction(function () use ($conversation, $assignee, $actor, $type): Conversation {
            $conversation = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $old = $this->activities->snapshot($conversation);
            $this->states->assertCanTransition($conversation->status, ConversationStatus::CUSTOMER_WAITING);
            $conversation->forceFill(['assigned_to' => $assignee->id, 'assigned_by' => $actor->id, 'assigned_type' => $type,
                'claimed_at' => now(), 'status' => ConversationStatus::CUSTOMER_WAITING])->save();
            $conversation = $conversation->refresh()->load(['customer.channels', 'assignee', 'tags']);
            $this->activities->rememberHandler($conversation, $assignee);
            $action = $type === AssignmentType::TRANSFER ? ConversationAction::TRANSFER : ConversationAction::ASSIGN;
            $this->activities->record($conversation, $action, $old, $this->activities->snapshot($conversation), $actor);
            event($type === AssignmentType::TRANSFER ? new ConversationTransferred($conversation, $actor) : new ConversationAssigned($conversation, $actor));
            return $conversation;
        });
    }
}
