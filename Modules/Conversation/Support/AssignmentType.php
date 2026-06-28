<?php

namespace Modules\Conversation\Support;

final class AssignmentType
{
    public const MANUAL = 'manual';
    public const CLAIM = 'claim';
    public const TRANSFER = 'transfer';

    public const ALL = [
        self::MANUAL,
        self::CLAIM,
        self::TRANSFER,
    ];
}
