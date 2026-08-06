<?php

namespace Modules\Facebook\DTO;

final readonly class FacebookPageData
{
    /** Đóng gói ID, tên, Page access token và ảnh đại diện của Facebook Page đã cấp quyền. */
    public function __construct(
        public string $id,
        public string $name,
        public string $accessToken,
        public ?string $avatar = null,
    ) {
    }
}
