<?php

namespace App\Services\Chatbot;

class ChatbotStreamParser
{
    private string $buffer = '';

    private string $event = 'message';

    private ?string $id = null;

    private array $data = [];

    private array $seen = [];

    /** Nhận một network chunk, giữ lại dòng chưa hoàn chỉnh và chỉ trả các SSE event đã kết thúc. */
    public function push(string $chunk): array
    {
        $this->buffer .= $chunk;
        $endsWithCarriageReturn = str_ends_with($this->buffer, "\r");
        $normalizable = $endsWithCarriageReturn ? substr($this->buffer, 0, -1) : $this->buffer;
        $this->buffer = str_replace(["\r\n", "\r"], "\n", $normalizable)
            .($endsWithCarriageReturn ? "\r" : '');
        $events = [];

        while (($position = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $position);
            $this->buffer = substr($this->buffer, $position + 1);

            if ($line === '') {
                if ($event = $this->flushEvent()) {
                    $events[] = $event;
                }

                continue;
            }

            if (str_starts_with($line, ':')) {
                continue;
            }

            [$field, $value] = array_pad(explode(':', $line, 2), 2, '');
            $value = str_starts_with($value, ' ') ? substr($value, 1) : $value;

            match ($field) {
                'event' => $this->event = $value,
                'id' => $this->id = $value,
                'data' => $this->data[] = $value,
                default => null,
            };
        }

        return $events;
    }

    /** Kết thúc stream và phát event cuối nếu server không gửi thêm dòng trống. */
    public function finish(): array
    {
        $events = [];

        if ($this->buffer !== '') {
            $events = array_merge($events, $this->push("\n"));
        }

        if ($event = $this->flushEvent()) {
            $events[] = $event;
        }

        return $events;
    }

    /** Chuẩn hóa một SSE event, giải mã JSON và loại event trùng theo id hoặc nội dung. */
    private function flushEvent(): ?array
    {
        if ($this->data === [] && $this->id === null && $this->event === 'message') {
            return null;
        }

        $raw = implode("\n", $this->data);
        $decoded = json_decode($raw, true);
        $event = [
            'id' => $this->id,
            'event' => $this->event,
            'data' => is_array($decoded) ? $decoded : ['text' => $raw],
        ];
        $fingerprint = match (true) {
            $this->id !== null && $this->id !== '' => 'id:'.$this->id,
            $this->event === 'message.completed' => 'completed:'.hash('sha256', $raw),
            default => null,
        };

        $this->event = 'message';
        $this->id = null;
        $this->data = [];

        if ($fingerprint !== null && isset($this->seen[$fingerprint])) {
            return null;
        }

        if ($fingerprint !== null) {
            $this->seen[$fingerprint] = true;
        }

        return $event;
    }
}
