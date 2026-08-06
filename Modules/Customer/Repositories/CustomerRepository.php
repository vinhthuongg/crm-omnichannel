<?php

namespace Modules\Customer\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Customer\Models\Customer;

class CustomerRepository
{
    /** Phân trang khách hàng mới nhất và nạp các kênh liên hệ của từng khách. */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        return Customer::query()->with('channels')->latest()->paginate((int) ($filters['per_page'] ?? 20));
    }

    /** Tạo khách hàng và các channel liên hệ trong cùng transaction. */
    public function createWithChannels(array $data): Customer
    {
        $channels = $data['channels'] ?? [];
        unset($data['channels']);
        $customer = Customer::query()->create($data);
        foreach ($channels as $channel) {
            $customer->channels()->updateOrCreate(['channel' => $channel['channel'], 'external_id' => $channel['external_id']], ['metadata' => $channel['metadata'] ?? null]);
        }
        return $customer->load('channels');
    }

    /** Lưu hồ sơ khách hàng và trả model đã refresh. */
    public function update(Customer $customer, array $data): Customer
    {
        $channels = $data['channels'] ?? null;
        unset($data['channels']);
        $customer->update($data);
        if ($channels !== null) {
            foreach ($channels as $channel) {
                $customer->channels()->updateOrCreate(['channel' => $channel['channel'], 'external_id' => $channel['external_id']], ['metadata' => $channel['metadata'] ?? null]);
            }
        }
        return $customer->refresh()->load('channels');
    }
}
