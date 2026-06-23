<?php

namespace Modules\Conversation\Repositories;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Conversation\Models\Conversation;
use Modules\Customer\Models\Customer;

class ConversationRepository
{
    public function paginateFor(User $user, array $filters = []): LengthAwarePaginator
    {
        return Conversation::query()
            ->with(['customer.channels', 'assignee', 'tags'])
            ->when(! $user->can('conversation.view_all'), fn ($query) => $query->where('assigned_to', $user->id))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest('last_message_at')
            ->paginate((int) ($filters['per_page'] ?? 20));
    }

    public function openForCustomer(Customer $customer): Conversation
    {
        return Conversation::query()->firstOrCreate(['customer_id' => $customer->id, 'status' => 'open'], ['last_message_at' => now()]);
    }
}