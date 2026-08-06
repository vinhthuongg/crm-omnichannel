<?php

namespace Modules\Zalo\Actions;

use Modules\Message\Models\Message;
use Modules\Message\Services\MessageService;
use Modules\Zalo\DTO\ZaloWebhookMessageData;

class HandleZaloWebhookAction
{
    /** Nhận MessageService để ghi nhận và gửi tin nhắn trong hội thoại. */
    public function __construct(private readonly MessageService $messages)
    {
    }

    /** Chuyển payload webhook Zalo thành tin inbound và lưu vào hội thoại CRM. */
    public function execute(array $payload): ?Message
    {
        if (! data_get($payload, 'message')) {
            return null;
        }
        return $this->messages->storeInbound(ZaloWebhookMessageData::fromPayload($payload));
    }
}
