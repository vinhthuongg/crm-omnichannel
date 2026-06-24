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
use Modules\Conversation\Services\ConversationService;
use Modules\Conversation\Services\WorkShiftService;
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
        return view('messenger.index', $action->execute($request->user(), null, [
            'search' => $request->string('q')->toString(),
        ]));
    }

    public function show(Request $request, Conversation $conversation, GetMessengerViewDataAction $action): View|JsonResponse
    {
        $data = $action->execute($request->user(), $conversation, [
            'search' => $request->string('q')->toString(),
        ]);

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
        $conversation->loadMissing(['customer.channels', 'assignee']);
        $canReply = $user->can('conversation.view_all') || (int) $conversation->assigned_to === (int) $user->id;
        $canClaim = ! $conversation->assigned_to && ! $user->can('conversation.view_all')
            && app(WorkShiftService::class)->userIsInCurrentShift($user, $conversation->work_shift_id);

        return [
            'data' => [
                'id' => (int) $conversation->id,
                'customer_id' => (int) $conversation->customer_id,
                'customer_name' => $conversation->customer?->name ?? 'Customer',
                'customer_avatar' => $conversation->customer?->avatar,
                'facebook_page_id' => $conversation->facebook_page_id,
                'assignee_name' => $conversation->assignee?->name,
                'assigned_to' => $conversation->assigned_to,
                'can_claim' => $canClaim,
                'can_reply' => $canReply,
                'active_channel' => $activeChannel,
                'created_at' => $conversation->created_at?->toISOString(),
                'messages_url' => route('crm.conversations.messages.index', $conversation),
                'stream_url' => route('crm.conversations.messages.stream', $conversation),
                'send_url' => route('crm.conversations.messages.store', $conversation),
                'attachments_url' => route('crm.conversations.attachments.store', $conversation),
                'claim_url' => route('crm.conversations.claim', $conversation),
                'broadcast_channel' => 'private-crm.conversation.'.$conversation->id,
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
                'data' => (new MessageResource($message->loadMissing('sender')))->resolve(),
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

    public function claim(Request $request, Conversation $conversation, ConversationService $service, WorkShiftService $shifts): JsonResponse
    {
        $this->authorizeConversationAccess($request, $conversation);

        if (! $request->user()->can('conversation.view_all')) {
            abort_unless(blank($conversation->assigned_to) || (int) $conversation->assigned_to === (int) $request->user()->id, 403);
            abort_unless($conversation->assigned_to || $shifts->userIsInCurrentShift($request->user(), $conversation->work_shift_id), 403);
        }

        try {
            $claimed = $service->claim($conversation, $request->user());
        } catch (\Throwable $exception) {
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
                $conversation->forceFill(['last_message_at' => null])->save();
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

    private function authorizeConversationAccess(Request $request, Conversation $conversation): void
    {
        $user = $request->user();

        if ($user->can('conversation.view_all') || $conversation->assigned_to === $user->id) {
            return;
        }

        if (! $conversation->assigned_to && app(WorkShiftService::class)->userIsInCurrentShift($user, $conversation->work_shift_id)) {
            return;
        }

        abort(403);
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
