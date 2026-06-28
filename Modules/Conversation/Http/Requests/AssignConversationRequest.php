<?php

namespace Modules\Conversation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Conversation\Services\AssignableUserService;

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
