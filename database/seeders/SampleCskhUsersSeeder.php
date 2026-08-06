<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Modules\Conversation\Models\WorkShift;

class SampleCskhUsersSeeder extends Seeder
{
    /** Tạo các tài khoản nhân viên chăm sóc khách hàng mẫu và gán vai trò CSKH. */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        $agents = collect([
            ['name' => 'Nam Nguyen', 'email' => 'nam@cskh.local'],
            ['name' => 'Huy Tran', 'email' => 'huy@cskh.local'],
            ['name' => 'Linh Pham', 'email' => 'linh@cskh.local'],
            ['name' => 'An Le', 'email' => 'an@cskh.local'],
            ['name' => 'Mai Hoang', 'email' => 'mai@cskh.local'],
            ['name' => 'Quan Do', 'email' => 'quan@cskh.local'],
            ['name' => 'Trang Bui', 'email' => 'trang@cskh.local'],
            ['name' => 'Khoa Vo', 'email' => 'khoa@cskh.local'],
        ])->map(function (array $agent): User {
            $user = User::query()->updateOrCreate(
                ['email' => $agent['email']],
                [
                    'name' => $agent['name'],
                    'password' => '12345678',
                    'is_active' => true,
                ],
            );
            $user->syncRoles(['CSKH']);

            return $user;
        })->values();

        $today = Carbon::today();
        $shiftDefinitions = [
            ['08:00', '09:00', [0, 1]],
            ['09:00', '10:00', [2, 3]],
            ['10:00', '11:00', [0, 2]],
            ['11:00', '12:00', [4, 5]],
            ['13:00', '14:00', [6, 7]],
            ['14:00', '15:00', [1, 3]],
            ['15:00', '16:00', [4, 6]],
            ['16:00', '17:00', [5, 7]],
        ];

        foreach ($shiftDefinitions as [$start, $end, $agentIndexes]) {
            $startsAt = $today->copy()->setTimeFromTimeString($start);
            $endsAt = $today->copy()->setTimeFromTimeString($end);
            $name = 'Ca '.$startsAt->format('H:i').'-'.$endsAt->format('H:i');

            $shift = WorkShift::query()->updateOrCreate(
                ['starts_at' => $startsAt, 'ends_at' => $endsAt],
                ['name' => $name, 'is_active' => true],
            );

            $shift->agents()->sync([
                $agents[$agentIndexes[0]]->id,
                $agents[$agentIndexes[1]]->id,
            ]);
        }
    }
}
