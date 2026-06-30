<?php

namespace Modules\Text\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Text\Services\TextConversationBridge;

class TextWebhookController extends Controller
{
    public function __invoke(Request $request, TextConversationBridge $bridge): JsonResponse
    {
        $secret = (string) config('services.text.webhook_secret', '');

        if ($secret !== '') {
            $provided = (string) ($request->header('X-Text-Webhook-Secret') ?: $request->query('token', ''));
            abort_unless(hash_equals($secret, $provided), 403);
        }

        $payload = $request->all();
        $link = $bridge->upsertFromWebhook($payload);

        Log::info('Text.com webhook handled', [
            'mapped' => (bool) $link?->conversation_id,
            'text_chat_id' => $link?->text_chat_id,
            'conversation_id' => $link?->conversation_id,
        ]);

        return response()->json(['ok' => true]);
    }
}
