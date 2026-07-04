<?php

namespace Modules\Facebook\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Conversation\Models\Conversation;
use Modules\Facebook\Models\FacebookPage;
use Modules\Facebook\Services\FacebookMessengerService;

class KeepTypingUntilBotReplyJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const INTERVAL_SECONDS = 8;
    private const MAX_TICKS = 15;

    public int $tries = 1;

    public int $timeout = 20;

    public function __construct(
        public readonly int $conversationId,
        public readonly int $inboundMessageId,
        public readonly string $recipientId,
        public readonly string $pageId,
        public readonly int $tick = 1,
    ) {
        $this->onQueue('default');
    }

    public static function start(Conversation $conversation, int $inboundMessageId, string $recipientId, string $pageId): void
    {
        self::dispatch((int) $conversation->id, $inboundMessageId, $recipientId, $pageId)
            ->delay(now()->addSeconds(self::INTERVAL_SECONDS));
    }

    public function handle(FacebookMessengerService $facebook): void
    {
        if ($this->tick > self::MAX_TICKS || $this->recipientId === '' || $this->pageId === '') {
            return;
        }

        $conversation = Conversation::query()->find($this->conversationId);

        if (! $conversation || $this->hasReplyAfterInbound($conversation)) {
            return;
        }

        $page = FacebookPage::query()
            ->where('page_id', $this->pageId)
            ->first();

        if (! $page?->page_access_token) {
            return;
        }

        $facebook->sendTypingOn($this->recipientId, $page->page_access_token);

        self::dispatch(
            $this->conversationId,
            $this->inboundMessageId,
            $this->recipientId,
            $this->pageId,
            $this->tick + 1,
        )->delay(now()->addSeconds(self::INTERVAL_SECONDS));
    }

    private function hasReplyAfterInbound(Conversation $conversation): bool
    {
        return $conversation->messages()
            ->where('id', '>', $this->inboundMessageId)
            ->where(function ($query): void {
                $query->where(function ($query): void {
                    $query->where('sender_type', 'system')
                        ->where('outbound_status', 'sent');
                })->orWhere(function ($query): void {
                    $query->where('sender_type', 'user')
                        ->where('message_type', '!=', 'whisper')
                        ->where('channel', '!=', 'internal');
                });
            })
            ->exists();
    }
}
