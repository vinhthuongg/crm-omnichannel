<?php

namespace Modules\Text\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Message\Models\Message;
use Modules\Text\Services\TextGatewayService;

class RelayOutboundMessageToTextJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(public readonly int $messageId)
    {
        $this->onQueue('default');
    }

    public function handle(TextGatewayService $gateway): void
    {
        $message = Message::query()
            ->with('conversation.textConversationLink')
            ->find($this->messageId);

        if (! $message) {
            return;
        }

        $gateway->relayAgentMessage($message);
    }
}
