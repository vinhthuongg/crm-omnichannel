<?php

namespace Modules\Conversation\Services;

use Modules\Conversation\Support\ConversationStatus;

class ConversationStateMachine
{
    private const ALLOWED = [
        ConversationStatus::CUSTOMER_WAITING => [
            ConversationStatus::WAITING_CUSTOMER,
            ConversationStatus::BOT_CONSULTING,
            ConversationStatus::CLOSED,
        ],
        ConversationStatus::WAITING_CUSTOMER => [
            ConversationStatus::CUSTOMER_WAITING,
            ConversationStatus::BOT_CONSULTING,
            ConversationStatus::CLOSED,
        ],
        ConversationStatus::BOT_CONSULTING => [
            ConversationStatus::CUSTOMER_WAITING,
            ConversationStatus::WAITING_CUSTOMER,
            ConversationStatus::CLOSED,
        ],
        ConversationStatus::CLOSED => [
            ConversationStatus::CUSTOMER_WAITING,
        ],
    ];

    public function assertCanTransition(?string $from, string $to): void
    {
        $from = ConversationStatus::normalize($from);

        if ($from === $to) {
            return;
        }

        if (! in_array($to, self::ALLOWED[$from] ?? [], true)) {
            throw new \RuntimeException("Khong the chuyen trang thai hoi thoai tu {$from} sang {$to}.");
        }
    }
}
