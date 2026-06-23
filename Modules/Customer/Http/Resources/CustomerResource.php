<?php

namespace Modules\Customer\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'avatar' => $this->avatar,
            'phone' => $this->phone,
            'email' => $this->email,
            'channels' => CustomerChannelResource::collection($this->whenLoaded('channels')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}