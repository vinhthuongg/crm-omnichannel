<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Modules\ActivityLog\Models\ActivityLog;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Models\Tag;
use Modules\Conversation\Support\AssignmentType;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Customer\Models\Customer;
use Modules\Customer\Models\CustomerChannel;
use Modules\Message\Models\Message;

class DemoCrmDashboardSeeder extends Seeder
{
    /** Tạo khách hàng, hội thoại, tin nhắn và hoạt động mẫu để hiển thị dashboard CRM. */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        ActivityLog::query()->where('action', 'like', 'demo.%')->delete();
        User::query()->where('email', 'like', '%@demo.crm')->delete();
        Customer::query()->where('email', 'like', '%@demo.crm')->delete();

        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'John d.', 'password' => '12345678', 'is_active' => true]
        );
        $admin->assignRole('Admin');

        $agents = collect([
            ['name' => 'Maya Nguyen', 'email' => 'maya@demo.crm'],
            ['name' => 'Long Tran', 'email' => 'long@demo.crm'],
            ['name' => 'An Pham', 'email' => 'an@demo.crm'],
            ['name' => 'Linh Do', 'email' => 'linh@demo.crm'],
        ])->map(function (array $agent): User {
            $user = User::query()->create([
                'name' => $agent['name'],
                'email' => $agent['email'],
                'password' => '12345678',
                'is_active' => true,
            ]);
            $user->assignRole('CSKH');

            return $user;
        });

        $tags = collect([
            'Marketing Teams' => '#000000',
            'Design Teams' => '#767676',
            'Production Teams' => '#cfcfcf',
            'Priority Lead' => '#404040',
        ])->mapWithKeys(fn (string $color, string $name): array => [
            $name => Tag::query()->firstOrCreate(['name' => $name], ['color' => $color]),
        ]);

        $customers = collect([
            ['name' => 'Product Hunt', 'phone' => '0901000001', 'email' => 'product-hunt@demo.crm', 'channel' => 'facebook', 'external' => 'fb_product_hunt'],
            ['name' => 'Acme Motors', 'phone' => '0901000002', 'email' => 'acme-motors@demo.crm', 'channel' => 'zalo', 'external' => 'zalo_acme_motors'],
            ['name' => 'Northstar Auto', 'phone' => '0901000003', 'email' => 'northstar@demo.crm', 'channel' => 'facebook', 'external' => 'fb_northstar_auto'],
            ['name' => 'Bluebird Studio', 'phone' => '0901000004', 'email' => 'bluebird@demo.crm', 'channel' => 'zalo', 'external' => 'zalo_bluebird'],
            ['name' => 'Vios Club', 'phone' => '0901000005', 'email' => 'vios-club@demo.crm', 'channel' => 'facebook', 'external' => 'fb_vios_club'],
            ['name' => 'Sunrise Logistics', 'phone' => '0901000006', 'email' => 'sunrise@demo.crm', 'channel' => 'zalo', 'external' => 'zalo_sunrise'],
            ['name' => 'Orbit Media', 'phone' => '0901000007', 'email' => 'orbit@demo.crm', 'channel' => 'facebook', 'external' => 'fb_orbit_media'],
            ['name' => 'Zalo Retail', 'phone' => '0901000008', 'email' => 'zalo-retail@demo.crm', 'channel' => 'zalo', 'external' => 'zalo_retail'],
        ])->map(function (array $item): Customer {
            $customer = Customer::query()->create([
                'name' => $item['name'],
                'phone' => $item['phone'],
                'email' => $item['email'],
                'avatar' => null,
            ]);

            CustomerChannel::query()->create([
                'customer_id' => $customer->id,
                'channel' => $item['channel'],
                'external_id' => $item['external'],
                'metadata' => ['source' => 'dashboard-demo'],
            ]);

            return $customer;
        });

        $today = Carbon::today();
        $contents = [
            'I need pricing for Vios this week.',
            'Please send warranty details.',
            'Can your team confirm delivery time?',
            'We need help with the current order.',
            'Please assign a consultant for our branch.',
            'I want to compare Facebook and Zalo leads.',
            'Can you share the promotion package?',
            'Please close this request after confirmation.',
        ];

        for ($dayOffset = 0; $dayOffset < 28; $dayOffset++) {
            $createdAt = $today->copy()->subDays($dayOffset)->setTime(9 + ($dayOffset % 8), 15);
            $customer = $customers[$dayOffset % $customers->count()];
            $agent = $agents[$dayOffset % $agents->count()];
            $channel = $dayOffset % 2 === 0 ? 'facebook' : 'zalo';
            $status = [
                ConversationStatus::IN_PROGRESS,
                ConversationStatus::WAITING,
                ConversationStatus::CLOSED,
                ConversationStatus::CLOSED,
                ConversationStatus::IN_PROGRESS,
                ConversationStatus::WAITING,
            ][$dayOffset % 6];
            $lastMessageAt = $createdAt->copy()->addMinutes(24 + ($dayOffset * 3) % 85);

            $conversation = Conversation::query()->create([
                'customer_id' => $customer->id,
                'assigned_to' => $agent->id,
                'assigned_by' => $admin->id,
                'assigned_type' => AssignmentType::MANUAL,
                'claimed_at' => $createdAt,
                'status' => $status,
                'last_message_at' => $lastMessageAt,
                'resolved_at' => $status === ConversationStatus::CLOSED ? $lastMessageAt->copy()->addMinutes(12) : null,
                'first_response_at' => $lastMessageAt,
                'closed_at' => $status === ConversationStatus::CLOSED ? $lastMessageAt->copy()->addMinutes(18) : null,
                'created_at' => $createdAt,
                'updated_at' => $lastMessageAt,
            ]);

            $conversation->tags()->sync([
                $tags->values()[$dayOffset % $tags->count()]->id,
                $tags->values()[($dayOffset + 1) % $tags->count()]->id,
            ]);

            Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender_type' => 'customer',
                'sender_id' => $customer->id,
                'channel' => $channel,
                'content' => $contents[$dayOffset % count($contents)],
                'message_type' => 'text',
                'attachments' => [],
                'external_message_id' => "demo-{$channel}-{$dayOffset}-customer",
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender_type' => 'user',
                'sender_id' => $agent->id,
                'channel' => $channel,
                'content' => 'Thanks, I will send the details and keep this conversation updated.',
                'message_type' => 'text',
                'attachments' => [],
                'external_message_id' => "demo-{$channel}-{$dayOffset}-agent",
                'created_at' => $lastMessageAt,
                'updated_at' => $lastMessageAt,
            ]);

            if ($dayOffset % 3 === 0) {
                Message::query()->create([
                    'conversation_id' => $conversation->id,
                    'sender_type' => 'user',
                    'sender_id' => $admin->id,
                    'channel' => $channel,
                    'content' => 'I can support if you need an additional confirmation.',
                    'message_type' => 'text',
                    'attachments' => [],
                    'external_message_id' => "demo-{$channel}-{$dayOffset}-admin",
                    'created_at' => $lastMessageAt->copy()->addMinutes(12),
                    'updated_at' => $lastMessageAt->copy()->addMinutes(12),
                ]);
            }

            ActivityLog::query()->create([
                'user_id' => $agent->id,
                'action' => $status === ConversationStatus::CLOSED ? 'demo.conversation.closed' : 'demo.message.sent',
                'subject_type' => Conversation::class,
                'subject_id' => $conversation->id,
                'metadata' => ['channel' => $channel, 'status' => $status],
                'created_at' => $lastMessageAt,
                'updated_at' => $lastMessageAt,
            ]);
        }

        foreach (range(0, 4) as $index) {
            $year = (int) $today->copy()->subYears(4 - $index)->format('Y');
            $customer = $customers[$index % $customers->count()];
            $agent = $agents[$index % $agents->count()];
            $createdAt = Carbon::create($year, 6, 12, 10, 0);

            $conversation = Conversation::query()->create([
                'customer_id' => $customer->id,
                'assigned_to' => $agent->id,
                'assigned_by' => $admin->id,
                'assigned_type' => AssignmentType::MANUAL,
                'claimed_at' => $createdAt,
                'status' => ConversationStatus::CLOSED,
                'last_message_at' => $createdAt->copy()->addMinutes(45),
                'resolved_at' => $createdAt->copy()->addMinutes(90),
                'first_response_at' => $createdAt->copy()->addMinutes(45),
                'closed_at' => $createdAt->copy()->addHours(2),
                'created_at' => $createdAt,
                'updated_at' => $createdAt->copy()->addHours(2),
            ]);

            Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender_type' => 'customer',
                'sender_id' => $customer->id,
                'channel' => 'facebook',
                'content' => 'Annual dashboard activity baseline.',
                'message_type' => 'text',
                'attachments' => [],
                'external_message_id' => "demo-year-{$year}",
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }
    }
}
