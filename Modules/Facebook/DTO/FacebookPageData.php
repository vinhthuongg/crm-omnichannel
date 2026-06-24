<?php

namespace Modules\Facebook\DTO;

final readonly class FacebookPageData
{
    public function __construct(
        public string $id,
        public string $name,
        public string $accessToken,
        public ?string $avatar = null,
    ) {
    }
}
