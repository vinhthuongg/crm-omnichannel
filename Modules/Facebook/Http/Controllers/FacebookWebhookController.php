<?php

namespace Modules\Facebook\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Modules\Facebook\Actions\HandleFacebookWebhookAction;
use Modules\Message\Http\Resources\MessageResource;
use Modules\Shared\Http\Controllers\ApiController;

class FacebookWebhookController extends ApiController
{
    /** Xác minh chữ ký hoặc token của request đến từ hệ thống bên ngoài. */
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub.mode') ?? $request->query('hub_mode');
        $token = $request->query('hub.verify_token') ?? $request->query('hub_verify_token');
        $challenge = $request->query('hub.challenge') ?? $request->query('hub_challenge');

        if ($mode === null && $token === null && $challenge === null) {
            return response('OK', 200)->header('Content-Type', 'text/plain');
        }

        $verifyToken = (string) config('services.facebook.verify_token');

        abort_unless($mode === 'subscribe' && $verifyToken !== '' && is_string($token) && is_string($challenge) && hash_equals($verifyToken, $token), 403);

        return response((string) $challenge, 200)->header('Content-Type', 'text/plain');
    }

    /** Tách các messaging event Facebook và chuyển từng event sang bộ xử lý webhook. */
    public function __invoke(Request $request, HandleFacebookWebhookAction $action): JsonResponse
    {
        $events = collect((array) $request->input('entry', []))
            ->flatMap(fn (array $entry): array => (array) data_get($entry, 'messaging', []));

        Log::info('Facebook webhook received', [
            'object' => $request->input('object'),
            'entry_count' => count((array) $request->input('entry', [])),
            'event_count' => $events->count(),
            'message_count' => $events->filter(fn (array $event): bool => filled(data_get($event, 'message')))->count(),
            'echo_count' => $events->filter(fn (array $event): bool => (bool) data_get($event, 'message.is_echo'))->count(),
            'sender_ids' => $events
                ->map(fn (array $event): string => (string) data_get($event, 'sender.id'))
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ]);

        $message = $action->execute($request->all());
        return response()->json(['data' => $message ? new MessageResource($message) : null]);
    }
}
