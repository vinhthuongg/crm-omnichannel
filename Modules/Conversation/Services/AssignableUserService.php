<?php

namespace Modules\Conversation\Services;

use App\Models\User;

class AssignableUserService
{
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
