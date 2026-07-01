<?php

namespace App\Http\Controllers\Web;

use App\Actions\Web\GetMessengerViewDataAction;
use App\Actions\Web\SendMessengerMessageAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\SendMessengerMessageRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Models\Tag;
use Modules\Conversation\Services\ConversationVisibilityService;
use Modules\Conversation\Services\ConversationService;
use Modules\Conversation\Services\WorkShiftService;
use Modules\Customer\Models\CustomerTag;
use Modules\Message\Events\MessageDeletedEvent;
use Modules\Message\Events\MessageUpdatedEvent;
use Modules\Message\Http\Resources\MessageResource;
use Modules\Message\Models\Message;
use Modules\Message\Services\MessengerAttachmentStorage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessengerController extends Controller
{
    public function index(Request $request, GetMessengerViewDataAction $action): View
    {
        $data = $action->execute($request->user(), null, [
            'search' => $request->string('q')->toString(),
            'tag' => $request->string('tag')->toString(),
            'channel' => $request->string('channel')->toString(),
            'status' => $request->string('status')->toString(),
        ]);

        return view('messenger.index', $data);
    }

    public function show(Request $request, Conversation $conversation, GetMessengerViewDataAction $action): View|JsonResponse
    {
        $data = $action->execute($request->user(), $conversation, [
            'search' => $request->string('q')->toString(),
            'tag' => $request->string('tag')->toString(),
            'channel' => $request->string('channel')->toString(),
            'status' => $request->string('status')->toString(),
        ]);
        $this->markConversationRead($data['activeConversation'] ?? null);
        $this->syncReadStateInViewData($data);

        if ($request->expectsJson()) {
            $messages = $conversation->messages()
                ->with(['sender', 'conversation.customer'])
                ->latest()
                ->limit(10)
                ->get()
                ->reverse()
                ->values();
            $oldestId = (int) ($messages->first()?->id ?? 0);

            return response()->json($this->conversationPayload(
                $data['activeConversation'],
                MessageResource::collection($messages)->resolve(),
                $oldestId > 0 && $conversation->messages()->where('id', '<', $oldestId)->exists(),
                $data['activeChannel'],
                $request->user(),
            ));
        }

        return view('messenger.index', $data);
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversationAccess($request, $conversation);

        $beforeId = $request->integer('before_id');
        $limit = min(max($request->integer('limit', 10), 1), 50);

        if ($beforeId > 0) {
            $messages = $conversation->messages()
                ->with(['sender', 'conversation.customer'])
                ->where('id', '<', $beforeId)
                ->latest()
                ->limit($limit)
                ->get()
                ->reverse()
                ->values();

            $oldestId = (int) ($messages->first()?->id ?? 0);

            return response()->json([
                'data' => MessageResource::collection($messages)->resolve(),
                'meta' => [
                    'has_more' => $oldestId > 0 && $conversation->messages()->where('id', '<', $oldestId)->exists(),
                    'oldest_id' => $oldestId,
                ],
            ]);
        }

        if ($request->integer('after_id') > 0) {
            $messages = $conversation->messages()
                ->with(['sender', 'conversation.customer'])
                ->where('id', '>', $request->integer('after_id'))
                ->oldest()
                ->limit($limit)
                ->get();

            return response()->json([
                'data' => MessageResource::collection($messages)->resolve(),
                'meta' => [
                    'has_more' => false,
                    'oldest_id' => (int) ($messages->first()?->id ?? 0),
                ],
            ]);
        }

        $messages = $conversation->messages()
            ->with(['sender', 'conversation.customer'])
            ->latest()
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        $oldestId = (int) ($messages->first()?->id ?? 0);

        return response()->json([
            'data' => MessageResource::collection($messages)->resolve(),
            'meta' => [
                'has_more' => $oldestId > 0 && $conversation->messages()->where('id', '<', $oldestId)->exists(),
                'oldest_id' => $oldestId,
            ],
        ]);
    }

    private function conversationPayload(Conversation $conversation, $messages, bool $hasOlderMessages, string $activeChannel, $user): array
    {
        $conversation->refresh();
        $conversation->loadMissing(['customer.channels', 'customer.notes.user', 'customer.tags', 'assignee', 'tags']);
        $canReply = $user->can('conversation.view_all') || (int) $conversation->assigned_to === (int) $user->id;
        $canClaim = ! $conversation->assigned_to && ! $user->can('conversation.view_all')
            && app(WorkShiftService::class)->userBelongsToShift($user, $conversation->queue_shift_id);

        return [
            'data' => [
                'id' => (int) $conversation->id,
                'customer_id' => (int) $conversation->customer_id,
                'customer_name' => $conversation->customer?->name ?? 'Customer',
                'customer_avatar' => $conversation->customer?->avatar,
                'customer_phone' => $conversation->customer?->phone,
                'customer_email' => $conversation->customer?->email,
                'facebook_profile_url' => $this->facebookProfileUrl($conversation),
                'customer_contact' => $this->customerContactPayload($conversation),
                'customer_public_details' => $this->customerPublicDetails($conversation),
                'customer_update_url' => route('crm.conversations.customer.update', $conversation),
                'customer_notes_url' => route('crm.conversations.customer-notes.store', $conversation),
                'customer_tags_url' => route('crm.conversations.customer-tags.store', $conversation),
                'customer_notes' => $this->customerNotesPayload($conversation),
                'customer_tags' => $this->customerTagsPayload($conversation),
                'all_customer_tags' => $this->tagPayloads(),
                'facebook_page_id' => $conversation->facebook_page_id,
                'assignee_name' => $conversation->assignee?->name,
                'assigned_to' => $conversation->assigned_to,
                'unread_messages_count' => (int) $conversation->unread_messages_count,
                'is_unread' => (int) $conversation->unread_messages_count > 0,
                'can_claim' => $canClaim,
                'can_reply' => $canReply,
                'can_assign' => $user->can('conversation.assign') || $user->can('conversation.transfer'),
                'active_channel' => $activeChannel,
                'created_at' => $conversation->created_at?->toISOString(),
                'messages_url' => route('crm.conversations.messages.index', $conversation),
                'read_url' => route('crm.conversations.read', $conversation),
                'stream_url' => route('crm.conversations.messages.stream', $conversation),
                'send_url' => route('crm.conversations.messages.store', $conversation),
                'delete_url' => route('crm.conversations.destroy', $conversation),
                'clear_messages_url' => route('crm.conversations.messages.clear', $conversation),
                'attachments_url' => route('crm.conversations.attachments.store', $conversation),
                'claim_url' => route('crm.conversations.claim', $conversation),
                'assign_url' => route('crm.conversations.assign', $conversation),
                'tags_url' => route('crm.conversations.tags.store', $conversation),
                'broadcast_channel' => 'private-crm.conversation.'.$conversation->id,
                'tags' => $conversation->tags
                    ->map(fn (Tag $tag): array => ['id' => (int) $tag->id, 'name' => $tag->name, 'color' => $tag->color])
                    ->values()
                    ->all(),
                'messages' => array_values(is_array($messages) ? $messages : $messages->all()),
                'meta' => [
                    'has_older_messages' => $hasOlderMessages,
                    'oldest_message_id' => (int) data_get($messages, '0.id', 0),
                    'last_message_id' => (int) data_get($messages, (string) max(count($messages) - 1, 0).'.id', 0),
                ],
            ],
        ];
    }

    public function messageStream(Request $request, Conversation $conversation): StreamedResponse
    {
        $this->authorizeConversationAccess($request, $conversation);

        $lastId = max(0, $request->integer('after_id'));

        return response()->stream(function () use ($conversation, $lastId): void {
            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }

            if (function_exists('session_write_close')) {
                @session_write_close();
            }

            $startedAt = time();
            echo "retry: 1000\n\n";
            echo ': '.str_repeat(' ', 2048)."\n\n";
            $this->flushStream();

            while (! connection_aborted() && time() - $startedAt < 55) {
                $messages = $conversation->messages()
                    ->with('sender')
                    ->where('id', '>', $lastId)
                    ->oldest()
                    ->limit(100)
                    ->get();

                foreach ($messages as $message) {
                    $lastId = max($lastId, (int) $message->id);
                    echo 'id: '.$lastId."\n";
                    echo "event: message\n";
                    echo 'data: '.json_encode((new MessageResource($message))->resolve(), JSON_UNESCAPED_UNICODE)."\n\n";
                }

                echo ": heartbeat\n\n";
                $this->flushStream();
                sleep(1);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function inboxMessageStream(Request $request, ConversationVisibilityService $visibility): StreamedResponse
    {
        $lastId = max(0, $request->integer('after_id'));
        $user = $request->user();

        return response()->stream(function () use ($visibility, $user, $lastId): void {
            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }

            if (function_exists('session_write_close')) {
                @session_write_close();
            }

            $startedAt = time();
            echo "retry: 1000\n\n";
            echo ': '.str_repeat(' ', 2048)."\n\n";
            $this->flushStream();

            while (! connection_aborted() && time() - $startedAt < 55) {
                $visibleConversationIds = $visibility->visibleFor($user)->select('id');
                $messages = Message::query()
                    ->with(['sender', 'conversation.customer', 'conversation.tags'])
                    ->where('id', '>', $lastId)
                    ->whereIn('conversation_id', $visibleConversationIds)
                    ->oldest()
                    ->limit(100)
                    ->get();

                foreach ($messages as $message) {
                    $lastId = max($lastId, (int) $message->id);
                    echo 'id: '.$lastId."\n";
                    echo "event: message\n";
                    echo 'data: '.json_encode((new MessageResource($message))->resolve(), JSON_UNESCAPED_UNICODE)."\n\n";
                }

                echo ": heartbeat\n\n";
                $this->flushStream();
                sleep(1);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function flushStream(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }

        @flush();
    }

    public function send(SendMessengerMessageRequest $request, Conversation $conversation, SendMessengerMessageAction $action): RedirectResponse|JsonResponse
    {
        $this->authorizeConversationAccess($request, $conversation);

        try {
            $message = $action->execute($conversation, $request->user(), $request->validated());
        } catch (\Throwable $exception) {
            Log::warning('Web messenger message failed', [
                'conversation_id' => $conversation->id,
                'channel' => $request->input('channel'),
                'error' => $exception->getMessage(),
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $this->friendlySendError($exception->getMessage()),
                ], 422);
            }

            return back()->withErrors([
                'content' => $this->friendlySendError($exception->getMessage()),
            ])->withInput();
        }

        if ($request->expectsJson()) {
            return response()->json([
                'data' => (new MessageResource($message->loadMissing(['sender', 'conversation.customer', 'conversation.tags'])))->resolve(),
            ], 201);
        }

        return redirect()->route('crm.conversations.show', $conversation);
    }

    public function uploadAttachments(Request $request, Conversation $conversation, MessengerAttachmentStorage $storage): JsonResponse
    {
        $this->authorizeConversationAccess($request, $conversation);

        $validated = $request->validate([
            'attachments' => ['required', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:20480'],
        ]);

        $attachments = collect($validated['attachments'])
            ->map(fn ($file): array => $storage->store($file))
            ->values()
            ->all();

        return response()->json([
            'data' => $attachments,
        ], 201);
    }

    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversationAccess($request, $conversation);
        $this->markConversationRead($conversation);

        return response()->json([
            'data' => [
                'id' => (int) $conversation->id,
                'unread_messages_count' => (int) $conversation->unread_messages_count,
                'is_unread' => false,
            ],
        ]);
    }

    public function updateCustomer(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversationAccess($request, $conversation);
        $conversation->loadMissing('customer.channels');
        abort_unless($conversation->customer, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        $conversation->customer->forceFill([
            'name' => trim($validated['name']),
            'phone' => filled($validated['phone'] ?? null) ? trim($validated['phone']) : null,
            'email' => filled($validated['email'] ?? null) ? trim($validated['email']) : null,
        ])->save();

        $conversation->load(['customer.channels', 'customer.notes.user', 'customer.tags', 'tags']);

        return response()->json([
            'data' => [
                'id' => (int) $conversation->id,
                'customer_name' => $conversation->customer?->name ?? 'Customer',
                'customer_avatar' => $conversation->customer?->avatar,
                'customer_phone' => $conversation->customer?->phone,
                'customer_email' => $conversation->customer?->email,
                'facebook_profile_url' => $this->facebookProfileUrl($conversation),
                'customer_contact' => $this->customerContactPayload($conversation),
                'customer_public_details' => $this->customerPublicDetails($conversation),
            ],
        ]);
    }

    public function storeCustomerNote(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversationAccess($request, $conversation);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $conversation->loadMissing('customer');
        $conversation->customer?->notes()->create([
            'user_id' => $request->user()->id,
            'body' => $validated['body'],
        ]);
        $conversation->load(['customer.notes.user']);

        return response()->json([
            'data' => [
                'notes' => $this->customerNotesPayload($conversation),
            ],
        ], 201);
    }

    public function storeCustomerTag(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversationAccess($request, $conversation);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'max:24'],
        ]);
        $name = trim($validated['name']);

        if ($name === '') {
            return response()->json(['message' => 'Tag khong duoc de trong.'], 422);
        }

        $systemTag = Tag::query()->where('name', $name)->first();

        if (! $systemTag) {
            return response()->json(['message' => 'Tag nay chua co trong he thong.'], 422);
        }

        $tag = CustomerTag::query()->firstOrCreate(
            ['name' => $systemTag->name],
            ['color' => $systemTag->color ?: '#2563eb'],
        );

        $conversation->loadMissing('customer');
        $conversation->customer?->tags()->sync([$tag->id]);
        $conversation->load(['customer.tags']);

        return response()->json([
            'data' => [
                'tags' => $this->customerTagsPayload($conversation),
                'all_tags' => $this->tagPayloads(),
            ],
        ], 201);
    }

    public function conversationTags(Request $request): JsonResponse
    {
        $this->assertCanManageTags($request);
        Tag::ensureDefaults();

        return response()->json([
            'data' => $this->tagPayloads(),
        ]);
    }

    public function storeConversationTag(Request $request): JsonResponse
    {
        $this->assertCanManageTags($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'max:24'],
        ]);

        $name = trim($validated['name']);

        if ($name === '') {
            return response()->json(['message' => 'Tag khong duoc de trong.'], 422);
        }

        Tag::ensureDefaults();

        $tag = Tag::query()->updateOrCreate(
            ['name' => $name],
            [
                'color' => $validated['color'] ?: '#2563eb',
                'is_default' => array_key_exists($name, Tag::DEFAULTS),
            ],
        );

        return response()->json([
            'data' => [
                'tag' => $this->tagPayload($tag->refresh()),
                'tags' => $this->tagPayloads(),
            ],
        ], 201);
    }

    public function updateConversationTag(Request $request, Tag $tag): JsonResponse
    {
        $this->assertCanManageTags($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'max:24'],
        ]);

        $name = trim($validated['name']);

        if ($name === '') {
            return response()->json(['message' => 'Tag khong duoc de trong.'], 422);
        }

        if ($tag->is_default && $name !== $tag->name) {
            return response()->json(['message' => 'Tag mac dinh khong duoc doi ten.'], 422);
        }

        $exists = Tag::query()
            ->where('name', $name)
            ->whereKeyNot($tag->id)
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Ten tag da ton tai.'], 422);
        }

        $tag->forceFill([
            'name' => $name,
            'color' => $validated['color'] ?: '#2563eb',
        ])->save();

        return response()->json([
            'data' => [
                'tag' => $this->tagPayload($tag->refresh()),
                'tags' => $this->tagPayloads(),
            ],
        ]);
    }

    public function destroyConversationTag(Request $request, Tag $tag): JsonResponse
    {
        $this->assertCanManageTags($request);

        if ($tag->is_default || array_key_exists($tag->name, Tag::DEFAULTS)) {
            return response()->json(['message' => 'Tag mac dinh khong duoc xoa.'], 422);
        }

        DB::transaction(function () use ($tag): void {
            $tag->conversations()->detach();
            $tag->delete();
        });

        return response()->json([
            'data' => [
                'tags' => $this->tagPayloads(),
            ],
        ]);
    }

    public function claim(Request $request, Conversation $conversation, ConversationService $service, WorkShiftService $shifts): JsonResponse
    {
        $this->authorizeConversationAccess($request, $conversation);

        try {
            $claimed = $service->claim($conversation, $request->user());
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'data' => [
                'id' => (int) $claimed->id,
                'assigned_to' => (int) $claimed->assigned_to,
                'assignee_name' => $claimed->assignee?->name,
                'claimed_at' => $claimed->claimed_at?->toISOString(),
            ],
        ]);
    }

    public function assign(Request $request, Conversation $conversation, ConversationService $service): JsonResponse
    {
        abort_unless($request->user()->can('conversation.assign') || $request->user()->can('conversation.transfer'), 403);

        $validated = $request->validate([
            'assigned_to' => ['required', 'integer', 'exists:users,id'],
        ]);

        try {
            $assigned = $service->assign($conversation, (int) $validated['assigned_to'], $request->user());
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'data' => [
                'id' => (int) $assigned->id,
                'assigned_to' => $assigned->assigned_to ? (int) $assigned->assigned_to : null,
                'assigned_by' => $assigned->assigned_by ? (int) $assigned->assigned_by : null,
                'assigned_type' => $assigned->assigned_type,
                'assignee_name' => $assigned->assignee?->name,
                'claimed_at' => $assigned->claimed_at?->toISOString(),
                'can_claim' => false,
                'can_reply' => $request->user()->can('conversation.view_all') || (int) $assigned->assigned_to === (int) $request->user()->id,
                'can_assign' => true,
                'assign_url' => route('crm.conversations.assign', $assigned),
                'claim_url' => route('crm.conversations.claim', $assigned),
            ],
        ]);
    }

    public function tags(Request $request, Conversation $conversation, ConversationService $service): JsonResponse
    {
        $this->authorizeConversationAccess($request, $conversation);
        abort_unless(
            $request->user()->can('conversation.tag')
            || $request->user()->can('conversation.view_all')
            || $request->user()->hasRole('Admin'),
            403,
        );

        $validated = $request->validate([
            'tags' => ['array'],
            'tags.*.name' => ['required', 'string', 'max:80'],
            'tags.*.color' => ['nullable', 'string', 'max:24'],
        ]);

        $conversation = $service->syncTags(
            $conversation,
            array_slice($validated['tags'] ?? [], 0, 1),
            $request->user(),
        );

        return response()->json([
            'data' => [
                'id' => (int) $conversation->id,
                'tags' => $conversation->tags
                    ->map(fn (Tag $tag): array => ['id' => (int) $tag->id, 'name' => $tag->name, 'color' => $tag->color])
                    ->values()
                    ->all(),
            ],
        ]);
    }

    public function recallMessage(Request $request, Conversation $conversation, Message $message): JsonResponse
    {
        $this->authorizeConversationAccess($request, $conversation);
        abort_unless($message->conversation_id === $conversation->id, 404);

        $canCancelBeforeFacebook = in_array((string) $message->outbound_status, ['queued', 'sending'], true)
            && blank($message->external_message_id)
            && blank($message->sent_at);

        $message->forceFill([
            'content' => null,
            'attachments' => null,
            'message_type' => 'recalled',
            'recalled_at' => now(),
            'recalled_by_user_id' => $request->user()->id,
            'outbound_status' => $canCancelBeforeFacebook ? 'cancelled' : $message->outbound_status,
            'outbound_error' => $canCancelBeforeFacebook ? 'Tin nhan da duoc thu hoi truoc khi gui sang Facebook.' : $message->outbound_error,
        ])->save();

        $message->loadMissing(['sender', 'conversation.customer']);
        event(new MessageUpdatedEvent($message));

        return response()->json([
            'data' => (new MessageResource($message))->resolve(),
            'meta' => [
                'facebook_recalled' => false,
                'facebook_cancelled_before_send' => $canCancelBeforeFacebook,
                'message' => $canCancelBeforeFacebook
                    ? 'Tin nhan da duoc huy truoc khi gui sang Facebook.'
                    : 'Messenger Platform khong ho tro thu hoi tin da gui tren Facebook qua API.',
            ],
        ]);
    }

    public function deleteMessage(Request $request, Conversation $conversation, Message $message): JsonResponse
    {
        $this->authorizeConversationAccess($request, $conversation);
        abort_unless($message->conversation_id === $conversation->id, 404);

        $messageId = (int) $message->id;
        $message->forceFill(['deleted_by_user_id' => $request->user()->id])->save();
        $message->delete();
        $this->refreshConversationLastMessageAt($conversation);
        event(new MessageDeletedEvent((int) $conversation->id, [$messageId]));

        return response()->json([
            'data' => [
                'conversation_id' => (int) $conversation->id,
                'message_ids' => [$messageId],
            ],
        ]);
    }

    public function clearMessages(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversationAccess($request, $conversation);

        $hasMessages = $conversation->messages()->exists();

        if ($hasMessages) {
            DB::transaction(function () use ($conversation, $request): void {
                $conversation->messages()->update(['deleted_by_user_id' => $request->user()->id]);
                $conversation->messages()->delete();
                $conversation->forceFill([
                    'last_message_at' => null,
                    'last_read_at' => now(),
                    'unread_messages_count' => 0,
                ])->save();
            });

            event(new MessageDeletedEvent((int) $conversation->id, [], true));
        }

        return response()->json([
            'data' => [
                'conversation_id' => (int) $conversation->id,
                'message_ids' => [],
                'clear_all' => true,
            ],
        ]);
    }

    public function destroy(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversationAccess($request, $conversation);

        $conversationId = (int) $conversation->id;

        DB::transaction(function () use ($conversation): void {
            $conversation->messages()->withTrashed()->forceDelete();
            $conversation->tags()->detach();
            $conversation->delete();
        });

        event(new MessageDeletedEvent($conversationId, [], true, true));

        return response()->json([
            'data' => [
                'conversation_id' => $conversationId,
                'message_ids' => [],
                'clear_all' => true,
                'delete_conversation' => true,
            ],
        ]);
    }

    private function authorizeConversationAccess(Request $request, Conversation $conversation): void
    {
        $user = $request->user();

        if (app(ConversationVisibilityService::class)->canView($user, $conversation)) {
            return;
        }

        abort(403);
    }

    private function assertCanManageTags(Request $request): void
    {
        abort_unless(
            $request->user()->can('conversation.tag')
            || $request->user()->can('conversation.view_all')
            || $request->user()->hasRole('Admin'),
            403,
        );
    }

    private function tagPayloads(): array
    {
        Tag::ensureDefaults();

        return Tag::query()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (Tag $tag): array => $this->tagPayload($tag))
            ->values()
            ->all();
    }

    private function tagPayload(Tag $tag): array
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

    private function markConversationRead(?Conversation $conversation): void
    {
        if (! $conversation || (int) $conversation->unread_messages_count === 0) {
            return;
        }

        $conversation->markAsRead();
    }

    private function syncReadStateInViewData(array &$data): void
    {
        $activeConversation = $data['activeConversation'] ?? null;

        if (! $activeConversation instanceof Conversation) {
            return;
        }

        $activeConversation->refresh();
        $data['activeConversation'] = $activeConversation;

        $data['conversations'] = $data['conversations']->map(function (Conversation $conversation) use ($activeConversation): Conversation {
            if ((int) $conversation->id === (int) $activeConversation->id) {
                $conversation->forceFill([
                    'unread_messages_count' => $activeConversation->unread_messages_count,
                    'last_read_at' => $activeConversation->last_read_at,
                ]);
            }

            return $conversation;
        });
    }

    private function facebookProfileUrl(Conversation $conversation): ?string
    {
        $customer = $conversation->customer;
        $facebookChannel = $customer?->channels?->firstWhere('channel', 'facebook');
        $metadata = (array) ($facebookChannel?->metadata ?? []);
        $profileUrl = (string) (
            data_get($metadata, 'profile.link')
            ?: data_get($metadata, 'profile.url')
            ?: data_get($metadata, 'profile.profile_url')
            ?: data_get($metadata, 'profile_url')
            ?: data_get($metadata, 'link')
        );

        if (filter_var($profileUrl, FILTER_VALIDATE_URL)) {
            return $profileUrl;
        }

        return '#';
    }

    private function customerContactPayload(Conversation $conversation): array
    {
        $customer = $conversation->customer;
        $primaryChannel = $customer?->channels?->first();

        return [
            'name' => $customer?->name ?? '',
            'phone' => $customer?->phone ?? '',
            'email' => $customer?->email ?? '',
            'channel' => $primaryChannel?->channel ? ucfirst($primaryChannel->channel) : '',
        ];
    }

    private function customerPublicDetails(Conversation $conversation): array
    {
        $customer = $conversation->customer;
        $primaryChannel = $customer?->channels?->first();

        return collect([
            ['label' => 'Ten cong khai', 'value' => $customer?->name],
            ['label' => 'So dien thoai', 'value' => $customer?->phone],
            ['label' => 'Email', 'value' => $customer?->email],
            ['label' => 'Kenh', 'value' => $primaryChannel?->channel ? ucfirst($primaryChannel->channel) : null],
        ])
            ->filter(fn (array $detail): bool => filled($detail['value']))
            ->values()
            ->all();
    }

    private function customerNotesPayload(Conversation $conversation): array
    {
        return $conversation->customer?->notes
            ?->sortByDesc('created_at')
            ->map(fn ($note): array => [
                'id' => (int) $note->id,
                'body' => $note->body,
                'author' => $note->user?->name ?? 'Admin',
                'created_at' => $note->created_at?->format('H:i d/m/Y'),
            ])
            ->values()
            ->all() ?? [];
    }

    private function customerTagsPayload(Conversation $conversation): array
    {
        return $conversation->customer?->tags
            ?->take(1)
            ->map(fn (CustomerTag $tag): array => ['id' => (int) $tag->id, 'name' => $tag->name, 'color' => $tag->color])
            ->values()
            ->all() ?? [];
    }

    private function refreshConversationLastMessageAt(Conversation $conversation): void
    {
        $conversation->forceFill([
            'last_message_at' => $conversation->messages()->latest()->value('created_at'),
        ])->save();
    }

    private function friendlySendError(string $error): string
    {
        if (str_contains($error, 'does not have')) {
            return 'Khach hang chua co external id cho kenh nay.';
        }

        if (str_contains($error, 'Unsupported message channel')) {
            return 'Kenh gui tin nhan khong duoc ho tro.';
        }

        return 'Khong gui duoc tin nhan: '.$error;
    }
}
