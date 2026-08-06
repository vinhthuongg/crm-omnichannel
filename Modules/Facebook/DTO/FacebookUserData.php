<?php

namespace Modules\Facebook\DTO;

final readonly class FacebookUserData
{
    /** Đóng gói hồ sơ Facebook cùng access token và refresh token nhận được từ OAuth. */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $email,
        public string $accessToken,
        public ?string $refreshToken = null,
    ) {
    }
}
