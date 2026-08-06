<?php

namespace Modules\Dashboard\Services;

use App\Models\User;

class AgentPerformanceFormatter
{
    /** Định dạng một nhân viên cùng số hội thoại, phản hồi, rating và tỷ lệ hiệu suất. */
    public function agent(User $user): array
    {
        return ['id' => (int) $user->id, 'name' => $user->name, 'email' => $user->email,
            'is_active' => (bool) $user->is_active, 'roles' => $user->getRoleNames()->values()];
    }

    /** Tính tỷ lệ phần trăm của một giá trị trên tổng, trả 0 khi tổng bằng 0. */
    public function percentage(int $value, int $total): float { return $total > 0 ? round(($value / $total) * 100, 2) : 0.0; }

    /** Định dạng số giây thành chuỗi giây, phút hoặc giờ dễ đọc. */
    public function duration(?int $seconds): string
    {
        if ($seconds === null) return 'Chưa có';
        if ($seconds < 60) return $seconds.' giây';
        $minutes = intdiv($seconds, 60); $remaining = $seconds % 60;
        if ($minutes < 60) return $remaining > 0 ? $minutes.' phút '.$remaining.' giây' : $minutes.' phút';
        $hours = intdiv($minutes, 60); $minutes %= 60;
        return $minutes > 0 ? $hours.' giờ '.$minutes.' phút' : $hours.' giờ';
    }
}
