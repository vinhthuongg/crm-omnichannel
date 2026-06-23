<?php

namespace Modules\Zalo\DTO;

use Modules\Message\DTO\InboundMessageData;

final readonly class ZaloWebhookMessageData
{
    public static function fromPayload(array $payload): InboundMessageData
    {
        $senderId = (string) data_get($payload, 'sender.id', data_get($payload, 'user_id'));
        $message = data_get($payload, 'message', []);

        return new InboundMessageData('zalo', $senderId, (string) data_get($payload, 'sender.name', $senderId), data_get($payload, 'sender.avatar'), data_get($message, 'text'), data_get($message, 'attachments') ? 'attachment' : 'text', data_get($message, 'attachments', []), (string) data_get($message, 'msg_id'), ['raw' => $payload]);
    }
}