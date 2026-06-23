<?php

namespace Modules\Customer\Http\Requests;

class UpdateCustomerRequest extends StoreCustomerRequest
{
    public function rules(): array
    {
        return ['name' => ['sometimes', 'string', 'max:255'], 'avatar' => ['nullable', 'url'], 'phone' => ['nullable', 'string', 'max:40'], 'email' => ['nullable', 'email'], 'channels' => ['array'], 'channels.*.channel' => ['required_with:channels', 'in:facebook,zalo'], 'channels.*.external_id' => ['required_with:channels', 'string'], 'channels.*.metadata' => ['array']];
    }
}