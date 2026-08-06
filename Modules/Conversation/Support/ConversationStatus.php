<?php

namespace Modules\Conversation\Support;

final class ConversationStatus
{
    public const CLOSED = 'closed';
    public const WAITING_CUSTOMER = 'waiting_customer';
    public const BOT_CONSULTING = 'bot_consulting';
    public const CUSTOMER_WAITING = 'customer_waiting';

    public const WAITING = self::CUSTOMER_WAITING;
    public const IN_PROGRESS = self::WAITING_CUSTOMER;
    public const RESOLVED = self::CLOSED;
    public const REOPENED = self::CUSTOMER_WAITING;

    public const ACTIVE = [
        self::CUSTOMER_WAITING,
        self::WAITING_CUSTOMER,
        self::BOT_CONSULTING,
    ];

    public const ALL = [
        self::CLOSED,
        self::WAITING_CUSTOMER,
        self::BOT_CONSULTING,
        self::CUSTOMER_WAITING,
    ];

    /** Ánh xạ trạng thái cũ hoặc alias đầu vào về trạng thái hội thoại chuẩn của hệ thống. */
    public static function normalize(?string $status): string
    {
        return match ($status) {
            'open',
            'in_progress' => self::WAITING_CUSTOMER,
            'pending',
            'waiting',
            'reopened' => self::CUSTOMER_WAITING,
            'resolved',
            self::CLOSED => self::CLOSED,
            self::WAITING_CUSTOMER,
            self::BOT_CONSULTING,
            self::CUSTOMER_WAITING => $status,
            default => self::CUSTOMER_WAITING,
        };
    }

    /** Chuyển giá trị bộ lọc đầu vào thành trạng thái hội thoại hợp lệ. */
    public static function fromFilter(?string $status): ?string
    {
        return match ($status) {
            'open',
            'in_progress' => self::WAITING_CUSTOMER,
            'pending',
            'waiting',
            'reopened' => self::CUSTOMER_WAITING,
            'resolved',
            self::CLOSED => self::CLOSED,
            self::WAITING_CUSTOMER,
            self::BOT_CONSULTING,
            self::CUSTOMER_WAITING => $status,
            default => null,
        };
    }

    /** Trả về nhãn hiển thị tương ứng với trạng thái hiện tại. */
    public static function label(?string $status): string
    {
        return match (self::normalize($status)) {
            self::CLOSED => 'Đóng',
            self::WAITING_CUSTOMER => 'Đợi khách trả lời',
            self::BOT_CONSULTING => 'Bot đang tư vấn',
            default => 'Khách đợi rep tin nhắn',
        };
    }

    /** Trả về mã màu giao diện tương ứng với trạng thái hiện tại. */
    public static function color(?string $status): string
    {
        return match (self::normalize($status)) {
            self::CLOSED => '#64748b',
            self::WAITING_CUSTOMER => '#2563eb',
            self::BOT_CONSULTING => '#7c3aed',
            default => '#f59e0b',
        };
    }
}
