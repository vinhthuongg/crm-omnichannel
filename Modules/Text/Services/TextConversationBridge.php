<?php

namespace Modules\Text\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Customer\Models\CustomerChannel;
use Modules\Facebook\Services\FacebookThreadControlService;
use Modules\Text\Models\TextConversationLink;

class TextConversationBridge
{
    private const BOT_RESUME_AFTER_MINUTES = 1;

    public function __construct(
        private readonly TextAgentChatService $text,
        private readonly FacebookThreadControlService $threadControl,
    )
    {
    }

    public function pauseBotForConversation(Conversation $conversation): void
    {
        $this->threadControl->takeThreadControl($conversation);

        $link = TextConversationLink::query()
            ->firstOrNew(['conversation_id' => $conversation->id]);

        if (! $link?->text_chat_id) {
            $link->forceFill([
                'bot_paused_at' => now(),
                'bot_resume_due_at' => now()->addMinutes(self::BOT_RESUME_AFTER_MINUTES),
                'bot_resumed_at' => null,
            ])->save();

            Log::info('Text.com bot resume timer stored without Text chat mapping', [
                'conversation_id' => $conversation->id,
                'resume_due_at' => $link->bot_resume_due_at?->toDateTimeString(),
            ]);

            return;
        }

        if ($link->bot_paused_at) {
            $link->forceFill([
                'bot_resume_due_at' => now()->addMinutes(self::BOT_RESUME_AFTER_MINUTES),
                'bot_resumed_at' => null,
            ])->save();

            return;
        }

        if (! $this->text->transferChatToHuman($link->text_chat_id)) {
            return;
        }

        $link->forceFill([
            'bot_paused_at' => now(),
            'bot_resume_due_at' => now()->addMinutes(self::BOT_RESUME_AFTER_MINUTES),
            'bot_resumed_at' => null,
        ])->save();

        Log::info('Text.com bot paused by CRM outbound message', [
            'conversation_id' => $conversation->id,
            'text_chat_id' => $link->text_chat_id,
            'resume_due_at' => $link->bot_resume_due_at?->toDateTimeString(),
        ]);
    }

    public function resumeBotForLink(TextConversationLink $link): bool
    {
        $conversation = $link->conversation;

        if (! $conversation) {
            return false;
        }

        if (! $this->threadControl->passThreadControlToBot($conversation)) {
            return false;
        }

        $link->forceFill([
            'bot_paused_at' => null,
            'bot_resume_due_at' => null,
            'bot_resumed_at' => now(),
        ])->save();

        Log::info('Text.com bot resumed after CRM inactivity timeout', [
            'conversation_id' => $conversation->id,
            'text_chat_id' => $link->text_chat_id,
        ]);

        return true;
    }

    public function upsertFromWebhook(array $payload): ?TextConversationLink
    {
        $chatId = $this->firstString($payload, [
            'chat.id',
            'chat_id',
            'payload.chat.id',
            'payload.chat_id',
            'event.chat.id',
            'event.chat_id',
            'data.chat.id',
            'data.chat_id',
        ]);

        if ($chatId === '') {
            Log::warning('Text.com webhook skipped because chat id is missing', ['payload' => $payload]);

            return null;
        }

        $facebookPsid = $this->firstString($payload, [
            'facebook_psid',
            'facebook.sender_id',
            'messenger.sender_id',
            'source.facebook_psid',
            'source.customer_id',
            'customer.facebook_psid',
            'customer.id',
            'visitor.id',
            'author.id',
        ]);
        $facebookPageId = $this->firstString($payload, [
            'facebook_page_id',
            'facebook.page_id',
            'messenger.page_id',
            'source.page_id',
            'integration.facebook.page_id',
        ]);
        $conversation = $this->resolveConversation($facebookPsid, $facebookPageId);

        return TextConversationLink::query()->updateOrCreate(
            $conversation
                ? ['conversation_id' => $conversation->id]
                : ['text_chat_id' => $chatId],
            [
                'conversation_id' => $conversation?->id,
                'text_chat_id' => $chatId,
                'text_thread_id' => $this->firstString($payload, [
                    'thread.id',
                    'thread_id',
                    'payload.thread.id',
                    'payload.thread_id',
                    'event.thread.id',
                    'event.thread_id',
                ]) ?: null,
                'text_customer_id' => $this->firstString($payload, [
                    'customer.id',
                    'visitor.id',
                    'author.id',
                ]) ?: null,
                'facebook_page_id' => $facebookPageId ?: null,
                'facebook_psid' => $facebookPsid ?: null,
                'last_payload' => $payload,
            ],
        );
    }

    private function resolveConversation(string $facebookPsid, string $facebookPageId): ?Conversation
    {
        if ($facebookPsid === '') {
            return null;
        }

        $customerId = CustomerChannel::query()
            ->where('channel', 'facebook')
            ->where('external_id', $facebookPsid)
            ->value('customer_id');

        if (! $customerId) {
            return null;
        }

        return Conversation::query()
            ->where('customer_id', $customerId)
            ->when($facebookPageId !== '', fn ($query) => $query->where('facebook_page_id', $facebookPageId))
            ->whereIn('status', ConversationStatus::ACTIVE)
            ->latest('last_message_at')
            ->first();
    }

    private function firstString(array $payload, array $paths): string
    {
        foreach ($paths as $path) {
            $value = Arr::get($payload, $path);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }
}
