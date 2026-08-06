<?php

namespace Modules\Conversation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveWorkShiftRequest extends FormRequest
{
    /** Chỉ cho người có quyền quản lý tài khoản tạo hoặc sửa ca trực. */
    public function authorize(): bool
    {
        return $this->user()?->can('user.manage') === true;
    }

    /** Kiểm tra tên, khung giờ, ngày áp dụng và nhân viên khi lưu ca trực. */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:120'],
            'starts_time' => ['required', 'date_format:H:i'],
            'ends_time' => ['required', 'date_format:H:i'],
            'agent_ids' => ['required', 'array', 'min:1', 'max:2'],
            'agent_ids.*' => ['integer', 'distinct', 'exists:users,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
