<?php

namespace App\Services;

use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Models\ConversationReplySuggestion;
use Modules\Message\Jobs\GenerateReplySuggestionsJob;

class ConversationReplySuggestionService
{
    public function __construct(private readonly NimReplySuggestionService $nim)
    {
    }

    public function hasProvider(): bool
    {
        return trim((string) config('services.nim.api_key')) !== '';
    }

    public function latestMessageId(Conversation $conversation): ?int
    {
        $messageId = (int) $conversation->messages()->max('id');

        return $messageId > 0 ? $messageId : null;
    }

    public function cached(Conversation $conversation, ?int $messageId = null): ?ConversationReplySuggestion
    {
        $messageId ??= $this->latestMessageId($conversation);

        if (! $messageId) {
            return null;
        }

        return ConversationReplySuggestion::query()
            ->where('conversation_id', $conversation->id)
            ->where('message_id', $messageId)
            ->latest('generated_at')
            ->first();
    }

    public function queue(Conversation $conversation, ?int $messageId = null, bool $force = false): void
    {
        if (! $this->hasProvider()) {
            return;
        }

        $messageId ??= $this->latestMessageId($conversation);

        if (! $messageId) {
            return;
        }

        if (! $force && $this->cached($conversation, $messageId)) {
            return;
        }

        GenerateReplySuggestionsJob::dispatch((int) $conversation->id, $messageId, $force)->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function generate(Conversation $conversation, ?int $messageId = null, bool $force = false): array
    {
        $messageId ??= $this->latestMessageId($conversation);

        if (! $messageId || ! $this->hasProvider()) {
            return [];
        }

        if (! $force && $cached = $this->cached($conversation, $messageId)) {
            return (array) $cached->suggestions;
        }

        $suggestions = $this->nim->suggest($conversation);

        if ($suggestions === []) {
            return [];
        }

        ConversationReplySuggestion::query()->updateOrCreate(
            [
                'conversation_id' => $conversation->id,
                'message_id' => $messageId,
            ],
            [
                'provider' => 'nvidia-nim',
                'suggestions' => array_values($suggestions),
                'generated_at' => now(),
            ],
        );

        return $suggestions;
    }
}
