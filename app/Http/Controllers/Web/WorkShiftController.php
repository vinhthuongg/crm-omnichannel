<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\CrmNavigationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Conversation\Http\Requests\SaveWorkShiftRequest;
use Modules\Conversation\Models\WorkShift;
use Modules\Conversation\Services\WorkShiftManagementService;
use Modules\Conversation\Services\WorkShiftQueryService;

class WorkShiftController extends Controller
{
    /** Nhận dịch vụ quản lý ca, truy vấn màn hình ca trực và tạo menu theo quyền. */
    public function __construct(
        private readonly WorkShiftManagementService $management,
        private readonly WorkShiftQueryService $queries,
        private readonly CrmNavigationService $navigation,
    ) {}

    /** Hiển thị trang quản lý ca với ca hiện tại/kế tiếp, nhân sự, trạng thái và chỉ số phân công. */
    public function index(Request $request): View
    {
        abort_unless($request->user()->can('user.manage'), 403);
        $user = $request->user();
        $data = $this->queries->overview($request->integer('shift_id') ?: null, (string) $request->query('status', 'all'), 50);

        return view('work_shifts.index', [
            'currentUser' => $user, 'activeSection' => 'work_shifts', 'navItems' => $this->navigation->forUser($user),
            'sidebar' => ['team_name' => $user->hasRole('Admin') ? 'CRM Admin Desk' : 'Assigned Inbox'],
            'shifts' => $data['shifts'], 'managedShifts' => $data['managed_shifts'], 'currentShift' => $data['current_shift'],
            'nextShift' => $data['next_shift'], 'selectedShift' => $data['selected_shift'], 'staffMembers' => $data['staff_members'],
            'manageStatus' => $data['manage_status'], 'staffMetrics' => $data['staff_metrics'], 'agents' => $data['agents'],
            'createAgents' => $data['create_agents'], 'busyAgentIds' => $data['busy_agent_ids'],
        ]);
    }

    /** Tạo ca trực mới từ thời gian, nhân sự và trạng thái hoạt động đã được xác thực. */
    public function store(SaveWorkShiftRequest $request): RedirectResponse
    {
        $this->management->create($request->validated(), $request->boolean('is_active', true));
        return back()->with('status', 'Đã tạo ca trực.');
    }

    /** Cập nhật lịch, nhân sự và trạng thái hoạt động của ca trực được chọn. */
    public function update(SaveWorkShiftRequest $request, WorkShift $workShift): RedirectResponse
    {
        $this->management->update($workShift, $request->validated(), $request->boolean('is_active'));
        return back()->with('status', 'Đã cập nhật ca trực.');
    }

    /** Xóa ca trực nếu người dùng có quyền quản lý tài khoản và nhân sự. */
    public function destroy(Request $request, WorkShift $workShift): RedirectResponse
    {
        abort_unless($request->user()->can('user.manage'), 403);
        $this->management->delete($workShift);
        return back()->with('status', 'Đã xóa ca trực.');
    }
}
