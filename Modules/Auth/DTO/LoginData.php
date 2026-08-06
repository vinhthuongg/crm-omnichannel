<?php

namespace Modules\Auth\DTO;

final readonly class LoginData
{
    /** Đóng gói email, mật khẩu và tên thiết bị thành dữ liệu đầu vào đăng nhập. */
    public function __construct(public string $email, public string $password, public string $deviceName)
    {
    }
}
