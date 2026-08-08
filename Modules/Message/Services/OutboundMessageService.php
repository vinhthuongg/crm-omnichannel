<?php

namespace Modules\Message\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Modules\Conversation\Models\Conversation;
use Modules\Facebook\Services\FacebookMessengerService;
use Modules\Facebook\Services\FacebookOutboundDispatcher;
use Modules\Facebook\Services\FacebookPageTokenProvider;
use Modules\Zalo\Services\ZaloOaService;
use RuntimeException;

class OutboundMessageService
{
    private array $lastResponse = [];

    /** Nhận FacebookMessengerService để gửi text, typing action và attachment qua Messenger; FacebookOutboundDispatcher để điều phối text và attachment gửi sang Facebook; FacebookPageTokenProvider để lấy Page token đúng với hội thoại; ZaloOaService để gửi tin nhắn qua Zalo OA API. */
    public function __construct(
        private readonly FacebookMessengerService $facebook,
        private readonly FacebookOutboundDispatcher $facebookDispatcher,
        private readonly FacebookPageTokenProvider $facebookTokens,
        private readonly ZaloOaService $zalo,
    ) {}

    /** Chọn adapter Facebook hoặc Zalo từ channel của message và trả external ID kết quả. */
    public function send(Conversation $conversation, string $channel, string $content, array $attachments = []): ?string
    {
        $customerChannel = $conversation->customer?->channels()->where('channel', $channel)->first();
        if (! $customerChannel?->external_id) {
            throw new RuntimeException("Customer does not have a {$channel} external id.");
        }

        $response = match ($channel) {
            'facebook' => $this->facebookDispatcher->send($customerChannel->external_id, $content, $attachments, $this->facebookTokens->forConversation($conversation)),
            'zalo' => $this->sendZalo($customerChannel->external_id, $content, $attachments),
            default => throw new RuntimeException("Unsupported message channel [{$channel}]."),
        };
        $this->lastResponse = $response;
        Log::info('Outbound message sent', ['conversation_id' => $conversation->id, 'channel' => $channel,
            'external_id' => $customerChannel->external_id, 'response' => $response]);

        return $this->externalId($channel, $response);
    }

    /** Xử lý trạng thái gửi tin outbound tại bước lastResponse. */
    public function lastResponse(): array
    {
        return $this->lastResponse;
    }

    /** Gửi nội dung text và quick reply đến Messenger bằng Page token của hội thoại. */
    public function sendText(Conversation $conversation, string $channel, string $content): ?string
    {
        return $this->send($conversation, $channel, $content);
    }

    /** Xử lý trạng thái gửi tin outbound tại bước stopTyping. */
    public function stopTyping(Conversation $conversation, string $channel): void
    {
        if ($channel !== 'facebook') {
            return;
        }
        $customerChannel = $conversation->customer?->channels()->where('channel', $channel)->first();
        if ($customerChannel?->external_id) {
            $this->facebook->sendTypingOff($customerChannel->external_id, $this->facebookTokens->forConversation($conversation));
        }
    }

    /** Gửi text/attachment qua Zalo OA và lấy message ID từ phản hồi cuối. */
    private function sendZalo(string $userId, string $content, array $attachments): array
    {
        $links = collect($attachments)->reject(fn (array $item): bool => ($item['type'] ?? '') === 'quick_reply')
            ->filter(fn (array $item): bool => filled($item['url'] ?? null))
            ->map(fn (array $item): string => ($item['name'] ?? 'File').': '.$item['url'])->implode("\n");

        return $this->zalo->sendText($userId, trim($content."\n".$links));
    }

    /** Xử lý trạng thái gửi tin outbound tại bước externalId. */
    private function externalId(string $channel, array $response): ?string
    {
        $id = match ($channel) {
            'facebook' => Arr::get($response, 'message_id'),
            'zalo' => Arr::get($response, 'data.message_id') ?? Arr::get($response, 'data.msg_id') ?? Arr::get($response, 'message_id'),
            default => null,
        };

        return $id ? (string) $id : null;
    }
}
