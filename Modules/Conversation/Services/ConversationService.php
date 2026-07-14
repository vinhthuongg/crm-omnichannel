<?php

namespace Modules\Conversation\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\ActivityLog\Services\ActivityLogService;
use Modules\Conversation\Events\ConversationAssigned;
use Modules\Conversation\Events\ConversationClaimed;
use Modules\Conversation\Events\ConversationClosed;
use Modules\Conversation\Events\ConversationReleased;
use Modules\Conversation\Events\ConversationReopened;
use Modules\Conversation\Events\ConversationResolved;
use Modules\Conversation\Events\ConversationTransferred;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Models\ConversationActivity;
use Modules\Conversation\Models\Tag;
use Modules\Conversation\Support\AssignmentType;
use Modules\Conversation\Support\ConversationAction;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Botpress\Jobs\ResumeBotAfterIdleJob;
use Modules\Message\Jobs\SendCustomerIdleFollowUpJob;
use Modules\Message\Models\Message;

class ConversationService
{
    public function __construct(
        private readonly ActivityLogService $activityLog,
        private readonly AssignableUserService $assignableUsers,
        private readonly ConversationStateMachine $states,
        private readonly WorkShiftService $shifts,
    ) {
    }

    public function assign(Conversation $conversation, int $userId, User $actor): Conversation
    {
        $assignee = $this->assignableUsers->findAssignable($userId);

        if ($conversation->assigned_to && (int) $conversation->assigned_to !== (int) $assignee->id && ! $actor->can('conversation.transfer')) {
            throw new \RuntimeException('Hoi thoai da co nhan vien phu trach. Ban can quyen chuyen giao de ghi de.');
        }

        return $this->applyAssignment(
            $conversation,
            $assignee,
            $actor,
            $conversation->assigned_to && (int) $conversation->assigned_to !== (int) $assignee->id
                ? AssignmentType::TRANSFER
                : AssignmentType::MANUAL,
        );
    }

    public function claim(Conversation $conversation, User $actor): Conversation
    {
        $this->assignableUsers->findAssignable((int) $actor->id);

        if (! $this->shifts->userBelongsToShift($actor, $conversation->queue_shift_id)) {
            throw new \RuntimeException('Ban khong thuoc ca truc dang chiu trach nhiem hoi thoai nay.');
        }

        $this->states->assertCanTransition($conversation->status, ConversationStatus::CUSTOMER_WAITING);

        $updated = Conversation::query()
            ->whereKey($conversation->id)
            ->whereNull('assigned_to')
            ->update([
                'assigned_to' => $actor->id,
                'assigned_by' => null,
                'assigned_type' => AssignmentType::CLAIM,
                'claimed_at' => now(),
                'status' => ConversationStatus::CUSTOMER_WAITING,
                'updated_at' => now(),
            ]);

        if (! $updated) {
            throw new \RuntimeException('Hoi thoai da co nhan vien khac nhan xu ly.');
        }

        $conversation = $conversation->refresh()->load(['customer.channels', 'assignee', 'tags']);
        $this->rememberHandledUser($conversation, $actor);
        $this->recordActivity($conversation, ConversationAction::CLAIM, ['assigned_to' => null], $this->assignmentSnapshot($conversation), $actor);
        $this->activityLog->record($actor, 'conversation.claimed', $conversation, ['assigned_to' => $actor->id]);
        event(new ConversationClaimed($conversation, $actor));

        return $conversation;
    }

    public function transfer(Conversation $conversation, int $userId, User $actor): Conversation
    {
        if (! $actor->can('conversation.transfer')) {
            throw new \RuntimeException('Ban khong co quyen chuyen giao hoi thoai.');
        }

        $assignee = $this->assignableUsers->findAssignable($userId);

        return $this->applyAssignment($conversation, $assignee, $actor, AssignmentType::TRANSFER);
    }

    public function release(Conversation $conversation, User $actor): Conversation
    {
        return DB::transaction(function () use ($conversation, $actor): Conversation {
            $conversation = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $old = $this->assignmentSnapshot($conversation);
            $this->states->assertCanTransition($conversation->status, ConversationStatus::CUSTOMER_WAITING);

            $conversation->forceFill([
                'assigned_to' => null,
                'assigned_by' => null,
                'assigned_type' => null,
                'claimed_at' => null,
                'status' => ConversationStatus::CUSTOMER_WAITING,
            ])->save();

            $conversation = $conversation->refresh()->load(['customer.channels', 'assignee', 'tags']);
            $this->recordActivity($conversation, ConversationAction::RELEASE, $old, $this->assignmentSnapshot($conversation), $actor);
            $this->activityLog->record($actor, 'conversation.released', $conversation);
            event(new ConversationReleased($conversation, $actor));

            return $conversation;
        });
    }

    public function resolve(Conversation $conversation, User $actor): Conversation
    {
        return $this->transition($conversation, ConversationStatus::CLOSED, ConversationAction::RESOLVE, $actor, [
            'resolved_at' => now(),
        ], ConversationResolved::class);
    }

    public function close(Conversation $conversation, User $actor): Conversation
    {
        return $this->transition($conversation, ConversationStatus::CLOSED, ConversationAction::CLOSE, $actor, [
            'closed_at' => now(),
        ], ConversationClosed::class);
    }

    public function reopen(Conversation $conversation, User $actor): Conversation
    {
        return $this->transition($conversation, ConversationStatus::CUSTOMER_WAITING, ConversationAction::REOPEN, $actor, [
            'closed_at' => null,
        ], ConversationReopened::class);
    }

    public function syncTags(Conversation $conversation, array $tags, User $actor): Conversation
    {
        Tag::ensureDefaults();

        $ids = collect($tags)
            ->take(1)
            ->map(fn (array $tag) => trim((string) ($tag['name'] ?? '')))
            ->filter()
            ->map(fn (string $name) => Tag::query()->where('name', $name)->value('id'))
            ->filter()
            ->all();
        $conversation->tags()->sync($ids);
        $this->activityLog->record($actor, 'conversation.tagged', $conversation, ['tags' => $ids]);
        return $conversation->load(['customer.channels', 'assignee', 'tags']);
    }

    public function recordOutboundMessage(Conversation $conversation, Message $message, bool $isWhisper = false): void
    {
        $automationState = (array) ($conversation->automation_state ?? []);

        if (! $isWhisper) {
            $automationState = [
                ...$automationState,
                'paused_by_user_at' => $message->created_at?->toISOString() ?? now()->toISOString(),
                'paused_by_user_message_id' => $message->id,
            ];
        }

        $conversation->forceFill([
            'last_message_at' => $message->created_at,
            'last_read_at' => now(),
            'status' => in_array(ConversationStatus::normalize($conversation->status), [ConversationStatus::CLOSED], true)
                ? ConversationStatus::CLOSED
                : ConversationStatus::WAITING_CUSTOMER,
            'first_response_at' => $isWhisper ? $conversation->first_response_at : ($conversation->first_response_at ?: now()),
            'unread_messages_count' => 0,
            'automation_state' => $automationState,
        ])->save();

        if (! $isWhisper) {
            ResumeBotAfterIdleJob::dispatchFor($message, 'agent_reply');
            SendCustomerIdleFollowUpJob::dispatchFor($message);
        }
    }

    public function pauseAutomationForFacebookEcho(Conversation $conversation, Message $message): void
    {
        $automationState = (array) ($conversation->automation_state ?? []);
        $automationState = [
            ...$automationState,
            'paused_by_user_at' => $message->created_at?->toISOString() ?? now()->toISOString(),
            'paused_by_user_message_id' => $message->id,
            'paused_by_echo_at' => $message->created_at?->toISOString() ?? now()->toISOString(),
            'paused_by_echo_message_id' => $message->id,
        ];

        $conversation->forceFill([
            'last_message_at' => $message->created_at,
            'last_read_at' => now(),
            'status' => in_array(ConversationStatus::normalize($conversation->status), [ConversationStatus::CLOSED], true)
                ? ConversationStatus::CLOSED
                : ConversationStatus::WAITING_CUSTOMER,
            'unread_messages_count' => 0,
            'automation_state' => $automationState,
        ])->save();

        ResumeBotAfterIdleJob::dispatchFor($message, 'facebook_echo');
    }

    private function applyAssignment(Conversation $conversation, User $assignee, User $actor, string $type): Conversation
    {
        return DB::transaction(function () use ($conversation, $assignee, $actor, $type): Conversation {
            $conversation = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $old = $this->assignmentSnapshot($conversation);
            $this->states->assertCanTransition($conversation->status, ConversationStatus::CUSTOMER_WAITING);

            $conversation->forceFill([
                'assigned_to' => $assignee->id,
                'assigned_by' => $actor->id,
                'assigned_type' => $type,
                'claimed_at' => now(),
                'status' => ConversationStatus::CUSTOMER_WAITING,
            ])->save();

            $conversation = $conversation->refresh()->load(['customer.channels', 'assignee', 'tags']);
            $this->rememberHandledUser($conversation, $assignee);
            $action = $type === AssignmentType::TRANSFER ? ConversationAction::TRANSFER : ConversationAction::ASSIGN;
            $this->recordActivity($conversation, $action, $old, $this->assignmentSnapshot($conversation), $actor);
            $this->activityLog->record($actor, 'conversation.'.$action, $conversation, ['assigned_to' => $assignee->id]);
            event($type === AssignmentType::TRANSFER
                ? new ConversationTransferred($conversation, $actor)
                : new ConversationAssigned($conversation, $actor));

            return $conversation;
        });
    }

    private function transition(Conversation $conversation, string $status, string $action, User $actor, array $extra, string $eventClass): Conversation
    {
        return DB::transaction(function () use ($conversation, $status, $action, $actor, $extra, $eventClass): Conversation {
            $conversation = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $old = ['status' => $conversation->status];
            $this->states->assertCanTransition($conversation->status, $status);

            $conversation->forceFill([
                ...$extra,
                'status' => $status,
            ])->save();

            $conversation = $conversation->refresh()->load(['customer.channels', 'assignee', 'tags']);
            $this->recordActivity($conversation, $action, $old, ['status' => $conversation->status], $actor);
            $this->activityLog->record($actor, 'conversation.'.$action, $conversation);
            event(new $eventClass($conversation, $actor));

            return $conversation;
        });
    }

    private function assignmentSnapshot(Conversation $conversation): array
    {
        return [
            'assigned_to' => $conversation->assigned_to ? (int) $conversation->assigned_to : null,
            'assigned_by' => $conversation->assigned_by ? (int) $conversation->assigned_by : null,
            'assigned_type' => $conversation->assigned_type,
            'claimed_at' => $conversation->claimed_at?->toISOString(),
            'status' => $conversation->status,
        ];
    }

    private function rememberHandledUser(Conversation $conversation, User $user): void
    {
        $conversation->handledUsers()->syncWithoutDetaching([
            $user->id => [
                'first_handled_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    private function recordActivity(Conversation $conversation, string $action, array $old, array $new, ?User $actor): void
    {
        ConversationActivity::query()->create([
            'conversation_id' => $conversation->id,
            'action' => $action,
            'old_value' => $old,
            'new_value' => $new,
            'performed_by' => $actor?->id,
        ]);
    }
}
