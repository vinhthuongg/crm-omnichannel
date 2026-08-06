<?php

namespace App\Services;

use App\Models\User;

class CrmNavigationService
{
    /** Tạo cấu trúc dữ liệu phù hợp với quyền của người dùng. */
    public function forUser(User $user): array
    {
        if (! $user->can('user.manage')) {
            return [
                ['section' => 'conversations', 'label' => 'Hội thoại', 'route' => 'crm.conversations', 'icon' => 'forum'],
                ['section' => 'notifications', 'label' => 'Thông báo', 'route' => 'crm.notifications', 'icon' => 'notifications'],
                ['section' => 'settings', 'label' => 'Cài đặt', 'route' => 'crm.settings', 'icon' => 'settings'],
            ];
        }

        return [
            ['section' => 'dashboard', 'label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'dashboard'],
            ['section' => 'conversations', 'label' => 'Conversations', 'route' => 'crm.conversations', 'icon' => 'forum'],
            ['section' => 'customers', 'label' => 'Customers', 'route' => 'crm.customers', 'icon' => 'contacts'],
            ['section' => 'channels', 'label' => 'Channels', 'route' => 'crm.channels', 'icon' => 'hub'],
            ['section' => 'work_shifts', 'label' => 'Shifts', 'route' => 'work-shifts.index', 'icon' => 'schedule'],
            ['section' => 'activity', 'label' => 'Activity Log', 'route' => 'crm.activity', 'icon' => 'history'],
            ['section' => 'notifications', 'label' => 'Notifications', 'route' => 'crm.notifications', 'icon' => 'notifications'],
            ['section' => 'settings', 'label' => 'Settings', 'route' => 'crm.settings', 'icon' => 'settings'],
        ];
    }
}
