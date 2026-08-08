<?php

namespace App\Services\Chatbot;

use RuntimeException;

class ChatbotException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly bool $retryable = false,
        public readonly ?string $requestId = null,
    ) {
        parent::__construct($message, $statusCode ?? 0);
    }
}
