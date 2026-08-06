<?php

namespace Modules\Facebook\Services;

use Illuminate\Http\Client\Factory as Http;

class FacebookOAuthGraphClient
{
    /** Khởi tạo OAuth Graph client cùng cấu hình endpoint Facebook. */
    public function __construct(private readonly Http $http, private readonly FacebookOAuthConfig $config) {}

    /** Đổi authorization code thành user access token. */
    public function exchangeCode(string $code): array
    {
        $this->config->ensureConfigured();
        return $this->getUrl($this->config->graphUrl('/oauth/access_token'), ['client_id' => $this->config->appId(),
            'client_secret' => $this->config->appSecret(), 'redirect_uri' => $this->config->redirectUri(), 'code' => $code]);
    }

    /** Đọc 1 tài nguyên Graph API bằng accesstoken đã cấp. */
    public function get(string $path, string $token, array $params = []): array
    {
        return $this->getUrl($this->config->graphUrl($path), [...$params, 'access_token' => $token]);
    }

    /** Gửi trực tiếp một URL phân trang do Graph API trả về. */
    public function getUrl(string $url, array $params): array
    {
        $response = $this->http->connectTimeout(5)->timeout(15)->get($url, $params);
        $response->throw();
        return $response->json();
    }

    /** Đăng ký Page nhận các trường webhook được yêu cầu. */
    public function subscribe(string $pageId, string $token, string $fields): void
    {
        $response = $this->http->connectTimeout(5)->timeout(15)->asForm()->post($this->config->graphUrl("/{$pageId}/subscribed_apps"),
            ['subscribed_fields' => $fields, 'access_token' => $token]);
        $response->throw();
    }
}
