<?php

namespace Modules\Dashboard\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\ConversationVisibilityService;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Customer\Models\Customer;

class DashboardAgentService
{
    /** Nhận ConversationVisibilityService để kiểm tra phạm vi truy cập. */
    public function __construct(private readonly ConversationVisibilityService $visibility)
    {
    }

    /** Lấy danh sách nhân viên phù hợp với điều kiện hiện tại. */
    public function agents(User $user): Collection
    {
        if (! $user->can('user.manage')) {
            return collect([$user->loadCount([
                'assignedConversations as open_conversations_count' => fn (Builder $query) => $query->where('status', ConversationStatus::IN_PROGRESS),
            ])]);
        }

        return User::query()->withCount([
            'assignedConversations as open_conversations_count' => fn (Builder $query) => $query->where('status', ConversationStatus::IN_PROGRESS),
            'assignedConversations as closed_conversations_count' => fn (Builder $query) => $query->where('status', ConversationStatus::CLOSED),
        ])->orderByDesc('open_conversations_count')->limit(5)->get();
    }

    /** Tính bảng hiệu suất, rating và phản hồi trung bình của từng nhân viên. */
    public function build(User $user): array
    {
        $agents = $this->rows($user);
        $totalHandled = $agents->sum('total_conversations');
        $processed = $agents->sum('processed_conversations');
        $phoneCollected = $agents->sum('phone_collected');
        $avgResponse = $agents->where('avg_response_minutes', '>', 0)->avg('avg_response_minutes') ?: 0;
        $newCustomers = Customer::query()->whereBetween('created_at', [today()->subDays(6)->startOfDay(), now()])->count();
        $previousNewCustomers = Customer::query()
            ->whereBetween('created_at', [today()->subDays(13)->startOfDay(), today()->subDays(7)->endOfDay()])->count();
        $customerChange = $this->percentageChange($newCustomers, $previousNewCustomers);

        return [
            'cards' => [
                ['label' => 'Tổng hội thoại xử lý', 'value' => number_format($totalHandled), 'suffix' => '', 'change' => number_format($processed).' đã xử lý', 'tone' => 'good', 'icon' => 'forum'],
                ['label' => 'TG phản hồi TB', 'value' => number_format($avgResponse, 1), 'suffix' => 'phút', 'change' => 'Tính từ hội thoại có phản hồi', 'tone' => 'good', 'icon' => 'timer'],
                ['label' => 'Tỷ lệ thu thập SĐT', 'value' => $totalHandled > 0 ? (int) round(($phoneCollected / $totalHandled) * 100) : 0, 'suffix' => '%', 'change' => number_format($phoneCollected).' số điện thoại', 'tone' => 'good', 'icon' => 'contact_phone'],
                ['label' => 'Khách hàng mới', 'value' => number_format($newCustomers), 'suffix' => '', 'change' => ($customerChange >= 0 ? '+' : '').$customerChange.'% so với tuần trước', 'tone' => $newCustomers >= $previousNewCustomers ? 'good' : 'bad', 'icon' => 'person_add'],
            ],
            'bar' => $agents->take(5)->map(fn (array $agent): array => ['agent' => $agent['short_name'], 'value' => $agent['total_conversations']])->values()->all(),
            'responseLine' => $this->responseLine($user),
            'rows' => $agents,
        ];
    }

    /** Tạo từng dòng hiệu suất cho các nhân viên mà người dùng được phép quản lý. */
    private function rows(User $user): Collection
    {
        $users = $user->can('user.manage') ? User::query()->where('is_active', true)->orderBy('name')->get() : collect([$user]);

        return $users->map(function (User $agent): array {
            $base = Conversation::query()->where('assigned_to', $agent->id);
            $total = (clone $base)->count();
            $processed = (clone $base)->whereIn('status', [ConversationStatus::IN_PROGRESS, ConversationStatus::CLOSED, ConversationStatus::RESOLVED])->count();
            $active = (clone $base)->where('status', ConversationStatus::IN_PROGRESS)->count();
            $phoneCollected = Customer::query()->whereNotNull('phone')
                ->whereHas('conversations', fn (Builder $query) => $query->where('assigned_to', $agent->id))->count();
            $avgResponse = $this->averageResponseMinutes($agent);

            return [
                'id' => (int) $agent->id, 'name' => $agent->name, 'short_name' => $this->shortName($agent->name),
                'avatar' => $agent->avatar ?? null, 'initial' => strtoupper(substr($agent->name, 0, 1)),
                'total_conversations' => $total, 'processed_conversations' => $processed,
                'active_conversations' => $active, 'avg_response_minutes' => $avgResponse,
                'phone_collected' => $phoneCollected, 'rating' => $this->rating($total, $processed, $avgResponse),
            ];
        })->sortByDesc('total_conversations')->values();
    }

    /** Tính thời gian phản hồi trung bình của nhân viên theo phút. */
    private function averageResponseMinutes(User $agent): float
    {
        $samples = Conversation::query()->where('assigned_to', $agent->id)->whereNotNull('first_response_at')
            ->latest('first_response_at')->limit(50)->get(['created_at', 'first_response_at'])
            ->map(fn (Conversation $conversation): int => max(1, $conversation->created_at->diffInMinutes($conversation->first_response_at)));

        return $samples->isEmpty() ? 0 : round($samples->avg(), 1);
    }

    /** Tính thời gian phản hồi trung bình của nhân viên theo các mốc hai giờ trong ngày. */
    private function responseLine(User $user): array
    {
        return collect(range(8, 18, 2))->map(function (int $hour) use ($user): array {
            $conversations = $this->visibility->visibleFor($user)->whereNotNull('first_response_at')
                ->whereDate('created_at', today())->whereTime('created_at', '>=', sprintf('%02d:00:00', $hour))
                ->whereTime('created_at', '<', sprintf('%02d:00:00', min(23, $hour + 2)))
                ->get(['created_at', 'first_response_at']);

            return ['hour' => sprintf('%02d:00', $hour), 'value' => round($conversations->map(
                fn (Conversation $conversation): int => max(1, $conversation->created_at->diffInMinutes($conversation->first_response_at))
            )->avg() ?: 0, 1)];
        })->all();
    }

    /** Tính tỷ lệ phần trăm thay đổi giữa giá trị hiện tại và kỳ trước. */
    private function percentageChange(int $current, ?int $previous): int
    {
        return $previous ? (int) round((($current - $previous) / $previous) * 100) : ($current > 0 ? 100 : 0);
    }

    /** Rút gọn họ tên thành tên cuối để hiển thị trong biểu đồ. */
    private function shortName(string $name): string
    {
        $parts = collect(explode(' ', trim($name)))->filter()->values();

        return $parts->count() <= 2 ? $name : $parts->take(2)->implode(' ');
    }

    /** Xếp hạng nhân viên theo tỷ lệ xử lý và thời gian phản hồi trung bình. */
    private function rating(int $total, int $processed, float $avgResponse): string
    {
        if ($total > 0 && $processed / max(1, $total) >= 0.85 && ($avgResponse === 0.0 || $avgResponse <= 4)) {
            return 'Xuất sắc';
        }

        return $total > 0 && $processed / max(1, $total) >= 0.65 ? 'Tốt' : 'Trung bình';
    }
}
