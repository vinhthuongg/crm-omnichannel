<?php

namespace Modules\Message\Jobs;

use App\Services\ConversationReplySuggestionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Conversation\Models\Conversation;

class GenerateReplySuggestionsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 25;

    /** Lưu ID hội thoại, tin nguồn và cờ tạo lại để sinh gợi ý đúng phiên bản tin nhắn. */
    public function __construct(
        private readonly int $conversationId,
        private readonly int $messageId,
        private readonly bool $force = false,
    ) {
    }

    /** Nạp hội thoại, gọi NIM sinh gợi ý và lưu cache theo tin khách mới nhất. */
    public function handle(ConversationReplySuggestionService $suggestions): void
    {
        $conversation = Conversation::query()
            ->with(['customer', 'tags'])
            ->find($this->conversationId);

        if (! $conversation) {
            return;
        }

        try {
            $suggestions->generate($conversation, $this->messageId, $this->force);
        } catch (\Throwable $exception) {
            Log::warning('Reply suggestion generation failed', [
                'conversation_id' => $this->conversationId,
                'message_id' => $this->messageId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
