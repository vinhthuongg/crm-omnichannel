<?php

namespace Modules\Conversation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TagConversationRequest extends FormRequest
{
    /** Chỉ cho người có quyền gắn nhãn cập nhật nhãn hội thoại. */
    public function authorize(): bool
    {
        return $this->user()?->can('conversation.tag') ?? false;
    }

    /** Kiểm tra danh sách tên và màu nhãn trước khi gắn vào hội thoại. */
    public function rules(): array
    {
        return ['tags' => ['required', 'array'], 'tags.*.name' => ['required', 'string', 'max:80'], 'tags.*.color' => ['nullable', 'string', 'max:24']];
    }
}
