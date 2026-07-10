<?php

namespace Modules\Mobile\Http\Controllers;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Validation\ValidationException;
use Modules\Conversation\Actions\AssignConversationAction;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Models\Tag;
use Modules\Conversation\Models\WorkShift;
use Modules\Conversation\Services\ConversationVisibilityService;
use Modules\Conversation\Services\WorkShiftService;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Customer\Models\Customer;
use Modules\Customer\Models\CustomerTag;
use Modules\Message\Actions\SendMessageAction;
use Modules\Message\Models\Message;
use Modules\Shared\Http\Controllers\ApiController;

class MobileApiController extends ApiController
{
    public function __construct(
        private readonly ConversationVisibilityService $visibility,
        private readonly WorkShiftService $shifts,
    )
    {
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->userPayload($request->user())]);
    }

    public function bootstrap(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'user' => $this->userPayload($user),
                'permissions' => $user->getAllPermissions()->pluck('name')->values(),
                'agents' => User::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get()
                    ->map(fn (User $agent): array => $this->agentPayload($agent))
                    ->values(),
                'tags' => Tag::query()
                    ->where('is_default', true)
                    ->orderBy('name')
                    ->get(['id', 'name', 'color', 'is_default'])
                    ->map(fn (Tag $tag): array => $this->tagPayload($tag))
                    ->values(),
                'channels' => ['facebook', 'zalo'],
                'realtime' => $this->realtimePayload($request),
            ],
        ]);
    }

    public function broadcastAuth(Request $request)
    {
        $request->validate([
            'socket_id' => ['required', 'string'],
            'channel_name' => ['required', 'string'],
        ]);

        return Broadcast::auth($request);
    }

    public function conversations(Request $request): JsonResponse
    {
        $query = $this->visibility->visibleFor($request->user())
            ->with(['customer.channels', 'assignee', 'tags'])
            ->when($request->filled('status'), fn (Builder $query): Builder => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('assigned_to'), fn (Builder $query): Builder => $query->where('assigned_to', $request->integer('assigned_to')))
            ->when($request->boolean('unread'), fn (Builder $query): Builder => $query->where('unread_messages_count', '>', 0))
            ->when($request->filled('q'), function (Builder $query) use ($request): void {
                $keyword = '%'.$request->string('q')->toString().'%';

                $query->where(function (Builder $query) use ($keyword): void {
                    $query->whereHas('customer', function (Builder $customer) use ($keyword): void {
                        $customer->where('name', 'like', $keyword)
                            ->orWhere('phone', 'like', $keyword)
                            ->orWhere('email', 'like', $keyword);
                    })->orWhereHas('messages', fn (Builder $message): Builder => $message->where('content', 'like', $keyword));
                });
            })
            ->latest('last_message_at')
            ->paginate($this->perPage($request, 20, 100));

        return response()->json([
            'data' => $query->getCollection()->map(fn (Conversation $conversation): array => $this->conversationPayload($conversation))->values(),
            'meta' => $this->paginationPayload($query),
        ]);
    }

    public function conversation(Request $request, Conversation $conversation): JsonResponse
    {
        abort_unless($this->visibility->canView($request->user(), $conversation), 403);

        $conversation->load(['customer.channels', 'assignee', 'tags']);

        return response()->json(['data' => $this->conversationPayload($conversation)]);
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        abort_unless($this->visibility->canView($request->user(), $conversation), 403);

        $query = $conversation->messages()
            ->with('sender')
            ->when($request->filled('before_id'), fn (Builder $query): Builder => $query->where('id', '<', $request->integer('before_id')))
            ->when($request->filled('after_id'), fn (Builder $query): Builder => $query->where('id', '>', $request->integer('after_id')))
            ->latest('id')
            ->paginate($this->perPage($request, 30, 100));

        return response()->json([
            'data' => $query->getCollection()
                ->reverse()
                ->map(fn (Message $message): array => $this->messagePayload($message))
                ->values(),
            'meta' => $this->paginationPayload($query),
        ]);
    }

    public function sendMessage(Request $request, Conversation $conversation, SendMessageAction $action): JsonResponse
    {
        abort_unless($this->visibility->canView($request->user(), $conversation), 403);
        abort_unless(
            $request->user()->can('conversation.view_all')
            || ($request->user()->can('conversation.reply') && (int) $conversation->assigned_to === (int) $request->user()->id),
            403
        );

        $data = $request->validate([
            'content' => ['required_without:attachments', 'nullable', 'string'],
            'message_type' => ['sometimes', 'string', 'max:32'],
            'attachments' => ['sometimes', 'array'],
            'channel' => ['nullable', 'in:facebook,zalo'],
        ]);

        $data['channel'] ??= $conversation->facebook_page_id ? 'facebook' : 'zalo';

        $message = $action->execute($conversation, $request->user(), $data);

        return response()->json(['data' => $this->messagePayload($message->load('sender'))], 201);
    }

    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        abort_unless($this->visibility->canView($request->user(), $conversation), 403);

        $conversation->markAsRead();

        return response()->json(['data' => $this->conversationPayload($conversation->fresh(['customer.channels', 'assignee', 'tags']))]);
    }

    public function assign(Request $request, Conversation $conversation, AssignConversationAction $action): JsonResponse
    {
        abort_unless($request->user()->can('conversation.assign') || $request->user()->can('conversation.transfer'), 403);

        $data = $request->validate(['assigned_to' => ['required', 'integer', 'exists:users,id']]);

        try {
            $conversation = $action->execute($conversation, (int) $data['assigned_to'], $request->user());
        } catch (\RuntimeException $exception) {
            abort(409, $exception->getMessage());
        }

        return response()->json(['data' => $this->conversationPayload($conversation->load(['customer.channels', 'assignee', 'tags']))]);
    }

    public function currentWorkShift(Request $request): JsonResponse
    {
        $shift = $this->shifts->currentShiftFor($request->user());

        return response()->json([
            'data' => $shift ? $this->workShiftPayload($shift->load('agents')) : null,
        ]);
    }

    public function workShiftOverview(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('user.manage'), 403);

        $shifts = WorkShift::query()
            ->with('agents')
            ->orderBy('starts_at')
            ->limit(100)
            ->get();
        $currentShift = $shifts->first(fn (WorkShift $shift): bool => $this->workShiftContainsNow($shift));
        $nextShift = $shifts->first(fn (WorkShift $shift): bool => $shift->is_active && $shift->starts_at && $shift->starts_at->greaterThan(now()));
        $selectedShift = $shifts->firstWhere('id', (int) $request->query('shift_id')) ?: $currentShift ?: $nextShift ?: $shifts->first();
        $manageStatus = $this->manageStatus($request);
        $managedShifts = $shifts
            ->filter(fn (WorkShift $shift): bool => $this->matchesManageStatus($shift, $manageStatus))
            ->values();
        $busyAgentIds = $this->busyAgentIds();
        $agents = $this->workShiftAgents();

        return response()->json([
            'data' => [
                'current_shift' => $currentShift ? $this->workShiftPayload($currentShift) : null,
                'next_shift' => $nextShift ? $this->workShiftPayload($nextShift) : null,
                'selected_shift' => $selectedShift ? $this->workShiftPayload($selectedShift) : null,
                'shifts' => $shifts->map(fn (WorkShift $shift): array => $this->workShiftPayload($shift))->values(),
                'managed_shifts' => $managedShifts->map(fn (WorkShift $shift): array => $this->workShiftPayload($shift))->values(),
                'staff_members' => $selectedShift ? $this->staffMembersForShift($selectedShift) : [],
                'staff_metrics' => $selectedShift ? $this->workShiftMetrics($selectedShift) : $this->emptyWorkShiftMetrics(),
                'agents' => $agents->map(fn (User $agent): array => $this->agentPayload($agent))->values(),
                'create_agents' => $agents
                    ->reject(fn (User $agent): bool => $busyAgentIds->contains($agent->id))
                    ->map(fn (User $agent): array => $this->agentPayload($agent))
                    ->values(),
                'busy_agent_ids' => $busyAgentIds,
                'manage_status' => $manageStatus,
            ],
        ]);
    }

    public function workShifts(Request $request): JsonResponse
    {
        $query = WorkShift::query()
            ->with('agents')
            ->when(! $request->user()->can('user.manage'), fn (Builder $query): Builder => $query
                ->whereHas('agents', fn (Builder $agents): Builder => $agents->whereKey($request->user()->id)))
            ->when($request->filled('status'), function (Builder $query) use ($request): void {
                match ($request->string('status')->toString()) {
                    'active' => $query->where('is_active', true),
                    'inactive' => $query->where('is_active', false),
                    'current' => $query
                        ->where('is_active', true)
                        ->where('starts_at', '<=', now())
                        ->where('ends_at', '>', now()),
                    default => null,
                };
            })
            ->orderByDesc('starts_at')
            ->paginate($this->perPage($request, 20, 100));

        return response()->json([
            'data' => $query->getCollection()->map(fn (WorkShift $shift): array => $this->workShiftPayload($shift))->values(),
            'meta' => $this->paginationPayload($query),
        ]);
    }

    public function workShift(Request $request, WorkShift $workShift): JsonResponse
    {
        abort_unless(
            $request->user()->can('user.manage')
            || $workShift->agents()->whereKey($request->user()->id)->exists(),
            403
        );

        return response()->json(['data' => $this->workShiftPayload($workShift->load('agents'))]);
    }

    public function storeWorkShift(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('user.manage'), 403);

        $validated = $this->validatedWorkShift($request);
        $this->ensureAgentsAvailable($validated['agent_ids']);

        $shift = WorkShift::query()->create($this->workShiftAttributes($validated, $request->boolean('is_active', true)));
        $shift->agents()->sync($validated['agent_ids']);

        return response()->json(['data' => $this->workShiftPayload($shift->load('agents'))], 201);
    }

    public function updateWorkShift(Request $request, WorkShift $workShift): JsonResponse
    {
        abort_unless($request->user()->can('user.manage'), 403);

        $validated = $this->validatedWorkShift($request);
        $this->ensureAgentsAvailable($validated['agent_ids'], $workShift);

        $workShift->update($this->workShiftAttributes($validated, $request->boolean('is_active')));
        $workShift->agents()->sync($validated['agent_ids']);

        return response()->json(['data' => $this->workShiftPayload($workShift->fresh('agents'))]);
    }

    public function destroyWorkShift(Request $request, WorkShift $workShift): JsonResponse
    {
        abort_unless($request->user()->can('user.manage'), 403);

        $workShift->delete();

        return response()->json(status: 204);
    }

    public function agentPerformance(Request $request): JsonResponse
    {
        [$startsAt, $endsAt] = $this->performanceDateRange($request);
        $workShiftId = $request->filled('work_shift_id') ? $request->integer('work_shift_id') : null;

        $agents = User::query()
            ->where('is_active', true)
            ->when(
                ! $request->user()->can('report.view') && ! $request->user()->can('user.manage'),
                fn (Builder $query): Builder => $query->whereKey($request->user()->id),
            )
            ->where(function (Builder $query): void {
                $query->role(['Admin', 'CSKH', 'User'])
                    ->orWhereHas('assignedConversations');
            })
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => [
                'range' => [
                    'starts_at' => $startsAt->toISOString(),
                    'ends_at' => $endsAt->toISOString(),
                    'work_shift_id' => $workShiftId,
                ],
                'summary' => $this->agentPerformanceSummary($agents, $startsAt, $endsAt, $workShiftId),
                'agents' => $agents
                    ->map(fn (User $agent): array => $this->agentPerformancePayload($agent, $startsAt, $endsAt, $workShiftId))
                    ->values(),
            ],
        ]);
    }

    public function agentPerformanceDetail(Request $request, User $agent): JsonResponse
    {
        abort_unless(
            $request->user()->can('report.view')
            || $request->user()->can('user.manage')
            || (int) $request->user()->id === (int) $agent->id,
            403
        );

        [$startsAt, $endsAt] = $this->performanceDateRange($request);
        $workShiftId = $request->filled('work_shift_id') ? $request->integer('work_shift_id') : null;

        return response()->json([
            'data' => [
                'range' => [
                    'starts_at' => $startsAt->toISOString(),
                    'ends_at' => $endsAt->toISOString(),
                    'work_shift_id' => $workShiftId,
                ],
                'agent' => $this->agentPerformancePayload($agent, $startsAt, $endsAt, $workShiftId),
            ],
        ]);
    }

    public function customers(Request $request): JsonResponse
    {
        $visibleCustomerIds = $this->visibility->visibleFor($request->user())->select('customer_id');

        $query = Customer::query()
            ->with('channels')
            ->whereIn('id', $visibleCustomerIds)
            ->when($request->filled('q'), function (Builder $query) use ($request): void {
                $keyword = '%'.$request->string('q')->toString().'%';

                $query->where(function (Builder $query) use ($keyword): void {
                    $query->where('name', 'like', $keyword)
                        ->orWhere('phone', 'like', $keyword)
                        ->orWhere('email', 'like', $keyword)
                        ->orWhereHas('channels', fn (Builder $channel): Builder => $channel->where('external_id', 'like', $keyword));
                });
            })
            ->latest('updated_at')
            ->paginate($this->perPage($request, 20, 100));

        return response()->json([
            'data' => $query->getCollection()->map(fn (Customer $customer): array => $this->customerPayload($customer))->values(),
            'meta' => $this->paginationPayload($query),
        ]);
    }

    public function customer(Request $request, Customer $customer): JsonResponse
    {
        $visibleConversations = $this->visibleCustomerConversations($request, $customer);

        abort_unless($visibleConversations->isNotEmpty(), 403);

        $customer->load(['channels', 'tags']);

        return response()->json(['data' => $this->customerDetailPayload($customer, $visibleConversations)]);
    }

    public function customerTags(Request $request): JsonResponse
    {
        Tag::ensureDefaults();

        return response()->json([
            'data' => Tag::query()
                ->where('is_default', true)
                ->orderBy('name')
                ->get(['id', 'name', 'color', 'is_default'])
                ->map(fn (Tag $tag): array => $this->tagPayload($tag))
                ->values(),
        ]);
    }

    public function attachCustomerTag(Request $request, Customer $customer): JsonResponse
    {
        $visibleConversations = $this->visibleCustomerConversations($request, $customer);
        abort_unless($visibleConversations->isNotEmpty(), 403);

        $validated = $request->validate([
            'tag_id' => ['required', 'integer', 'exists:tags,id'],
        ]);

        $tag = Tag::query()
            ->where('is_default', true)
            ->findOrFail($validated['tag_id']);
        $customerTag = CustomerTag::query()->firstOrCreate(
            ['name' => $tag->name],
            ['color' => $tag->color ?: '#2563eb'],
        );

        $customer->tags()->syncWithoutDetaching([$customerTag->id]);
        $customer->load(['channels', 'tags']);

        return response()->json([
            'data' => $this->customerDetailPayload($customer, $visibleConversations),
        ]);
    }

    public function detachCustomerTag(Request $request, Customer $customer, int $tagId): JsonResponse
    {
        $visibleConversations = $this->visibleCustomerConversations($request, $customer);
        abort_unless($visibleConversations->isNotEmpty(), 403);

        $customerTag = CustomerTag::query()->find($tagId);

        if (! $customerTag) {
            $systemTag = Tag::query()->find($tagId);
            $customerTag = $systemTag
                ? CustomerTag::query()->where('name', $systemTag->name)->first()
                : null;
        }

        if ($customerTag) {
            $customer->tags()->detach($customerTag->id);
        }

        $customer->load(['channels', 'tags']);

        return response()->json([
            'data' => $this->customerDetailPayload($customer, $visibleConversations),
        ]);
    }

    public function notifications(Request $request): JsonResponse
    {
        $query = $request->user()
            ->notifications()
            ->latest()
            ->paginate($this->perPage($request, 20, 100));

        return response()->json([
            'data' => $query->getCollection()->map(fn (DatabaseNotification $notification): array => $this->notificationPayload($notification))->values(),
            'meta' => $this->paginationPayload($query),
        ]);
    }

    public function markNotificationRead(Request $request, string $notification): JsonResponse
    {
        $request->user()->notifications()->whereKey($notification)->firstOrFail()->markAsRead();

        return response()->json(status: 204);
    }

    public function markAllNotificationsRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(status: 204);
    }

    private function conversationPayload(Conversation $conversation): array
    {
        $lastMessage = $conversation->relationLoaded('messages') && $conversation->messages->isNotEmpty()
            ? $conversation->messages->sortByDesc('created_at')->first()
            : $conversation->messages()->latest('created_at')->first();

        return [
            'id' => (int) $conversation->id,
            'status' => $conversation->status,
            'channel' => $conversation->facebook_page_id ? 'facebook' : 'zalo',
            'facebook_page_id' => $conversation->facebook_page_id,
            'unread_messages_count' => (int) $conversation->unread_messages_count,
            'last_message_at' => $conversation->last_message_at?->toISOString(),
            'last_read_at' => $conversation->last_read_at?->toISOString(),
            'customer' => $conversation->customer ? $this->customerPayload($conversation->customer) : null,
            'assignee' => $conversation->assignee ? $this->agentPayload($conversation->assignee) : null,
            'tags' => $conversation->tags->map(fn (Tag $tag): array => $this->tagPayload($tag))->values(),
            'last_message' => $lastMessage ? $this->messagePayload($lastMessage) : null,
            'created_at' => $conversation->created_at?->toISOString(),
            'updated_at' => $conversation->updated_at?->toISOString(),
        ];
    }

    private function messagePayload(Message $message): array
    {
        return [
            'id' => (int) $message->id,
            'conversation_id' => (int) $message->conversation_id,
            'sender_type' => $message->sender_type,
            'sender_id' => $message->sender_id ? (int) $message->sender_id : null,
            'sender_name' => $message->senderName(),
            'channel' => $message->channel,
            'content' => $message->recalled_at ? null : $message->content,
            'message_type' => $message->message_type,
            'attachments' => $message->recalled_at ? [] : $this->attachmentsPayload($message),
            'outbound_status' => $message->outbound_status,
            'outbound_error' => $message->outbound_error,
            'external_message_id' => $message->external_message_id,
            'is_recalled' => (bool) $message->recalled_at,
            'sent_at' => $message->sent_at?->toISOString(),
            'read_at' => $message->read_at?->toISOString(),
            'created_at' => $message->created_at?->toISOString(),
        ];
    }

    private function attachmentsPayload(Message $message): array
    {
        return collect($message->attachments ?? [])
            ->reject(fn (array $attachment): bool => ($attachment['type'] ?? '') === 'quick_reply')
            ->map(function (array $attachment): array {
                $url = (string) (
                    data_get($attachment, 'payload.image_data.url')
                    ?: data_get($attachment, 'payload.video_data.url')
                    ?: data_get($attachment, 'payload.audio_data.url')
                    ?: data_get($attachment, 'url')
                    ?: data_get($attachment, 'payload.url')
                    ?: data_get($attachment, 'payload.file_url')
                );

                return [
                    'type' => (string) ($attachment['type'] ?? 'file'),
                    'name' => (string) ($attachment['name'] ?? basename((string) parse_url($url, PHP_URL_PATH))),
                    'url' => $url,
                    'mime_type' => (string) ($attachment['mime_type'] ?? ''),
                    'payload' => $attachment['payload'] ?? null,
                ];
            })
            ->filter(fn (array $attachment): bool => $attachment['url'] !== '')
            ->values()
            ->all();
    }

    private function customerPayload(Customer $customer): array
    {
        return [
            'id' => (int) $customer->id,
            'name' => $customer->name,
            'avatar' => $customer->avatar,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'channels' => $customer->relationLoaded('channels')
                ? $customer->channels->map(fn ($channel): array => [
                    'id' => (int) $channel->id,
                    'channel' => $channel->channel,
                    'external_id' => $channel->external_id,
                    'metadata' => $channel->metadata,
                ])->values()
                : [],
            'created_at' => $customer->created_at?->toISOString(),
            'updated_at' => $customer->updated_at?->toISOString(),
        ];
    }

    private function customerDetailPayload(Customer $customer, $visibleConversations): array
    {
        return [
            'information' => [
                'id' => (int) $customer->id,
                'name' => $customer->name,
                'avatar' => $customer->avatar,
                'phone' => $customer->phone,
                'email' => $customer->email,
            ],
            'channels' => $customer->channels->map(fn ($channel): array => [
                'id' => (int) $channel->id,
                'channel' => $channel->channel,
                'external_id' => $channel->external_id,
            ])->values(),
            'interests' => $this->customerInterestPayload($customer, $visibleConversations),
        ];
    }

    private function visibleCustomerConversations(Request $request, Customer $customer)
    {
        return $this->visibility->visibleFor($request->user())
            ->where('customer_id', $customer->id)
            ->with('tags')
            ->get();
    }

    private function customerInterestPayload(Customer $customer, $visibleConversations)
    {
        return $customer->tags
            ->map(fn ($tag): array => [
                'id' => (int) $tag->id,
                'source' => 'customer',
                'name' => $tag->name,
                'color' => $tag->color,
            ])
            ->filter(fn (array $tag): bool => filled($tag['name']))
            ->unique(fn (array $tag): string => mb_strtolower((string) $tag['name']))
            ->values();
    }

    private function agentPayload(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => (bool) $user->is_active,
            'roles' => $user->relationLoaded('roles') ? $user->roles->pluck('name')->values() : $user->getRoleNames()->values(),
        ];
    }

    private function userPayload(User $user): array
    {
        return [
            ...$this->agentPayload($user->loadMissing('roles')),
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
        ];
    }

    private function agentPerformanceSummary($agents, Carbon $startsAt, Carbon $endsAt, ?int $workShiftId): array
    {
        $payloads = $agents->map(fn (User $agent): array => $this->agentPerformancePayload($agent, $startsAt, $endsAt, $workShiftId));

        $totalConversations = (int) $payloads->sum('conversations.total');
        $respondedConversations = (int) $payloads->sum('response.responded_conversations');
        $phoneConversations = (int) $payloads->sum('phone.conversations_with_phone');
        $taggedConversations = (int) $payloads->sum('process.tagged_conversations');
        $classifiedConversations = (int) $payloads->sum('process.classified_conversations');
        $notedConversations = (int) $payloads->sum('process.noted_conversations');
        $weightedResponseSeconds = (int) $payloads->sum(
            fn (array $payload): int => (int) ($payload['response']['average_seconds'] ?? 0)
                * (int) ($payload['response']['responded_conversations'] ?? 0)
        );

        return [
            'total_agents' => $agents->count(),
            'total_conversations' => $totalConversations,
            'responded_conversations' => $respondedConversations,
            'average_response_seconds' => $respondedConversations > 0 ? (int) round($weightedResponseSeconds / $respondedConversations) : null,
            'average_response_label' => $respondedConversations > 0 ? $this->durationLabel((int) round($weightedResponseSeconds / $respondedConversations)) : 'Chưa có',
            'phone_collected' => [
                'conversations' => $phoneConversations,
                'rate' => $this->percentage($phoneConversations, $totalConversations),
            ],
            'process_compliance' => [
                'tagged_rate' => $this->percentage($taggedConversations, $totalConversations),
                'classified_rate' => $this->percentage($classifiedConversations, $totalConversations),
                'noted_rate' => $this->percentage($notedConversations, $totalConversations),
                'overall_rate' => $this->percentage($taggedConversations + $classifiedConversations + $notedConversations, $totalConversations * 3),
            ],
        ];
    }

    private function agentPerformancePayload(User $agent, Carbon $startsAt, Carbon $endsAt, ?int $workShiftId): array
    {
        $base = $this->agentPerformanceConversationQuery($agent, $startsAt, $endsAt, $workShiftId);
        $totalConversations = (clone $base)->count();
        $respondedConversations = (clone $base)->whereNotNull('first_response_at')->count();
        $averageResponseSeconds = (clone $base)
            ->whereNotNull('first_response_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, created_at, first_response_at)) as average_seconds')
            ->value('average_seconds');
        $phoneConversations = (clone $base)
            ->whereHas('customer', fn (Builder $query): Builder => $query
                ->whereNotNull('phone')
                ->where('phone', '!=', ''))
            ->count();
        $taggedConversations = (clone $base)
            ->whereHas('tags')
            ->count();
        $classifiedConversations = (clone $base)
            ->where(function (Builder $query): void {
                $query->whereHas('tags')
                    ->orWhereHas('customer.tags');
            })
            ->count();
        $notedConversations = (clone $base)
            ->whereHas('customer.notes', fn (Builder $query): Builder => $query
                ->where('user_id', $agent->id)
                ->whereBetween('created_at', [$startsAt, $endsAt]))
            ->count();
        $sentMessages = Message::query()
            ->where('sender_type', 'user')
            ->where('sender_id', $agent->id)
            ->whereBetween('created_at', [$startsAt, $endsAt])
            ->count();

        $responseSeconds = $averageResponseSeconds === null ? null : max(0, (int) round((float) $averageResponseSeconds));

        return [
            'agent' => $this->agentPayload($agent),
            'conversations' => [
                'total' => $totalConversations,
                'active' => (clone $base)->whereIn('status', ConversationStatus::ACTIVE)->count(),
                'waiting' => (clone $base)->where('status', ConversationStatus::WAITING)->count(),
                'finished' => (clone $base)->whereIn('status', [ConversationStatus::RESOLVED, ConversationStatus::CLOSED])->count(),
                'sent_messages' => $sentMessages,
            ],
            'response' => [
                'responded_conversations' => $respondedConversations,
                'average_seconds' => $responseSeconds,
                'average_minutes' => $responseSeconds === null ? null : round($responseSeconds / 60, 2),
                'label' => $responseSeconds === null ? 'Chưa có' : $this->durationLabel($responseSeconds),
                'rate' => $this->percentage($respondedConversations, $totalConversations),
            ],
            'phone' => [
                'conversations_with_phone' => $phoneConversations,
                'rate' => $this->percentage($phoneConversations, $totalConversations),
            ],
            'process' => [
                'tagged_conversations' => $taggedConversations,
                'classified_conversations' => $classifiedConversations,
                'noted_conversations' => $notedConversations,
                'tagged_rate' => $this->percentage($taggedConversations, $totalConversations),
                'classified_rate' => $this->percentage($classifiedConversations, $totalConversations),
                'noted_rate' => $this->percentage($notedConversations, $totalConversations),
                'overall_rate' => $this->percentage($taggedConversations + $classifiedConversations + $notedConversations, $totalConversations * 3),
            ],
        ];
    }

    private function agentPerformanceConversationQuery(User $agent, Carbon $startsAt, Carbon $endsAt, ?int $workShiftId): Builder
    {
        return Conversation::query()
            ->where('assigned_to', $agent->id)
            ->whereBetween('created_at', [$startsAt, $endsAt])
            ->when($workShiftId, fn (Builder $query): Builder => $query
                ->where(function (Builder $query) use ($workShiftId): void {
                    $query->where('owner_shift_id', $workShiftId)
                        ->orWhere('queue_shift_id', $workShiftId)
                        ->orWhere('work_shift_id', $workShiftId);
                }));
    }

    private function performanceDateRange(Request $request): array
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'work_shift_id' => ['nullable', 'integer', 'exists:work_shifts,id'],
        ]);

        $startsAt = filled($validated['date_from'] ?? null)
            ? Carbon::parse($validated['date_from'])->startOfDay()
            : now()->startOfDay();
        $endsAt = filled($validated['date_to'] ?? null)
            ? Carbon::parse($validated['date_to'])->endOfDay()
            : now()->endOfDay();

        if ($endsAt->lessThan($startsAt)) {
            throw ValidationException::withMessages([
                'date_to' => 'Ngày kết thúc phải sau ngày bắt đầu.',
            ]);
        }

        return [$startsAt, $endsAt];
    }

    private function percentage(int $value, int $total): float
    {
        return $total > 0 ? round(($value / $total) * 100, 2) : 0.0;
    }

    private function durationLabel(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.' giây';
        }

        $minutes = intdiv($seconds, 60);
        $remainingSeconds = $seconds % 60;

        if ($minutes < 60) {
            return $remainingSeconds > 0
                ? $minutes.' phút '.$remainingSeconds.' giây'
                : $minutes.' phút';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return $remainingMinutes > 0
            ? $hours.' giờ '.$remainingMinutes.' phút'
            : $hours.' giờ';
    }

    private function tagPayload(Tag $tag): array
    {
        return [
            'id' => (int) $tag->id,
            'name' => $tag->name,
            'color' => $tag->color,
            'is_default' => (bool) $tag->is_default,
        ];
    }

    private function notificationPayload(DatabaseNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'data' => $notification->data,
            'read_at' => $notification->read_at?->toISOString(),
            'created_at' => $notification->created_at?->toISOString(),
        ];
    }

    private function workShiftPayload(WorkShift $shift): array
    {
        $metrics = $this->workShiftMetrics($shift);

        return [
            'id' => (int) $shift->id,
            'name' => $shift->name,
            'starts_at' => $shift->starts_at?->toISOString(),
            'ends_at' => $shift->ends_at?->toISOString(),
            'starts_time' => $shift->starts_at?->format('H:i'),
            'ends_time' => $shift->ends_at?->format('H:i'),
            'is_active' => (bool) $shift->is_active,
            'is_current' => $this->workShiftContainsNow($shift),
            'agents' => $shift->relationLoaded('agents')
                ? $shift->agents->map(fn (User $agent): array => $this->agentPayload($agent))->values()
                : [],
            'metrics' => $metrics,
            'created_at' => $shift->created_at?->toISOString(),
            'updated_at' => $shift->updated_at?->toISOString(),
        ];
    }

    private function workShiftMetrics(WorkShift $shift): array
    {
        $waiting = Conversation::query()
            ->where('queue_shift_id', $shift->id)
            ->where('status', ConversationStatus::WAITING)
            ->whereNull('assigned_to')
            ->count();
        $handling = Conversation::query()
            ->where('owner_shift_id', $shift->id)
            ->whereIn('status', ConversationStatus::ACTIVE)
            ->whereNotNull('assigned_to')
            ->count();
        $finished = Conversation::query()
            ->where('owner_shift_id', $shift->id)
            ->whereIn('status', [ConversationStatus::RESOLVED, ConversationStatus::CLOSED])
            ->count();

        return [
            'waiting_conversations' => $waiting,
            'handling_conversations' => $handling,
            'finished_conversations' => $finished,
            'total_conversations' => $waiting + $handling + $finished,
        ];
    }

    private function emptyWorkShiftMetrics(): array
    {
        return [
            'waiting_conversations' => 0,
            'handling_conversations' => 0,
            'finished_conversations' => 0,
            'total_conversations' => 0,
        ];
    }

    private function staffMembersForShift(WorkShift $shift)
    {
        return $shift->agents()
            ->withCount([
                'assignedConversations as active_conversations_count' => fn (Builder $query) => $query
                    ->where('owner_shift_id', $shift->id)
                    ->whereIn('status', ConversationStatus::ACTIVE),
                'assignedConversations as finished_conversations_count' => fn (Builder $query) => $query
                    ->where('owner_shift_id', $shift->id)
                    ->whereIn('status', [ConversationStatus::RESOLVED, ConversationStatus::CLOSED]),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (User $agent): array => [
                ...$this->agentPayload($agent),
                'active_conversations_count' => (int) $agent->active_conversations_count,
                'finished_conversations_count' => (int) $agent->finished_conversations_count,
            ])
            ->values();
    }

    private function workShiftAgents()
    {
        return User::role(['CSKH', 'User'])
            ->where('is_active', true)
            ->withCount([
                'assignedConversations as active_conversations_count' => fn (Builder $query) => $query->whereIn('status', ConversationStatus::ACTIVE),
            ])
            ->orderBy('name')
            ->get();
    }

    private function busyAgentIds()
    {
        return WorkShift::query()
            ->where('is_active', true)
            ->with('agents:id')
            ->get()
            ->flatMap(fn (WorkShift $shift) => $shift->agents->pluck('id'))
            ->unique()
            ->values();
    }

    private function validatedWorkShift(Request $request): array
    {
        return $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'starts_time' => ['required', 'date_format:H:i'],
            'ends_time' => ['required', 'date_format:H:i'],
            'agent_ids' => ['required', 'array', 'min:1', 'max:2'],
            'agent_ids.*' => ['integer', 'distinct', 'exists:users,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function workShiftAttributes(array $validated, bool $isActive): array
    {
        $startsAt = $this->timeOnSystemDate($validated['starts_time']);
        $endsAt = $this->timeOnSystemDate($validated['ends_time']);

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            $endsAt->addDay();
        }

        return [
            'name' => $validated['name'] ?? null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'is_active' => $isActive,
        ];
    }

    private function timeOnSystemDate(string $time): Carbon
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return now()->startOfDay()->setTime($hour, $minute);
    }

    private function ensureAgentsAvailable(array $agentIds, ?WorkShift $currentShift = null): void
    {
        $busyShift = WorkShift::query()
            ->where('is_active', true)
            ->when($currentShift, fn (Builder $query) => $query->where('id', '!=', $currentShift->id))
            ->whereHas('agents', fn (Builder $query) => $query->whereIn('users.id', $agentIds))
            ->first();

        if (! $busyShift) {
            return;
        }

        throw ValidationException::withMessages([
            'agent_ids' => 'Nhân viên đã nằm trong ca trực đang bật. Vui lòng chọn nhân viên khác.',
        ]);
    }

    private function manageStatus(Request $request): string
    {
        $status = (string) $request->query('status', 'all');

        return in_array($status, ['all', 'active', 'inactive', 'current'], true) ? $status : 'all';
    }

    private function matchesManageStatus(WorkShift $shift, string $status): bool
    {
        return match ($status) {
            'active' => $shift->is_active,
            'inactive' => ! $shift->is_active,
            'current' => $this->workShiftContainsNow($shift),
            default => true,
        };
    }

    private function workShiftContainsNow(WorkShift $shift): bool
    {
        if (! $shift->is_active || ! $shift->starts_at || ! $shift->ends_at) {
            return false;
        }

        $now = now();

        if ($shift->starts_at <= $now && $shift->ends_at > $now) {
            return true;
        }

        $start = ((int) $shift->starts_at->format('H') * 3600) + ((int) $shift->starts_at->format('i') * 60);
        $end = ((int) $shift->ends_at->format('H') * 3600) + ((int) $shift->ends_at->format('i') * 60);
        $current = ((int) $now->format('H') * 3600) + ((int) $now->format('i') * 60);

        if ($start === $end) {
            return true;
        }

        if ($start < $end) {
            return $current >= $start && $current < $end;
        }

        return $current >= $start || $current < $end;
    }

    private function realtimePayload(Request $request): array
    {
        $reverb = config('broadcasting.connections.reverb');
        $publicHost = config('reverb.public.host');
        $publicPort = config('reverb.public.port');
        $publicScheme = match (config('reverb.public.scheme')) {
            'http' => 'ws',
            'https' => 'wss',
            default => config('reverb.public.scheme'),
        };

        $host = $publicHost ?: (
            in_array($reverb['options']['host'], ['127.0.0.1', 'localhost'], true)
                ? $request->getHost()
                : $reverb['options']['host']
        );

        $port = $publicHost
            ? $publicPort
            : (
                in_array($reverb['options']['host'], ['127.0.0.1', 'localhost'], true) && $request->secure()
                    ? null
                    : $reverb['options']['port']
            );

        $scheme = $publicScheme ?: ($request->secure() ? 'wss' : ($reverb['options']['scheme'] === 'https' ? 'wss' : 'ws'));

        return [
            'enabled' => filled($reverb['key']) && filled($host),
            'key' => $reverb['key'],
            'websocket_url' => sprintf(
                '%s://%s%s/app/%s?protocol=7&client=crm-mobile&version=1.0&flash=false',
                $scheme,
                $host,
                $port ? ':'.(int) $port : '',
                rawurlencode((string) $reverb['key']),
            ),
            'auth_url' => url('/api/mobile/broadcasting/auth'),
            'inbox_channel' => 'private-crm.user.'.$request->user()->id.'.conversations',
            'conversation_channel_pattern' => 'private-crm.conversation.{conversation_id}',
            'events' => ['message.created', 'message.updated', 'message.deleted'],
        ];
    }

    private function paginationPayload($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
            'has_more' => $paginator->hasMorePages(),
        ];
    }

    private function perPage(Request $request, int $default, int $max): int
    {
        return min(max((int) $request->query('per_page', $default), 1), $max);
    }
}
