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
use Modules\Customer\Models\CustomerTag;
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
        $status = strtolower(trim((string) $request->query('status', '')));
        $agentId = (int) $request->query('agent_id', 0);
        $tagId = (int) $request->query('tag_id', 0);
        $date = trim((string) $request->query('date', ''));
        $visibleConversationIds = $this->visibility->visibleFor($user)->pluck('id');
        $filteredConversationIds = Conversation::query()
            ->whereIn('id', $visibleConversationIds)
            ->when(in_array($status, ['open', 'pending', 'closed'], true), fn (Builder $query) => $query->where('status', $status))
            ->when($agentId > 0, fn (Builder $query) => $query->where('assigned_to', $agentId))
            ->when($date !== '', fn (Builder $query) => $query->whereDate('last_message_at', $date))
            ->pluck('id');
        $vectorCustomerIds = $search !== ''
            ? collect($this->vectors->searchCustomers($search, (int) config('search.vector.top_k', 50)))
            : collect();
        $visibleAgentIds = Conversation::query()
            ->whereIn('id', $visibleConversationIds)
            ->whereNotNull('assigned_to')
            ->distinct()
            ->pluck('assigned_to');

        $customers = Customer::query()
            ->whereHas('conversations', fn (Builder $query) => $query->whereIn('conversations.id', $filteredConversationIds))
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
            ->when($tagId > 0, fn (Builder $query) => $query->whereHas('tags', fn (Builder $tags) => $tags->where('customer_tags.id', $tagId)))
            ->with(['channels', 'tags'])
            ->withCount([
                'conversations' => fn (Builder $query) => $query->whereIn('conversations.id', $filteredConversationIds),
                'notes',
            ])
            ->select('customers.*')
            ->selectSub(
                Conversation::query()
                    ->selectRaw('max(last_message_at)')
                    ->whereColumn('customer_id', 'customers.id')
                    ->whereIn('id', $filteredConversationIds),
                'last_message_at'
            )
            ->when(
                $search !== '' && $vectorCustomerIds->isNotEmpty(),
                fn (Builder $query) => $query->orderByRaw($this->vectorOrderSql($vectorCustomerIds->all()))
            )
            ->orderByDesc('last_message_at')
            ->paginate(10)
            ->withQueryString();

        $latestConversations = Conversation::query()
            ->whereIn('id', $filteredConversationIds)
            ->whereIn('customer_id', $customers->getCollection()->pluck('id'))
            ->with(['assignee', 'tags'])
            ->latest('last_message_at')
            ->get()
            ->unique('customer_id')
            ->keyBy('customer_id');
        $customerRows = $customers->getCollection()
            ->map(function (Customer $customer) use ($latestConversations): array {
                $conversation = $latestConversations->get($customer->id);
                $primaryChannel = $customer->channels->first();
                $status = $conversation?->status ?: 'open';
                $lastAt = $customer->last_message_at
                    ? \Illuminate\Support\Carbon::parse($customer->last_message_at)
                    : null;
                $interestTags = ($conversation?->tags?->isNotEmpty() ? $conversation->tags : $customer->tags)
                    ->take(2)
                    ->map(fn ($tag): array => [
                        'name' => $tag->name,
                        'color' => $tag->color ?: '#2563eb',
                    ])
                    ->values();

                return [
                    'id' => (int) $customer->id,
                    'name' => $customer->name ?: 'Khach hang #'.$customer->id,
                    'avatar' => $customer->avatar,
                    'initial' => strtoupper(substr($customer->name ?: 'K', 0, 1)),
                    'phone' => $customer->phone ?: 'Chua co so dien thoai',
                    'email' => $customer->email ?: 'Chua co email',
                    'channel' => strtolower((string) ($primaryChannel?->channel ?: 'other')),
                    'channel_label' => ucfirst((string) ($primaryChannel?->channel ?: 'Khac')),
                    'tags' => $interestTags,
                    'last_date' => $lastAt?->format('d/m/Y') ?: 'Chua co',
                    'last_time' => $lastAt?->format('H:i') ?: '',
                    'assignee' => $conversation?->assignee?->name,
                    'assignee_initial' => $conversation?->assignee ? strtoupper(substr($conversation->assignee->name, 0, 1)) : null,
                    'status_label' => match ($status) {
                        'pending' => 'Dang cho',
                        'closed' => 'Da dong',
                        default => 'Dang xu ly',
                    },
                    'status_class' => match ($status) {
                        'pending' => 'waiting',
                        'closed' => 'success',
                        default => 'active',
                    },
                    'conversation_url' => $conversation ? route('crm.conversations.show', $conversation) : null,
                ];
            });

        return view('customers.index', [
            'currentUser' => $user,
            'customers' => $customers,
            'customerRows' => $customerRows,
            'latestConversations' => $latestConversations,
            'filters' => [
                'q' => $search,
                'channel' => $channel,
                'status' => $status,
                'agent_id' => $agentId,
                'tag_id' => $tagId,
                'date' => $date,
            ],
            'agents' => User::query()
                ->whereIn('id', $visibleAgentIds)
                ->orderBy('name')
                ->get(['id', 'name']),
            'customerTags' => CustomerTag::query()
                ->orderBy('name')
                ->get(['id', 'name', 'color']),
            'navItems' => $this->navItems($user),
            'sidebar' => [
                'team_name' => $user->hasRole('Admin') ? 'CRM Admin Desk' : 'Assigned Inbox',
            ],
            'summary' => [
                'total' => Customer::query()
                    ->whereHas('conversations', fn (Builder $query) => $query->whereIn('conversations.id', $visibleConversationIds))
                    ->count(),
                'facebook' => Customer::query()
                    ->whereHas('conversations', fn (Builder $query) => $query->whereIn('conversations.id', $visibleConversationIds))
                    ->whereHas('channels', fn (Builder $query) => $query->where('channel', 'facebook'))
                    ->count(),
                'zalo' => Customer::query()
                    ->whereHas('conversations', fn (Builder $query) => $query->whereIn('conversations.id', $visibleConversationIds))
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
