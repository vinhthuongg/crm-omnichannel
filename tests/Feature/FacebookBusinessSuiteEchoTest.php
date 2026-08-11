<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Customer\Models\Customer;
use Modules\Message\Models\ChatbotResponse;
use Modules\Message\Models\Message;
use Modules\Message\Services\MessageService;
use Tests\TestCase;

class FacebookBusinessSuiteEchoTest extends TestCase
{
    use RefreshDatabase;

    /** Xác nhận tin mới từ Business Suite là nhân viên Meta, xuất hiện trong CRM và hủy lượt bot đang chờ. */
    public function test_business_suite_echo_is_stored_as_meta_agent_and_cancels_pending_bot(): void
    {
        Event::fake();
        [$conversation, $source] = $this->conversation(ConversationStatus::CLOSED);
        $response = ChatbotResponse::query()->create([
            'conversation_id' => $conversation->id,
            'source_message_id' => $source->id,
            'external_message_id' => 'crm-message-'.$source->id,
            'status' => 'pending',
        ]);

        $message = app(MessageService::class)->storeFacebookEcho($this->echoEvent('meta-agent-message', 'Nhân viên đã tư vấn'));

        $this->assertNotNull($message);
        $this->assertSame('user', $message->sender_type);
        $this->assertNull($message->sender_id);
        $this->assertSame('Nhân viên Meta', $message->senderName());
        $this->assertSame('cancelled', $response->fresh()->status);
        $this->assertSame('staff_intervened', $response->fresh()->error_code);
        $this->assertSame(ConversationStatus::WAITING_CUSTOMER, $conversation->fresh()->status);
        $this->assertNull($conversation->fresh()->closed_at);
    }

    /** Xác nhận echo khớp message Bot đã lưu chỉ cập nhật idempotency và không bị đổi thành nhân viên Meta. */
    public function test_known_bot_echo_keeps_the_existing_system_message(): void
    {
        Event::fake();
        [$conversation] = $this->conversation(ConversationStatus::WAITING_CUSTOMER);
        $existing = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'system',
            'channel' => 'facebook',
            'content' => 'Tin của bot',
            'message_type' => 'text',
            'external_message_id' => 'known-bot-message',
            'outbound_status' => 'sent',
        ]);

        $message = app(MessageService::class)->storeFacebookEcho($this->echoEvent('known-bot-message', 'Tin của bot'));

        $this->assertSame($existing->id, $message?->id);
        $this->assertSame('system', $message?->sender_type);
        $this->assertSame('Bot', $message?->senderName());
        $this->assertDatabaseCount('messages', 2);
    }

    private function conversation(string $status): array
    {
        $customer = Customer::query()->create(['name' => 'Khách Facebook']);
        $customer->channels()->create(['channel' => 'facebook', 'external_id' => 'customer-psid']);
        $conversation = Conversation::query()->create([
            'customer_id' => $customer->id,
            'facebook_page_id' => 'page-id',
            'status' => $status,
            'closed_at' => $status === ConversationStatus::CLOSED ? now() : null,
            'last_message_at' => now()->subMinute(),
        ]);
        $source = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'customer',
            'sender_id' => $customer->id,
            'channel' => 'facebook',
            'content' => 'Khách cần tư vấn',
            'message_type' => 'text',
            'external_message_id' => 'customer-message-'.$conversation->id,
        ]);

        return [$conversation, $source];
    }

    private function echoEvent(string $messageId, string $content): array
    {
        return [
            'sender' => ['id' => 'page-id'],
            'recipient' => ['id' => 'customer-psid'],
            'message' => [
                'mid' => $messageId,
                'text' => $content,
                'is_echo' => true,
                'app_id' => 'facebook-app',
            ],
        ];
    }
}
