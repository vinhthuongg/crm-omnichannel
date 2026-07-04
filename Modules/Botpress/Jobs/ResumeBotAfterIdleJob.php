<?php

namespace Modules\Botpress\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Modules\Conversation\Models\Conversation;
use Modules\Message\Models\Message;

class ResumeBotAfterIdleJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public readonly int $conversationId,
        public readonly int $messageId,
        public readonly string $trigger,
    ) {
        $this->onQueue('default');
    }

    public static function dispatchFor(Message $message, string $trigger): void
    {
        $minutes = max(1, (int) config('services.botpress.idle_resume_minutes', 2));

        self::dispatch((int) $message->conversation_id, (int) $message->id, $trigger)
            ->delay(now()->addMinutes($minutes));
    }

    public function handle(): void
    {
        $conversation = Conversation::query()->find($this->conversationId);

        if (! $conversation) {
            return;
        }

        $latest = $conversation->messages()
            ->latest('created_at')
            ->latest('id')
            ->first();

        if (! $latest || (int) $latest->id !== $this->messageId) {
            return;
        }

        if ($this->trigger === 'agent_reply' && $this->isAgentVisibleMessage($latest)) {
            $this->resumeAutomation($conversation, 'customer_idle_after_agent_reply', false);

            return;
        }

        if ($this->trigger === 'customer_message' && $latest->sender_type === 'customer') {
            if (! $this->isPaused($conversation)) {
                return;
            }

            $this->resumeAutomation($conversation, 'agent_idle_after_customer_message', true);
            RelayInboundMessageToBotpressJob::dispatch($latest->id);
        }
    }

    private function resumeAutomation(Conversation $conversation, string $reason, bool $willRelay): void
    {
        $state = (array) ($conversation->automation_state ?? []);

        Arr::forget($state, [
            'paused_by_user_at',
            'paused_by_user_message_id',
        ]);

        $state['resumed_by_idle_at'] = now()->toISOString();
        $state['resumed_by_idle_reason'] = $reason;

        $conversation->forceFill([
            'automation_state' => $state,
        ])->save();

        Log::info('Botpress automation resumed after idle window', [
            'conversation_id' => $conversation->id,
            'message_id' => $this->messageId,
            'trigger' => $this->trigger,
            'reason' => $reason,
            'will_relay_customer_message' => $willRelay,
        ]);
    }

    private function isPaused(Conversation $conversation): bool
    {
        return filled(data_get($conversation->automation_state ?? [], 'paused_by_user_at'));
    }

    private function isAgentVisibleMessage(Message $message): bool
    {
        return $message->sender_type === 'user'
            && $message->message_type !== 'whisper'
            && $message->channel !== 'internal';
    }
}
