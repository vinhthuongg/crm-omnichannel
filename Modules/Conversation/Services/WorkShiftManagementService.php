<?php

namespace Modules\Conversation\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Conversation\Models\WorkShift;

class WorkShiftManagementService
{
    /** Tạo ca trực, kiểm tra nhân viên không trùng lịch và đồng bộ danh sách thành viên. */
    public function create(array $data, bool $isActive = true): WorkShift
    {
        $this->ensureAgentsAvailable($data['agent_ids']);

        $shift = WorkShift::query()->create($this->attributes($data, $isActive));
        $shift->agents()->sync($data['agent_ids']);

        return $shift->load('agents');
    }

    /** Cập nhật giờ, tên, trạng thái và thành viên ca sau khi kiểm tra xung đột lịch. */
    public function update(WorkShift $shift, array $data, bool $isActive): WorkShift
    {
        $this->ensureAgentsAvailable($data['agent_ids'], $shift);

        $shift->update($this->attributes($data, $isActive));
        $shift->agents()->sync($data['agent_ids']);

        return $shift->fresh('agents');
    }

    /** Gỡ các thành viên rồi xóa ca trực không còn được sử dụng. */
    public function delete(WorkShift $shift): void
    {
        $shift->delete();
    }

    /** Chuẩn hóa các thuộc tính dùng để tạo hoặc cập nhật tài nguyên. */
    private function attributes(array $data, bool $isActive): array
    {
        $startsAt = $this->timeOnSystemDate($data['starts_time']);
        $endsAt = $this->timeOnSystemDate($data['ends_time']);

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            $endsAt->addDay();
        }

        return [
            'name' => $data['name'] ?? null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'is_active' => $isActive,
        ];
    }

    /** Ghép giờ ca trực với ngày hệ thống để so sánh chính xác. */
    private function timeOnSystemDate(string $time): Carbon
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return now()->startOfDay()->setTime($hour, $minute);
    }

    /** Báo lỗi nếu một nhân viên đã thuộc ca khác có khung giờ chồng lấn. */
    private function ensureAgentsAvailable(array $agentIds, ?WorkShift $currentShift = null): void
    {
        $busyShift = WorkShift::query()
            ->where('is_active', true)
            ->when($currentShift, fn (Builder $query) => $query->where('id', '!=', $currentShift->id))
            ->whereHas('agents', fn (Builder $query) => $query->whereIn('users.id', $agentIds))
            ->exists();

        if ($busyShift) {
            throw ValidationException::withMessages([
                'agent_ids' => 'Nhân viên đã nằm trong ca trực đang bật. Vui lòng chọn nhân viên khác.',
            ]);
        }
    }
}
