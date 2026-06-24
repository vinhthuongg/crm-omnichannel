<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'conversation.view_all',
            'conversation.view_assigned',
            'conversation.reply',
            'conversation.assign',
            'conversation.transfer',
            'conversation.close',
            'conversation.tag',
            'user.manage',
            'report.view',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::findOrCreate('Admin', 'web')->syncPermissions($permissions);
        $cskhPermissions = [
            'conversation.view_assigned',
            'conversation.reply',
            'conversation.close',
            'conversation.tag',
        ];

        Role::findOrCreate('CSKH', 'web')->syncPermissions($cskhPermissions);
        Role::findOrCreate('User', 'web')->syncPermissions($cskhPermissions);
    }
}
