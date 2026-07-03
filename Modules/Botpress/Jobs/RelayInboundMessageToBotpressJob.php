<?php

namespace Modules\Botpress\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
        $message = Message::query()
            ->with('conversation.customer.channels')
            ->find($this->messageId);

        if (! $message) {
            return;
        }

        $botpress->relayCustomerMessage($message);
    }
}
