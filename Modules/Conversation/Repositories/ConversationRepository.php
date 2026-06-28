<?php

namespace Modules\Conversation\Repositories;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\ConversationVisibilityService;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Customer\Models\Customer;

class ConversationRepository
{
    public function __construct(private readonly ConversationVisibilityService $visibility)
    {
    }

    public function paginateFor(User $user, array $filters = []): LengthAwarePaginator
    {
        $status = ConversationStatus::fromFilter($filters['status'] ?? null);

        return $this->visibility->visibleFor($user)
            ->with(['customer.channels', 'assignee', 'tags'])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->latest('last_message_at')
            ->paginate((int) ($filters['per_page'] ?? 20));
    }

    public function openForCustomer(Customer $customer): Conversation
    {
        return Conversation::query()->firstOrCreate(
            ['customer_id' => $customer->id, 'status' => ConversationStatus::WAITING],
            ['last_message_at' => now()],
        );
    }
}
