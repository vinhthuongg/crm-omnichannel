<?php

namespace App\Actions\Web;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LogoutUserAction
{
    /** Đăng xuất guard hiện tại, hủy session cũ và tạo lại CSRF token. */
    public function execute(Request $request): void
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
