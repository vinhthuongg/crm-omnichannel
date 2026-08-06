<?php

namespace Modules\Facebook\Services;

use Modules\Facebook\Models\FacebookPage;
use Modules\Message\Models\Message;
use Modules\Message\Services\MessageService;

class FacebookWebhookEchoHandler
{
    /** Khởi tạo handler với message workflow và Messenger gateway. */
    public function __construct(private readonly MessageService $messages, private readonly FacebookMessengerService $facebook) {}

    /** Ghi nhận message echo và trả về message nội bộ tương ứng. */
    public function handle(array $event): ?Message
    {
        $message = $this->messages->storeFacebookEcho($event);
        $this->stopTyping($event);
        return $message;
    }

    /** Tắt trạng thái typing sau khi Facebook xác nhận tin đã gửi. */
    private function stopTyping(array $event): void
    {
        $externalId = (string) data_get($event, 'message.mid');
        $customerId = (string) data_get($event, 'recipient.id');
        $pageId = (string) data_get($event, 'sender.id');
        if ($externalId === '' || $customerId === '' || $pageId === '') return;
        if (! Message::query()->where('external_message_id', $externalId)->where('sender_type', 'system')->exists()) return;
        $page = FacebookPage::query()->where('page_id', $pageId)->first();
        if (! $page?->page_access_token) return;
        app()->terminating(fn () => $this->facebook->sendTypingOff($customerId, $page->page_access_token));
    }
}
