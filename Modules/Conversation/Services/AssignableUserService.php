<?php

namespace Modules\Conversation\Services;

use App\Models\User;

class AssignableUserService
{
    /** Tìm người dùng đang hoạt động, có quyền trả lời và thuộc ca trực phù hợp để nhận hội thoại. */
    public function findAssignable(int $userId): User
    {
        $user = User::query()->whereKey($userId)->first();

        if (! $user) {
            throw new \RuntimeException('Nhan vien duoc phan cong khong ton tai.');
        }

        if (! (bool) $user->is_active) {
            throw new \RuntimeException('Nhan vien duoc phan cong dang bi khoa hoac khong hoat dong.');
        }

        if (! $user->can('conversation.reply')) {
            throw new \RuntimeException('Nhan vien duoc phan cong khong co quyen nhan hoi thoai.');
        }

        return $user;
    }
}
