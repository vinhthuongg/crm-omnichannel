<?php

namespace Modules\Customer\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\ConversationVisibilityService;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Customer\Models\Customer;
use Modules\Customer\Models\CustomerTag;
use Modules\Search\Services\VectorSearchService;

class CustomerPageQueryService
{
    /** Nhận ConversationVisibilityService để kiểm tra phạm vi truy cập; VectorSearchService để lập chỉ mục và tìm khách hàng theo độ tương đồng. */
    public function __construct(private readonly ConversationVisibilityService $visibility, private readonly VectorSearchService $vectors) {}

    /** Tìm khách hàng theo từ khóa/vector, áp dụng bộ lọc và trả dữ liệu trang web phân trang. */
    public function execute(User $user, array $input): array
    {
        $filters = $this->filters($input);
        $visibleIds = $this->visibility->visibleFor($user)->pluck('id');
        $filteredIds = Conversation::query()->whereIn('id', $visibleIds)
            ->when($filters['status'] !== '', fn (Builder $query) => $query->where('status', $filters['status']))
            ->when($filters['agent_id'] > 0, fn (Builder $query) => $query->where('assigned_to', $filters['agent_id']))
            ->when($filters['date'] !== '', fn (Builder $query) => $query->whereDate('last_message_at', $filters['date']))->pluck('id');
        $vectorIds = $filters['q'] !== '' ? collect($this->vectors->searchCustomers($filters['q'], (int) config('search.vector.top_k', 50))) : collect();
        $useVector = $filters['q'] !== '' && $vectorIds->isNotEmpty();

        $customers = Customer::query()
            ->whereHas('conversations', fn (Builder $query) => $query->whereIn('conversations.id', $filteredIds))
            ->when($useVector, fn (Builder $query) => $query->whereIn('id', $vectorIds))
            ->when($filters['q'] !== '' && ! $useVector, fn (Builder $query) => $this->fallbackSearch($query, $filters['q']))
            ->when(in_array($filters['channel'], ['facebook', 'zalo'], true), fn (Builder $query) => $query
                ->whereHas('channels', fn (Builder $channels) => $channels->where('channel', $filters['channel'])))
            ->when($filters['tag_id'] > 0, fn (Builder $query) => $query
                ->whereHas('tags', fn (Builder $tags) => $tags->where('customer_tags.id', $filters['tag_id'])))
            ->with(['channels', 'tags'])
            ->withCount(['conversations' => fn (Builder $query) => $query->whereIn('conversations.id', $filteredIds), 'notes'])
            ->select('customers.*')->selectSub(Conversation::query()->selectRaw('max(last_message_at)')
                ->whereColumn('customer_id', 'customers.id')->whereIn('id', $filteredIds), 'last_message_at')
            ->when($useVector, fn (Builder $query) => $query->orderByRaw($this->vectorOrderSql($vectorIds->all())))
            ->orderByDesc('last_message_at')->paginate(10)->withQueryString();

        $latest = Conversation::query()->whereIn('id', $filteredIds)->whereIn('customer_id', $customers->getCollection()->pluck('id'))
            ->with(['assignee', 'tags'])->latest('last_message_at')->get()->unique('customer_id')->keyBy('customer_id');

        return [
            'customers' => $customers, 'customerRows' => $customers->getCollection()->map(fn (Customer $customer): array => $this->row($customer, $latest->get($customer->id))),
            'latestConversations' => $latest, 'filters' => $filters,
            'agents' => User::query()->whereIn('id', Conversation::query()->whereIn('id', $visibleIds)->whereNotNull('assigned_to')->distinct()->pluck('assigned_to'))
                ->orderBy('name')->get(['id', 'name']),
            'customerTags' => CustomerTag::query()->orderBy('name')->get(['id', 'name', 'color']),
            'summary' => $this->summary($visibleIds),
        ];
    }

    /** Chuẩn hóa các bộ lọc được phép từ dữ liệu đầu vào. */
    private function filters(array $input): array
    {
        return ['q' => trim((string) ($input['q'] ?? '')), 'channel' => strtolower(trim((string) ($input['channel'] ?? ''))),
            'status' => ConversationStatus::fromFilter(strtolower(trim((string) ($input['status'] ?? '')))) ?? '',
            'agent_id' => (int) ($input['agent_id'] ?? 0), 'tag_id' => (int) ($input['tag_id'] ?? 0), 'date' => trim((string) ($input['date'] ?? ''))];
    }

    /** Chuẩn bị truy vấn hoặc dữ liệu dòng khách hàng tại bước fallbackSearch. */
    private function fallbackSearch(Builder $query, string $search): void
    {
        $query->where(fn (Builder $query) => $query->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")
            ->orWhere('email', 'like', "%{$search}%")->orWhereHas('channels', fn (Builder $channels) => $channels->where('external_id', 'like', "%{$search}%")));
    }

    /** Chuẩn bị truy vấn hoặc dữ liệu dòng khách hàng tại bước row. */
    private function row(Customer $customer, ?Conversation $conversation): array
    {
        $channel = $customer->channels->first();
        $lastAt = $customer->last_message_at ? Carbon::parse($customer->last_message_at) : null;
        $tags = ($conversation?->tags?->isNotEmpty() ? $conversation->tags : $customer->tags)->take(2)
            ->map(fn ($tag): array => ['name' => $tag->name, 'color' => $tag->color ?: '#2563eb'])->values();
        $status = ConversationStatus::normalize($conversation?->status);
        return ['id' => (int) $customer->id, 'name' => $customer->name ?: 'Khách hàng #'.$customer->id, 'avatar' => $customer->avatar,
            'initial' => strtoupper(substr($customer->name ?: 'K', 0, 1)), 'phone' => $customer->phone ?: 'Chưa có số điện thoại',
            'email' => $customer->email ?: 'Chưa có email', 'channel' => strtolower((string) ($channel?->channel ?: 'other')),
            'channel_label' => ucfirst((string) ($channel?->channel ?: 'Khác')), 'tags' => $tags,
            'last_date' => $lastAt?->format('d/m/Y') ?: 'Chưa có', 'last_time' => $lastAt?->format('H:i') ?: '',
            'assignee' => $conversation?->assignee?->name, 'assignee_initial' => $conversation?->assignee ? strtoupper(substr($conversation->assignee->name, 0, 1)) : null,
            'status_label' => ConversationStatus::label($status), 'status_class' => match ($status) {
                ConversationStatus::CLOSED => 'success', ConversationStatus::WAITING_CUSTOMER => 'active', ConversationStatus::BOT_CONSULTING => 'bot', default => 'waiting'},
            'conversation_url' => $conversation ? route('crm.conversations.show', $conversation) : null];
    }

    /** Đếm tổng khách hàng hiển thị và số khách có kênh Facebook hoặc Zalo. */
    private function summary($visibleIds): array
    {
        $base = fn () => Customer::query()->whereHas('conversations', fn (Builder $query) => $query->whereIn('conversations.id', $visibleIds));
        return ['total' => $base()->count(), 'facebook' => $base()->whereHas('channels', fn (Builder $query) => $query->where('channel', 'facebook'))->count(),
            'zalo' => $base()->whereHas('channels', fn (Builder $query) => $query->where('channel', 'zalo'))->count()];
    }

    /** Chuẩn bị truy vấn hoặc dữ liệu dòng khách hàng tại bước vectorOrderSql. */
    private function vectorOrderSql(array $ids): string
    {
        $ids = collect($ids)->map(fn ($id): int => (int) $id)->filter()->values()->implode(',');
        return $ids === '' ? 'customers.id asc' : "case when field(customers.id, {$ids}) = 0 then 999999 else field(customers.id, {$ids}) end asc";
    }
}
