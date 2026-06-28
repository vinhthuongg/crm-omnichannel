<?php

namespace Modules\Conversation\Services;

use Modules\Conversation\Support\ConversationStatus;

class ConversationStateMachine
{
    private const ALLOWED = [
        ConversationStatus::WAITING => [
            ConversationStatus::IN_PROGRESS,
        ],
        ConversationStatus::IN_PROGRESS => [
            ConversationStatus::WAITING,
            ConversationStatus::RESOLVED,
        ],
        ConversationStatus::RESOLVED => [
            ConversationStatus::CLOSED,
        ],
        ConversationStatus::CLOSED => [
            ConversationStatus::REOPENED,
        ],
        ConversationStatus::REOPENED => [
            ConversationStatus::IN_PROGRESS,
            ConversationStatus::WAITING,
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
