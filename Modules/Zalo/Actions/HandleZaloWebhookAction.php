<?php

namespace Modules\Zalo\Actions;

use Modules\Message\Models\Message;
use Modules\Message\Services\MessageService;
use Modules\Zalo\DTO\ZaloWebhookMessageData;

class HandleZaloWebhookAction
{
    public function __construct(private readonly MessageService $messages)
    {
    }

    public function execute(array $payload): ?Message
    {
        if (! data_get($payload, 'message')) {
            return null;
        }
        return $this->messages->storeInbound(ZaloWebhookMessageData::fromPayload($payload));
    }
}