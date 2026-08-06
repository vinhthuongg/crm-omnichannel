<?php

namespace Modules\Facebook\Actions;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Facebook\Services\FacebookOAuthService;

class RedirectToFacebookLoginAction
{
    /** Nhận FacebookOAuthService để tạo URL đăng nhập, đổi code và lấy danh sách Page. */
    public function __construct(private readonly FacebookOAuthService $facebook)
    {
    }

    /** Tạo OAuth state trong session và chuyển người dùng đến trang cấp quyền Facebook. */
    public function execute(): string
    {
        $state = Str::random(40);
        session(['facebook_oauth_state' => $state]);
        session()->save();
        Cache::put('facebook_oauth_state:'.$state, true, now()->addMinutes(10));

        return $this->facebook->authorizationUrl($state);
    }
}
