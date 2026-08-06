<?php

namespace Modules\Facebook\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FacebookMessengerService
{
    /** Khởi tạo cổng Messenger cùng client Graph và dịch vụ xử lý tệp đính kèm. */
    public function __construct(
        private readonly Http $http,
        private readonly FacebookMessagePayloadNormalizer $normalizer,
        private readonly FacebookAttachmentService $attachments,
    ) {
    }

    /** Gửi một tin nhắn văn bản đến người nhận trên Facebook. */
    public function sendText(string $recipientId, string $message, ?string $pageAccessToken = null): array
    {
        return $this->sendTextPayload($recipientId, ['text' => $message], $pageAccessToken);
    }

    /** Gửi Văn Bản Kèm Các Lựa Chọn Trả Lời Đã Được Chuẩn Hóa*/
    public function sendTextWithQuickReplies(string $recipientId, string $message, array $quickReplies, ?string $pageAccessToken = null): array
    {
        return $this->sendTextPayload($recipientId, ['text' => $message, 'quick_replies' => array_values($quickReplies)], $pageAccessToken);
    }

    /** Bật trạng thái đang nhập cho cuộc trò chuyện của người nhận. */
    public function sendTypingOn(string $recipientId, ?string $pageAccessToken = null): bool
    {
        return $this->sendSenderAction($recipientId, 'typing_on', $pageAccessToken);
    }

    /** Tắt trạng thái đang nhập cho cuộc trò chuyện của người nhận. */
    public function sendTypingOff(string $recipientId, ?string $pageAccessToken = null): bool
    {
        return $this->sendSenderAction($recipientId, 'typing_off', $pageAccessToken);
    }

    /** Gửi sender action như typing_on hoặc typing_off qua Graph API. */
    public function sendSenderAction(string $recipientId, string $action, ?string $pageAccessToken = null): bool
    {
        if (! in_array($action, ['typing_on', 'typing_off', 'mark_seen'], true)) throw new RuntimeException("Unsupported Facebook sender action [{$action}].");
        if ($recipientId === '') return false;
        return $this->safeBooleanRequest('/me/messages', ['recipient' => ['id' => $recipientId], 'sender_action' => $action], $this->token($pageAccessToken),
            'Facebook sender action', ['recipient_id' => $recipientId, 'action' => $action]);
    }

    /** Yêu cầu Facebook chuyển quyền điều khiển hội thoại về ứng dụng CRM. */
    public function takeThreadControl(string $recipientId, ?string $pageAccessToken = null, string $metadata = 'CRM agent replied'): bool
    {
        if ($recipientId === '') return false;
        return $this->safeBooleanRequest('/me/take_thread_control', ['recipient' => ['id' => $recipientId], 'metadata' => $metadata],
            $this->token($pageAccessToken), 'Facebook take_thread_control', ['recipient_id' => $recipientId]);
    }

    /** Lấy thông tin hồ sơ công khai của người dùng theo PSID. */
    public function profile(string $psid, ?string $pageAccessToken = null): array
    {
        if ($psid === '') return [];
        try {
            $token = $this->token($pageAccessToken);
            $response = $this->http->connectTimeout(1)->timeout(3)->get($this->url("/{$psid}"), [
                'fields' => 'name,first_name,last_name,profile_pic', 'access_token' => $token,
            ]);
            if (! $response->successful()) {
                Log::warning('Facebook profile lookup failed', ['psid' => $psid, 'status' => $response->status(), 'body' => $response->body()]);
                return [];
            }
            $profile = $response->json();
            $genderResponse = $this->http->connectTimeout(1)->timeout(3)->get($this->url("/{$psid}"), [
                'fields' => 'gender', 'access_token' => $token,
            ]);
            if ($genderResponse->successful() && filled($genderResponse->json('gender'))) {
                $profile['gender'] = $genderResponse->json('gender');
            } elseif (! $genderResponse->successful()) {
                Log::info('Facebook gender lookup is unavailable', ['psid' => $psid, 'status' => $genderResponse->status()]);
            }
            $profile['gender_lookup_completed'] = true;

            return $profile;
        } catch (\Throwable $exception) {
            Log::warning('Facebook profile lookup exception', ['psid' => $psid, 'error' => $exception->getMessage()]);
            return [];
        }
    }

    /** Gửi tệp đính kèm từ một URL có thể truy cập công khai. */
    public function sendAttachment(string $recipientId, string $url, string $type = 'file', ?string $pageAccessToken = null): array
    {
        return $this->attachments->sendUrl($recipientId, $url, $type, $this->token($pageAccessToken));
    }

    /** Upload và gửi một tệp đang lưu trên máy chủ ứng dụng. */
    public function sendLocalAttachment(string $recipientId, string $path, string $type = 'file', ?string $mimeType = null, ?string $filename = null, ?string $pageAccessToken = null): array
    {
        return $this->attachments->sendLocal($recipientId, $path, $type, $mimeType, $filename, $this->token($pageAccessToken));
    }

    /** Gửi lại tệp bằng attachment ID đã được Facebook lưu trước đó */
    public function sendAttachmentId(string $recipientId, string $attachmentId, string $type = 'file', ?string $pageAccessToken = null): array
    {
        return $this->attachments->sendId($recipientId, $attachmentId, $type, $this->token($pageAccessToken));
    }

    /** Đóng gói payload văn bản và thực hiện request gửi tin nhắn. */
    private function sendTextPayload(string $recipientId, array $message, ?string $pageAccessToken): array
    {
        $message = $this->normalizer->normalize($message);
        if (isset($message['text'])) $message['text'] = MessengerTextFormatter::format((string) $message['text']);
        $quickReplies = (array) ($message['quick_replies'] ?? []);
        if ($quickReplies !== [] && mb_strlen((string) ($message['text'] ?? '')) > FacebookMessagePayloadNormalizer::TEXT_LIMIT) {
            $chunks = $this->normalizer->split((string) $message['text']);
            foreach (array_slice($chunks, 0, -1) as $chunk) $this->sendTextPayload($recipientId, ['text' => $chunk], $pageAccessToken);
            return $this->sendTextPayload($recipientId, ['text' => end($chunks) ?: '', 'quick_replies' => $quickReplies], $pageAccessToken);
        }
        try {
            $response = $this->http->connectTimeout(5)->timeout(15)->withToken($this->token($pageAccessToken))
                ->post($this->url('/me/messages'), ['messaging_type' => 'RESPONSE', 'recipient' => ['id' => $recipientId], 'message' => $message]);
        } catch (ConnectionException $exception) {
            if (str_contains($exception->getMessage(), 'cURL error 28')) Log::warning('Facebook text send timed out after request was sent', ['recipient_id' => $recipientId, 'error' => $exception->getMessage()]);
            throw $exception;
        }
        $response->throw();
        return $response->json();
    }

    /** Thực hiện request dạng boolean và ghi log thay vì làm gián đoạn luồng khi lỗi. */
    private function safeBooleanRequest(string $path, array $payload, string $token, string $label, array $context): bool
    {
        try {
            $response = $this->http->connectTimeout(5)->timeout(10)->withToken($token)->post($this->url($path), $payload);
            if ($response->successful()) return true;
            Log::warning($label.' failed', [...$context, 'status' => $response->status(), 'body' => $response->body()]);
        } catch (\Throwable $exception) { Log::warning($label.' exception', [...$context, 'error' => $exception->getMessage()]); }
        return false;
    }

    /** Chọn page access token truyền vào hoặc token mặc định đã cấu hình. */
    private function token(?string $token): string
    {
        if (! $token) throw new RuntimeException('Facebook page access token is missing for this conversation.');
        return $token;
    }

    /** Tạo URL Graph API đầy đủ từ đường dẫn endpoint tương đối. */
    private function url(string $path): string
    {
        return 'https://graph.facebook.com/'.config('services.facebook.graph_version', 'v25.0').$path;
    }
}
