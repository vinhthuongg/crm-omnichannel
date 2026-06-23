<?php

namespace Modules\Customer\Services;

use Modules\Customer\Models\Customer;
use Modules\Customer\Repositories\CustomerRepository;

class CustomerService
{
    public function __construct(private readonly CustomerRepository $repository)
    {
    }

    public function create(array $data): Customer
    {
        return $this->repository->createWithChannels($data);
    }

    public function update(Customer $customer, array $data): Customer
    {
        return $this->repository->update($customer, $data);
    }
}