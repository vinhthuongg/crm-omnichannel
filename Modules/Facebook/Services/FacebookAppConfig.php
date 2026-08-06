<?php

namespace Modules\Facebook\Services;

use RuntimeException;

class FacebookAppConfig
{
    /** Tạo URL Graph API theo phiên bản Facebook đang cấu hình. */
    public function graphUrl(string $path): string { return 'https://graph.facebook.com/'.config('services.facebook.graph_version', 'v25.0').$path; }
    /** Lấy App ID dùng cho luồng đăng nhập Facebook. */
    public function loginId(): string { return (string) config('services.facebook.client_id'); }
    /** Lấy App ID chuyên dùng cho tích hợp Messenger. */
    public function messengerId(): string { return (string) config('services.facebook.messenger_app_id'); }
    /** Lấy verify token dùng để xác thực webhook. */
    public function verifyToken(): string { return (string) config('services.facebook.verify_token'); }
    /** Tạo URL callback công khai nhận webhook Facebook. */
    public function callbackUrl(): string { return rtrim((string) config('app.url'), '/').'/api/webhook/facebook'; }

    /** Tạo app access token cho ứng dụng đăng nhập. */
    public function loginAccessToken(): string { return $this->accessToken($this->loginId(), (string) config('services.facebook.client_secret'), 'FACEBOOK_CLIENT_ID or FACEBOOK_CLIENT_SECRET'); }
    /** Tạo app access token cho ứng dụng Messenger. */
    public function messengerAccessToken(): string { return $this->accessToken($this->messengerId(), (string) config('services.facebook.messenger_app_secret'), 'MESSENGER_APP_ID or MESSENGER_APP_SECRET'); }

    /** Kiểm tra các cấu hình bắt buộc trước khi đăng ký webhook. */
    public function ensureWebhookConfigured(): void
    {
        $this->loginAccessToken();
        if ($this->verifyToken() === '') throw new RuntimeException('FACEBOOK_VERIFY_TOKEN is missing in .env.');
        if (! str_starts_with($this->callbackUrl(), 'https://')) throw new RuntimeException('APP_URL must be an HTTPS URL before configuring Facebook webhooks.');
    }

    /** Ghép App ID và App Secret thành app access token sau khi xác thực đầu vào. */
    private function accessToken(string $id, string $secret, string $label): string
    {
        if ($id === '' || $secret === '') throw new RuntimeException($label.' is missing in .env.');
        return $id.'|'.$secret;
    }
}
