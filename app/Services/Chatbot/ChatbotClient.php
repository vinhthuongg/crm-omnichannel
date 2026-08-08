<?php

namespace App\Services\Chatbot;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class ChatbotClient
{
    public function __construct(private readonly ?ClientInterface $http = null) {}

    /** Gọi endpoint chat không streaming cho tác vụ backend cần nhận một JSON hoàn chỉnh. */
    public function chat(array $payload): array
    {
        $conversationId = (string) ($payload['crmConversationId'] ?? '');
        $messageId = (string) ($payload['externalMessageId'] ?? '');

        try {
            $response = ($this->http ?? new Client)->request('POST', $this->url('/chat'), [
                'headers' => [
                    'accept' => 'application/json',
                    'content-type' => 'application/json',
                    'x-api-key' => $this->apiKey(),
                ],
                'json' => $payload,
                'connect_timeout' => (float) config('chatbot.connect_timeout', 5),
                'timeout' => (float) config('chatbot.stream_timeout', 120),
                'http_errors' => false,
            ]);
            $status = $response->getStatusCode();
            $requestId = $response->getHeaderLine('x-request-id') ?: null;

            Log::info('Chatbot request completed', compact('conversationId', 'messageId', 'requestId', 'status'));

            if ($status < 200 || $status >= 300) {
                throw $this->httpException($status, $requestId);
            }

            $decoded = json_decode((string) $response->getBody(), true);

            if (! is_array($decoded)) {
                throw new ChatbotException('Chatbot trả về JSON không hợp lệ.', $status, false, $requestId);
            }

            return $decoded;
        } catch (ChatbotException $exception) {
            throw $exception;
        } catch (GuzzleException $exception) {
            Log::warning('Chatbot request network failure', [
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
                'error_type' => $exception::class,
            ]);

            throw new ChatbotException('Không thể kết nối chatbot.', null, true);
        }
    }

    /** Mở POST SSE tới chatbot nội bộ và chuyển từng event cho callback mà không buffer toàn bộ response. */
    public function stream(array $payload, callable $onEvent): void
    {
        $conversationId = (string) ($payload['crmConversationId'] ?? '');
        $messageId = (string) ($payload['externalMessageId'] ?? '');
        $startedAt = microtime(true);

        try {
            $response = ($this->http ?? new Client)->request('POST', $this->url('/chat/stream'), [
                'headers' => [
                    'accept' => 'text/event-stream',
                    'content-type' => 'application/json',
                    'x-api-key' => $this->apiKey(),
                ],
                'json' => $payload,
                'connect_timeout' => (float) config('chatbot.connect_timeout', 5),
                'timeout' => (float) config('chatbot.stream_timeout', 120),
                'read_timeout' => (float) config('chatbot.stream_timeout', 120),
                'stream' => true,
                'http_errors' => false,
            ]);
            $status = $response->getStatusCode();
            $requestId = $response->getHeaderLine('x-request-id') ?: null;

            Log::info('Chatbot stream opened', compact('conversationId', 'messageId', 'requestId', 'status'));

            if ($status < 200 || $status >= 300) {
                throw $this->httpException($status, $requestId);
            }

            $parser = new ChatbotStreamParser;
            $body = $response->getBody();

            while (! $body->eof()) {
                $chunk = $body->read(8192);

                if ($chunk === '') {
                    continue;
                }

                foreach ($parser->push($chunk) as $event) {
                    $onEvent($event, $requestId);
                }
            }

            foreach ($parser->finish() as $event) {
                $onEvent($event, $requestId);
            }

            Log::info('Chatbot stream closed', [
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
                'request_id' => $requestId,
                'status' => $status,
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);
        } catch (ChatbotException $exception) {
            throw $exception;
        } catch (GuzzleException $exception) {
            Log::warning('Chatbot stream network failure', [
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
                'error_type' => $exception::class,
            ]);

            throw new ChatbotException('Không thể kết nối chatbot.', null, true);
        }
    }

    /** Tạo URL từ cấu hình nội bộ và từ chối cấu hình rỗng. */
    private function url(string $path): string
    {
        $baseUrl = rtrim((string) config('chatbot.base_url'), '/');

        if ($baseUrl === '') {
            throw new ChatbotException('CHATBOT_BASE_URL chưa được cấu hình.', null, false);
        }

        return $baseUrl.$path;
    }

    /** Đọc API key chỉ ở backend và từ chối chạy nếu secret chưa được cấu hình. */
    private function apiKey(): string
    {
        $key = (string) config('chatbot.api_key');

        if ($key === '') {
            throw new ChatbotException('CHATBOT_API_KEY chưa được cấu hình.', 401, false);
        }

        return $key;
    }

    /** Chuyển HTTP status thành lỗi có chính sách retry hữu hạn phù hợp. */
    private function httpException(int $status, ?string $requestId): ChatbotException
    {
        return match (true) {
            $status === 401 => new ChatbotException('Chatbot từ chối API key.', $status, false, $requestId),
            $status === 409 => new ChatbotException('Tin nhắn đã xử lý hoặc mapping không hợp lệ.', $status, false, $requestId),
            $status === 429 => new ChatbotException('Chatbot đang giới hạn lưu lượng.', $status, true, $requestId),
            $status >= 500 => new ChatbotException('Chatbot tạm thời không khả dụng.', $status, true, $requestId),
            default => new ChatbotException('Chatbot trả về HTTP '.$status.'.', $status, false, $requestId),
        };
    }
}
