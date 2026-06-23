<?php

namespace Modules\Conversation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Customer\Http\Resources\CustomerResource;
use Modules\Message\Http\Resources\MessageResource;
use Modules\User\Http\Resources\UserResource;

class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'assignee' => new UserResource($this->whenLoaded('assignee')),
            'messages' => MessageResource::collection($this->whenLoaded('messages')),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'last_message_at' => $this->last_message_at?->toISOString(),
            'closed_at' => $this->closed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}