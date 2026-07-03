<?php

namespace Modules\Botpress\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Botpress\Services\BotpressChatService;
use Modules\Message\Http\Resources\MessageResource;
use Modules\Shared\Http\Controllers\ApiController;

class BotpressWebhookController extends ApiController
{
    public function __invoke(Request $request, BotpressChatService $botpress): JsonResponse
    {
        $secret = trim((string) config('services.botpress.callback_secret', ''));

        if ($secret !== '' && ! $this->validSecret($request, $secret)) {
            Log::warning('Botpress callback rejected by invalid secret', [
                'has_header_secret' => $request->headers->has('X-Botpress-Secret'),
                'has_bearer' => filled($request->bearerToken()),
                'has_query_secret' => filled($request->query('secret')),
            ]);

            abort(403);
        }

        $message = $botpress->receiveWebhookReply($request->all());

        return response()->json([
            'ok' => true,
            'data' => $message ? new MessageResource($message) : null,
        ]);
    }

    private function validSecret(Request $request, string $secret): bool
    {
        foreach ([
            (string) $request->header('X-Botpress-Secret', ''),
            (string) $request->bearerToken(),
            (string) $request->query('secret', ''),
            (string) $request->input('secret', ''),
        ] as $candidate) {
            if ($candidate !== '' && hash_equals($secret, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
