<?php

namespace Modules\User\Actions;

use App\Models\User;
use Modules\User\Services\UserService;

class CreateUserAction
{
    /** Nhận UserService để tạo tài khoản, băm mật khẩu và gán vai trò. */
    public function __construct(private readonly UserService $service)
    {
    }

    /** Tạo tài khoản mới, băm mật khẩu và gán vai trò được yêu cầu. */
    public function execute(array $data): User
    {
        return $this->service->create($data);
    }
}
