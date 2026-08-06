<?php

namespace Modules\Conversation\Services;

use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Models\Tag;
use Modules\Customer\Models\Customer;
use Modules\Message\Models\Message;

class ConversationIntentService
{
    public const TAG_QUOTE = Tag::DEFAULT_QUOTE;
    public const TAG_TEST_DRIVE = Tag::DEFAULT_TEST_DRIVE;
    public const TAG_INSTALLMENT = Tag::DEFAULT_INSTALLMENT;
    public const TAG_APPOINTMENT = Tag::DEFAULT_APPOINTMENT;
    public const TAG_MAINTENANCE = 'Bảo dưỡng';
    public const TAG_PHONE = Tag::DEFAULT_PHONE;

    /** Phân loại ý định của tin nhắn để cập nhật hội thoại. */
    public function classifyMessage(Message $message): void
    {
        $conversation = $message->conversation()
            ->with(['customer', 'messages' => fn ($query) => $query->latest()->limit(8), 'tags'])
            ->first();

        if (! $conversation) {
            return;
        }

        $this->syncPhone($conversation->customer, (string) $message->content);
    }

    /** Lưu số điện thoại mới phát hiện vào khách hàng và ghi thời điểm thu thập lần đầu. */
    private function syncPhone(?Customer $customer, string $content): void
    {
        if (! $customer || filled($customer->phone)) {
            return;
        }

        $phone = $this->extractPhoneNumber($content);

        if ($phone) {
            $customer->forceFill(['phone' => $phone])->save();
        }
    }

    /** Chuẩn hóa nội dung và trích số điện thoại Việt Nam hợp lệ đầu tiên. */
    private function extractPhoneNumber(string $content): ?string
    {
        preg_match_all('/(?:\+?84|0)(?:[\s.\-()]?\d){8,10}/', $content, $matches);

        foreach ($matches[0] ?? [] as $candidate) {
            $normalized = preg_replace('/\D+/', '', $candidate) ?: '';

            if (str_starts_with($normalized, '84')) {
                $normalized = '0'.substr($normalized, 2);
            }

            if (preg_match('/^0\d{8,10}$/', $normalized)) {
                return $normalized;
            }
        }

        return null;
    }

}
