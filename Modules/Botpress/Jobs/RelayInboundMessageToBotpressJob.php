<?php

namespace Modules\Botpress\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Botpress\Services\BotpressChatService;
use Modules\Message\Models\Message;

class RelayInboundMessageToBotpressJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $messageId)
    {
        $this->onQueue('default');
    }

    public function handle(BotpressChatService $botpress): void
    {
        Log::warning('Botpress relay job started', [
            'message_id' => $this->messageId,
        ]);

        $message = Message::query()
            ->with('conversation.customer.channels')
            ->find($this->messageId);

        if (! $message) {
            Log::warning('Botpress relay job skipped because message was not found', [
                'message_id' => $this->messageId,
            ]);

            return;
        }

        $botpress->relayCustomerMessage($message);

        Log::warning('Botpress relay job completed', [
            'message_id' => $this->messageId,
            'conversation_id' => $message->conversation_id,
        ]);
    }
}
