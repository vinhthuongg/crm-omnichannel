<?php

namespace Modules\Message\Jobs;

use App\Services\Chatbot\ChatbotException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Message\Events\ChatbotResponseFailed;
use Modules\Message\Models\ChatbotResponse;
use Modules\Message\Services\ChatbotResponseProcessor;

class GenerateChatbotResponseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 135;

    public array $backoff = [5, 20, 60];

    /** Ghi ID response và đưa job vào queue cấu hình riêng mà không khóa các conversation khác. */
    public function __construct(public readonly int $responseId)
    {
        $this->onQueue((string) config('chatbot.queue', 'default'));
    }

    /** Khóa đúng một conversation, chạy stream và luôn giải phóng lock kể cả khi lỗi. */
    public function handle(ChatbotResponseProcessor $processor): void
    {
        $response = ChatbotResponse::query()->find($this->responseId);

        if (! $response || $response->status === 'completed') {
            return;
        }

        $lock = Cache::lock('chatbot:conversation:'.$response->conversation_id, (int) config('chatbot.lock_seconds', 150));

        if (! $lock->get()) {
            $this->release(5);

            return;
        }

        try {
            $processor->process($response);
        } catch (ChatbotException $exception) {
            $this->markFailed($response, $exception);

            if ($exception->retryable && $this->attempts() < $this->tries) {
                throw $exception;
            }
        } catch (\Throwable $exception) {
            $safe = new ChatbotException('Chatbot tạm thời không khả dụng.', null, true);
            $this->markFailed($response, $safe, $exception::class);

            if ($this->attempts() < $this->tries) {
                throw $exception;
            }
        } finally {
            $lock->release();
        }
    }

    /** Lưu trạng thái failed/retryable, log mã định danh an toàn và thông báo lỗi thân thiện qua Reverb. */
    private function markFailed(ChatbotResponse $response, ChatbotException $exception, ?string $errorType = null): void
    {
        $retryable = $exception->retryable && $this->attempts() < $this->tries;
        $response->forceFill([
            'status' => $retryable ? 'retryable' : 'failed',
            'request_id' => $exception->requestId ?: $response->request_id,
            'error_code' => $exception->statusCode ? 'http_'.$exception->statusCode : 'stream_error',
            'error_message' => $exception->getMessage(),
        ])->save();

        Log::warning('Chatbot response failed', [
            'response_id' => $response->id,
            'conversation_id' => $response->conversation_id,
            'message_id' => $response->source_message_id,
            'request_id' => $response->request_id,
            'status' => $response->status,
            'http_status' => $exception->statusCode,
            'error_type' => $errorType,
        ]);

        event(new ChatbotResponseFailed($response->conversation_id, $response->id, [
            'message' => 'Chatbot chưa thể trả lời. Vui lòng thử lại.',
            'retryable' => $retryable || ! in_array($exception->statusCode, [401, 409], true),
            'retry_url' => route('crm.conversations.chatbot.retry', [$response->conversation_id, $response->id]),
        ]));
    }
}
