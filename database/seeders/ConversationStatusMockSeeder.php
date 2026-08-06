<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Customer\Models\Customer;
use Modules\Customer\Models\CustomerChannel;
use Modules\Facebook\Models\FacebookPage;
use Modules\Message\Models\Message;

class ConversationStatusMockSeeder extends Seeder
{
    /** Tạo hội thoại mẫu ở từng trạng thái để kiểm tra bộ lọc và dashboard trạng thái. */
    public function run(): void
    {
        $agent = User::query()->firstOrCreate(
            ['email' => 'admin@oldthread.store'],
            [
                'name' => 'Admin',
                'password' => Hash::make('password'),
                'is_active' => true,
            ],
        );

        $facebookPageId = FacebookPage::query()->value('page_id');

        $rows = [
            [
                'status' => ConversationStatus::CUSTOMER_WAITING,
                'name' => 'Mock Khách Đợi Rep',
                'phone' => '0901000001',
                'external_id' => 'mock_customer_waiting',
                'assigned_to' => $agent->id,
                'unread' => 3,
                'messages' => [
                    ['sender_type' => 'system', 'content' => 'Em gửi anh thông tin tham khảo trước ạ.', 'minutes_ago' => 18],
                    ['sender_type' => 'customer', 'content' => 'Anh muốn báo giá Vios và cần nhân viên gọi lại.', 'minutes_ago' => 3],
                ],
            ],
            [
                'status' => ConversationStatus::WAITING_CUSTOMER,
                'name' => 'Mock Đợi Khách Trả Lời',
                'phone' => '0901000002',
                'external_id' => 'mock_waiting_customer',
                'assigned_to' => $agent->id,
                'unread' => 0,
                'messages' => [
                    ['sender_type' => 'customer', 'content' => 'Anh muốn xem ưu đãi xe Raize.', 'minutes_ago' => 25],
                    ['sender_type' => 'user', 'sender_id' => $agent->id, 'content' => 'Dạ em đã gửi ưu đãi, anh cần em tính thêm trả góp không ạ?', 'minutes_ago' => 8],
                ],
            ],
            [
                'status' => ConversationStatus::BOT_CONSULTING,
                'name' => 'Mock Bot Đang Tư Vấn',
                'phone' => '0901000003',
                'external_id' => 'mock_bot_consulting',
                'assigned_to' => null,
                'unread' => 0,
                'messages' => [
                    ['sender_type' => 'customer', 'content' => 'Em tư vấn giúp anh xe gia đình.', 'minutes_ago' => 14],
                    ['sender_type' => 'system', 'content' => 'Dạ, anh thường đi mấy người và ưu tiên xe 5 chỗ hay 7 chỗ ạ?', 'minutes_ago' => 11],
                ],
            ],
            [
                'status' => ConversationStatus::CLOSED,
                'name' => 'Mock Đã Đóng',
                'phone' => '0901000004',
                'external_id' => 'mock_closed',
                'assigned_to' => $agent->id,
                'unread' => 0,
                'messages' => [
                    ['sender_type' => 'customer', 'content' => 'Anh đã đặt lịch lái thử rồi.', 'minutes_ago' => 60],
                    ['sender_type' => 'user', 'sender_id' => $agent->id, 'content' => 'Dạ em đã ghi nhận lịch, cảm ơn anh ạ.', 'minutes_ago' => 55],
                ],
            ],
        ];

        foreach ($rows as $index => $row) {
            $customer = Customer::query()->updateOrCreate(
                ['phone' => $row['phone']],
                [
                    'name' => $row['name'],
                    'email' => null,
                    'avatar' => null,
                ],
            );

            CustomerChannel::query()->updateOrCreate(
                ['channel' => 'facebook', 'external_id' => $row['external_id']],
                [
                    'customer_id' => $customer->id,
                    'metadata' => [
                        'mock' => true,
                        'facebook_page_id' => $facebookPageId,
                    ],
                ],
            );

            $lastMessageAt = now()->subMinutes((int) $row['messages'][array_key_last($row['messages'])]['minutes_ago']);

            $conversation = Conversation::query()->updateOrCreate(
                ['external_conversation_id' => 'mock_status_'.$row['status']],
                [
                    'customer_id' => $customer->id,
                    'facebook_page_id' => $facebookPageId,
                    'assigned_to' => $row['assigned_to'],
                    'assigned_by' => $row['assigned_to'],
                    'assigned_type' => $row['assigned_to'] ? 'manual' : null,
                    'claimed_at' => $row['assigned_to'] ? now()->subMinutes(30 - $index) : null,
                    'status' => $row['status'],
                    'last_message_at' => $lastMessageAt,
                    'last_read_at' => $row['unread'] > 0 ? now()->subMinutes(20) : $lastMessageAt,
                    'unread_messages_count' => $row['unread'],
                    'resolved_at' => $row['status'] === ConversationStatus::CLOSED ? $lastMessageAt : null,
                    'closed_at' => $row['status'] === ConversationStatus::CLOSED ? $lastMessageAt : null,
                    'first_response_at' => now()->subMinutes(45 - $index),
                ],
            );

            Message::withTrashed()
                ->where('conversation_id', $conversation->id)
                ->forceDelete();

            foreach ($row['messages'] as $message) {
                Message::query()->create([
                    'conversation_id' => $conversation->id,
                    'sender_type' => $message['sender_type'],
                    'sender_id' => $message['sender_id'] ?? ($message['sender_type'] === 'customer' ? $customer->id : null),
                    'channel' => $message['sender_type'] === 'system' ? 'facebook' : 'facebook',
                    'content' => $message['content'],
                    'message_type' => 'text',
                    'attachments' => [],
                    'external_message_id' => 'mock_'.$row['status'].'_'.$message['minutes_ago'],
                    'outbound_status' => in_array($message['sender_type'], ['system', 'user'], true) ? 'sent' : null,
                    'sent_at' => in_array($message['sender_type'], ['system', 'user'], true) ? now()->subMinutes((int) $message['minutes_ago']) : null,
                    'read_at' => $message['sender_type'] === 'customer' && $row['unread'] === 0 ? now()->subMinutes((int) $message['minutes_ago'] - 1) : null,
                    'created_at' => now()->subMinutes((int) $message['minutes_ago']),
                    'updated_at' => now()->subMinutes((int) $message['minutes_ago']),
                ]);
            }
        }
    }
}
