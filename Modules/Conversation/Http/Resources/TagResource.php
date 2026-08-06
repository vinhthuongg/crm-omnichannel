<?php

namespace Modules\Conversation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TagResource extends JsonResource
{
    /** Trả ID, tên và màu dùng để hiển thị nhãn hội thoại trên giao diện. */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'color' => $this->color];
    }
}
