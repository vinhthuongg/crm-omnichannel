<?php

namespace App\Http\Requests\Web;

use Illuminate\Foundation\Http\FormRequest;

class SendMessengerMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('conversation.reply') ?? false;
    }

    public function rules(): array
    {
        return [
            'channel' => ['required', 'in:facebook,zalo'],
            'client_message_id' => ['sometimes', 'nullable', 'string', 'max:80'],
            'content' => ['required_without_all:attachments,uploaded_attachments', 'nullable', 'string', 'max:5000'],
            'attachments' => ['sometimes', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:20480'],
            'uploaded_attachments' => ['sometimes', 'array', 'max:5'],
            'uploaded_attachments.*.name' => ['required_with:uploaded_attachments', 'string', 'max:255'],
            'uploaded_attachments.*.path' => ['required_with:uploaded_attachments', 'string', 'max:500'],
            'uploaded_attachments.*.url' => ['required_with:uploaded_attachments', 'url', 'max:1000'],
            'uploaded_attachments.*.mime_type' => ['nullable', 'string', 'max:120'],
            'uploaded_attachments.*.type' => ['required_with:uploaded_attachments', 'in:image,video,audio,file'],
            'uploaded_attachments.*.size' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
