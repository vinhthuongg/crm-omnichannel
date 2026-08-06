<?php

namespace Modules\Facebook\Services;

use Illuminate\Support\Arr;
use RuntimeException;

class FacebookTokenValidationService
{
    /** Khởi tạo dịch vụ xác thực token với cấu hình App và debug client. */
    public function __construct(private readonly FacebookAppConfig $config, private readonly FacebookTokenDebugClient $client) {}
    /** Xác thực user token thuộc đúng ứng dụng Facebook Login. */
    public function validateUserToken(string $token): array { return $this->validate($token, 'user', $this->config->loginId(), $this->config->loginAccessToken()); }
    /** Xác thực page token thuộc đúng ứng dụng Messenger. */
    public function validatePageToken(string $token): array { return $this->validate($token, 'page', $this->config->messengerId(), $this->config->messengerAccessToken()); }
    /** Đọc metadata token bằng app token truyền vào hoặc app token mặc định. */
    public function debugToken(string $token, ?string $appToken = null): array { return $this->client->debug($token, $appToken ?: $this->config->loginAccessToken()); }

    /** Bảo đảm Page token được phát hành cho Messenger App đang cấu hình. */
    public function ensurePageBelongsToMessengerApp(?string $appId): void
    {
        if ((string) $appId !== $this->config->messengerId()) throw new \InvalidArgumentException('Page belongs to different Messenger App');
    }

    /** Kiểm tra token còn hiệu lực và App ID khớp với loại token mong đợi. */
    private function validate(string $token, string $label, string $expectedId, string $appToken): array
    {
        if ($token === '') throw new RuntimeException("Facebook {$label} access token is empty.");
        if ($expectedId === '') throw new RuntimeException("Expected Facebook {$label} app id is missing in .env.");
        $data = $this->client->debug($token, $appToken);
        if (! (bool) Arr::get($data, 'is_valid')) throw new RuntimeException("Invalid Facebook {$label} access token.");
        $actual = (string) Arr::get($data, 'app_id');
        if ($actual !== $expectedId) throw new RuntimeException("Facebook {$label} token app_id mismatch. Expected {$expectedId}, got {$actual}.");
        return $data;
    }
}
