<?php

namespace Modules\Message\Services;

use App\Services\Chatbot\ChatbotClient;
use App\Services\Chatbot\ChatbotException;
use Illuminate\Support\Facades\DB;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Message\Events\ChatbotMessageBreak;
use Modules\Message\Events\ChatbotResponseCompleted;
use Modules\Message\Events\ChatbotResponseDelta;
use Modules\Message\Events\ChatbotResponseStarted;
use Modules\Message\Http\Resources\MessageResource;
use Modules\Message\Jobs\SendOutboundMessageJob;
use Modules\Message\Models\ChatbotResponse;
use Modules\Message\Models\Message;

class ChatbotResponseProcessor
{
    public function __construct(
        private readonly ChatbotClient $client,
        private readonly MessagePostProcessor $post,
    ) {}

    /** Gọi chatbot bằng định danh CRM ổn định, broadcast từng delta và chỉ lưu message AI khi completed. */
    public function process(ChatbotResponse $response): void
    {
        if ($response->status === 'completed') {
            return;
        }

        $response->loadMissing(['conversation.customer', 'sourceMessage']);
        $conversation = $response->conversation;
        $source = $response->sourceMessage;

        if (! $conversation || ! $source || $source->sender_type !== 'customer') {
            throw new ChatbotException('Không tìm thấy ngữ cảnh tin khách hợp lệ.', 409, false);
        }

        $segments = [''];
        $completed = false;
        $response->forceFill([
            'status' => 'streaming',
            'segments' => [],
            'error_code' => null,
            'error_message' => null,
            'started_at' => now(),
        ])->save();

        event(new ChatbotResponseStarted($conversation->id, $response->id));

        $payload = [
            'crmConversationId' => (string) $conversation->id,
            'customerId' => (string) $conversation->customer_id,
            'externalMessageId' => $response->external_message_id,
            'message' => (string) $source->content,
            'userContext' => [
                'channel' => (string) $source->channel,
                'displayName' => (string) ($conversation->customer?->name ?? 'Khách hàng'),
            ],
        ];

        $this->client->stream($payload, function (array $event, ?string $requestId) use ($response, &$segments, &$completed): void {
            if ($requestId && $response->request_id !== $requestId) {
                $response->forceFill(['request_id' => $requestId])->save();
            }

            $data = (array) ($event['data'] ?? []);

            match ((string) ($event['event'] ?? '')) {
                'message.start', 'message.meta' => $this->captureMetadata($response, $data),
                'message.delta' => $this->appendDelta($response, $segments, $data),
                'message.break' => $this->breakSegment($response, $segments),
                'message.completed' => $completed = $this->complete($response, $segments, $data),
                'message.error' => throw new ChatbotException($this->safeError($data), null, false, $requestId),
                default => null,
            };
        });

        if (! $completed) {
            throw new ChatbotException('Luồng chatbot kết thúc trước event completed.', null, true, $response->request_id);
        }
    }

    /** Ghi mã message/request phục vụ audit nhưng không chuyển metadata thô lên frontend. */
    private function captureMetadata(ChatbotResponse $response, array $data): void
    {
        $messageId = data_get($data, 'chatbotMessageId') ?? data_get($data, 'messageId') ?? data_get($data, 'id');

        if ($messageId) {
            $response->forceFill(['chatbot_message_id' => (string) $messageId])->save();
        }
    }

    /** Nối delta vào bubble hiện tại và chỉ broadcast phần văn bản mới. */
    private function appendDelta(ChatbotResponse $response, array &$segments, array $data): void
    {
        $delta = $this->text($data);

        if ($delta === '') {
            return;
        }

        $index = count($segments) - 1;
        $segments[$index] .= $delta;
        event(new ChatbotResponseDelta($response->conversation_id, $response->id, [
            'segment' => $index,
            'delta' => $delta,
        ]));
    }

    /** Kết thúc bubble có nội dung và báo frontend tạo bubble AI mới. */
    private function breakSegment(ChatbotResponse $response, array &$segments): void
    {
        if (trim((string) end($segments)) === '') {
            return;
        }

        $segments[] = '';
        event(new ChatbotMessageBreak($response->conversation_id, $response->id, [
            'segment' => count($segments) - 1,
        ]));
    }

    /** Hoàn tất đúng một lần, lưu từng bubble thành message system rồi đưa ra queue gửi kênh ngoài. */
    private function complete(ChatbotResponse $response, array $segments, array $data): bool
    {
        $response->refresh();

        if ($response->status === 'completed') {
            return true;
        }

        $this->captureMetadata($response, $data);
        $segments = array_values(array_filter(array_map('trim', $segments), fn (string $text): bool => $text !== ''));

        if ($segments === []) {
            $final = trim($this->text($data));
            $segments = $final !== '' ? [$final] : [];
        }

        if ($segments === []) {
            throw new ChatbotException('Chatbot completed nhưng không có nội dung.', null, true, $response->request_id);
        }

        $messages = DB::transaction(function () use ($response, $segments): array {
            $locked = ChatbotResponse::query()->lockForUpdate()->findOrFail($response->id);

            if ($locked->status === 'completed') {
                return Message::query()->where('sender_type', 'system')
                    ->where('client_message_id', 'like', 'chatbot-'.$locked->id.'-%')->get()->all();
            }

            $created = [];

            foreach ($segments as $index => $text) {
                $created[] = Message::query()->create([
                    'conversation_id' => $locked->conversation_id,
                    'sender_type' => 'system',
                    'sender_id' => null,
                    'channel' => $locked->sourceMessage->channel,
                    'content' => $text,
                    'message_type' => 'text',
                    'attachments' => [],
                    'client_message_id' => 'chatbot-'.$locked->id.'-'.$index,
                    'outbound_status' => in_array($locked->sourceMessage->channel, ['facebook', 'zalo'], true) ? 'queued' : null,
                ]);
            }

            $locked->forceFill([
                'status' => 'completed',
                'segments' => $segments,
                'completed_at' => now(),
                'error_code' => null,
                'error_message' => null,
            ])->save();
            $locked->conversation->forceFill([
                'status' => ConversationStatus::WAITING_CUSTOMER,
                'last_message_at' => end($created)->created_at,
            ])->save();

            return $created;
        });

        foreach ($messages as $message) {
            $this->post->broadcast($message);

            if ($message->outbound_status === 'queued') {
                SendOutboundMessageJob::dispatch($message->id);
            }
        }

        $payload = collect($messages)->map(fn (Message $message): array => (new MessageResource(
            $message->loadMissing(['sender', 'conversation.customer', 'conversation.tags'])
        ))->resolve())->values()->all();
        event(new ChatbotResponseCompleted($response->conversation_id, $response->id, ['messages' => $payload]));

        return true;
    }

    /** Lấy phần text từ các biến thể payload SSE được chatbot hỗ trợ. */
    private function text(array $data): string
    {
        return (string) (data_get($data, 'delta') ?? data_get($data, 'text') ?? data_get($data, 'content') ?? data_get($data, 'message.content') ?? '');
    }

    /** Chuyển lỗi SSE thành thông báo nội bộ ngắn, không chứa stack trace hoặc payload nhạy cảm. */
    private function safeError(array $data): string
    {
        $code = (string) (data_get($data, 'code') ?? 'stream_error');

        return 'Chatbot báo lỗi: '.$code;
    }
}
