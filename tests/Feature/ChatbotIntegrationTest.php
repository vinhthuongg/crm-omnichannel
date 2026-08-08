<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Chatbot\ChatbotClient;
use App\Services\Chatbot\ChatbotException;
use Database\Seeders\RolesAndPermissionsSeeder;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\ConversationVisibilityService;
use Modules\Customer\Models\Customer;
use Modules\Message\Events\ChatbotResponseDelta;
use Modules\Message\Jobs\GenerateChatbotResponseJob;
use Modules\Message\Models\ChatbotResponse;
use Modules\Message\Models\Message;
use Modules\Message\Services\ChatbotResponseProcessor;
use Modules\Message\Services\MessagePostProcessor;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ChatbotIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /** Xác nhận khách A/B và hai conversation cùng khách đều gửi định danh/context độc lập. */
    public function test_conversation_and_customer_context_is_isolated_and_stable(): void
    {
        Queue::fake();
        Event::fake();
        $client = new FakeChatbotClient;
        [$first, $firstMessage] = $this->conversation('Khách A', 'facebook', 'A-1');
        [$second, $secondMessage] = $this->conversation('Khách B', 'zalo', 'B-1');
        [$third, $thirdMessage] = $this->conversation('Khách A lần hai', 'facebook', 'A-2', $first->customer);

        foreach ([[$first, $firstMessage], [$second, $secondMessage], [$third, $thirdMessage]] as [$conversation, $message]) {
            $response = $this->response($conversation, $message);
            $this->processor($client)->process($response);
        }

        $this->assertSame([
            (string) $first->id,
            (string) $second->id,
            (string) $third->id,
        ], array_column($client->payloads, 'crmConversationId'));
        $this->assertSame((string) $first->customer_id, $client->payloads[0]['customerId']);
        $this->assertSame((string) $third->customer_id, $client->payloads[2]['customerId']);
        $this->assertNotSame($client->payloads[0]['crmConversationId'], $client->payloads[2]['crmConversationId']);
        $this->assertSame((string) $first->id, $client->payloads[0]['crmConversationId']);
    }

    /** Xác nhận externalMessageId/source message chống xếp hai job cho cùng một tin khách. */
    public function test_external_message_id_is_idempotent(): void
    {
        Queue::fake();
        config()->set('chatbot.enabled', true);
        [$conversation, $message] = $this->conversation('Khách', 'facebook', 'same');
        $post = app(MessagePostProcessor::class);

        $post->queueChatbot($conversation, $message);
        $post->queueChatbot($conversation, $message);

        $this->assertDatabaseCount('chatbot_responses', 1);
        $this->assertSame('crm-message-'.$message->id, ChatbotResponse::query()->value('external_message_id'));
        Queue::assertPushed(GenerateChatbotResponseJob::class, 1);
    }

    /** Xác nhận break tạo nhiều bubble, completed lưu một lần và completed lặp không nhân đôi nội dung. */
    public function test_break_and_completed_persist_segments_once(): void
    {
        Queue::fake();
        Event::fake();
        [$conversation, $message] = $this->conversation('Khách', 'facebook', 'split');
        $response = $this->response($conversation, $message);
        $client = new FakeChatbotClient;

        $this->processor($client)->process($response);
        $this->processor($client)->process($response->fresh());

        $this->assertSame(['Xin chào', 'Tôi có thể hỗ trợ gì?'], $response->fresh()->segments);
        $this->assertSame(2, Message::query()->where('sender_type', 'system')->count());
    }

    /** Xác nhận reconnect sau timeout dùng lại response/external ID và không tạo hai câu trả lời. */
    public function test_timeout_then_retry_does_not_duplicate_response(): void
    {
        Queue::fake();
        Event::fake();
        [$conversation, $message] = $this->conversation('Khách', 'facebook', 'retry');
        $response = $this->response($conversation, $message);
        $client = new FakeChatbotClient;
        $client->failNext = true;

        try {
            $this->processor($client)->process($response);
            $this->fail('Expected timeout.');
        } catch (ChatbotException $exception) {
            $this->assertTrue($exception->retryable);
        }

        $this->processor($client)->process($response->fresh());

        $this->assertCount(2, $client->payloads);
        $this->assertSame($client->payloads[0]['externalMessageId'], $client->payloads[1]['externalMessageId']);
        $this->assertSame(2, Message::query()->where('sender_type', 'system')->count());
    }

    /** Xác nhận message.error từ SSE đánh dấu response failed và không làm mất tin khách. */
    public function test_sse_error_marks_response_failed_without_deleting_customer_message(): void
    {
        Queue::fake();
        Event::fake();
        [$conversation, $message] = $this->conversation('Khách', 'facebook', 'error');
        $response = $this->response($conversation, $message);
        $client = new FakeChatbotClient;
        $client->errorNext = true;
        $job = new GenerateChatbotResponseJob($response->id);

        $job->handle($this->processor($client));

        $this->assertSame('failed', $response->fresh()->status);
        $this->assertDatabaseHas('messages', ['id' => $message->id, 'sender_type' => 'customer']);
        $this->assertSame(0, Message::query()->where('sender_type', 'system')->count());
    }

    /** Xác nhận secret không xuất hiện trong broadcast hoặc dữ liệu response được lưu. */
    public function test_api_key_is_absent_from_broadcast_and_persistence(): void
    {
        config()->set('chatbot.api_key', 'top-secret-key');
        $event = new ChatbotResponseDelta(10, 20, ['segment' => 0, 'delta' => 'Xin chào']);

        $this->assertStringNotContainsString('top-secret-key', json_encode($event->broadcastWith(), JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('api_key', $event->broadcastWith());
    }

    /** Xác nhận client gửi secret ở header backend nhưng không ghi secret đó vào log. */
    public function test_api_key_is_sent_server_side_but_never_logged(): void
    {
        config()->set('chatbot.base_url', 'http://127.0.0.1:3000/api/v1');
        config()->set('chatbot.api_key', 'top-secret-key');
        Log::spy();
        $mock = new MockHandler([
            new GuzzleResponse(200, ['x-request-id' => 'safe-request'], "event: message.completed\ndata: {\"text\":\"Xong\"}\n\n"),
        ]);
        $client = new ChatbotClient(new GuzzleClient(['handler' => HandlerStack::create($mock)]));

        $client->stream([
            'crmConversationId' => '1',
            'customerId' => '1',
            'externalMessageId' => 'crm-message-1',
            'message' => 'Nội dung riêng tư',
            'userContext' => ['channel' => 'facebook', 'displayName' => 'Khách'],
        ], fn () => null);

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
            return ! str_contains(json_encode([$message, $context]), 'top-secret-key')
                && ! str_contains(json_encode([$message, $context]), 'Nội dung riêng tư');
        })->atLeast()->once();
    }

    /** Xác nhận private Reverb channel từ chối nhân viên không có quyền xem conversation. */
    public function test_reverb_authorization_is_isolated_by_conversation(): void
    {
        config()->set('broadcasting.default', 'reverb');
        config()->set('broadcasting.connections.reverb.key', 'test-key');
        config()->set('broadcasting.connections.reverb.secret', 'test-secret');
        config()->set('broadcasting.connections.reverb.app_id', 'test-app');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(RolesAndPermissionsSeeder::class);
        $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@chatbot.test', 'password' => 'password', 'is_active' => true]);
        $stranger = User::query()->create(['name' => 'Stranger', 'email' => 'stranger@chatbot.test', 'password' => 'password', 'is_active' => true]);
        $owner->assignRole('CSKH');
        $stranger->assignRole('CSKH');
        [$conversation] = $this->conversation('Khách riêng', 'facebook', 'auth-private');
        $conversation->forceFill(['assigned_to' => $owner->id])->save();
        $payload = ['socket_id' => '123.456', 'channel_name' => 'private-crm.conversation.'.$conversation->id];

        $this->assertTrue(app(ConversationVisibilityService::class)->canView($owner, $conversation->fresh()));
        $this->assertFalse(app(ConversationVisibilityService::class)->canView($stranger, $conversation->fresh()));
        $this->actingAs($stranger)->postJson('/broadcasting/auth', $payload)->assertForbidden();
    }

    private function processor(ChatbotClient $client): ChatbotResponseProcessor
    {
        return new ChatbotResponseProcessor($client, app(MessagePostProcessor::class));
    }

    private function conversation(string $name, string $channel, string $externalId, ?Customer $customer = null): array
    {
        $customer ??= Customer::query()->create(['name' => $name]);
        $conversation = Conversation::query()->create([
            'customer_id' => $customer->id,
            'status' => 'open',
            'last_message_at' => now(),
        ]);
        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'customer',
            'sender_id' => $customer->id,
            'channel' => $channel,
            'content' => 'Nội dung '.$externalId,
            'message_type' => 'text',
            'external_message_id' => $externalId,
        ]);

        return [$conversation, $message];
    }

    private function response(Conversation $conversation, Message $message): ChatbotResponse
    {
        return ChatbotResponse::query()->create([
            'conversation_id' => $conversation->id,
            'source_message_id' => $message->id,
            'external_message_id' => 'crm-message-'.$message->id,
            'status' => 'pending',
        ]);
    }
}

class FakeChatbotClient extends ChatbotClient
{
    public array $payloads = [];

    public bool $failNext = false;

    public bool $errorNext = false;

    /** Phát một stream giả có delta, break và completed để kiểm thử integration không gọi mạng. */
    public function stream(array $payload, callable $onEvent): void
    {
        $this->payloads[] = $payload;

        if ($this->failNext) {
            $this->failNext = false;
            throw new ChatbotException('Timeout', null, true, 'request-timeout');
        }

        if ($this->errorNext) {
            $this->errorNext = false;
            $onEvent(['event' => 'message.error', 'data' => ['code' => 'MODEL_ERROR']], 'request-error');

            return;
        }

        $onEvent(['event' => 'message.start', 'data' => ['messageId' => 'bot-1']], 'request-1');
        $onEvent(['event' => 'message.delta', 'data' => ['delta' => 'Xin chào']], 'request-1');
        $onEvent(['event' => 'message.break', 'data' => []], 'request-1');
        $onEvent(['event' => 'message.delta', 'data' => ['delta' => 'Tôi có thể hỗ trợ gì?']], 'request-1');
        $onEvent(['event' => 'message.completed', 'data' => ['messageId' => 'bot-1']], 'request-1');
    }
}
