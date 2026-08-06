<?php

namespace Modules\Message\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Message\Models\Message;
use Modules\Message\Services\CustomerIdleFollowUpService;

class SendCustomerIdleFollowUpJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    /** Lưu ID hội thoại và tin nguồn để kiểm tra trạng thái trước khi gửi follow-up. */
    public function __construct(
        public readonly int $conversationId,
        public readonly int $sourceMessageId,
    ) {
        $this->onQueue('default');
    }

    /** Xếp job trì hoãn cho tin outbound hiển thị với khách qua Facebook hoặc Zalo. */
    public static function dispatchFor(Message $message): void
    {
        if (! CustomerIdleFollowUpService::isCustomerVisibleOutbound($message)) {
            return;
        }

        $minutes = max(1, (int) config('services.customer_idle_follow_up_minutes', 3));

        self::dispatch((int) $message->conversation_id, (int) $message->id)
            ->delay(now()->addMinutes($minutes));
    }

    /** Kiểm tra hội thoại còn im lặng rồi tạo và xếp hàng tin follow-up. */
    public function handle(CustomerIdleFollowUpService $followUpService): void
    {
        $followUpService->send($this->conversationId, $this->sourceMessageId);
    }
}
