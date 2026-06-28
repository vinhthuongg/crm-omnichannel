<?php

namespace Modules\Conversation\Support;

final class ConversationStatus
{
    public const WAITING = 'waiting';
    public const IN_PROGRESS = 'in_progress';
    public const RESOLVED = 'resolved';
    public const CLOSED = 'closed';
    public const REOPENED = 'reopened';

    public const ACTIVE = [
        self::WAITING,
        self::IN_PROGRESS,
        self::REOPENED,
    ];

    public const ALL = [
        self::WAITING,
        self::IN_PROGRESS,
        self::RESOLVED,
        self::CLOSED,
        self::REOPENED,
    ];

    public static function normalize(?string $status): string
    {
        return match ($status) {
            'open' => self::IN_PROGRESS,
            'pending' => self::WAITING,
            self::WAITING,
            self::IN_PROGRESS,
            self::RESOLVED,
            self::CLOSED,
            self::REOPENED => $status,
            default => self::WAITING,
        };
    }

    public static function fromFilter(?string $status): ?string
    {
        return match ($status) {
            'open' => self::IN_PROGRESS,
            'pending' => self::WAITING,
            'closed' => self::CLOSED,
            self::WAITING,
            self::IN_PROGRESS,
            self::RESOLVED,
            self::CLOSED,
            self::REOPENED => $status,
            default => null,
        };
    }
}
