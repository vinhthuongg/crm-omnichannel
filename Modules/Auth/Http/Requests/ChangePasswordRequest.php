<?php

namespace Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    /** Xác minh mật khẩu hiện tại và yêu cầu mật khẩu mới đạt chuẩn, có xác nhận. */
    public function rules(): array
    {
        return ['current_password' => ['required', 'current_password:sanctum'], 'password' => ['required', 'confirmed', Password::defaults()]];
    }

    /** Cho phép người dùng đã xác thực đổi mật khẩu của chính tài khoản hiện tại. */
    public function authorize(): bool
    {
        return true;
    }
}
