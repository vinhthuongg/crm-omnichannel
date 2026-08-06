<?php

namespace Modules\Facebook\Actions;

use App\Models\User;
use Modules\Facebook\Models\FacebookAccount;
use Modules\Facebook\Services\FacebookOAuthService;
use RuntimeException;

class ListFacebookPagesAction
{
    /** Nhận FacebookOAuthService để tạo URL đăng nhập, đổi code và lấy danh sách Page. */
    public function __construct(private readonly FacebookOAuthService $facebook)
    {
    }

    /** Lấy các Page từ Facebook, đối chiếu Page đã kết nối và lưu lựa chọn vào session. */
    public function execute(User $user): array
    {
        $account = FacebookAccount::query()->where('user_id', $user->id)->latest()->first();

        if (! $account) {
            throw new RuntimeException('User has not connected a Facebook account.');
        }

        return $this->facebook->pages($account->access_token);
    }
}
