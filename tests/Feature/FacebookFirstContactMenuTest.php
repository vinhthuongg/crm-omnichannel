<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Modules\Conversation\Models\Conversation;
use Modules\Customer\Models\Customer;
use Modules\Message\Jobs\GenerateChatbotResponseJob;
use Modules\Message\Jobs\SendOutboundMessageJob;
use Modules\Message\Models\Message;
use Modules\Message\Services\FacebookFirstContactMenuService;
use Modules\Message\Services\MessagePostProcessor;
use Tests\TestCase;

class FacebookFirstContactMenuTest extends TestCase
{
    use RefreshDatabase;

    /** Xác nhận tin Facebook đầu tiên tạo một carousel và chatbot vẫn nhận chính tin khách để tư vấn. */
    public function test_first_customer_message_queues_menu_and_chatbot_independently(): void
    {
        Queue::fake();
        config()->set('chatbot.enabled', true);
        config()->set('services.facebook.first_contact_menu.enabled', true);
        config()->set('services.facebook.first_contact_menu.elements.0.image_url', 'https://cdn.example.test/cars.jpg');
        [$conversation, $message] = $this->conversation('first-message');

        $messages = app(FacebookFirstContactMenuService::class)->queue($conversation, $message);
        $menu = collect($messages)->firstWhere('message_type', 'attachment');
        app(MessagePostProcessor::class)->queueChatbot($conversation, $message);

        $this->assertNotNull($menu);
        $this->assertSame('system', $menu->sender_type);
        $this->assertSame('generic_template', $menu->attachments[0]['type']);
        $this->assertSame('postback', $menu->attachments[0]['elements'][0]['buttons'][0]['type']);
        $this->assertNotSame('', $menu->attachments[0]['elements'][0]['buttons'][0]['payload']);
        $this->assertDatabaseHas('chatbot_responses', ['source_message_id' => $message->id, 'status' => 'pending']);
        Queue::assertPushed(SendOutboundMessageJob::class, fn ($job): bool => $job->messageId === $menu->id);
        Queue::assertPushed(GenerateChatbotResponseJob::class, 1);
    }

    /** Xác nhận webhook lặp hoặc tin tiếp theo không gửi lại menu lần hai. */
    public function test_menu_is_idempotent_and_only_sent_for_first_message(): void
    {
        Queue::fake();
        [$conversation, $first] = $this->conversation('first');
        $service = app(FacebookFirstContactMenuService::class);

        $this->assertCount(2, $service->queue($conversation, $first));
        $this->assertSame([], $service->queue($conversation, $first));

        $second = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'customer',
            'sender_id' => $conversation->customer_id,
            'channel' => 'facebook',
            'content' => 'Tin tiếp theo',
            'message_type' => 'text',
            'external_message_id' => 'second',
        ]);

        $this->assertSame([], $service->queue($conversation, $second));
        $this->assertDatabaseCount('messages', 4);
        Queue::assertPushed(SendOutboundMessageJob::class, 2);
    }

    private function conversation(string $externalId): array
    {
        $customer = Customer::query()->create(['name' => 'Khách đầu tiên']);
        $conversation = Conversation::query()->create([
            'customer_id' => $customer->id,
            'facebook_page_id' => 'page-id',
            'status' => 'customer_waiting',
            'last_message_at' => now(),
        ]);
        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'customer',
            'sender_id' => $customer->id,
            'channel' => 'facebook',
            'content' => 'Tôi cần tư vấn',
            'message_type' => 'text',
            'external_message_id' => $externalId,
        ]);

        return [$conversation, $message];
    }
}
