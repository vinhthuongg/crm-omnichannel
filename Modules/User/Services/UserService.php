<?php

namespace Modules\User\Services;

use App\Models\User;
use Modules\User\Repositories\UserRepository;

class UserService
{
    /** Nhận UserRepository để đọc và lưu dữ liệu. */
    public function __construct(private readonly UserRepository $repository)
    {
    }

    /** Băm mật khẩu, tạo tài khoản và gán vai trò được chọn hoặc vai trò User. */
    public function create(array $data): User
    {
        $role = $data['role'];
        unset($data['role']);
        $user = $this->repository->create($data);
        $user->assignRole($role);
        return $user->load('roles');
    }

    /** Băm mật khẩu mới nếu có, lưu hồ sơ và đồng bộ vai trò tài khoản. */
    public function update(User $user, array $data): User
    {
        $role = $data['role'] ?? null;
        unset($data['role']);
        $user = $this->repository->update($user, $data);
        if ($role) {
            $user->syncRoles([$role]);
        }
        return $user->load('roles');
    }
}
