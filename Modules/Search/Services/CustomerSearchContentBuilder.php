<?php

namespace Modules\Search\Services;

use Illuminate\Support\Str;
use Modules\Customer\Models\Customer;

class CustomerSearchContentBuilder
{
    /** Ghép tên, liên hệ, kênh, nhãn, ghi chú và tin nhắn thành văn bản lập chỉ mục khách hàng. */
    public function build(Customer $customer): string
    {
        $parts = ['Khach hang: '.$customer->name, 'So dien thoai: '.($customer->phone ?: ''), 'Email: '.($customer->email ?: ''),
            'Kenh: '.$customer->channels->map(fn ($channel): string => $channel->channel.' '.$channel->external_id)->implode('; '),
            'Tag: '.$customer->tags->pluck('name')->implode('; '), 'Ghi chu: '.$customer->notes->pluck('body')->implode('; '),
            'Nội Dung Hội Thoại '.$customer->conversations->flatMap(fn ($conversation) => $conversation->messages)
                ->sortByDesc('created_at')->take((int) config('search.vector.customer_message_limit', 40))
                ->map(fn ($message): string => trim(($message->sender_type === 'customer' ? 'Khach: ' : 'Nhan vien: ').(string) $message->content))
                ->filter()->implode(' | ')];
        return collect($parts)->map(fn (string $part): string => Str::squish($part))->filter()->implode("\n");
    }
}
