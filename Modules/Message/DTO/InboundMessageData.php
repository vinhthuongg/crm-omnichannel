<?php

namespace Modules\Message\DTO;

final readonly class InboundMessageData
{
    /** Đóng gói kênh, khách hàng ngoài hệ thống, nội dung, attachment và metadata của một tin nhắn đến. */
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
