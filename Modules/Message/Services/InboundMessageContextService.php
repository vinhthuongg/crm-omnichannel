<?php

namespace Modules\Message\Services;

use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\WorkShiftService;
use Modules\Conversation\Support\ConversationStatus;
use Modules\Customer\Models\Customer;
use Modules\Customer\Models\CustomerChannel;
use Modules\Message\DTO\InboundMessageData;

class InboundMessageContextService
{
    /** Nhận WorkShiftService để xác định ca trực và thành viên đang hoạt động. */
    public function __construct(private readonly WorkShiftService $shifts) {}

    /** Tìm hoặc tạo customer/channel/conversation và gán ca trực phù hợp cho tin nhắn đến. */
    public function resolve(InboundMessageData $data): array
    {
        $pageId = (string) data_get($data->metadata, 'facebook_page_id', '');
        $channel = CustomerChannel::query()->where('channel', $data->channel)->where('external_id', $data->externalCustomerId)->first();
        $customer = $channel?->customer ?? Customer::query()->create(['name' => $data->customerName, 'avatar' => $data->customerAvatar]);
        $this->refreshProfile($customer, $data);
        $customer->channels()->updateOrCreate(['channel' => $data->channel, 'external_id' => $data->externalCustomerId], ['metadata' => $data->metadata]);
        $conversation = Conversation::query()->where('customer_id', $customer->id)->where('facebook_page_id', $pageId !== '' ? $pageId : null)
            ->whereIn('status', ConversationStatus::ACTIVE)->latest('last_message_at')->first();
        if (! $conversation) {
            $shift = $this->shifts->currentShift();
            $conversation = Conversation::query()->create(['customer_id' => $customer->id, 'facebook_page_id' => $pageId !== '' ? $pageId : null,
                'status' => ConversationStatus::CUSTOMER_WAITING, 'last_message_at' => now(), 'work_shift_id' => $shift?->id,
                'owner_shift_id' => $shift?->id, 'queue_shift_id' => $shift?->id]);
        }
        return [$customer, $conversation];
    }

    /** Làm mới hồ sơ khách hàng từ dữ liệu kênh vừa nhận. */
    private function refreshProfile(Customer $customer, InboundMessageData $data): void
    {
        $updates = [];
        if ($data->customerName && ($customer->name === $data->externalCustomerId || blank($customer->name))) $updates['name'] = $data->customerName;
        if ($data->customerAvatar && $customer->avatar !== $data->customerAvatar) $updates['avatar'] = $data->customerAvatar;
        $sharedPhone = (string) data_get($data->metadata, 'shared_phone_number', '');
        if ($sharedPhone !== '' && $customer->phone !== $sharedPhone) $updates['phone'] = $sharedPhone;
        elseif (blank($customer->phone) && $phone = $this->extractPhone((string) $data->content)) $updates['phone'] = $phone;
        if ($updates) $customer->forceFill($updates)->save();
    }

    /** Trích số điện thoại đầu tiên từ nội dung tin nhắn để bổ sung hồ sơ khách hàng. */
    private function extractPhone(string $content): ?string
    {
        preg_match_all('/(?:\+?84|0)(?:[\s.\-()]?\d){8,10}/', $content, $matches);
        foreach ($matches[0] ?? [] as $candidate) {
            $phone = preg_replace('/\D+/', '', $candidate) ?: '';
            if (str_starts_with($phone, '84')) $phone = '0'.substr($phone, 2);
            if (preg_match('/^0\d{8,10}$/', $phone)) return $phone;
        }
        return null;
    }
}
