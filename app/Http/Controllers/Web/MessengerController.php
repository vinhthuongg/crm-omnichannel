<?php

namespace App\Http\Controllers\Web;

use App\Actions\Web\GetMessengerViewDataAction;
use App\Actions\Web\SendMessengerMessageAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\SendMessengerMessageRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Modules\Conversation\Models\Conversation;
use Modules\Message\Http\Resources\MessageResource;
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

    public function show(Request $request, Conversation $conversation, GetMessengerViewDataAction $action): View
    {
        return view('messenger.index', $action->execute($request->user(), $conversation, [
            'search' => $request->string('q')->toString(),
        ]));
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        abort_unless($request->user()->can('conversation.view_all') || $conversation->assigned_to === $request->user()->id, 403);

        $messages = $conversation->messages()
            ->with('sender')
            ->when($request->integer('after_id') > 0, fn ($query) => $query->where('id', '>', $request->integer('after_id')))
            ->oldest()
            ->limit(100)
            ->get();

        return response()->json([
            'data' => MessageResource::collection($messages)->resolve(),
        ]);
    }

    public function messageStream(Request $request, Conversation $conversation): StreamedResponse
    {
        abort_unless($request->user()->can('conversation.view_all') || $conversation->assigned_to === $request->user()->id, 403);

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
        abort_unless($request->user()->can('conversation.view_all') || $conversation->assigned_to === $request->user()->id, 403);

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
        abort_unless($request->user()->can('conversation.reply'), 403);
        abort_unless($request->user()->can('conversation.view_all') || $conversation->assigned_to === $request->user()->id, 403);

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
