<?php

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    /** Chỉ cho người có quyền quản lý người dùng cập nhật tài khoản. */
    public function authorize(): bool
    {
        return $this->user()?->can('user.manage') ?? false;
    }

    /** Kiểm tra trường cập nhật và giữ email duy nhất ngoại trừ chính tài khoản này. */
    public function rules(): array
    {
        $userId = $this->route('user')?->id;
        return ['name' => ['sometimes', 'string', 'max:255'], 'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($userId)], 'password' => ['sometimes', Password::defaults()], 'role' => ['sometimes', 'in:Admin,CSKH,User'], 'is_active' => ['sometimes', 'boolean']];
    }
}
