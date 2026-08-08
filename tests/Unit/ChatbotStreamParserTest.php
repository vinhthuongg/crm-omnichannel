<?php

namespace Tests\Unit;

use App\Services\Chatbot\ChatbotStreamParser;
use PHPUnit\Framework\TestCase;

class ChatbotStreamParserTest extends TestCase
{
    /** Xác nhận parser giữ buffer khi một SSE event bị cắt giữa nhiều network chunk. */
    public function test_it_parses_events_split_across_chunks(): void
    {
        $parser = new ChatbotStreamParser;

        $this->assertSame([], $parser->push("id: 1\nevent: message.del"));
        $events = $parser->push("ta\ndata: {\"delta\":\"Xin chào\"}\n\n");

        $this->assertCount(1, $events);
        $this->assertSame('message.delta', $events[0]['event']);
        $this->assertSame('Xin chào', $events[0]['data']['delta']);
    }

    /** Xác nhận event break và completed được giữ đúng thứ tự. */
    public function test_it_parses_break_and_completed_events(): void
    {
        $parser = new ChatbotStreamParser;
        $events = $parser->push("event: message.break\ndata: {}\n\nevent: message.completed\ndata: {\"messageId\":\"m1\"}\n\n");

        $this->assertSame(['message.break', 'message.completed'], array_column($events, 'event'));
    }

    /** Xác nhận event trùng id hoặc trùng completed payload không được phát lần hai. */
    public function test_it_deduplicates_repeated_events(): void
    {
        $parser = new ChatbotStreamParser;
        $chunk = "id: done-1\nevent: message.completed\ndata: {\"text\":\"Xong\"}\n\n";

        $this->assertCount(1, $parser->push($chunk));
        $this->assertSame([], $parser->push($chunk));
    }

    /** Xác nhận hai delta giống nhau nhưng không có event id vẫn được giữ vì có thể là token hợp lệ lặp lại. */
    public function test_it_keeps_identical_deltas_without_event_ids(): void
    {
        $parser = new ChatbotStreamParser;
        $chunk = "event: message.delta\ndata: {\"delta\":\"ha\"}\n\n";

        $this->assertCount(1, $parser->push($chunk));
        $this->assertCount(1, $parser->push($chunk));
    }

    /** Xác nhận CRLF bị cắt giữa hai chunk không tạo event rỗng hoặc làm mất dữ liệu. */
    public function test_it_handles_crlf_split_between_chunks(): void
    {
        $parser = new ChatbotStreamParser;

        $this->assertSame([], $parser->push("event: message.delta\r"));
        $events = $parser->push("\ndata: {\"delta\":\"A\"}\r\n\r\n");

        $this->assertCount(1, $events);
        $this->assertSame('A', $events[0]['data']['delta']);
    }

    /** Xác nhận event error được giải mã để job có thể đánh dấu response thất bại. */
    public function test_it_parses_error_event(): void
    {
        $parser = new ChatbotStreamParser;
        $events = $parser->push("event: message.error\ndata: {\"code\":\"MODEL_TIMEOUT\"}\n\n");

        $this->assertSame('MODEL_TIMEOUT', $events[0]['data']['code']);
    }
}
