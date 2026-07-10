<?php

namespace Modules\Mobile\Http\Controllers;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Broadcast;
use Modules\Conversation\Actions\AssignConversationAction;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Models\Tag;
use Modules\Conversation\Services\ConversationVisibilityService;
use Modules\Customer\Models\Customer;
use Modules\Message\Actions\SendMessageAction;
use Modules\Message\Models\Message;
use Modules\Shared\Http\Controllers\ApiController;

class MobileApiController extends ApiController
{
    public function __construct(private readonly ConversationVisibilityService $visibility)
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
        abort_unless(
            $this->visibility->visibleFor($request->user())->where('customer_id', $customer->id)->exists(),
            403
        );

        $customer->load(['channels', 'tags']);

        return response()->json(['data' => $this->customerDetailPayload($customer)]);
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

    private function customerDetailPayload(Customer $customer): array
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
            'interests' => $customer->tags->map(fn ($tag): array => [
                'id' => (int) $tag->id,
                'name' => $tag->name,
                'color' => $tag->color,
            ])->values(),
        ];
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
