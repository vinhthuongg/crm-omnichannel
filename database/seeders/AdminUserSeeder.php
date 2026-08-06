<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /** Tạo tài khoản quản trị mặc định và gán vai trò Admin để đăng nhập quản lý hệ thống. */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        $user = User::query()->updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin',
                'password' => '12345678',
                'is_active' => true,
            ],
        );

        $user->syncRoles(['Admin']);
    }
}
