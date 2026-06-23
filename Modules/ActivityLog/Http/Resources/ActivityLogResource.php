<?php

namespace Modules\ActivityLog\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\User\Http\Resources\UserResource;

class ActivityLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'user' => new UserResource($this->whenLoaded('user')), 'action' => $this->action, 'subject_type' => $this->subject_type, 'subject_id' => $this->subject_id, 'metadata' => $this->metadata, 'created_at' => $this->created_at?->toISOString()];
    }
}