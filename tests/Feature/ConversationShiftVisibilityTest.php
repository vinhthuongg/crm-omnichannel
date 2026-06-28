<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Models\WorkShift;
use Modules\Conversation\Services\ConversationService;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Customer\Models\Customer;
use Modules\Facebook\Models\FacebookPage;
use Modules\Message\Models\Message;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ConversationShiftVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RolesAndPermissionsSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-06-28 09:55:00'));

        $this->admin = $this->user('Admin', 'admin@shift.test', 'Admin');
        FacebookPage::query()->create([
            'user_id' => $this->admin->id,
            'page_id' => 'page-test',
            'page_name' => 'Test Page',
            'page_access_token' => 'token',
            'token_status' => 'valid',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_only_agents_in_current_shift_see_waiting_messages(): void
    {
        [$agentA, $agentB, $outsideAgent] = [
            $this->user('Agent A', 'agent-a@shift.test'),
            $this->user('Agent B', 'agent-b@shift.test'),
            $this->user('Outside Agent', 'outside@shift.test'),
        ];

        $shift = $this->shift('Ca 09:00-10:00', '09:00', '10:00', [$agentA, $agentB]);
        $this->shift('Ca 10:00-11:00', '10:00', '11:00', [$outsideAgent]);
        $conversation = $this->waitingConversation($shift, 'Tin nhan cho ca hien tai');

        $this->assertConversationVisibleTo($conversation, $agentA);
        $this->assertConversationVisibleTo($conversation, $agentB);
        $this->assertConversationHiddenFrom($conversation, $outsideAgent);
    }

    public function test_assigned_conversation_is_visible_only_to_assignee_and_admin(): void
    {
        [$agentA, $agentB, $outsideAgent] = [
            $this->user('Agent A', 'assign-a@shift.test'),
            $this->user('Agent B', 'assign-b@shift.test'),
            $this->user('Outside Agent', 'assign-outside@shift.test'),
        ];

        $shift = $this->shift('Ca 09:00-10:00', '09:00', '10:00', [$agentA, $agentB]);
        $conversation = $this->waitingConversation($shift, 'Tin nhan sau khi phan cong');

        app(ConversationService::class)->assign($conversation, $agentA->id, $this->admin);
        $conversation->refresh();

        $this->assertConversationVisibleTo($conversation, $agentA);
        $this->assertConversationHiddenFrom($conversation, $agentB);
        $this->assertConversationHiddenFrom($conversation, $outsideAgent);
        $this->assertConversationVisibleTo($conversation, $this->admin);
    }

    public function test_shift_boundary_routes_new_waiting_messages_to_next_shift(): void
    {
        [$beforeAgent, $afterAgent] = [
            $this->user('Before Agent', 'before@shift.test'),
            $this->user('After Agent', 'after@shift.test'),
        ];

        $beforeShift = $this->shift('Ca 09:00-10:00', '09:00', '10:00', [$beforeAgent]);
        $afterShift = $this->shift('Ca 10:00-11:00', '10:00', '11:00', [$afterAgent]);

        $beforeConversation = $this->waitingConversation($beforeShift, 'Tin luc 09:55');
        $this->assertConversationVisibleTo($beforeConversation, $beforeAgent);
        $this->assertConversationHiddenFrom($beforeConversation, $afterAgent);

        Carbon::setTestNow(Carbon::parse('2026-06-28 10:00:01'));
        $afterConversation = $this->waitingConversation($afterShift, 'Tin luc 10:00:01');

        $this->assertConversationHiddenFrom($afterConversation, $beforeAgent);
        $this->assertConversationVisibleTo($afterConversation, $afterAgent);
    }

    private function user(string $name, string $email, string $role = 'CSKH'): User
    {
        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function shift(string $name, string $start, string $end, array $agents): WorkShift
    {
        $date = Carbon::parse('2026-06-28');
        $shift = WorkShift::query()->create([
            'name' => $name,
            'starts_at' => $date->copy()->setTimeFromTimeString($start),
            'ends_at' => $date->copy()->setTimeFromTimeString($end),
            'is_active' => true,
        ]);
        $shift->agents()->sync(collect($agents)->pluck('id')->all());

        return $shift;
    }

    private function waitingConversation(WorkShift $shift, string $content): Conversation
    {
        $customer = Customer::query()->create([
            'name' => 'Khach '.$content,
        ]);
        $conversation = Conversation::query()->create([
            'customer_id' => $customer->id,
            'status' => ConversationStatus::WAITING,
            'last_message_at' => now(),
            'owner_shift_id' => $shift->id,
            'queue_shift_id' => $shift->id,
            'work_shift_id' => $shift->id,
            'unread_messages_count' => 1,
        ]);
        Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'customer',
            'sender_id' => $customer->id,
            'channel' => 'facebook',
            'content' => $content,
            'message_type' => 'text',
            'external_message_id' => 'msg-'.Str::uuid(),
        ]);

        return $conversation;
    }

    private function assertConversationVisibleTo(Conversation $conversation, User $user): void
    {
        $this->actingAs($user)
            ->get(route('crm.conversations'))
            ->assertOk()
            ->assertSee((string) $conversation->customer->name)
            ->assertSee((string) $conversation->messages()->latest()->value('content'));
    }

    private function assertConversationHiddenFrom(Conversation $conversation, User $user): void
    {
        $this->actingAs($user)
            ->get(route('crm.conversations'))
            ->assertOk()
            ->assertDontSee((string) $conversation->customer->name)
            ->assertDontSee((string) $conversation->messages()->latest()->value('content'));
    }
}
