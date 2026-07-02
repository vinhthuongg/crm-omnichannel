<?php

namespace Modules\Text\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Text\Services\TextGatewayService;

class TextWebhookController extends Controller
{
    public function __invoke(Request $request, TextGatewayService $gateway): JsonResponse
    {
        $secret = (string) config('services.text.webhook_secret', '');

        if ($secret !== '') {
            $provided = (string) ($request->header('X-Text-Webhook-Secret') ?: $request->query('token', ''));
            abort_unless(hash_equals($secret, $provided), 403);
        }

        $payload = $request->all();
        $message = $gateway->handleWebhook($payload);

        Log::info('Text.com webhook handled', [
            'message_id' => $message?->id,
            'conversation_id' => $message?->conversation_id,
            'text_chat_id' => data_get($payload, 'payload.chat_id') ?: data_get($payload, 'chat_id'),
        ]);

        return response()->json(['ok' => true]);
    }
}
