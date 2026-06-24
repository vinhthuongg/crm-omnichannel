<?php

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('user.manage') ?? false;
    }

    public function rules(): array
    {
        $userId = $this->route('user')?->id;
        return ['name' => ['sometimes', 'string', 'max:255'], 'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($userId)], 'password' => ['sometimes', Password::defaults()], 'role' => ['sometimes', 'in:Admin,CSKH,User'], 'is_active' => ['sometimes', 'boolean']];
    }
}
