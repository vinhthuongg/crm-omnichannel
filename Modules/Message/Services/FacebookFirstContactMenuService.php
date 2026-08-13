<?php

namespace Modules\Message\Services;

use Modules\Conversation\Models\Conversation;
use Modules\Facebook\Services\FacebookFirstContactMenuSettings;
use Modules\Message\Jobs\SendOutboundMessageJob;
use Modules\Message\Models\Message;

class FacebookFirstContactMenuService
{
    public function __construct(private readonly FacebookFirstContactMenuSettings $settings) {}

    /** Tạo và xếp gửi menu carousel đúng một lần khi khách Facebook nhắn lần đầu cho Page. */
    public function queue(Conversation $conversation, Message $source): array
    {
        $settings = $this->settings->get();

        if (! ($settings['enabled'] ?? false) || $source->channel !== 'facebook') {
            return [];
        }

        $key = $conversation->facebook_page_id.'-'.$conversation->customer_id;
        $clientId = 'facebook-first-contact-menu-'.$key;

        if (Message::query()->where('client_message_id', $clientId)->exists()) {
            return [];
        }

        $isFirstCustomerMessage = Message::query()
            ->where('channel', 'facebook')
            ->where('sender_type', 'customer')
            ->whereHas('conversation', fn ($query) => $query
                ->where('customer_id', $conversation->customer_id)
                ->where('facebook_page_id', $conversation->facebook_page_id))
            ->count() === 1;

        if (! $isFirstCustomerMessage) {
            return [];
        }

        $elements = $this->elements((array) ($settings['elements'] ?? []));

        if ($elements === []) {
            return [];
        }

        $messages = [];
        $text = trim((string) ($settings['text'] ?? ''));

        if ($text !== '') {
            $messages[] = Message::query()->firstOrCreate(
                ['channel' => 'facebook', 'client_message_id' => 'facebook-first-contact-text-'.$key],
                [
                    'conversation_id' => $conversation->id,
                    'sender_type' => 'system',
                    'sender_id' => null,
                    'content' => $text,
                    'message_type' => 'text',
                    'attachments' => [],
                    'outbound_status' => 'queued',
                ],
            );
        }

        $message = Message::query()->firstOrCreate(
            ['channel' => 'facebook', 'client_message_id' => $clientId],
            [
                'conversation_id' => $conversation->id,
                'sender_type' => 'system',
                'sender_id' => null,
                'content' => null,
                'message_type' => 'attachment',
                'attachments' => [['type' => 'generic_template', 'elements' => $elements]],
                'outbound_status' => 'queued',
            ],
        );
        $messages[] = $message;

        foreach ($messages as $queued) {
            if ($queued->wasRecentlyCreated) {
                SendOutboundMessageJob::dispatch($queued->id);
            }
        }

        return $messages;
    }

    /** Chuẩn hóa tối đa 10 thẻ Generic Template và tối đa 3 nút postback trên mỗi thẻ. */
    private function elements(array $configured): array
    {
        return collect($configured)
            ->map(function (array $element): ?array {
                $title = trim((string) ($element['title'] ?? ''));
                $buttons = collect((array) ($element['buttons'] ?? []))->map(function (array $button): ?array {
                    $title = trim((string) ($button['title'] ?? ''));
                    $payload = trim((string) ($button['payload'] ?? ''));

                    return $title !== '' && $payload !== '' ? [
                        'type' => 'postback',
                        'title' => mb_substr($title, 0, 20),
                        'payload' => mb_substr($payload, 0, 1000),
                    ] : null;
                })->filter()->take(3)->values()->all();

                if ($title === '' || $buttons === []) {
                    return null;
                }

                return array_filter([
                    'title' => mb_substr($title, 0, 80),
                    'subtitle' => mb_substr(trim((string) ($element['subtitle'] ?? '')), 0, 80),
                    'image_url' => filter_var($element['image_url'] ?? null, FILTER_VALIDATE_URL) ?: null,
                    'buttons' => $buttons,
                ]);
            })->filter()->take(10)->values()->all();
    }
}
