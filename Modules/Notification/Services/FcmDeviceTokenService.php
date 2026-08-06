<?php

namespace Modules\Notification\Services;

use App\Models\User;
use Modules\Notification\Models\FcmDeviceToken;

class FcmDeviceTokenService
{
    /** Upsert FCM token và cập nhật chủ sở hữu, nền tảng, thiết bị cùng lần dùng cuối. */
    public function store(User $user, array $data): FcmDeviceToken
    {
        return FcmDeviceToken::query()->updateOrCreate(['token' => $data['token']], ['user_id' => $user->id,
            'platform' => $data['platform'] ?? null, 'device_id' => $data['device_id'] ?? null,
            'app_version' => $data['app_version'] ?? null, 'last_used_at' => now()]);
    }

    /** Xóa FCM token thuộc người dùng khi thiết bị đăng xuất hoặc tắt nhận thông báo. */
    public function delete(User $user, string $token): void
    {
        $user->fcmDeviceTokens()->where('token', $token)->delete();
    }

    /** Xóa token không còn hợp lệ sau khi Firebase từ chối thiết bị. */
    public function purgeInvalid(string $token): void
    {
        FcmDeviceToken::query()->where('token', $token)->delete();
    }
}
