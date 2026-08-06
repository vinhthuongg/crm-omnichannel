<?php

namespace Modules\Auth\Actions;

use App\Models\User;
use Modules\Auth\Services\AuthService;

class LogoutAction
{
    /** Nhận AuthService để thu hồi token đăng nhập. */
    public function __construct(private readonly AuthService $service)
    {
    }

    /** Thu hồi toàn bộ access token của người dùng đang đăng nhập. */
    public function execute(User $user): void
    {
        $this->service->logout($user);
    }
}
