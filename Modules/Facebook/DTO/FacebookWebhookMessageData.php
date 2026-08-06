<?php

namespace Modules\Facebook\DTO;

use Modules\Message\DTO\InboundMessageData;

final readonly class FacebookWebhookMessageData
{
    /** Lấy messaging event đầu tiên trong payload Facebook để tạo dữ liệu tin đến. */
    public static function fromPayload(array $payload, array $profile = []): InboundMessageData
    {
        return self::fromMessagingEvent($payload['entry'][0]['messaging'][0] ?? [], $profile);
    }

    /** Chuẩn hóa sender, Page, nội dung, quick reply và attachment của một messaging event. */
    public static function fromMessagingEvent(array $entry, array $profile = []): InboundMessageData
    {
        $senderId = (string) data_get($entry, 'sender.id');
        $pageId = (string) data_get($entry, 'recipient.id');
        $message = data_get($entry, 'message', []);
        $attachments = self::normalizeAttachments((array) data_get($message, 'attachments', []));
        $hasMessageAttachments = $attachments !== [];
        $quickReplyPayload = trim((string) data_get($message, 'quick_reply.payload', ''));
        $quickReplyTitle = trim((string) data_get($message, 'text', ''));
        $content = $quickReplyPayload !== '' ? $quickReplyPayload : data_get($message, 'text');

        if ($quickReplyPayload !== '') {
            $attachments[] = [
                'name' => 'Quick reply',
                'type' => 'quick_reply',
                'payload' => [
                    'title' => $quickReplyTitle,
                    'text' => $quickReplyPayload,
                    'payload' => $quickReplyPayload,
                    'raw' => data_get($message, 'quick_reply', []),
                ],
            ];
        }

        $name = trim((string) data_get($profile, 'first_name').' '.(string) data_get($profile, 'last_name'));
        $name = $name !== '' ? $name : (string) data_get($profile, 'name', '');
        $avatar = data_get($profile, 'profile_pic');

        return new InboundMessageData('facebook', $senderId, $name !== '' ? $name : $senderId, $avatar, $content, $hasMessageAttachments ? 'attachment' : 'text', $attachments, data_get($message, 'mid'), ['raw' => $entry, 'profile' => $profile, 'facebook_page_id' => $pageId, 'shared_phone_number' => self::phoneFromQuickReply($quickReplyPayload), 'quick_reply_title' => $quickReplyTitle, 'quick_reply_payload' => $quickReplyPayload]);
    }

    /** Trích số điện thoại khách chia sẻ trong quick reply của Facebook. */
    private static function phoneFromQuickReply(string $payload): ?string
    {
        if ($payload === '') {
            return null;
        }

        $normalized = preg_replace('/\D+/', '', $payload) ?: '';

        if (str_starts_with($normalized, '84')) {
            $normalized = '0'.substr($normalized, 2);
        }

        return preg_match('/^0\d{8,10}$/', $normalized) ? $normalized : null;
    }

    /** Chuẩn hóa attachment webhook thành URL, loại, tên và payload có thể lưu. */
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
