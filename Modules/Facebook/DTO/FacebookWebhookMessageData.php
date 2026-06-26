<?php

namespace Modules\Facebook\DTO;

use Modules\Message\DTO\InboundMessageData;

final readonly class FacebookWebhookMessageData
{
    public static function fromPayload(array $payload, array $profile = []): InboundMessageData
    {
        return self::fromMessagingEvent($payload['entry'][0]['messaging'][0] ?? [], $profile);
    }

    public static function fromMessagingEvent(array $entry, array $profile = []): InboundMessageData
    {
        $senderId = (string) data_get($entry, 'sender.id');
        $pageId = (string) data_get($entry, 'recipient.id');
        $message = data_get($entry, 'message', []);
        $attachments = self::normalizeAttachments((array) data_get($message, 'attachments', []));
        $name = trim((string) data_get($profile, 'first_name').' '.(string) data_get($profile, 'last_name'));
        $name = $name !== '' ? $name : (string) data_get($profile, 'name', '');
        $avatar = data_get($profile, 'profile_pic');

        return new InboundMessageData('facebook', $senderId, $name !== '' ? $name : $senderId, $avatar, data_get($message, 'text'), $attachments ? 'attachment' : 'text', $attachments, data_get($message, 'mid'), ['raw' => $entry, 'profile' => $profile, 'facebook_page_id' => $pageId]);
    }

    private static function normalizeAttachments(array $attachments): array
    {
        return collect($attachments)
            ->map(function (array $attachment): array {
                $type = (string) data_get($attachment, 'type', 'file');
                $url = (string) (data_get($attachment, 'payload.url') ?: data_get($attachment, 'url', ''));
                $name = $url ? basename((string) parse_url($url, PHP_URL_PATH)) : ucfirst($type);

                return [
                    'name' => $name ?: ucfirst($type),
                    'url' => $url,
                    'type' => $type,
                    'mime_type' => (string) data_get($attachment, 'mime_type', ''),
                    'payload' => data_get($attachment, 'payload', []),
                ];
            })
            ->filter(fn (array $attachment): bool => $attachment['url'] !== '')
            ->unique('url')
            ->values()
            ->all();
    }
}
