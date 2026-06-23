<?php

namespace Modules\Conversation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('conversation.assign') ?? false;
    }

    public function rules(): array
    {
        return ['assigned_to' => ['required', 'exists:users,id']];
    }
}