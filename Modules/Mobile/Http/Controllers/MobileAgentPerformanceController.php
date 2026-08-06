<?php

namespace Modules\Mobile\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Modules\Dashboard\Http\Requests\AgentPerformanceRequest;
use Modules\Dashboard\Services\AgentPerformanceService;
use Modules\Shared\Http\Controllers\ApiController;

class MobileAgentPerformanceController extends ApiController
{
    /** Nhận AgentPerformanceService để truy vấn và định dạng chỉ số hiệu suất nhân viên. */
    public function __construct(private readonly AgentPerformanceService $performance)
    {
    }

    /** Trả bảng hiệu suất tất cả nhân viên trong khoảng ngày được yêu cầu. */
    public function index(AgentPerformanceRequest $request): JsonResponse
    {
        [$startsAt, $endsAt, $shiftId] = $this->range($request);
        $agents = $this->performance->agentsFor($request->user());

        return response()->json(['data' => [
            'range' => $this->rangePayload($startsAt, $endsAt, $shiftId),
            'summary' => $this->performance->summary($agents, $startsAt, $endsAt, $shiftId),
            'agents' => $agents->map(fn (User $agent): array => $this->performance->forAgent($agent, $startsAt, $endsAt, $shiftId))->values(),
        ]]);
    }

    /** Trả metrics hiệu suất chi tiết của một nhân viên trong khoảng ngày. */
    public function show(AgentPerformanceRequest $request, User $agent): JsonResponse
    {
        abort_unless($request->user()->can('report.view') || $request->user()->can('user.manage') || $request->user()->is($agent), 403);
        [$startsAt, $endsAt, $shiftId] = $this->range($request);

        return response()->json(['data' => [
            'range' => $this->rangePayload($startsAt, $endsAt, $shiftId),
            'agent' => $this->performance->forAgent($agent, $startsAt, $endsAt, $shiftId),
        ]]);
    }

    /** Chuẩn hóa start_date/end_date thành khoảng ngày dùng để tính hiệu suất. */
    private function range(AgentPerformanceRequest $request): array
    {
        $data = $request->validated();
        return [
            filled($data['date_from'] ?? null) ? Carbon::parse($data['date_from'])->startOfDay() : now()->startOfDay(),
            filled($data['date_to'] ?? null) ? Carbon::parse($data['date_to'])->endOfDay() : now()->endOfDay(),
            filled($data['work_shift_id'] ?? null) ? (int) $data['work_shift_id'] : null,
        ];
    }

    /** Chuyển khoảng ngày thành chuỗi và nhãn hiển thị trong response mobile. */
    private function rangePayload(Carbon $startsAt, Carbon $endsAt, ?int $shiftId): array
    {
        return ['starts_at' => $startsAt->toISOString(), 'ends_at' => $endsAt->toISOString(), 'work_shift_id' => $shiftId];
    }
}
