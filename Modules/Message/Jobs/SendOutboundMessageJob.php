<?php

namespace Modules\Message\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Message\Services\OutboundMessageProcessor;

class SendOutboundMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public array $backoff = [2, 5, 15];

    /** Lưu ID tin nhắn cần gửi để queue có thể nạp lại message khi thực thi. */
    public function __construct(public readonly int $messageId) { $this->onQueue('outbound'); }

    /** Nạp message theo ID và chuyển cho processor gửi sang kênh ngoài. */
    public function handle(OutboundMessageProcessor $processor): void
    {
        $processor->process($this->messageId, $this->attempts(), $this->tries);
    }
}
