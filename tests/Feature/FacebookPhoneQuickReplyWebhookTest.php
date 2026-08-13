<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Facebook\DTO\FacebookWebhookMessageData;
use Modules\Message\Services\InboundMessageContextService;
use Tests\TestCase;

class FacebookPhoneQuickReplyWebhookTest extends TestCase
{
    use RefreshDatabase;

    /** Xác nhận số điện thoại khách chia sẻ qua Messenger được chuẩn hóa và lưu vào hồ sơ CRM. */
    public function test_shared_phone_number_is_saved_to_the_customer(): void
    {
        $data = FacebookWebhookMessageData::fromMessagingEvent([
            'sender' => ['id' => 'customer-psid'],
            'recipient' => ['id' => 'page-id'],
            'message' => [
                'mid' => 'mid.phone.1',
                'text' => '+84 912 345 678',
                'quick_reply' => ['payload' => '+84 912 345 678'],
            ],
        ], ['name' => 'Khách Messenger']);

        [$customer] = app(InboundMessageContextService::class)->resolve($data);

        $this->assertSame('0912345678', $customer->fresh()->phone);
        $this->assertNotNull($customer->fresh()->phone_collected_at);
        $this->assertSame('0912345678', data_get($data->metadata, 'shared_phone_number'));
    }
}
