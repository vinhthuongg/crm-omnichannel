<?php

namespace Modules\Auth\Actions;

use App\Models\User;
use Modules\Auth\Services\AuthService;

class ChangePasswordAction
{
    /** Nhận AuthService để thực hiện việc lưu mật khẩu mới. */
    public function __construct(private readonly AuthService $service)
    {
    }

    /** Băm và lưu mật khẩu mới cho tài khoản người dùng. */
    public function execute(User $user, string $password): void
    {
        $this->service->changePassword($user, $password);
    }
}
