<?php

namespace Modules\Mobile\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Conversation\Http\Requests\SaveWorkShiftRequest;
use Modules\Conversation\Models\WorkShift;
use Modules\Conversation\Services\WorkShiftManagementService;
use Modules\Conversation\Services\WorkShiftQueryService;
use Modules\Conversation\Services\WorkShiftService;
use Modules\Mobile\Presenters\MobileWorkShiftPresenter;
use Modules\Shared\Http\Controllers\ApiController;

class MobileWorkShiftManagementController extends ApiController
{
    /** Nhận dịch vụ tạo/sửa/xóa ca, xác định ca hiện tại, truy vấn nhân sự và định dạng response mobile. */
    public function __construct(private readonly WorkShiftManagementService $management, private readonly WorkShiftService $shifts,
        private readonly WorkShiftQueryService $queries, private readonly MobileWorkShiftPresenter $presenter) {}

    /** Trả danh sách ca, ca được chọn, nhân viên và chỉ số hội thoại cho quản lý mobile. */
    public function overview(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('user.manage'), 403);
        $data = $this->queries->overview($request->integer('shift_id') ?: null, (string) $request->query('status', 'all'));
        return response()->json(['data' => [
            'current_shift' => $data['current_shift'] ? $this->presenter->shift($data['current_shift']) : null,
            'next_shift' => $data['next_shift'] ? $this->presenter->shift($data['next_shift']) : null,
            'selected_shift' => $data['selected_shift'] ? $this->presenter->shift($data['selected_shift']) : null,
            'shifts' => $data['shifts']->map(fn (WorkShift $shift): array => $this->presenter->shift($shift))->values(),
            'managed_shifts' => $data['managed_shifts']->map(fn (WorkShift $shift): array => $this->presenter->shift($shift))->values(),
            'staff_members' => $data['staff_members']->map(fn ($agent): array => [...$this->presenter->agent($agent),
                'active_conversations_count' => (int) $agent->active_conversations_count,
                'finished_conversations_count' => (int) $agent->finished_conversations_count])->values(),
            'staff_metrics' => $this->presenter->metrics($data['staff_metrics']),
            'agents' => $data['agents']->map(fn ($agent): array => $this->presenter->agent($agent))->values(),
            'create_agents' => $data['create_agents']->map(fn ($agent): array => $this->presenter->agent($agent))->values(),
            'busy_agent_ids' => $data['busy_agent_ids'], 'manage_status' => $data['manage_status'],
        ]]);
    }

    /** Trả danh sách ca trực mobile cùng nhân viên, trạng thái và phân trang. */
    public function index(Request $request): JsonResponse
    {
        $page = $this->queries->paginateFor($request->user(), $request->filled('status') ? $request->string('status')->toString() : null, $request->integer('per_page', 20));
        return response()->json(['data' => $page->getCollection()->map(fn (WorkShift $shift): array => $this->presenter->shift($shift))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
                'last_page' => $page->lastPage(), 'has_more' => $page->hasMorePages()]]);
    }

    /** Trả ca trực hiện tại của người dùng cùng trạng thái thành viên và thời gian ca. */
    public function current(Request $request): JsonResponse
    {
        $shift = $this->shifts->currentShiftFor($request->user());
        return response()->json(['data' => $shift ? $this->presenter->shift($shift->load('agents')) : null]);
    }

    /** Trả chi tiết thời gian, trạng thái và thành viên của ca trực được chọn. */
    public function show(Request $request, WorkShift $workShift): JsonResponse
    {
        abort_unless($this->queries->canView($request->user(), $workShift), 403);
        return response()->json(['data' => $this->presenter->shift($workShift->load('agents'))]);
    }

    /** Tạo ca trực mới từ dữ liệu hợp lệ và trả payload ca cho mobile. */
    public function store(SaveWorkShiftRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->presenter->shift($this->management->create($request->validated(), $request->boolean('is_active', true)))], 201);
    }

    /** Cập nhật thời gian, thành viên và trạng thái của ca trực được chọn. */
    public function update(SaveWorkShiftRequest $request, WorkShift $workShift): JsonResponse
    {
        return response()->json(['data' => $this->presenter->shift($this->management->update($workShift, $request->validated(), $request->boolean('is_active')))]);
    }

    /** Xóa ca trực nếu người dùng có quyền quản lý và ca không bị ràng buộc. */
    public function destroy(Request $request, WorkShift $workShift): JsonResponse
    {
        abort_unless($request->user()->can('user.manage'), 403);
        $this->management->delete($workShift);
        return response()->json(status: 204);
    }
}
