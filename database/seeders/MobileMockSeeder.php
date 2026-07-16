<?php

namespace Database\Seeders;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Models\ConversationReplySuggestion;
use Modules\Conversation\Models\ConversationActivity;
use Modules\Conversation\Models\Tag;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Customer\Models\Customer;
use Modules\Customer\Models\CustomerChannel;
use Modules\Customer\Models\CustomerNote;
use Modules\Customer\Models\CustomerTag;
use Modules\Message\Models\Message;
use Modules\Search\Services\VectorSearchService;
use Spatie\Permission\Models\Role;

class MobileMockSeeder extends Seeder
{
    public function run(): void
    {
        $agent = $this->ensureAgent();
        $page = DB::table('facebook_pages')->orderBy('id')->first();

        $this->clearConversationData();
        Tag::ensureDefaults();
        $customerTags = $this->ensureCustomerTags();

        $baseRows = $this->rows($agent, $page?->page_id, $page?->page_name);

        foreach ($baseRows as $index => $row) {
            $this->seedRow($row, $customerTags, $agent, $index);
        }

        app(VectorSearchService::class)->rebuildCustomers(Customer::query()->with(['channels', 'tags', 'notes', 'conversations.messages'])->get());
    }

    private function ensureAgent(): User
    {
        $role = Role::findOrCreate('Admin', 'web');

        $agent = User::query()->updateOrCreate(
            ['email' => 'admin@oldthread.store'],
            [
                'name' => 'Admin',
                'password' => 'password',
                'is_active' => true,
            ],
        );

        $agent->syncRoles([$role->name]);

        return $agent;
    }

    /**
     * @return array<string, CustomerTag>
     */
    private function ensureCustomerTags(): array
    {
        Tag::ensureDefaults();

        $tags = [];

        foreach (Tag::DEFAULTS as $name => $color) {
            $tags[$name] = CustomerTag::query()->updateOrCreate(
                ['name' => $name],
                ['color' => $color],
            );
        }

        return $tags;
    }

    private function clearConversationData(): void
    {
        $tables = [
            'botpress_conversation_links',
            'conversation_reply_suggestions',
            'conversation_user_access',
            'conversation_activities',
            'conversation_tag',
            'messages',
            'customer_customer_tag',
            'customer_notes',
            'customer_channels',
            'conversations',
            'customers',
            'customer_tags',
            'tags',
            'activity_logs',
            'notifications',
            'vector_search_documents',
        ];

        Schema::disableForeignKeyConstraints();

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->truncate();
            }
        }

        Schema::enableForeignKeyConstraints();
    }

    /**
     * @param array<string, CustomerTag> $customerTags
     */
    private function seedRow(array $row, array $customerTags, User $agent, int $index): void
    {
        $now = CarbonImmutable::now();
        $customer = Customer::query()->create([
            'name' => $row['customer_name'],
            'avatar' => $row['avatar'],
            'phone' => $row['phone'],
            'phone_collected_at' => $row['phone_collected_at'] ?? null,
            'email' => $row['email'],
            'is_potential' => $row['is_potential'],
            'potential_marked_at' => $row['potential_marked_at'],
            'potential_marked_by' => $row['is_potential'] ? $agent->id : null,
        ]);

        $customer->channels()->create([
            'channel' => $row['channel'],
            'external_id' => $row['external_id'],
            'metadata' => [
                'mock' => true,
                'channel' => $row['channel'],
                'facebook_page_id' => $row['facebook_page_id'],
                'source' => 'seed',
            ],
        ]);

        $customer->notes()->create([
            'user_id' => $row['note_by'] ? $agent->id : null,
            'body' => $row['note'] ?? '',
        ]);

        $customerTagIds = collect($row['customer_tags'] ?? [])
            ->map(fn (string $name): int => (int) ($customerTags[$name]->id ?? 0))
            ->filter()
            ->values()
            ->all();

        if ($customerTagIds !== []) {
            $customer->tags()->sync($customerTagIds);
        }

        $conversation = Conversation::query()->create([
            'customer_id' => $customer->id,
            'facebook_page_id' => $row['facebook_page_id'],
            'external_conversation_id' => $row['external_conversation_id'],
            'assigned_to' => $row['assigned_to'] ? $agent->id : null,
            'assigned_by' => $row['assigned_to'] ? $agent->id : null,
            'assigned_type' => $row['assigned_to'] ? 'manual' : null,
            'claimed_at' => $row['assigned_to'] ? $now->subMinutes(30 - $index * 3) : null,
            'owner_shift_id' => null,
            'queue_shift_id' => null,
            'status' => $row['status'],
            'last_message_at' => $now->subMinutes($row['last_message_minutes_ago']),
            'unread_messages_count' => $row['unread_count'],
            'automation_state' => $row['automation_state'] ?? null,
            'last_read_at' => $row['unread_count'] > 0 ? $now->subMinutes($row['last_message_minutes_ago'] + 2) : $now->subMinutes($row['last_message_minutes_ago'] - 1),
            'resolved_at' => $row['status'] === ConversationStatus::CLOSED ? $now->subMinutes($row['last_message_minutes_ago']) : null,
            'first_response_at' => $row['first_response_minutes_ago'] !== null ? $now->subMinutes($row['first_response_minutes_ago']) : null,
            'closed_at' => $row['status'] === ConversationStatus::CLOSED ? $now->subMinutes($row['last_message_minutes_ago']) : null,
        ]);

        $conversationTagIds = collect($row['conversation_tags'] ?? [])
            ->map(fn (string $name): int => (int) DB::table('tags')->where('name', $name)->value('id'))
            ->filter()
            ->values()
            ->all();

        if ($conversationTagIds !== []) {
            $conversation->tags()->sync($conversationTagIds);
        }

        $conversation->handledUsers()->syncWithoutDetaching([
            $agent->id => [
                'first_handled_at' => $conversation->claimed_at ?: $conversation->created_at,
            ],
        ]);

        $messages = collect($row['messages'])
            ->sortByDesc('minutes_ago')
            ->values();

        $lastMessage = null;
        $firstNonCustomerMessage = null;

        foreach ($messages as $messageRow) {
            $createdAt = $now->subMinutes((int) $messageRow['minutes_ago']);
            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender_type' => $messageRow['sender_type'],
                'sender_id' => $messageRow['sender_id'] ?? ($messageRow['sender_type'] === 'customer' ? $customer->id : ($messageRow['sender_type'] === 'user' ? $agent->id : null)),
                'channel' => $messageRow['channel'] ?? $row['channel'],
                'content' => $messageRow['content'],
                'message_type' => $messageRow['message_type'] ?? 'text',
                'attachments' => $messageRow['attachments'] ?? [],
                'external_message_id' => $messageRow['external_message_id'] ?? 'mock_'.$conversation->id.'_'.Str::slug((string) $messageRow['content']).'_'.$messageRow['minutes_ago'],
                'client_message_id' => $messageRow['client_message_id'] ?? null,
                'outbound_status' => $messageRow['outbound_status'] ?? null,
                'outbound_error' => null,
                'sent_at' => $messageRow['sender_type'] !== 'customer' ? $createdAt : null,
                'read_at' => $messageRow['read_at'] ?? null,
                'recalled_at' => null,
                'recalled_by_user_id' => null,
                'deleted_by_user_id' => null,
            ]);

            DB::table('messages')
                ->where('id', $message->id)
                ->update([
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);

            if ($lastMessage === null || (int) $messageRow['minutes_ago'] < (int) $lastMessage['minutes_ago']) {
                $lastMessage = $messageRow;
            }

            if ($firstNonCustomerMessage === null && $messageRow['sender_type'] !== 'customer') {
                $firstNonCustomerMessage = $messageRow;
            }
        }

        if ($lastMessage && ($row['status'] === ConversationStatus::CLOSED)) {
            ConversationActivity::query()->create([
                'conversation_id' => $conversation->id,
                'action' => 'status.changed',
                'old_value' => ['status' => ConversationStatus::WAITING_CUSTOMER],
                'new_value' => ['status' => ConversationStatus::CLOSED],
                'performed_by' => $agent->id,
            ]);
        }

        if ($lastMessage && ! empty($row['reply_suggestions'])) {
            $lastCustomerMessage = Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('sender_type', 'customer')
                ->latest('id')
                ->first();

            if ($lastCustomerMessage) {
                ConversationReplySuggestion::query()->create([
                    'conversation_id' => $conversation->id,
                    'message_id' => $lastCustomerMessage->id,
                    'provider' => 'groq',
                    'suggestions' => $row['reply_suggestions'],
                    'generated_at' => $now->subMinutes($row['last_message_minutes_ago'])->addSeconds(10),
                ]);
            }
        }

        DB::table('botpress_conversation_links')->updateOrInsert(
            ['conversation_id' => $conversation->id],
            [
                'botpress_user_id' => $row['botpress_user_id'],
                'botpress_user_key' => $row['botpress_user_key'],
                'botpress_conversation_id' => $row['botpress_conversation_id'],
                'last_botpress_message_id' => $row['botpress_last_message_id'],
                'last_payload' => json_encode([
                    'mock' => true,
                    'conversation_id' => $conversation->id,
                    'customer_id' => $customer->id,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    private function rows(User $agent, ?string $facebookPageId, ?string $facebookPageName): array
    {
        $facebookPageId ??= '950608971471401';
        $facebookPageName ??= 'Old Thread';

        return [
            [
                'customer_name' => 'Vinh Thuong Truong',
                'avatar' => 'https://i.pravatar.cc/240?img=12',
                'phone' => '0936778029',
                'phone_collected_at' => CarbonImmutable::now()->subDays(1),
                'email' => 'vinh.thuong@example.com',
                'is_potential' => true,
                'potential_marked_at' => CarbonImmutable::now()->subHours(12),
                'channel' => 'facebook',
                'external_id' => 'mock_fb_1001',
                'facebook_page_id' => $facebookPageId,
                'external_conversation_id' => 'mock_conv_1001',
                'botpress_user_id' => 'mock_bp_user_1001',
                'botpress_user_key' => 'mock_bp_key_1001',
                'botpress_conversation_id' => 'mock_bp_conv_1001',
                'botpress_last_message_id' => 'mock_bp_msg_1001',
                'status' => ConversationStatus::CUSTOMER_WAITING,
                'assigned_to' => true,
                'unread_count' => 2,
                'last_message_minutes_ago' => 4,
                'first_response_minutes_ago' => 18,
                'customer_tags' => [Tag::DEFAULT_QUOTE, Tag::DEFAULT_INSTALLMENT],
                'conversation_tags' => [Tag::DEFAULT_QUOTE, Tag::DEFAULT_INSTALLMENT],
                'note' => 'Khach muon tham khao Vios va can tra gop.',
                'note_by' => true,
                'messages' => [
                    [
                        'sender_type' => 'customer',
                        'content' => 'Vios giá bao nhiêu?',
                        'minutes_ago' => 19,
                        'external_message_id' => 'mock_cust_1001_a',
                    ],
                    [
                        'sender_type' => 'system',
                        'content' => 'Anh/chị dự định đăng ký xe tại tỉnh/thành phố nào để em tính giá lăn bánh chính xác nhất cho từng phiên bản nhé?',
                        'minutes_ago' => 17,
                        'outbound_status' => 'sent',
                        'external_message_id' => 'mock_bot_1001_a',
                    ],
                    [
                        'sender_type' => 'customer',
                        'content' => 'Đăng ký Kiên Giang.',
                        'minutes_ago' => 4,
                        'external_message_id' => 'mock_cust_1001_b',
                    ],
                ],
                'reply_suggestions' => [
                    'Giá lăn bánh Vios ở Kiên Giang?',
                    'Tư vấn trả góp Vios',
                    'Hỗ trợ báo giá chi tiết',
                ],
            ],
            [
                'customer_name' => 'Lê Thị Mai',
                'avatar' => 'https://i.pravatar.cc/240?img=32',
                'phone' => '0901234567',
                'phone_collected_at' => CarbonImmutable::now()->subHours(3),
                'email' => 'mai.le@example.com',
                'is_potential' => false,
                'potential_marked_at' => null,
                'channel' => 'facebook',
                'external_id' => 'mock_fb_1002',
                'facebook_page_id' => $facebookPageId,
                'external_conversation_id' => 'mock_conv_1002',
                'botpress_user_id' => 'mock_bp_user_1002',
                'botpress_user_key' => 'mock_bp_key_1002',
                'botpress_conversation_id' => 'mock_bp_conv_1002',
                'botpress_last_message_id' => 'mock_bp_msg_1002',
                'status' => ConversationStatus::WAITING_CUSTOMER,
                'assigned_to' => true,
                'unread_count' => 0,
                'last_message_minutes_ago' => 9,
                'first_response_minutes_ago' => 27,
                'customer_tags' => [Tag::DEFAULT_TEST_DRIVE, Tag::DEFAULT_PHONE],
                'conversation_tags' => [Tag::DEFAULT_TEST_DRIVE],
                'note' => 'Khach muon lai thu Yaris Cross trong tuan nay.',
                'note_by' => true,
                'messages' => [
                    [
                        'sender_type' => 'customer',
                        'content' => 'Em muốn đặt lịch lái thử Yaris Cross.',
                        'minutes_ago' => 28,
                        'external_message_id' => 'mock_cust_1002_a',
                    ],
                    [
                        'sender_type' => 'user',
                        'sender_id' => $agent->id,
                        'content' => 'Dạ em đã ghi nhận lịch lái thử, anh/chị cho em xin ngày mong muốn ạ.',
                        'minutes_ago' => 20,
                        'outbound_status' => 'sent',
                        'external_message_id' => 'mock_user_1002_a',
                    ],
                ],
                'reply_suggestions' => [
                    'Chốt lịch lái thử',
                    'Cần em gọi xác nhận không?',
                    'Gửi địa chỉ showroom',
                ],
            ],
            [
                'customer_name' => 'Nguyễn Văn An',
                'avatar' => 'https://i.pravatar.cc/240?img=15',
                'phone' => null,
                'phone_collected_at' => null,
                'email' => null,
                'is_potential' => false,
                'potential_marked_at' => null,
                'channel' => 'facebook',
                'external_id' => 'mock_fb_1003',
                'facebook_page_id' => $facebookPageId,
                'external_conversation_id' => 'mock_conv_1003',
                'botpress_user_id' => 'mock_bp_user_1003',
                'botpress_user_key' => 'mock_bp_key_1003',
                'botpress_conversation_id' => 'mock_bp_conv_1003',
                'botpress_last_message_id' => 'mock_bp_msg_1003',
                'status' => ConversationStatus::BOT_CONSULTING,
                'assigned_to' => false,
                'unread_count' => 0,
                'last_message_minutes_ago' => 6,
                'first_response_minutes_ago' => null,
                'customer_tags' => [Tag::DEFAULT_CONSULTING],
                'conversation_tags' => [Tag::DEFAULT_CONSULTING],
                'note' => 'Bot dang tu van dong xe gia dinh 7 cho.',
                'note_by' => false,
                'messages' => [
                    [
                        'sender_type' => 'customer',
                        'content' => 'Anh cần xe cho gia đình 7 chỗ.',
                        'minutes_ago' => 24,
                        'external_message_id' => 'mock_cust_1003_a',
                    ],
                    [
                        'sender_type' => 'system',
                        'content' => 'Anh tham khảo Veloz Cross hoặc Avanza Premio nhé.',
                        'minutes_ago' => 19,
                        'message_type' => 'attachment',
                        'attachments' => [
                            [
                                'type' => 'image',
                                'name' => 've-loz-cross.jpg',
                                'url' => 'https://picsum.photos/seed/veloz-cross/900/600',
                                'mime_type' => 'image/jpeg',
                                'payload' => [
                                    'image_data' => [
                                        'url' => 'https://picsum.photos/seed/veloz-cross/900/600',
                                    ],
                                ],
                            ],
                        ],
                        'outbound_status' => 'sent',
                        'external_message_id' => 'mock_bot_1003_a',
                    ],
                    [
                        'sender_type' => 'system',
                        'content' => 'Mời anh xem hình thực tế Veloz Cross nhé.',
                        'minutes_ago' => 6,
                        'message_type' => 'attachment',
                        'attachments' => [
                            [
                                'type' => 'file',
                                'name' => 'brochure-veloz.pdf',
                                'url' => 'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf',
                                'mime_type' => 'application/pdf',
                                'payload' => [
                                    'file_url' => 'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf',
                                ],
                            ],
                        ],
                        'outbound_status' => 'sent',
                        'external_message_id' => 'mock_bot_1003_b',
                    ],
                ],
                'reply_suggestions' => [
                    'Xe 7 chỗ nào phù hợp?',
                    'Gửi thêm hình thực tế',
                    'So sánh Veloz và Avanza',
                ],
            ],
            [
                'customer_name' => 'Trần Thuỳ Linh',
                'avatar' => 'https://i.pravatar.cc/240?img=47',
                'phone' => '0988888777',
                'phone_collected_at' => CarbonImmutable::now()->subDays(2),
                'email' => 'linh.tran@example.com',
                'is_potential' => true,
                'potential_marked_at' => CarbonImmutable::now()->subDay(),
                'channel' => 'facebook',
                'external_id' => 'mock_fb_1004',
                'facebook_page_id' => $facebookPageId,
                'external_conversation_id' => 'mock_conv_1004',
                'botpress_user_id' => 'mock_bp_user_1004',
                'botpress_user_key' => 'mock_bp_key_1004',
                'botpress_conversation_id' => 'mock_bp_conv_1004',
                'botpress_last_message_id' => 'mock_bp_msg_1004',
                'status' => ConversationStatus::WAITING_CUSTOMER,
                'assigned_to' => true,
                'unread_count' => 1,
                'last_message_minutes_ago' => 11,
                'first_response_minutes_ago' => 24,
                'customer_tags' => [Tag::DEFAULT_PHONE, Tag::DEFAULT_APPOINTMENT],
                'conversation_tags' => [Tag::DEFAULT_PHONE, Tag::DEFAULT_APPOINTMENT],
                'note' => 'Da co SDT va dang cho xac nhan lich hen.',
                'note_by' => true,
                'messages' => [
                    [
                        'sender_type' => 'customer',
                        'content' => 'Em muốn đặt lịch xem xe vào cuối tuần.',
                        'minutes_ago' => 31,
                        'external_message_id' => 'mock_cust_1004_a',
                    ],
                    [
                        'sender_type' => 'user',
                        'sender_id' => $agent->id,
                        'content' => 'Dạ em sẽ giữ lịch cho anh/chị, mình cho em xin khung giờ phù hợp nhé.',
                        'minutes_ago' => 24,
                        'outbound_status' => 'sent',
                        'external_message_id' => 'mock_user_1004_a',
                    ],
                    [
                        'sender_type' => 'customer',
                        'content' => 'Chiều chủ nhật nhé.',
                        'minutes_ago' => 11,
                        'external_message_id' => 'mock_cust_1004_b',
                    ],
                ],
                'reply_suggestions' => [
                    'Xác nhận giờ hẹn',
                    'Gửi địa chỉ showroom',
                    'Cần em gọi lại không?',
                ],
            ],
            [
                'customer_name' => 'Phạm Minh Khoa',
                'avatar' => 'https://i.pravatar.cc/240?img=56',
                'phone' => '0912345678',
                'phone_collected_at' => CarbonImmutable::now()->subHours(5),
                'email' => null,
                'is_potential' => false,
                'potential_marked_at' => null,
                'channel' => 'facebook',
                'external_id' => 'mock_fb_1005',
                'facebook_page_id' => $facebookPageId,
                'external_conversation_id' => 'mock_conv_1005',
                'botpress_user_id' => 'mock_bp_user_1005',
                'botpress_user_key' => 'mock_bp_key_1005',
                'botpress_conversation_id' => 'mock_bp_conv_1005',
                'botpress_last_message_id' => 'mock_bp_msg_1005',
                'status' => ConversationStatus::CLOSED,
                'assigned_to' => true,
                'unread_count' => 0,
                'last_message_minutes_ago' => 42,
                'first_response_minutes_ago' => 55,
                'customer_tags' => [Tag::DEFAULT_PHONE, Tag::DEFAULT_QUOTE],
                'conversation_tags' => [Tag::DEFAULT_QUOTE],
                'note' => 'Da dong ho so va da dong hop dong.',
                'note_by' => true,
                'messages' => [
                    [
                        'sender_type' => 'customer',
                        'content' => 'Cho em báo giá Camry.',
                        'minutes_ago' => 60,
                        'external_message_id' => 'mock_cust_1005_a',
                    ],
                    [
                        'sender_type' => 'system',
                        'content' => 'Dạ em gửi anh/chị báo giá và thông tin ưu đãi ngay ạ.',
                        'minutes_ago' => 55,
                        'outbound_status' => 'sent',
                        'external_message_id' => 'mock_bot_1005_a',
                    ],
                    [
                        'sender_type' => 'user',
                        'sender_id' => $agent->id,
                        'content' => 'Đã hỗ trợ xong, cảm ơn anh/chị.',
                        'minutes_ago' => 42,
                        'message_type' => 'whisper',
                        'channel' => 'internal',
                        'outbound_status' => 'sent',
                        'external_message_id' => 'mock_user_1005_a',
                    ],
                ],
                'reply_suggestions' => [
                    'Gửi báo giá Camry',
                    'Tư vấn trả góp',
                    'Hẹn lái thử',
                ],
            ],
        ];
    }
}
