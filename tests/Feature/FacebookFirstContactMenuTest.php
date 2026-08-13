<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Conversation\Models\Conversation;
use Modules\Customer\Models\Customer;
use Modules\Message\Jobs\GenerateChatbotResponseJob;
use Modules\Message\Jobs\SendOutboundMessageJob;
use Modules\Message\Models\Message;
use Modules\Message\Services\FacebookFirstContactMenuService;
use Modules\Message\Services\MessagePostProcessor;
use Modules\Facebook\Services\FacebookMessengerService;
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
        $phone = collect($messages)->first(fn (Message $item): bool => str_contains((string) $item->client_message_id, '-phone-'));
        app(MessagePostProcessor::class)->queueChatbot($conversation, $message);

        $this->assertNotNull($menu);
        $this->assertNotNull($phone);
        $this->assertSame('quick_reply', $phone->attachments[0]['type']);
        $this->assertSame('user_phone_number', $phone->attachments[0]['quick_replies'][0]['content_type']);
        $this->assertSame('Anh/chị có thể chia sẻ số điện thoại để Toyota Kiên Giang liên hệ tư vấn nhanh hơn.', $phone->content);
        $this->assertSame('system', $menu->sender_type);
        $this->assertSame('generic_template', $menu->attachments[0]['type']);
        $this->assertSame('postback', $menu->attachments[0]['elements'][0]['buttons'][0]['type']);
        $this->assertNotSame('', $menu->attachments[0]['elements'][0]['buttons'][0]['payload']);
        $this->assertDatabaseHas('chatbot_responses', ['source_message_id' => $message->id, 'status' => 'pending']);
        Queue::assertPushed(SendOutboundMessageJob::class, fn ($job): bool => $job->messageId === $menu->id);
        Queue::assertPushed(GenerateChatbotResponseJob::class, 1);
    }

    /** Xác nhận CRM gửi đúng cấu trúc Phone Number Quick Reply mà Messenger Platform yêu cầu. */
    public function test_phone_quick_reply_is_sent_with_the_official_payload_shape(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['recipient_id' => 'customer-psid', 'message_id' => 'mid.1'])]);

        app(FacebookMessengerService::class)->sendTextWithQuickReplies(
            'customer-psid',
            'Vui lòng chia sẻ số điện thoại.',
            [['content_type' => 'user_phone_number']],
            'valid-page-token',
        );

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return $request->url() === 'https://graph.facebook.com/v25.0/me/messages'
                && data_get($payload, 'recipient.id') === 'customer-psid'
                && data_get($payload, 'messaging_type') === 'RESPONSE'
                && data_get($payload, 'message.quick_replies') === [['content_type' => 'user_phone_number']]
                && ! array_key_exists('title', data_get($payload, 'message.quick_replies.0', []))
                && ! array_key_exists('payload', data_get($payload, 'message.quick_replies.0', []));
        });
    }

    /** Xác nhận menu không lặp sau khi đã xếp gửi thành công. */
    public function test_menu_is_idempotent_after_it_has_been_queued(): void
    {
        Queue::fake();
        [$conversation, $first] = $this->conversation('first');
        $service = app(FacebookFirstContactMenuService::class);

        $this->assertCount(3, $service->queue($conversation, $first));
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
        $this->assertDatabaseCount('messages', 5);
        Queue::assertPushed(SendOutboundMessageJob::class, 3);
    }

    /** Xác nhận khách có lịch sử cũ vẫn nhận menu ở tin kế tiếp nếu menu chưa từng được gửi. */
    public function test_existing_customer_receives_menu_when_no_successful_menu_marker_exists(): void
    {
        Queue::fake();
        [$conversation, $first] = $this->conversation('old-first');
        $second = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'customer',
            'sender_id' => $conversation->customer_id,
            'channel' => 'facebook',
            'content' => 'Tin mới sau khi bật menu',
            'message_type' => 'text',
            'external_message_id' => 'new-after-enabled',
        ]);

        $messages = app(FacebookFirstContactMenuService::class)->queue($conversation, $second);

        $this->assertCount(3, $messages);
        $this->assertDatabaseHas('messages', [
            'client_message_id' => 'facebook-first-contact-menu-page-id-'.$conversation->customer_id,
            'outbound_status' => 'queued',
        ]);
        Queue::assertPushed(SendOutboundMessageJob::class, 3);
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
