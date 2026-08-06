<?php

namespace Modules\Notification\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Modules\Notification\Infrastructure\FirebaseMessagingClient;

class FirebaseCloudMessagingService
{
    /** Nhận FirebaseCredentials để cung cấp cấu hình và thông tin xác thực; FirebaseAccessTokenProvider để tạo OAuth token từ service account; FirebaseMessagingClient để giao tiếp với dịch vụ bên ngoài; FcmDeviceTokenService để lưu và loại bỏ token thiết bị nhận push. */
    public function __construct(
        private readonly FirebaseCredentials $credentials,
        private readonly FirebaseAccessTokenProvider $tokens,
        private readonly FirebaseMessagingClient $client,
        private readonly FcmDeviceTokenService $deviceTokens,
    ) {}

    /** Gửi push notification tới mọi FCM token đang đăng ký của người dùng. */
    public function sendToUser(User $user, string $title, string $body, array $data = []): void
    {
        if (! $this->enabled()) return;
        foreach ($user->fcmDeviceTokens()->pluck('token') as $token) $this->sendToToken($token, $title, $body, $data);
    }

    /** Gửi một FCM HTTP v1 message và loại token nếu Firebase báo thiết bị không hợp lệ. */
    public function sendToToken(string $token, string $title, string $body, array $data = []): void
    {
        if (! $this->enabled()) return;
        $projectId = (string) $this->credentials->get('project_id');
        $response = $this->client->send($projectId, $this->tokens->token(), [
                'token' => $token, 'notification' => ['title' => $title, 'body' => $body], 'data' => $this->stringData($data),
                'android' => ['priority' => 'HIGH', 'notification' => ['sound' => 'default']],
                'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
            ]);
        if ($response->successful()) return;
        Log::warning('FCM push failed', ['status' => $response->status(), 'body' => $response->body()]);
        if (in_array($response->status(), [400, 404], true)) $this->deviceTokens->purgeInvalid($token);
    }

    /** Kiểm tra tính năng hoặc cấu hình hiện tại có đang được bật hay không. */
    public function enabled(): bool { return $this->credentials->enabled(); }

    /** Chuyển toàn bộ Firebase data payload thành chuỗi theo yêu cầu FCM. */
    private function stringData(array $data): array
    {
        return collect($data)->mapWithKeys(fn ($value, string $key): array => [$key => is_scalar($value) ? (string) $value : json_encode($value)])->all();
    }
}
