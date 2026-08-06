<?php

namespace Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /** Yêu cầu email, mật khẩu và giới hạn tên thiết bị khi đăng nhập API. */
    public function rules(): array
    {
        return ['email' => ['required', 'email'], 'password' => ['required', 'string'], 'device_name' => ['nullable', 'string', 'max:120']];
    }

    /** Cho phép khách chưa đăng nhập gửi email, mật khẩu và tên thiết bị để xác thực. */
    public function authorize(): bool
    {
        return true;
    }
}
