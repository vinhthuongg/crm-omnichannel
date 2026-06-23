<?php

namespace Modules\Customer\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Customer\Models\Customer;
use Modules\Customer\Models\CustomerChannel;

class CustomerRepository
{
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        return Customer::query()->with('channels')->latest()->paginate((int) ($filters['per_page'] ?? 20));
    }

    public function findByChannel(string $channel, string $externalId): ?Customer
    {
        return CustomerChannel::query()->where(compact('channel'))->where('external_id', $externalId)->first()?->customer;
    }

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