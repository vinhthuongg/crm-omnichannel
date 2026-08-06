<?php

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    /** Chỉ cho người có quyền quản lý người dùng tạo tài khoản mới. */
    public function authorize(): bool
    {
        return $this->user()?->can('user.manage') ?? false;
    }

    /** Yêu cầu hồ sơ, email duy nhất, mật khẩu đạt chuẩn và vai trò khi tạo tài khoản. */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'unique:users,email'], 'password' => ['required', Password::defaults()], 'role' => ['required', 'in:Admin,CSKH,User'], 'is_active' => ['sometimes', 'boolean']];
    }
}
