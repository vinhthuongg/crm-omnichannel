<?php

namespace Modules\Message\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('conversation.reply') ?? false;
    }

    public function rules(): array
    {
        return ['content' => ['required_without:attachments', 'nullable', 'string'], 'message_type' => ['sometimes', 'string', 'max:32'], 'attachments' => ['array'], 'channel' => ['required', 'in:facebook,zalo']];
    }
}