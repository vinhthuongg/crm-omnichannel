<?php

namespace Modules\Customer\Actions;

use Modules\Customer\Models\Customer;
use Modules\Customer\Services\CustomerService;

class UpdateCustomerAction
{
    public function __construct(private readonly CustomerService $service)
    {
    }

    public function execute(Customer $customer, array $data): Customer
    {
        return $this->service->update($customer, $data);
    }
}