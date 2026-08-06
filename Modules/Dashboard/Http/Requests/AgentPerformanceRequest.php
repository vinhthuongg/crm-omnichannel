<?php

namespace Modules\Dashboard\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AgentPerformanceRequest extends FormRequest
{
    /** Chỉ cho người có quyền xem báo cáo truy cập hiệu suất nhân viên. */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** Kiểm tra khoảng ngày, ca trực và nhân viên dùng để lọc báo cáo hiệu suất. */
    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'work_shift_id' => ['nullable', 'integer', 'exists:work_shifts,id'],
        ];
    }
}
