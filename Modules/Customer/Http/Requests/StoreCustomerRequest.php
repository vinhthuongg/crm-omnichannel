<?php

namespace Modules\Customer\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRequest extends FormRequest
{
    /** Cho phép người dùng API đã xác thực tạo hồ sơ khách hàng. */
    public function authorize(): bool
    {
        return $this->user()?->can('conversation.view_all') ?? false;
    }

    /** Kiểm tra hồ sơ và định danh kênh Facebook/Zalo khi tạo khách hàng. */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'avatar' => ['nullable', 'url'], 'phone' => ['nullable', 'string', 'max:40'], 'email' => ['nullable', 'email'], 'channels' => ['array'], 'channels.*.channel' => ['required_with:channels', 'in:facebook,zalo'], 'channels.*.external_id' => ['required_with:channels', 'string'], 'channels.*.metadata' => ['array']];
    }
}
