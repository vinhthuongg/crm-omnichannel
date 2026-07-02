<?php

namespace Modules\Text\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Customer\Models\CustomerChannel;
use Modules\Text\Models\TextConversationLink;

class TextConversationBridge
{
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

        $criteria = $conversation
            ? ['conversation_id' => $conversation->id]
            : ['text_chat_id' => $chatId];
        $existing = TextConversationLink::query()
            ->when(isset($criteria['conversation_id']), fn ($query) => $query->where('conversation_id', $criteria['conversation_id']))
            ->when(isset($criteria['text_chat_id']), fn ($query) => $query->where('text_chat_id', $criteria['text_chat_id']))
            ->first();

        return TextConversationLink::query()->updateOrCreate(
            $criteria,
            [
                'conversation_id' => $conversation?->id ?? $existing?->conversation_id,
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
                ]) ?: $existing?->text_customer_id,
                'facebook_page_id' => $facebookPageId ?: $existing?->facebook_page_id,
                'facebook_psid' => $facebookPsid ?: $existing?->facebook_psid,
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
