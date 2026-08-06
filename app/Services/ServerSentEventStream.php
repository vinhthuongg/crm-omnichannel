<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\StreamedResponse;

class ServerSentEventStream
{
    /** Tạo StreamedResponse SSE với header chống cache và callback phát sự kiện. */
    public function response(callable $poll): StreamedResponse
    {
        return response()->stream(function () use ($poll): void {
            if (function_exists('set_time_limit')) @set_time_limit(0);
            if (function_exists('session_write_close')) @session_write_close();
            $startedAt = time();
            echo "retry: 1000\n\n".': '.str_repeat(' ', 2048)."\n\n";
            $this->flush();
            while (! connection_aborted() && time() - $startedAt < 55) {
                $poll();
                echo ": heartbeat\n\n";
                $this->flush();
                sleep(1);
            }
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive', 'X-Accel-Buffering' => 'no']);
    }

    /** Ghi một sự kiện SSE đã định dạng vào output stream. */
    public function write(int $id, array $payload): void
    {
        echo 'id: '.$id."\n"."event: message\n".'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
    }

    /** Đẩy ngay dữ liệu đang đệm ra client SSE. */
    private function flush(): void
    {
        if (ob_get_level() > 0) @ob_flush();
        @flush();
    }
}
