<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
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
            ->orderBy('starts_at')
            ->limit(50)
            ->get();
        $now = now();
        $currentShift = $shifts->first(fn (WorkShift $shift): bool => $shift->is_active && $this->containsTime($shift, $now));
        $nextShift = $shifts->first(fn (WorkShift $shift): bool => $shift->is_active && $shift->starts_at && $shift->starts_at->greaterThan($now));

        return view('work_shifts.index', [
            'currentUser' => $user,
            'activeSection' => 'work_shifts',
            'navItems' => $this->navItems($user),
            'sidebar' => [
                'team_name' => $user->hasRole('Admin') ? 'CRM Admin Desk' : 'Assigned Inbox',
            ],
            'shifts' => $shifts,
            'currentShift' => $currentShift,
            'nextShift' => $nextShift,
            'agents' => User::role(['CSKH', 'User'])
                ->where('is_active', true)
                ->withCount([
                    'assignedConversations as active_conversations_count' => fn (Builder $query) => $query->whereIn('status', ConversationStatus::ACTIVE),
                ])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('user.manage'), 403);

        $validated = $this->validated($request);
        $shift = WorkShift::query()->create($this->shiftAttributes($validated, $request->boolean('is_active', true)));
        $shift->agents()->sync($validated['agent_ids']);

        return back()->with('status', 'Da tao ca truc.');
    }

    public function update(Request $request, WorkShift $workShift): RedirectResponse
    {
        abort_unless($request->user()->can('user.manage'), 403);

        $validated = $this->validated($request);
        $workShift->update($this->shiftAttributes($validated, $request->boolean('is_active')));
        $workShift->agents()->sync($validated['agent_ids']);

        return back()->with('status', 'Da cap nhat ca truc.');
    }

    public function destroy(Request $request, WorkShift $workShift): RedirectResponse
    {
        abort_unless($request->user()->can('user.manage'), 403);

        $workShift->delete();

        return back()->with('status', 'Da xoa ca truc.');
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
}
