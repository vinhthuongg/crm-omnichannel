<?php

namespace Modules\User\Repositories;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class UserRepository
{
    /** Phân trang tài khoản mới nhất kèm vai trò để phục vụ màn hình quản lý người dùng. */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        return User::query()->with('roles')->latest()->paginate((int) ($filters['per_page'] ?? 20));
    }

    /** Lưu tài khoản mới từ dữ liệu hồ sơ và mật khẩu đã chuẩn hóa. */
    public function create(array $data): User
    {
        return User::query()->create($data);
    }

    /** Lưu thay đổi và trả model người dùng đã refresh. */
    public function update(User $user, array $data): User
    {
        $user->update($data);
        return $user->refresh();
    }
}
