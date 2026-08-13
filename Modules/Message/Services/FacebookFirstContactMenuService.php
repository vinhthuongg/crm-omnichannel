<?php

namespace Modules\Message\Services;

use Illuminate\Support\Facades\Log;
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

        $existingMenu = Message::query()->where('client_message_id', $clientId)->first();
        $phoneClientId = 'facebook-first-contact-phone-v3-'.$key;
        $existingPhone = Message::query()->where('client_message_id', $phoneClientId)->first();
        $menuAlreadyQueued = $existingMenu && in_array($existingMenu->outbound_status, ['queued', 'sending', 'sent'], true);
        $phoneAlreadyQueued = $existingPhone && in_array($existingPhone->outbound_status, ['pending', 'queued', 'sending', 'sent'], true);
        $phoneRequired = ($settings['phone_enabled'] ?? false) && filled($settings['phone_text'] ?? null);

        if ($menuAlreadyQueued && (! $phoneRequired || $phoneAlreadyQueued)) {
            return [];
        }

        $elements = $this->elements((array) ($settings['elements'] ?? []));

        if ($elements === []) {
            return [];
        }

        $messages = [];
        $text = trim((string) ($settings['text'] ?? ''));

        if (! $menuAlreadyQueued && $text !== '') {
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

        $message = $existingMenu ?: Message::query()->create([
            'channel' => 'facebook',
            'client_message_id' => $clientId,
            'conversation_id' => $conversation->id,
            'sender_type' => 'system',
            'sender_id' => null,
            'content' => null,
            'message_type' => 'attachment',
            'attachments' => [['type' => 'generic_template', 'elements' => $elements]],
            'outbound_status' => 'queued',
        ]);

        if ($existingMenu && ! $menuAlreadyQueued) {
            $message->forceFill([
                'conversation_id' => $conversation->id,
                'attachments' => [['type' => 'generic_template', 'elements' => $elements]],
                'outbound_status' => 'queued',
                'outbound_error' => null,
            ])->save();
            $message->wasRecentlyCreated = true;
        }
        if (! $menuAlreadyQueued) {
            $messages[] = $message;
        }

        if ($phoneRequired && ! $phoneAlreadyQueued) {
            $phoneAttachment = [[
                'type' => 'quick_reply',
                'quick_replies' => [['content_type' => 'user_phone_number']],
            ]];
            $phoneMessage = $existingPhone ?: Message::query()->create([
                'channel' => 'facebook',
                'client_message_id' => $phoneClientId,
                'conversation_id' => $conversation->id,
                'sender_type' => 'system',
                'sender_id' => null,
                'content' => trim((string) $settings['phone_text']),
                'message_type' => 'attachment',
                'attachments' => $phoneAttachment,
                'outbound_status' => 'pending',
            ]);
            if ($existingPhone) {
                $phoneMessage->forceFill([
                    'conversation_id' => $conversation->id,
                    'content' => trim((string) $settings['phone_text']),
                    'attachments' => $phoneAttachment,
                    'outbound_status' => 'pending',
                    'outbound_error' => null,
                ])->save();
                $phoneMessage->wasRecentlyCreated = true;
            }
        }

        foreach ($messages as $queued) {
            if ($queued->wasRecentlyCreated) {
                SendOutboundMessageJob::dispatch($queued->id);
            }
        }

        Log::info('Facebook first-contact menu queued', [
            'conversation_id' => $conversation->id,
            'customer_id' => $conversation->customer_id,
            'page_id' => $conversation->facebook_page_id,
            'message_ids' => collect($messages)->pluck('id')->all(),
            'retry' => (bool) $existingMenu,
        ]);

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
