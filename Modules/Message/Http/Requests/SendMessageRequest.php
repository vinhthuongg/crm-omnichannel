<?php

namespace Modules\Message\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Conversation\Models\Conversation;

class SendMessageRequest extends FormRequest
{
    /** Chỉ cho người có quyền trả lời gửi message vào hội thoại. */
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user) {
            return false;
        }

        $conversation = $this->route('conversation');

        if (! $conversation instanceof Conversation) {
            return false;
        }

        if ($user->can('conversation.view_all')) {
            return true;
        }

        return $user->can('conversation.reply')
            && (int) $conversation->assigned_to === (int) $user->id;
    }

    /** Yêu cầu nội dung hoặc attachment và chỉ chấp nhận kênh Facebook/Zalo. */
    public function rules(): array
    {
        return ['content' => ['required_without:attachments', 'nullable', 'string'], 'message_type' => ['sometimes', 'string', 'max:32'], 'attachments' => ['array'], 'channel' => ['required', 'in:facebook,zalo']];
    }
}
