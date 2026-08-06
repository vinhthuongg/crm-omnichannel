<?php

namespace Modules\Conversation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Conversation\Services\AssignableUserService;

class AssignConversationRequest extends FormRequest
{
    /** Chỉ cho người có quyền phân công hoặc chuyển tiếp chọn người phụ trách hội thoại. */
    public function authorize(): bool
    {
        return $this->user()?->can('conversation.assign') ?? false;
    }

    /** Yêu cầu ID người nhận phân công phải tồn tại trong bảng người dùng. */
    public function rules(): array
    {
        return ['assigned_to' => ['required', 'exists:users,id']];
    }

    /** Bổ sung validation hậu kiểm cho dữ liệu phân công hội thoại. */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                try {
                    app(AssignableUserService::class)->findAssignable((int) $this->input('assigned_to'));
                } catch (\RuntimeException $exception) {
                    $validator->errors()->add('assigned_to', $exception->getMessage());
                }
            },
        ];
    }
}
