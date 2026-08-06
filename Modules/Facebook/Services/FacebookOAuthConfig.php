<?php

namespace Modules\Facebook\Services;

use Illuminate\Support\Facades\URL;
use RuntimeException;

class FacebookOAuthConfig
{
    /** Tạo URL chuyển hướng người dùng đến màn hình cấp quyền Facebook. */
    public function authorizationUrl(string $state): string
    {
        $this->ensureConfigured();
        $params = ['client_id' => $this->appId(), 'redirect_uri' => $this->redirectUri(), 'state' => $state, 'response_type' => 'code'];
        if ($this->loginConfigId() !== '') $params['config_id'] = $this->loginConfigId();
        elseif ($this->scopes() !== []) $params['scope'] = implode(',', $this->scopes());
        return 'https://www.facebook.com/'.$this->graphVersion().'/dialog/oauth?'.http_build_query($params);
    }

    /** Tạo URL Graph API theo phiên bản OAuth đang cấu hình. */
    public function graphUrl(string $path): string { return 'https://graph.facebook.com/'.$this->graphVersion().$path; }
    /** Lấy Facebook App ID phục vụ OAuth. */
    public function appId(): string { return (string) config('services.facebook.client_id'); }
    /** Lấy Facebook App Secret phục vụ đổi authorization code. */
    public function appSecret(): string { return (string) config('services.facebook.client_secret'); }
    /** Xác định redirect URI nhận kết quả từ Facebook OAuth. */
    public function redirectUri(): string { return (string) (config('services.facebook.redirect') ?: URL::route('facebook.callback')); }
    /** Lấy phiên bản Graph API dùng cho OAuth. */
    private function graphVersion(): string { return (string) config('services.facebook.graph_version', 'v25.0'); }
    /** Lấy Login Configuration ID nếu có cấu hình Facebook Login for Business. */
    private function loginConfigId(): string { return (string) config('services.facebook.login_config_id', ''); }
    /** Chuẩn hóa danh sách quyền OAuth thành mảng scope hợp lệ. */
    private function scopes(): array { $value = config('services.facebook.scopes', ['email']); return is_array($value) ? $value : ['email']; }

    /** Kiểm tra App ID, App Secret và redirect URI trước khi chạy OAuth. */
    public function ensureConfigured(): void
    {
        if ($this->appId() === '') throw new RuntimeException('FACEBOOK_CLIENT_ID is missing in .env.');
        if ($this->appSecret() === '') throw new RuntimeException('FACEBOOK_CLIENT_SECRET or FACEBOOK_APP_SECRET is missing in .env.');
        if ($this->redirectUri() === '') throw new RuntimeException('FACEBOOK_REDIRECT_URI is missing in .env.');
    }
}
