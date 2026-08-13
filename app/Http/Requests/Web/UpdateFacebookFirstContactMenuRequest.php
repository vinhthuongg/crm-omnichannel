<?php

namespace App\Http\Requests\Web;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFacebookFirstContactMenuRequest extends FormRequest
{
    /** Chỉ Admin mới được thay đổi nội dung menu Messenger đầu tiên. */
    public function authorize(): bool
    {
        return $this->user()?->hasRole('Admin') === true;
    }

    /** Kiểm tra giới hạn Generic Template của Messenger cho thẻ, nút, URL và payload. */
    public function rules(): array
    {
        return [
            'enabled' => ['nullable', 'boolean'],
            'text' => ['nullable', 'string', 'max:1024'],
            'phone_enabled' => ['nullable', 'boolean'],
            'phone_text' => ['nullable', 'required_if:phone_enabled,1', 'string', 'max:1024'],
            'elements' => ['required', 'array', 'min:1', 'max:3'],
            'elements.*.title' => ['required', 'string', 'max:80'],
            'elements.*.subtitle' => ['nullable', 'string', 'max:80'],
            'elements.*.image_url' => ['nullable', 'url:https', 'max:2048'],
            'elements.*.buttons' => ['required', 'array', 'min:1', 'max:2'],
            'elements.*.buttons.*.title' => ['required', 'string', 'max:20'],
            'elements.*.buttons.*.payload' => ['required', 'string', 'max:1000'],
        ];
    }

    /** Chuẩn hóa checkbox không được gửi thành false trước khi validate và lưu. */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'enabled' => $this->boolean('enabled'),
            'phone_enabled' => $this->boolean('phone_enabled'),
        ]);
    }
}
