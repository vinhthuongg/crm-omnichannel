<?php

namespace Modules\Conversation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TagConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('conversation.tag') ?? false;
    }

    public function rules(): array
    {
        return ['tags' => ['required', 'array'], 'tags.*.name' => ['required', 'string', 'max:80'], 'tags.*.color' => ['nullable', 'string', 'max:24']];
    }
}