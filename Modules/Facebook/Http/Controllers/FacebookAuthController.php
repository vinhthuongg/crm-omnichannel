<?php

namespace Modules\Facebook\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Facebook\Actions\HandleFacebookLoginCallbackAction;
use Modules\Facebook\Actions\RedirectToFacebookLoginAction;
use RuntimeException;

class FacebookAuthController extends Controller
{
    /** Chuyển người dùng sang Facebook để cấp quyền OAuth. */
    public function redirect(RedirectToFacebookLoginAction $action): RedirectResponse
    {
        try {
            return redirect()->away($action->execute());
        } catch (RuntimeException $exception) {
            return redirect()->route(auth()->check() ? 'facebook.pages' : 'login')->withErrors([
                auth()->check() ? 'facebook' : 'email' => $exception->getMessage(),
            ]);
        }
    }

    /** Xử lý OAuth callback, đăng nhập tài khoản CRM và chuyển về danh sách Page. */
    public function callback(Request $request, HandleFacebookLoginCallbackAction $action): RedirectResponse
    {
        if ($request->filled('error')) {
            return redirect()->route(auth()->check() ? 'facebook.pages' : 'login')->withErrors([
                auth()->check() ? 'facebook' : 'email' => $request->string('error_description')->toString() ?: 'Facebook login failed.',
            ]);
        }

        try {
            $action->execute(
                $request->string('code')->toString(),
                $request->string('state')->toString(),
            );
        } catch (RuntimeException $exception) {
            return redirect()->route(auth()->check() ? 'facebook.pages' : 'login')->withErrors([
                auth()->check() ? 'facebook' : 'email' => $exception->getMessage().' Hay bam Ket noi Facebook lai tu dau.',
            ]);
        }

        return redirect()->route('facebook.pages');
    }
}
