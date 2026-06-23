<?php

namespace Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    public function rules(): array
    {
        return ['current_password' => ['required', 'current_password:sanctum'], 'password' => ['required', 'confirmed', Password::defaults()]];
    }

    public function authorize(): bool
    {
        return true;
    }
}