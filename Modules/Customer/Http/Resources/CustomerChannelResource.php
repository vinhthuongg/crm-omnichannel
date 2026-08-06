<?php

namespace Modules\Customer\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerChannelResource extends JsonResource
{
    /** Trả loại kênh, định danh bên ngoài và metadata liên hệ của khách hàng. */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel' => $this->channel,
            'external_id' => $this->external_id,
            'metadata' => $this->metadata,
        ];
    }
}
