<?php

namespace Modules\Message\Services;

use App\Services\Chatbot\ChatbotClient;
use App\Services\Chatbot\ChatbotException;
use Illuminate\Support\Facades\DB;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Message\Events\ChatbotMessageBreak;
use Modules\Message\Events\ChatbotResponseCompleted;
use Modules\Message\Events\ChatbotResponseDelta;
use Modules\Message\Events\ChatbotResponseMedia;
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
        $media = [[]];
        $quickReplies = [];
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

        $this->client->stream($payload, function (array $event, ?string $requestId) use ($response, &$segments, &$media, &$quickReplies, &$completed): void {
            if ($requestId && $response->request_id !== $requestId) {
                $response->forceFill(['request_id' => $requestId])->save();
            }

            $data = (array) ($event['data'] ?? []);

            match ((string) ($event['event'] ?? '')) {
                'message.start', 'message.meta' => $this->captureContext($response, $quickReplies, $data),
                'message.delta' => $this->appendDelta($response, $segments, $data),
                'message.media' => $this->appendMedia($response, $media, $data),
                'message.break' => $this->breakSegment($response, $segments, $media),
                'message.completed' => $completed = $this->complete($response, $segments, $media, $quickReplies, $data),
                'message.error' => throw new ChatbotException($this->safeError($data), null, false, $requestId),
                default => null,
            };
        });

        if (! $completed) {
            throw new ChatbotException('Luồng chatbot kết thúc trước event completed.', null, true, $response->request_id);
        }
    }

    /** Ghi metadata audit và thu nhận quick reply theo ngữ cảnh nếu chatbot cung cấp. */
    private function captureContext(ChatbotResponse $response, array &$quickReplies, array $data): void
    {
        $this->captureMetadata($response, $data);
        $provided = $this->quickReplies($data);

        if ($provided !== []) {
            $quickReplies = $provided;
        }
    }

    /** Chuẩn hóa media từ chatbot thành attachment CRM rồi broadcast bản an toàn để xem ngay. */
    private function appendMedia(ChatbotResponse $response, array &$media, array $data): void
    {
        $attachments = $this->attachments($data);

        if ($attachments === []) {
            return;
        }

        $index = count($media) - 1;
        $media[$index] = array_values(array_merge($media[$index] ?? [], $attachments));
        event(new ChatbotResponseMedia($response->conversation_id, $response->id, [
            'segment' => $index,
            'attachments' => $attachments,
        ]));
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
    private function breakSegment(ChatbotResponse $response, array &$segments, array &$media): void
    {
        if (trim((string) end($segments)) === '' && ($media[array_key_last($media)] ?? []) === []) {
            return;
        }

        $segments[] = '';
        $media[] = [];
        event(new ChatbotMessageBreak($response->conversation_id, $response->id, [
            'segment' => count($segments) - 1,
        ]));
    }

    /** Hoàn tất đúng một lần, lưu từng bubble thành message system rồi đưa ra queue gửi kênh ngoài. */
    private function complete(ChatbotResponse $response, array $segments, array $media, array $quickReplies, array $data): bool
    {
        $response->refresh();

        if ($response->status === 'completed') {
            return true;
        }

        $this->captureMetadata($response, $data);
        $quickReplies = $this->quickReplies($data) ?: $quickReplies;

        if (count(array_filter($segments, fn (string $text): bool => trim($text) !== '')) === 0) {
            $segments[0] = trim($this->text($data));
        }

        $completedMedia = $this->attachments($data);

        if ($completedMedia !== []) {
            $lastIndex = max(count($media) - 1, 0);
            $media[$lastIndex] = array_values(array_merge($media[$lastIndex] ?? [], $completedMedia));
        }

        $bubbles = collect(range(0, max(count($segments), count($media)) - 1))
            ->map(fn (int $index): array => [
                'content' => trim((string) ($segments[$index] ?? '')),
                'attachments' => array_values($media[$index] ?? []),
            ])
            ->filter(fn (array $bubble): bool => $bubble['content'] !== '' || $bubble['attachments'] !== [])
            ->values()
            ->all();

        if ($response->sourceMessage?->channel === 'facebook' && config('chatbot.quick_replies_enabled', true)) {
            $quickReplies = $quickReplies ?: (array) config('chatbot.default_quick_replies', []);
            $target = collect($bubbles)->keys()->reverse()->first(fn (int $index): bool => $bubbles[$index]['content'] !== '');

            if ($target !== null && $quickReplies !== []) {
                $bubbles[$target]['attachments'][] = ['type' => 'quick_reply', 'quick_replies' => $quickReplies];
            }
        }

        if ($bubbles === []) {
            throw new ChatbotException('Chatbot completed nhưng không có nội dung.', null, true, $response->request_id);
        }

        $messages = DB::transaction(function () use ($response, $bubbles): array {
            $locked = ChatbotResponse::query()->lockForUpdate()->findOrFail($response->id);

            if ($locked->status === 'completed') {
                return Message::query()->where('sender_type', 'system')
                    ->where('client_message_id', 'like', 'chatbot-'.$locked->id.'-%')->get()->all();
            }

            $created = [];

            foreach ($bubbles as $index => $bubble) {
                $created[] = Message::query()->create([
                    'conversation_id' => $locked->conversation_id,
                    'sender_type' => 'system',
                    'sender_id' => null,
                    'channel' => $locked->sourceMessage->channel,
                    'content' => $bubble['content'] !== '' ? $bubble['content'] : null,
                    'message_type' => $bubble['attachments'] !== [] ? 'attachment' : 'text',
                    'attachments' => $bubble['attachments'],
                    'client_message_id' => 'chatbot-'.$locked->id.'-'.$index,
                    'outbound_status' => in_array($locked->sourceMessage->channel, ['facebook', 'zalo'], true) ? 'queued' : null,
                ]);
            }

            $locked->forceFill([
                'status' => 'completed',
                'segments' => array_column($bubbles, 'content'),
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

    /** Nhận các biến thể payload message.media và chỉ giữ trường cần để hiển thị/gửi kênh ngoài. */
    private function attachments(array $data): array
    {
        $candidates = data_get($data, 'attachments')
            ?? data_get($data, 'media')
            ?? data_get($data, 'items')
            ?? $data;
        $candidates = array_is_list((array) $candidates) ? (array) $candidates : [(array) $candidates];

        return collect($candidates)->map(function ($item): ?array {
            $item = is_array($item) ? $item : [];
            $url = (string) (data_get($item, 'url') ?? data_get($item, 'src') ?? data_get($item, 'downloadUrl') ?? data_get($item, 'payload.url') ?? '');

            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

            if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false || ! in_array($scheme, ['http', 'https'], true)) {
                return null;
            }

            $mime = (string) (data_get($item, 'mime_type') ?? data_get($item, 'mimeType') ?? data_get($item, 'contentType') ?? '');
            $type = strtolower((string) (data_get($item, 'type') ?? data_get($item, 'mediaType') ?? ''));
            $type = match (true) {
                in_array($type, ['image', 'video', 'audio', 'file'], true) => $type,
                str_starts_with($mime, 'image/') => 'image',
                str_starts_with($mime, 'video/') => 'video',
                str_starts_with($mime, 'audio/') => 'audio',
                default => 'file',
            };
            $name = (string) (data_get($item, 'name') ?? data_get($item, 'filename') ?? basename((string) parse_url($url, PHP_URL_PATH)));

            return array_filter([
                'name' => $name !== '' ? $name : ucfirst($type),
                'url' => $url,
                'mime_type' => $mime,
                'type' => $type,
                'size' => is_numeric(data_get($item, 'size')) ? (int) data_get($item, 'size') : null,
            ], fn ($value): bool => $value !== null && $value !== '');
        })->filter()->unique('url')->values()->all();
    }

    /** Chuẩn hóa quickReplies động của chatbot về payload Facebook Messenger hợp lệ. */
    private function quickReplies(array $data): array
    {
        $items = data_get($data, 'quickReplies')
            ?? data_get($data, 'quick_replies')
            ?? data_get($data, 'suggestions')
            ?? data_get($data, 'message.quickReplies')
            ?? [];

        return collect((array) $items)->map(function ($item): ?array {
            if (is_string($item)) {
                $item = ['title' => $item, 'payload' => $item];
            }

            if (! is_array($item)) {
                return null;
            }

            $title = trim((string) (data_get($item, 'title') ?? data_get($item, 'label') ?? data_get($item, 'text') ?? ''));

            if ($title === '') {
                return null;
            }

            return [
                'content_type' => 'text',
                'title' => $title,
                'payload' => trim((string) (data_get($item, 'payload') ?? data_get($item, 'value') ?? $title)),
            ];
        })->filter()->unique('title')->take(13)->values()->all();
    }

    /** Chuyển lỗi SSE thành thông báo nội bộ ngắn, không chứa stack trace hoặc payload nhạy cảm. */
    private function safeError(array $data): string
    {
        $code = (string) (data_get($data, 'code') ?? 'stream_error');

        return 'Chatbot báo lỗi: '.$code;
    }
}
