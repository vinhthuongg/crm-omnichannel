<?php

namespace Modules\Conversation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Customer\Http\Resources\CustomerResource;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Message\Http\Resources\MessageResource;
use Modules\User\Http\Resources\UserResource;

class ConversationResource extends JsonResource
{
    /** Trả trạng thái, phân công, khách hàng, tin nhắn và nhãn đã nạp của hội thoại. */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => ConversationStatus::normalize($this->status),
            'status_label' => ConversationStatus::label($this->status),
            'status_color' => ConversationStatus::color($this->status),
            'assigned_to' => $this->assigned_to,
            'assigned_by' => $this->assigned_by,
            'assigned_type' => $this->assigned_type,
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'assignee' => new UserResource($this->whenLoaded('assignee')),
            'messages' => MessageResource::collection($this->whenLoaded('messages')),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'last_message_at' => $this->last_message_at?->toISOString(),
            'claimed_at' => $this->claimed_at?->toISOString(),
            'resolved_at' => $this->resolved_at?->toISOString(),
            'first_response_at' => $this->first_response_at?->toISOString(),
            'closed_at' => $this->closed_at?->toISOString(),
            'owner_shift_id' => $this->owner_shift_id,
            'queue_shift_id' => $this->queue_shift_id,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
