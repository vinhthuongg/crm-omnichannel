<?php

namespace Modules\Facebook\DTO;

final readonly class FacebookUserData
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $email,
        public string $accessToken,
        public ?string $refreshToken = null,
    ) {
    }
}
