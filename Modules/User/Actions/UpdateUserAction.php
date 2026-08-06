<?php

namespace Modules\User\Actions;

use App\Models\User;
use Modules\User\Services\UserService;

class UpdateUserAction
{
    /** Nhận UserService để cập nhật hồ sơ, mật khẩu và vai trò tài khoản. */
    public function __construct(private readonly UserService $service)
    {
    }

    /** Cập nhật hồ sơ, mật khẩu và vai trò của tài khoản được chọn. */
    public function execute(User $user, array $data): User
    {
        return $this->service->update($user, $data);
    }
}
