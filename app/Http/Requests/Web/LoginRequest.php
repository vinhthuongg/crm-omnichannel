<?php

namespace App\Http\Requests\Web;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /** Kiểm tra người dùng hiện tại có được phép thực hiện request hay không. */
    public function authorize(): bool
    {
        return true;
    }

    /** Yêu cầu email hợp lệ và mật khẩu dạng chuỗi cho đăng nhập trang quản trị. */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ];
    }
}
