<?php

namespace App\Http\Controllers\Web;

use App\Actions\Web\LoginUserAction;
use App\Actions\Web\LogoutUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuthController extends Controller
{
    /** Hiển thị biểu mẫu đăng nhập cho người dùng chưa xác thực. */
    public function create(): View
    {
        return view('auth.login');
    }

    /** Xác thực email/mật khẩu, tạo lại session và chuyển người dùng vào dashboard. */
    public function store(LoginRequest $request, LoginUserAction $action): RedirectResponse
    {
        $action->execute($request->validated());

        return redirect()->intended(route('dashboard'));
    }

    /** Đăng xuất, hủy session hiện tại, tạo CSRF token mới và chuyển về trang đăng nhập. */
    public function destroy(Request $request, LogoutUserAction $action): RedirectResponse
    {
        $action->execute($request);

        return redirect()->route('login');
    }
}
