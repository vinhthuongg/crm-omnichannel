<?php

namespace Modules\Customer\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\ConversationVisibilityService;
use Modules\Customer\Models\Customer;
use Modules\Message\Models\Message;
use Modules\Search\Services\VectorSearchService;

class CustomerPageController extends Controller
{
    public function __construct(
        private readonly ConversationVisibilityService $visibility,
        private readonly VectorSearchService $vectors,
    )
    {
    }

    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $search = trim((string) $request->query('q', ''));
        $channel = strtolower(trim((string) $request->query('channel', '')));
        $visibleConversationIds = $this->visibility->visibleFor($user)->pluck('id');
        $vectorCustomerIds = $search !== ''
            ? collect($this->vectors->searchCustomers($search, (int) config('search.vector.top_k', 50)))
            : collect();

        $customers = Customer::query()
            ->whereHas('conversations', fn (Builder $query) => $query->whereIn('id', $visibleConversationIds))
            ->when($search !== '', function (Builder $query) use ($search, $vectorCustomerIds): void {
                $query->where(function (Builder $query) use ($search, $vectorCustomerIds): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhereHas('channels', fn (Builder $channels) => $channels->where('external_id', 'like', "%{$search}%"));

                    if ($vectorCustomerIds->isNotEmpty()) {
                        $query->orWhereIn('id', $vectorCustomerIds);
                    }
                });
            })
            ->when(in_array($channel, ['facebook', 'zalo'], true), fn (Builder $query) => $query->whereHas('channels', fn (Builder $channels) => $channels->where('channel', $channel)))
            ->with(['channels', 'tags'])
            ->withCount([
                'conversations' => fn (Builder $query) => $query->whereIn('id', $visibleConversationIds),
                'notes',
            ])
            ->select('customers.*')
            ->selectSub(
                Conversation::query()
                    ->selectRaw('max(last_message_at)')
                    ->whereColumn('customer_id', 'customers.id')
                    ->whereIn('id', $visibleConversationIds),
                'last_message_at'
            )
            ->selectSub(
                Message::query()
                    ->selectRaw('count(*)')
                    ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
                    ->whereColumn('conversations.customer_id', 'customers.id')
                    ->whereIn('conversations.id', $visibleConversationIds),
                'messages_count'
            )
            ->when(
                $search !== '' && $vectorCustomerIds->isNotEmpty(),
                fn (Builder $query) => $query->orderByRaw($this->vectorOrderSql($vectorCustomerIds->all()))
            )
            ->orderByDesc('last_message_at')
            ->paginate(20)
            ->withQueryString();

        $latestConversations = Conversation::query()
            ->whereIn('id', $visibleConversationIds)
            ->whereIn('customer_id', $customers->getCollection()->pluck('id'))
            ->with(['assignee', 'tags'])
            ->latest('last_message_at')
            ->get()
            ->unique('customer_id')
            ->keyBy('customer_id');

        return view('customers.index', [
            'currentUser' => $user,
            'customers' => $customers,
            'latestConversations' => $latestConversations,
            'filters' => [
                'q' => $search,
                'channel' => $channel,
            ],
            'navItems' => $this->navItems($user),
            'sidebar' => [
                'team_name' => $user->hasRole('Admin') ? 'CRM Admin Desk' : 'Assigned Inbox',
            ],
            'summary' => [
                'total' => Customer::query()
                    ->whereHas('conversations', fn (Builder $query) => $query->whereIn('id', $visibleConversationIds))
                    ->count(),
                'facebook' => Customer::query()
                    ->whereHas('conversations', fn (Builder $query) => $query->whereIn('id', $visibleConversationIds))
                    ->whereHas('channels', fn (Builder $query) => $query->where('channel', 'facebook'))
                    ->count(),
                'zalo' => Customer::query()
                    ->whereHas('conversations', fn (Builder $query) => $query->whereIn('id', $visibleConversationIds))
                    ->whereHas('channels', fn (Builder $query) => $query->where('channel', 'zalo'))
                    ->count(),
            ],
        ]);
    }

    private function navItems(User $user): array
    {
        $items = [
            ['section' => 'dashboard', 'label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'dashboard'],
            ['section' => 'conversations', 'label' => 'Conversations', 'route' => 'crm.conversations', 'icon' => 'forum'],
            ['section' => 'customers', 'label' => 'Customers', 'route' => 'crm.customers', 'icon' => 'contacts'],
            ['section' => 'agents', 'label' => 'Agents', 'route' => 'crm.agents', 'icon' => 'support_agent'],
            ['section' => 'channels', 'label' => 'Channels', 'route' => 'crm.channels', 'icon' => 'hub'],
            ['section' => 'activity', 'label' => 'Activity Log', 'route' => 'crm.activity', 'icon' => 'history'],
            ['section' => 'notifications', 'label' => 'Notifications', 'route' => 'crm.notifications', 'icon' => 'notifications'],
            ['section' => 'settings', 'label' => 'Settings', 'route' => 'crm.settings', 'icon' => 'settings'],
        ];

        if ($user->can('user.manage')) {
            array_splice($items, 4, 0, [[
                'section' => 'work_shifts',
                'label' => 'Shifts',
                'route' => 'work-shifts.index',
                'icon' => 'schedule',
            ]]);
        }

        return $items;
    }

    private function vectorOrderSql(array $ids): string
    {
        $ids = collect($ids)
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->values()
            ->implode(',');

        return $ids === ''
            ? 'customers.id asc'
            : "case when field(customers.id, {$ids}) = 0 then 999999 else field(customers.id, {$ids}) end asc";
    }
}
