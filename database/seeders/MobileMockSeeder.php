<?php

namespace Database\Seeders;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Models\ConversationActivity;
use Modules\Conversation\Models\ConversationReplySuggestion;
use Modules\Conversation\Models\Tag;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Customer\Models\Customer;
use Modules\Customer\Models\CustomerTag;
use Modules\Message\Models\Message;
use Modules\Search\Services\VectorSearchService;
use Spatie\Permission\Models\Role;

class MobileMockSeeder extends Seeder
{
    /** Tạo người dùng, ca trực, khách hàng, hội thoại và notification mẫu cho ứng dụng mobile. */
    public function run(): void
    {
        $agent = $this->ensureAgent();
        $page = DB::table('facebook_pages')->orderBy('id')->first();

        $this->clearConversationData();
        Tag::ensureDefaults();
        $customerTags = $this->ensureCustomerTags();

        foreach ($this->rows($agent, $page?->page_id, $page?->page_name) as $index => $row) {
            $this->seedRow($row, $customerTags, $agent, $index);
        }

        app(VectorSearchService::class)->rebuildCustomers(
            Customer::query()->with(['channels', 'tags', 'notes', 'conversations.messages'])->get(),
        );
    }

    /** Tạo hoặc cập nhật nhân viên mobile mẫu, gán vai trò CSKH và quyền cần thiết. */
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

    /** Xóa dữ liệu hội thoại/khách hàng mẫu cũ để seeder có thể chạy lại ổn định. */
    private function clearConversationData(): void
    {
        $tables = [
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
        $customerCreatedAt = $row['customer_created_at'] ?? $now->subDays(random_int(1, 30));
        $conversationCreatedAt = $row['conversation_created_at'] ?? $row['conversation_started_at'] ?? $now->subMinutes($row['last_message_minutes_ago'] + random_int(20, 240));

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

        DB::table('customers')
            ->where('id', $customer->id)
            ->update([
                'created_at' => $customerCreatedAt,
                'updated_at' => $customerCreatedAt,
            ]);

        $customer->channels()->create([
            'channel' => $row['channel'],
            'external_id' => $row['external_id'],
            'metadata' => [
                'mock' => true,
                'channel' => $row['channel'],
                'facebook_page_id' => $row['facebook_page_id'],
                'facebook_page_name' => $row['facebook_page_name'] ?? null,
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
            'claimed_at' => $row['assigned_to'] ? $now->subMinutes(30 - $index) : null,
            'owner_shift_id' => null,
            'queue_shift_id' => null,
            'status' => $row['status'],
            'last_message_at' => $now->subMinutes($row['last_message_minutes_ago']),
            'unread_messages_count' => $row['unread_count'],
            'last_read_at' => $row['unread_count'] > 0 ? $now->subMinutes($row['last_message_minutes_ago'] + 2) : $now->subMinutes(max(1, $row['last_message_minutes_ago'] - 1)),
            'resolved_at' => $row['status'] === ConversationStatus::CLOSED ? $now->subMinutes($row['last_message_minutes_ago']) : null,
            'first_response_at' => $row['first_response_minutes_ago'] !== null ? $now->subMinutes($row['first_response_minutes_ago']) : null,
            'closed_at' => $row['status'] === ConversationStatus::CLOSED ? $now->subMinutes($row['last_message_minutes_ago']) : null,
        ]);

        DB::table('conversations')
            ->where('id', $conversation->id)
            ->update([
                'created_at' => $conversationCreatedAt,
                'updated_at' => $conversationCreatedAt,
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
        }

        if ($row['status'] === ConversationStatus::CLOSED) {
            ConversationActivity::query()->create([
                'conversation_id' => $conversation->id,
                'action' => 'status.changed',
                'old_value' => ['status' => ConversationStatus::WAITING_CUSTOMER],
                'new_value' => ['status' => ConversationStatus::CLOSED],
                'performed_by' => $agent->id,
            ]);
        }

        if (! empty($row['reply_suggestions'])) {
            $lastCustomerMessage = Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('sender_type', 'customer')
                ->latest('id')
                ->first();

            if ($lastCustomerMessage) {
                ConversationReplySuggestion::query()->create([
                    'conversation_id' => $conversation->id,
                    'message_id' => $lastCustomerMessage->id,
                    'provider' => 'mock',
                    'suggestions' => $row['reply_suggestions'],
                    'generated_at' => $now->subMinutes($row['last_message_minutes_ago'])->addSeconds(10),
                ]);
            }
        }

    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(User $agent, ?string $facebookPageId, ?string $facebookPageName): array
    {
        $facebookPageId ??= '950608971471401';
        $facebookPageName ??= 'Old Thread';

        $names = [
            'Vinh Thuong Truong',
            'Le Thi Mai',
            'Nguyen Van An',
            'Tran Thu Linh',
            'Pham Minh Khoa',
            'Hoang Gia Bao',
            'Doan Ngoc Han',
            'Bui Quoc Huy',
            'Vu Thu Trang',
            'Dang Thanh Long',
            'Nguyen Thi Phuong',
            'Phan Quang Hieu',
            'Le Gia Han',
            'Tran Minh Tri',
            'Hoang My Linh',
            'Nguyen Hoang Nam',
            'Pham Dieu My',
            'Do Minh Tuan',
            'Ngo Khanh Vy',
            'Le Minh Duc',
            'Tran Gia Bao',
            'Vu Hong Ngoc',
            'Nguyen Quoc Dat',
            'Pham Thi Mai Anh',
            'Do Huu Phuc',
            'Tran Thi Kim Anh',
            'Le Thanh Phat',
            'Nguyen Chi Khang',
            'Bui Thi Thanh Huyen',
            'Hoang Tuan Anh',
        ];

        $topics = [
            'Vios',
            'Raize',
            'Veloz Cross',
            'Yaris Cross',
            'Camry',
            'Hilux',
            'Corolla Cross',
            'Avanza Premio',
            'Wigo',
            'Land Cruiser',
        ];

        $rows = [];
        $usedPhones = [];
        $now = CarbonImmutable::now();

        for ($i = 0; $i < 30; $i++) {
            $seed = 1001 + $i;
            $topic = $topics[$i % count($topics)];
            $name = $names[$i];
            $phone = $this->generatePhone($usedPhones);
            $avatarIndex = (($i * 7) % 70) + 1;
            $scenario = $i % 6;
            $conversationAgeMinutes = random_int(300, 60 * 24 * 20);
            $conversationCreatedAt = $now->subMinutes($conversationAgeMinutes);

            $rows[] = array_merge(
                [
                    'customer_name' => $name,
                    'avatar' => 'https://i.pravatar.cc/240?img='.$avatarIndex,
                    'phone' => $phone,
                    'phone_collected_at' => $conversationCreatedAt->addMinutes(random_int(5, min(240, $conversationAgeMinutes - 10))),
                    'customer_created_at' => $conversationCreatedAt->subMinutes(random_int(15, 1440)),
                    'conversation_created_at' => $conversationCreatedAt,
                    'email' => Str::slug($name).'@example.com',
                    'is_potential' => random_int(0, 1) === 1,
                    'potential_marked_at' => null,
                    'channel' => 'facebook',
                    'external_id' => 'mock_fb_'.str_pad((string) $seed, 4, '0', STR_PAD_LEFT),
                    'facebook_page_id' => $facebookPageId,
                    'facebook_page_name' => $facebookPageName,
                    'external_conversation_id' => 'mock_conv_'.str_pad((string) $seed, 4, '0', STR_PAD_LEFT),
                ],
                $this->buildScenario($scenario, $seed, $topic, $agent),
            );

            if ($rows[$i]['is_potential']) {
                $maxPotentialOffset = max(20, min(240, $conversationAgeMinutes - 10));
                $rows[$i]['potential_marked_at'] = $conversationCreatedAt->addMinutes(random_int(20, $maxPotentialOffset));
            }
        }

        return $rows;
    }

    /**
     * @return array{
     *     status: string,
     *     assigned_to: bool,
     *     unread_count: int,
     *     first_response_minutes_ago: ?int,
     *     customer_tags: array<int, string>,
     *     conversation_tags: array<int, string>,
     *     note: string,
     *     note_by: bool,
     *     messages: array<int, array<string, mixed>>,
     *     reply_suggestions: array<int, string>,
     *     last_message_minutes_ago: int
     * }
     */
    private function buildScenario(int $scenario, int $seed, string $topic, User $agent): array
    {
        return match ($scenario) {
            0 => $this->scenarioQuoteWaiting($seed, $topic),
            1 => $this->scenarioWaitingCustomer($seed, $topic, $agent),
            2 => $this->scenarioBotConsulting($seed, $topic),
            3 => $this->scenarioClosed($seed, $topic, $agent),
            4 => $this->scenarioAttachment($seed, $topic),
            default => $this->scenarioWhisper($seed, $topic, $agent),
        };
    }

    /** Tạo kịch bản khách hỏi báo giá và đang chờ nhân viên phản hồi. */
    private function scenarioQuoteWaiting(int $seed, string $topic): array
    {
        $lastAgo = random_int(4, 24);

        return [
            'status' => ConversationStatus::CUSTOMER_WAITING,
            'assigned_to' => true,
            'unread_count' => random_int(1, 4),
            'first_response_minutes_ago' => $lastAgo + random_int(20, 60),
            'customer_tags' => [Tag::DEFAULT_QUOTE, Tag::DEFAULT_INSTALLMENT, Tag::DEFAULT_PHONE],
            'conversation_tags' => [Tag::DEFAULT_QUOTE, Tag::DEFAULT_INSTALLMENT],
            'note' => 'Khách đang xin báo giá và trả góp cho '.$topic.'.',
            'note_by' => true,
            'messages' => [
                [
                    'sender_type' => 'customer',
                    'content' => 'Anh/chị muốn xem giá '.$topic.'.',
                    'minutes_ago' => $lastAgo + 90,
                    'external_message_id' => 'mock_quote_'.$seed.'_cust_a',
                ],
                [
                    'sender_type' => 'system',
                    'content' => 'Dạ em gửi anh/chị báo giá sơ bộ cho '.$topic.' ạ.',
                    'minutes_ago' => $lastAgo + 40,
                    'outbound_status' => 'sent',
                    'external_message_id' => 'mock_quote_'.$seed.'_bot_a',
                ],
                [
                    'sender_type' => 'customer',
                    'content' => 'Cho em xem ưu đãi và mức trả trước nhé.',
                    'minutes_ago' => $lastAgo,
                    'external_message_id' => 'mock_quote_'.$seed.'_cust_b',
                ],
            ],
            'reply_suggestions' => [
                'Xem báo giá '.$topic,
                'Tư vấn trả góp',
                'Gửi ưu đãi',
            ],
            'last_message_minutes_ago' => $lastAgo,
        ];
    }

    /** Tạo kịch bản nhân viên đã trả lời và đang chờ phản hồi từ khách hàng. */
    private function scenarioWaitingCustomer(int $seed, string $topic, User $agent): array
    {
        $lastAgo = random_int(8, 60);

        return [
            'status' => ConversationStatus::WAITING_CUSTOMER,
            'assigned_to' => true,
            'unread_count' => 0,
            'first_response_minutes_ago' => $lastAgo + random_int(25, 70),
            'customer_tags' => [Tag::DEFAULT_TEST_DRIVE, Tag::DEFAULT_PHONE],
            'conversation_tags' => [Tag::DEFAULT_TEST_DRIVE],
            'note' => 'Đã tư vấn xong và đang chờ khách phản hồi lịch cho '.$topic.'.',
            'note_by' => true,
            'messages' => [
                [
                    'sender_type' => 'customer',
                    'content' => 'Anh/chị quan tâm '.$topic.' cho gia đình.',
                    'minutes_ago' => $lastAgo + 180,
                    'external_message_id' => 'mock_wait_'.$seed.'_cust_a',
                ],
                [
                    'sender_type' => 'user',
                    'sender_id' => $agent->id,
                    'content' => 'Dạ em đã ghi nhận, anh/chị cho em xin thời gian thuận tiện nhé.',
                    'minutes_ago' => $lastAgo,
                    'outbound_status' => 'sent',
                    'external_message_id' => 'mock_wait_'.$seed.'_agent_a',
                ],
            ],
            'reply_suggestions' => [
                'Xác nhận lịch',
                'Gửi địa chỉ showroom',
                'Cần em gọi xác nhận không?',
            ],
            'last_message_minutes_ago' => $lastAgo,
        ];
    }

    /** Tạo kịch bản bot đang tư vấn tự động trước khi chuyển cho nhân viên. */
    private function scenarioBotConsulting(int $seed, string $topic): array
    {
        $lastAgo = random_int(3, 18);

        return [
            'status' => ConversationStatus::BOT_CONSULTING,
            'assigned_to' => false,
            'unread_count' => 0,
            'first_response_minutes_ago' => $lastAgo + random_int(30, 90),
            'customer_tags' => [Tag::DEFAULT_CONSULTING, Tag::DEFAULT_PHONE],
            'conversation_tags' => [Tag::DEFAULT_CONSULTING],
            'note' => 'Bot đang tư vấn về '.$topic.' và đã gửi ảnh minh họa.',
            'note_by' => false,
            'messages' => [
                [
                    'sender_type' => 'customer',
                    'content' => 'Anh cần thêm thông tin về '.$topic.'.',
                    'minutes_ago' => $lastAgo + 140,
                    'external_message_id' => 'mock_bot_'.$seed.'_cust_a',
                ],
                [
                    'sender_type' => 'system',
                    'content' => 'Anh tham khảo '.$topic.' nhé, em gửi thêm hình thực tế.',
                    'minutes_ago' => $lastAgo + 40,
                    'message_type' => 'attachment',
                    'attachments' => [
                        [
                            'type' => 'image',
                            'name' => 'toyota-'.$seed.'.jpg',
                            'url' => 'https://picsum.photos/seed/mock-'.$seed.'/900/600',
                            'mime_type' => 'image/jpeg',
                            'payload' => [
                                'image_data' => [
                                    'url' => 'https://picsum.photos/seed/mock-'.$seed.'/900/600',
                                ],
                            ],
                        ],
                    ],
                    'outbound_status' => 'sent',
                    'external_message_id' => 'mock_bot_'.$seed.'_bot_a',
                ],
                [
                    'sender_type' => 'system',
                    'content' => 'Nếu cần, em có thể gửi thêm brochure hoặc bảng màu.',
                    'minutes_ago' => $lastAgo,
                    'outbound_status' => 'sent',
                    'external_message_id' => 'mock_bot_'.$seed.'_bot_b',
                ],
            ],
            'reply_suggestions' => [
                'Xem thêm hình',
                'Gửi brochure',
                'So sánh các mẫu',
            ],
            'last_message_minutes_ago' => $lastAgo,
        ];
    }

    /** Tạo kịch bản hội thoại đã xử lý xong và đóng bởi nhân viên. */
    private function scenarioClosed(int $seed, string $topic, User $agent): array
    {
        $lastAgo = random_int(30, 180);

        return [
            'status' => ConversationStatus::CLOSED,
            'assigned_to' => true,
            'unread_count' => 0,
            'first_response_minutes_ago' => $lastAgo + random_int(60, 150),
            'customer_tags' => [Tag::DEFAULT_APPOINTMENT, Tag::DEFAULT_PHONE],
            'conversation_tags' => [Tag::DEFAULT_APPOINTMENT, Tag::DEFAULT_PHONE],
            'note' => 'Khách đã xác nhận và cuộc hội thoại đã đóng.',
            'note_by' => true,
            'messages' => [
                [
                    'sender_type' => 'customer',
                    'content' => 'Anh/chị muốn chốt lịch hẹn cho '.$topic.'.',
                    'minutes_ago' => $lastAgo + 240,
                    'external_message_id' => 'mock_closed_'.$seed.'_cust_a',
                ],
                [
                    'sender_type' => 'system',
                    'content' => 'Dạ em đã ghi nhận lịch hẹn cho anh/chị ạ.',
                    'minutes_ago' => $lastAgo + 110,
                    'outbound_status' => 'sent',
                    'external_message_id' => 'mock_closed_'.$seed.'_bot_a',
                ],
                [
                    'sender_type' => 'user',
                    'sender_id' => $agent->id,
                    'content' => 'Em đã hoàn tất lịch hẹn, cảm ơn anh/chị.',
                    'minutes_ago' => $lastAgo + 20,
                    'message_type' => 'whisper',
                    'channel' => 'internal',
                    'outbound_status' => 'sent',
                    'external_message_id' => 'mock_closed_'.$seed.'_user_a',
                ],
                [
                    'sender_type' => 'user',
                    'sender_id' => $agent->id,
                    'content' => 'Hẹn gặp anh/chị tại showroom theo lịch đã xác nhận.',
                    'minutes_ago' => $lastAgo,
                    'outbound_status' => 'sent',
                    'external_message_id' => 'mock_closed_'.$seed.'_user_b',
                ],
            ],
            'reply_suggestions' => [
                'Xác nhận lịch',
                'Gửi địa chỉ',
                'Đóng hội thoại',
            ],
            'last_message_minutes_ago' => $lastAgo,
        ];
    }

    /** Tạo kịch bản hội thoại có ảnh hoặc tệp đính kèm để kiểm tra mobile timeline. */
    private function scenarioAttachment(int $seed, string $topic): array
    {
        $lastAgo = random_int(5, 36);

        return [
            'status' => ConversationStatus::CUSTOMER_WAITING,
            'assigned_to' => true,
            'unread_count' => random_int(1, 2),
            'first_response_minutes_ago' => $lastAgo + random_int(25, 80),
            'customer_tags' => [Tag::DEFAULT_QUOTE, Tag::DEFAULT_CONSULTING],
            'conversation_tags' => [Tag::DEFAULT_QUOTE, Tag::DEFAULT_CONSULTING],
            'note' => 'Khách đang xem hình và tài liệu về '.$topic.'.',
            'note_by' => true,
            'messages' => [
                [
                    'sender_type' => 'customer',
                    'content' => 'Anh/chị gửi thêm hình thực tế giúp em với.',
                    'minutes_ago' => $lastAgo + 180,
                    'external_message_id' => 'mock_attach_'.$seed.'_cust_a',
                ],
                [
                    'sender_type' => 'system',
                    'content' => 'Dạ em gửi anh/chị một số hình Toyota thực tế nhé.',
                    'minutes_ago' => $lastAgo + 90,
                    'message_type' => 'attachment',
                    'attachments' => [
                        [
                            'type' => 'image',
                            'name' => 'toyota-'.$seed.'-gallery.jpg',
                            'url' => 'https://picsum.photos/seed/attach-'.$seed.'/900/600',
                            'mime_type' => 'image/jpeg',
                            'payload' => [
                                'image_data' => [
                                    'url' => 'https://picsum.photos/seed/attach-'.$seed.'/900/600',
                                ],
                            ],
                        ],
                    ],
                    'outbound_status' => 'sent',
                    'external_message_id' => 'mock_attach_'.$seed.'_bot_a',
                ],
                [
                    'sender_type' => 'system',
                    'content' => 'Em gửi thêm brochure chi tiết để anh/chị xem nhanh hơn.',
                    'minutes_ago' => $lastAgo + 30,
                    'message_type' => 'attachment',
                    'attachments' => [
                        [
                            'type' => 'file',
                            'name' => 'brochure-'.$seed.'.pdf',
                            'url' => 'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf',
                            'mime_type' => 'application/pdf',
                            'payload' => [
                                'file_url' => 'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf',
                            ],
                        ],
                    ],
                    'outbound_status' => 'sent',
                    'external_message_id' => 'mock_attach_'.$seed.'_bot_b',
                ],
                [
                    'sender_type' => 'customer',
                    'content' => 'Đẹp quá, cho em xin giá bản cao nhất nhé.',
                    'minutes_ago' => $lastAgo,
                    'external_message_id' => 'mock_attach_'.$seed.'_cust_b',
                ],
            ],
            'reply_suggestions' => [
                'Gửi giá bản cao nhất',
                'So sánh các phiên bản',
                'Gửi thêm hình',
            ],
            'last_message_minutes_ago' => $lastAgo,
        ];
    }

    /** Tạo kịch bản có ghi chú whisper nội bộ giữa các nhân viên. */
    private function scenarioWhisper(int $seed, string $topic, User $agent): array
    {
        $lastAgo = random_int(6, 72);

        return [
            'status' => ConversationStatus::CUSTOMER_WAITING,
            'assigned_to' => true,
            'unread_count' => random_int(1, 3),
            'first_response_minutes_ago' => $lastAgo + random_int(20, 45),
            'customer_tags' => [Tag::DEFAULT_PHONE, Tag::DEFAULT_APPOINTMENT],
            'conversation_tags' => [Tag::DEFAULT_PHONE],
            'note' => 'Nhân viên có ghi chú nội bộ và đang chờ khách phản hồi.',
            'note_by' => true,
            'messages' => [
                [
                    'sender_type' => 'customer',
                    'content' => 'Anh/chị cần em hỗ trợ '.$topic.' thêm.',
                    'minutes_ago' => $lastAgo + 120,
                    'external_message_id' => 'mock_whisper_'.$seed.'_cust_a',
                ],
                [
                    'sender_type' => 'user',
                    'sender_id' => $agent->id,
                    'content' => 'Thì thầm: khách đang cân nhắc, ưu tiên nhắn lại sau 3 phút.',
                    'minutes_ago' => $lastAgo + 20,
                    'message_type' => 'whisper',
                    'channel' => 'internal',
                    'outbound_status' => 'sent',
                    'external_message_id' => 'mock_whisper_'.$seed.'_user_a',
                ],
                [
                    'sender_type' => 'customer',
                    'content' => 'Để em xem thêm rồi phản hồi lại.',
                    'minutes_ago' => $lastAgo,
                    'external_message_id' => 'mock_whisper_'.$seed.'_cust_b',
                ],
            ],
            'reply_suggestions' => [
                'Nhắc khách phản hồi',
                'Ghi chú nội bộ',
                'Đợi khách',
            ],
            'last_message_minutes_ago' => $lastAgo,
        ];
    }

    /** Sinh số điện thoại mẫu duy nhất và tránh trùng với các số đã tạo. */
    private function generatePhone(array &$usedPhones): string
    {
        do {
            $phone = '09'.str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT);
        } while (in_array($phone, $usedPhones, true));

        $usedPhones[] = $phone;

        return $phone;
    }
}
