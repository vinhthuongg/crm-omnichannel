<?php

namespace Modules\Message\DTO;

final readonly class InboundMessageData
{
    public function __construct(
        public string $channel,
        public string $externalCustomerId,
        public string $customerName,
        public ?string $customerAvatar,
        public ?string $content,
        public string $messageType,
        public array $attachments,
        public ?string $externalMessageId,
        public array $metadata = [],
    ) {
    }
}