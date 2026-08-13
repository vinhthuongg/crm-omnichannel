<?php

namespace Tests\Feature;

use App\Models\ApplicationSetting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Modules\Facebook\Services\FacebookFirstContactMenuSettings;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FacebookFirstContactMenuAdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** Xác nhận Admin nhìn thấy biểu mẫu và lưu được cấu hình menu vào database. */
    public function test_admin_can_view_and_update_first_contact_menu(): void
    {
        $admin = $this->user('admin-menu@example.test', 'Admin');
        $payload = $this->payload();

        $this->actingAs($admin)->get(route('crm.settings'))
            ->assertOk()
            ->assertSee('Menu Messenger đầu tiên');

        $this->actingAs($admin)
            ->patch(route('crm.settings.facebook-first-contact-menu.update'), $payload)
            ->assertRedirect()
            ->assertSessionHas('settings_status');

        $stored = ApplicationSetting::query()->findOrFail('facebook.first_contact_menu')->value;
        $this->assertTrue($stored['enabled']);
        $this->assertSame('Menu chào tùy chỉnh', $stored['text']);
        $this->assertTrue($stored['phone_enabled']);
        $this->assertSame('Vui lòng chia sẻ số điện thoại.', $stored['phone_text']);
        $this->assertSame('Gửi số điện thoại', $stored['phone_button_title']);
        $this->assertSame('Khách muốn gửi số điện thoại.', $stored['phone_payload']);
        $this->assertSame('Tư vấn Vios', $stored['elements'][0]['buttons'][0]['title']);
        $this->assertSame($stored, app(FacebookFirstContactMenuSettings::class)->get());
    }

    /** Xác nhận CSKH không thấy biểu mẫu và bị từ chối kể cả tự gọi endpoint cập nhật. */
    public function test_non_admin_cannot_view_or_update_first_contact_menu(): void
    {
        $agent = $this->user('agent-menu@example.test', 'CSKH');

        $this->actingAs($agent)->get(route('crm.settings'))
            ->assertOk()
            ->assertDontSee('Menu Messenger đầu tiên');

        $this->actingAs($agent)
            ->patch(route('crm.settings.facebook-first-contact-menu.update'), $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('application_settings', 0);
    }

    private function payload(): array
    {
        return [
            'enabled' => '1',
            'text' => 'Menu chào tùy chỉnh',
            'phone_enabled' => '1',
            'phone_text' => 'Vui lòng chia sẻ số điện thoại.',
            'phone_button_title' => 'Gửi số điện thoại',
            'phone_payload' => 'Khách muốn gửi số điện thoại.',
            'elements' => [[
                'title' => 'Các mẫu xe Toyota',
                'subtitle' => 'Chọn nhu cầu',
                'image_url' => 'https://cdn.example.test/toyota.jpg',
                'buttons' => [[
                    'title' => 'Tư vấn Vios',
                    'payload' => 'Khách muốn được tư vấn Toyota Vios.',
                ]],
            ]],
        ];
    }

    private function user(string $email, string $role): User
    {
        $user = User::query()->create([
            'name' => $role.' Menu Test',
            'email' => $email,
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
