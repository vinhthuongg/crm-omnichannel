<?php

namespace Modules\Auth\Actions;

use Modules\Auth\DTO\LoginData;
use Modules\Auth\Services\AuthService;

class LoginAction
{
    /** Nhận AuthService để xác thực tài khoản và phát hành access token. */
    public function __construct(private readonly AuthService $service)
    {
    }

    /** Xác thực email, mật khẩu và trả về thông tin người dùng cùng access token. */
    public function execute(LoginData $data): array
    {
        return $this->service->login($data);
    }
}
