<?php

namespace Modules\Chatbot\DTO;

final readonly class ChatbotReply
{
    public function __construct(
        public string $content,
        public array $quickReplies = [],
        public ?string $clientMessageKey = null,
    ) {
    }
}
