<?php

namespace App\Actions\Web;

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginUserAction
{
    /**
     * @param array{email: string, password: string, remember?: bool} $credentials
     */
    public function execute(array $credentials): void
    {
        $remember = (bool) ($credentials['remember'] ?? false);

        if (! Auth::attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            'is_active' => true,
        ], $remember)) {
            throw ValidationException::withMessages([
                'email' => __('Thông tin đăng nhập không chính xác hoặc tài khoản đã bị khóa.'),
            ]);
        }

        request()->session()->regenerate();
    }
}
