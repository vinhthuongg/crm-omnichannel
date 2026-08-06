<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Models\Tag;
use Modules\Conversation\Services\ConversationVisibilityService;

class MessengerInboxQueryService
{
    /** Nhận ConversationVisibilityService để kiểm tra phạm vi truy cập. */
    public function __construct(private readonly ConversationVisibilityService $visibility)
    {
    }

    /** Truy vấn danh sách hội thoại người dùng được phép xem. */
    public function conversations(User $user, array $filters): Collection
    {
        return $this->filtered($user, $filters)->with([
            'customer.channels', 'customer.tags', 'assignee', 'tags',
            'messages' => fn ($query) => $query->latest()->limit(1),
        ])->latest('last_message_at')->limit(30)->get();
    }

    /** Lấy hội thoại đang được chọn và còn khả dụng với người dùng. */
    public function active(User $user, ?Conversation $selected): ?Conversation
    {
        if (! $selected) return null;
        if (! $this->visibility->canView($user, $selected)) throw new AuthorizationException;
        return $selected->load(['customer.channels', 'customer.notes.user', 'customer.tags', 'assignee', 'tags']);
    }

    /** Tạo các tab bộ lọc và số lượng hội thoại theo kênh. */
    public function channelTabs(User $user, array $filters): array
    {
        return collect([
            ['key' => 'all', 'label' => 'Tat ca'], ['key' => 'facebook', 'label' => 'Facebook'], ['key' => 'zalo', 'label' => 'Zalo'],
        ])->map(function (array $item) use ($user, $filters): array {
            $query = $this->baseFilters($this->visibility->visibleFor($user), $filters);
            $query = $this->statusFilter($query, $filters['status'], $user);
            return [...$item, 'active' => $filters['channel'] === $item['key'],
                'unread' => (int) $this->channelFilter($query, $item['key'])->where('unread_messages_count', '>', 0)->count()];
        })->all();
    }

    /** Tạo các tab bộ lọc và số lượng hội thoại theo trạng thái. */
    public function statusTabs(User $user, array $filters): array
    {
        return collect([
            ['key' => 'all', 'label' => 'Tat ca'], ['key' => 'unread', 'label' => 'Chua doc'], ['key' => 'mine', 'label' => 'Cua toi'],
        ])->map(function (array $item) use ($user, $filters): array {
            $query = $this->channelFilter($this->baseFilters($this->visibility->visibleFor($user), $filters), $filters['channel']);
            return [...$item, 'active' => $filters['status'] === $item['key'],
                'count' => (int) $this->statusFilter($query, $item['key'], $user)->count()];
        })->all();
    }

    /** Lấy danh sách nhân viên đang hoạt động và có quyền nhận hội thoại. */
    public function assignableAgents(User $user): Collection
    {
        if (! $user->can('conversation.assign') && ! $user->can('conversation.transfer')) return collect();
        return User::query()->permission('conversation.reply')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email']);
    }

    /** Lấy và định dạng danh sách nhãn liên quan. */
    public function tags(): Collection
    {
        Tag::ensureDefaults();
        return Tag::query()->where('is_default', true)->orderByDesc('is_default')->orderBy('name')->get();
    }

    /** Áp dụng toàn bộ bộ lọc inbox và trả về truy vấn hội thoại phù hợp. */
    private function filtered(User $user, array $filters): Builder
    {
        $query = $this->baseFilters($this->visibility->visibleFor($user), $filters);
        return $this->statusFilter($this->channelFilter($query, $filters['channel']), $filters['status'], $user);
    }

    /** Áp dụng các bộ lọc nền dùng chung cho truy vấn inbox. */
    private function baseFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
                $search = $filters['search'];
                $query->whereHas('customer', fn (Builder $customer): Builder => $customer
                    ->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
            })
            ->when($filters['tag'] !== '', fn (Builder $query): Builder => $query
                ->whereHas('tags', fn (Builder $tags): Builder => $tags->where('name', $filters['tag'])));
    }

    /** Giới hạn truy vấn inbox theo kênh được chọn. */
    private function channelFilter(Builder $query, string $channel): Builder
    {
        if (! in_array($channel, ['facebook', 'zalo'], true)) return $query;
        return $query->where(fn (Builder $query) => $query
            ->whereHas('customer.channels', fn (Builder $channels) => $channels->where('channel', $channel))
            ->orWhereHas('messages', fn (Builder $messages) => $messages->where('channel', $channel)));
    }

    /** Giới hạn truy vấn inbox theo trạng thái được chọn. */
    private function statusFilter(Builder $query, string $status, User $user): Builder
    {
        return match ($status) {
            'unread' => $query->where('unread_messages_count', '>', 0),
            'mine' => $query->where('assigned_to', $user->id),
            default => $query,
        };
    }
}
