<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Models\WorkShift;
use Modules\Conversation\Support\ConversationStatus;

class WorkShiftController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->can('user.manage'), 403);
        $user = $request->user();

        $shifts = WorkShift::query()
            ->with(['agents' => fn ($query) => $query->withCount([
                'assignedConversations as active_conversations_count' => fn (Builder $conversationQuery) => $conversationQuery->whereIn('status', ConversationStatus::ACTIVE),
            ])])
            ->withCount([
                'agents',
                'queuedConversations as waiting_conversations_count' => fn (Builder $query) => $query
                    ->where('status', ConversationStatus::WAITING)
                    ->whereNull('assigned_to'),
                'ownedConversations as handling_conversations_count' => fn (Builder $query) => $query
                    ->whereIn('status', ConversationStatus::ACTIVE)
                    ->whereNotNull('assigned_to'),
                'ownedConversations as finished_conversations_count' => fn (Builder $query) => $query
                    ->whereIn('status', [ConversationStatus::RESOLVED, ConversationStatus::CLOSED]),
            ])
            ->orderBy('starts_at')
            ->limit(50)
            ->get();
        $now = now();
        $currentShift = $shifts->first(fn (WorkShift $shift): bool => $shift->is_active && $this->containsTime($shift, $now));
        $nextShift = $shifts->first(fn (WorkShift $shift): bool => $shift->is_active && $shift->starts_at && $shift->starts_at->greaterThan($now));
        $selectedShift = $shifts->firstWhere('id', (int) $request->query('shift_id')) ?: $currentShift ?: $nextShift ?: $shifts->first();
        $manageStatus = $this->manageStatus($request);
        $managedShifts = $shifts
            ->filter(fn (WorkShift $shift): bool => $this->matchesManageStatus($shift, $manageStatus, $now))
            ->values();
        $staffMembers = $this->staffMembersFor($selectedShift);
        $agents = $this->agents();
        $busyAgentIds = $this->busyAgentIds();

        return view('work_shifts.index', [
            'currentUser' => $user,
            'activeSection' => 'work_shifts',
            'navItems' => $this->navItems($user),
            'sidebar' => [
                'team_name' => $user->hasRole('Admin') ? 'CRM Admin Desk' : 'Assigned Inbox',
            ],
            'shifts' => $shifts,
            'managedShifts' => $managedShifts,
            'currentShift' => $currentShift,
            'nextShift' => $nextShift,
            'selectedShift' => $selectedShift,
            'staffMembers' => $staffMembers,
            'manageStatus' => $manageStatus,
            'staffMetrics' => $this->staffMetrics($selectedShift),
            'agents' => $agents,
            'createAgents' => $agents
                ->reject(fn (User $agent): bool => $busyAgentIds->contains($agent->id))
                ->values(),
            'busyAgentIds' => $busyAgentIds,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('user.manage'), 403);

        $validated = $this->validated($request);
        $this->ensureAgentsAvailable($validated['agent_ids']);
        $shift = WorkShift::query()->create($this->shiftAttributes($validated, $request->boolean('is_active', true)));
        $shift->agents()->sync($validated['agent_ids']);

        return back()->with('status', 'Đã tạo ca trực.');
    }

    public function update(Request $request, WorkShift $workShift): RedirectResponse
    {
        abort_unless($request->user()->can('user.manage'), 403);

        $validated = $this->validated($request);
        $this->ensureAgentsAvailable($validated['agent_ids'], $workShift);
        $workShift->update($this->shiftAttributes($validated, $request->boolean('is_active')));
        $workShift->agents()->sync($validated['agent_ids']);

        return back()->with('status', 'Đã cập nhật ca trực.');
    }

    public function destroy(Request $request, WorkShift $workShift): RedirectResponse
    {
        abort_unless($request->user()->can('user.manage'), 403);

        $workShift->delete();

        return back()->with('status', 'Đã xóa ca trực.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'starts_time' => ['required', 'date_format:H:i'],
            'ends_time' => ['required', 'date_format:H:i'],
            'agent_ids' => ['required', 'array', 'min:1', 'max:2'],
            'agent_ids.*' => ['integer', 'distinct', 'exists:users,id'],
        ]);
    }

    private function shiftAttributes(array $validated, bool $isActive): array
    {
        $startsAt = $this->timeOnSystemDate($validated['starts_time']);
        $endsAt = $this->timeOnSystemDate($validated['ends_time']);

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            $endsAt->addDay();
        }

        return [
            'name' => $validated['name'] ?? null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'is_active' => $isActive,
        ];
    }

    private function timeOnSystemDate(string $time): Carbon
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return now()->startOfDay()->setTime($hour, $minute);
    }

    private function containsTime(WorkShift $shift, $time): bool
    {
        if (! $shift->starts_at || ! $shift->ends_at) {
            return false;
        }

        if ($shift->starts_at <= $time && $shift->ends_at > $time) {
            return true;
        }

        $start = ((int) $shift->starts_at->format('H') * 3600) + ((int) $shift->starts_at->format('i') * 60);
        $end = ((int) $shift->ends_at->format('H') * 3600) + ((int) $shift->ends_at->format('i') * 60);
        $current = ((int) $time->format('H') * 3600) + ((int) $time->format('i') * 60);

        if ($start === $end) {
            return true;
        }

        if ($start < $end) {
            return $current >= $start && $current < $end;
        }

        return $current >= $start || $current < $end;
    }

    private function manageStatus(Request $request): string
    {
        $status = (string) $request->query('status', 'all');

        return in_array($status, ['all', 'active', 'inactive', 'current'], true) ? $status : 'all';
    }

    private function matchesManageStatus(WorkShift $shift, string $status, Carbon $now): bool
    {
        return match ($status) {
            'active' => $shift->is_active,
            'inactive' => ! $shift->is_active,
            'current' => $shift->is_active && $this->containsTime($shift, $now),
            default => true,
        };
    }

    private function staffMembersFor(?WorkShift $shift)
    {
        if (! $shift) {
            return collect();
        }

        return $shift->agents()
            ->withCount([
                'assignedConversations as active_conversations_count' => fn (Builder $query) => $query
                    ->where('owner_shift_id', $shift->id)
                    ->whereIn('status', ConversationStatus::ACTIVE),
                'assignedConversations as finished_conversations_count' => fn (Builder $query) => $query
                    ->where('owner_shift_id', $shift->id)
                    ->whereIn('status', [ConversationStatus::RESOLVED, ConversationStatus::CLOSED]),
            ])
            ->orderBy('name')
            ->get();
    }

    private function staffMetrics(?WorkShift $shift): array
    {
        if (! $shift) {
            return [
                'waiting' => 0,
                'handling' => 0,
                'finished' => 0,
                'total' => 0,
            ];
        }

        $waiting = Conversation::query()
            ->where('queue_shift_id', $shift->id)
            ->where('status', ConversationStatus::WAITING)
            ->whereNull('assigned_to')
            ->count();
        $handling = Conversation::query()
            ->where('owner_shift_id', $shift->id)
            ->whereIn('status', ConversationStatus::ACTIVE)
            ->whereNotNull('assigned_to')
            ->count();
        $finished = Conversation::query()
            ->where('owner_shift_id', $shift->id)
            ->whereIn('status', [ConversationStatus::RESOLVED, ConversationStatus::CLOSED])
            ->count();

        return [
            'waiting' => $waiting,
            'handling' => $handling,
            'finished' => $finished,
            'total' => $waiting + $handling + $finished,
        ];
    }

    private function agents()
    {
        return User::role(['CSKH', 'User'])
            ->where('is_active', true)
            ->withCount([
                'assignedConversations as active_conversations_count' => fn (Builder $query) => $query->whereIn('status', ConversationStatus::ACTIVE),
            ])
            ->orderBy('name')
            ->get();
    }

    private function busyAgentIds()
    {
        return WorkShift::query()
            ->where('is_active', true)
            ->with('agents:id')
            ->get()
            ->flatMap(fn (WorkShift $shift) => $shift->agents->pluck('id'))
            ->unique()
            ->values();
    }

    private function ensureAgentsAvailable(array $agentIds, ?WorkShift $currentShift = null): void
    {
        $busyShift = WorkShift::query()
            ->where('is_active', true)
            ->when($currentShift, fn (Builder $query) => $query->where('id', '!=', $currentShift->id))
            ->whereHas('agents', fn (Builder $query) => $query->whereIn('users.id', $agentIds))
            ->first();

        if (! $busyShift) {
            return;
        }

        throw ValidationException::withMessages([
            'agent_ids' => 'Nhân viên đã nằm trong ca trực đang bật. Vui lòng chọn nhân viên khác.',
        ]);
    }

    private function navItems(User $user): array
    {
        $items = [
            ['section' => 'dashboard', 'label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'dashboard'],
            ['section' => 'conversations', 'label' => 'Hội thoại', 'route' => 'crm.conversations', 'icon' => 'forum'],
            ['section' => 'customers', 'label' => 'Khách hàng', 'route' => 'crm.customers', 'icon' => 'contacts'],
            ['section' => 'channels', 'label' => 'Kết nối kênh', 'route' => 'crm.channels', 'icon' => 'hub'],
            ['section' => 'activity', 'label' => 'Hoạt động', 'route' => 'crm.activity', 'icon' => 'history'],
            ['section' => 'notifications', 'label' => 'Thông báo', 'route' => 'crm.notifications', 'icon' => 'notifications'],
            ['section' => 'settings', 'label' => 'Cài đặt', 'route' => 'crm.settings', 'icon' => 'settings'],
        ];

        if ($user->can('user.manage')) {
            array_splice($items, 4, 0, [[
                'section' => 'work_shifts',
                'label' => 'Ca trực',
                'route' => 'work-shifts.index',
                'icon' => 'schedule',
            ]]);
        }

        return $items;
    }
}
