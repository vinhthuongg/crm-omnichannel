<?php

namespace Modules\Facebook\Services;

use Illuminate\Support\Arr;
use Modules\Facebook\DTO\FacebookPageData;
use Modules\Facebook\DTO\FacebookUserData;
use RuntimeException;

class FacebookOAuthService
{
    /** Khởi tạo luồng OAuth với cấu hình, Graph client và dịch vụ kiểm tra token. */
    public function __construct(private readonly FacebookOAuthConfig $config, private readonly FacebookOAuthGraphClient $graph,
        private readonly FacebookTokenValidationService $tokens) {}

    /** Trả về URL bắt đầu luồng cấp quyền Facebook kèm state chống CSRF. */
    public function authorizationUrl(string $state): string { return $this->config->authorizationUrl($state); }

    /** Đổi code và tạo DTO người dùng Facebook đã xác thực. */
    public function userFromCode(string $code): FacebookUserData
    {
        $token = $this->graph->exchangeCode($code);
        $accessToken = (string) Arr::get($token, 'access_token');
        if ($accessToken === '') throw new RuntimeException('Facebook did not return an access token.');
        $this->tokens->validateUserToken($accessToken);
        $profile = $this->graph->get('/me', $accessToken, ['fields' => 'id,name,email']);
        return new FacebookUserData((string) Arr::get($profile, 'id'), (string) Arr::get($profile, 'name', 'Facebook User'),
            Arr::get($profile, 'email'), $accessToken, Arr::get($token, 'refresh_token'));
    }

    /** Lấy toàn bộ Page mà người dùng có quyền quản trị qua các trang phân trang. */
    public function pages(string $userAccessToken): array
    {
        $this->tokens->validateUserToken($userAccessToken);
        $pages = [];
        $url = $this->config->graphUrl('/me/accounts');
        $params = ['fields' => 'id,name,access_token,picture{url}', 'limit' => 100, 'access_token' => $userAccessToken];
        do {
            $payload = $this->graph->getUrl($url, $params + ['access_token' => $userAccessToken]);
            foreach ((array) Arr::get($payload, 'data', []) as $page) {
                $token = (string) Arr::get($page, 'access_token');
                if ($token === '') continue;
                $this->tokens->validatePageToken($token);
                $pages[] = new FacebookPageData((string) Arr::get($page, 'id'), (string) Arr::get($page, 'name'), $token, Arr::get($page, 'picture.data.url'));
            }
            $url = Arr::get($payload, 'paging.next');
            $params = [];
        } while ($url);
        return $pages;
    }

    /** Lấy URL ảnh đại diện của Page nếu Facebook cung cấp. */
    public function pagePicture(string $pageId, string $pageAccessToken): ?string
    {
        $this->tokens->validatePageToken($pageAccessToken);
        return Arr::get($this->graph->get("/{$pageId}", $pageAccessToken, ['fields' => 'picture{url}']), 'picture.data.url');
    }

    /** Đăng ký Page vào webhook cho các sự kiện Messenger cần thiết. */
    public function subscribePage(string $pageId, string $pageAccessToken): void
    {
        $this->tokens->validatePageToken($pageAccessToken);
        try {
            $this->graph->subscribe($pageId, $pageAccessToken, 'messages,message_echoes,messaging_postbacks,message_deliveries,message_reads,messaging_customer_information');
        } catch (\Throwable $e) {
            if (! str_contains($e->getMessage(), 'message_echoes')) throw $e;
            $this->graph->subscribe($pageId, $pageAccessToken, 'messages,messaging_postbacks,message_deliveries,message_reads');
        }
    }
}
