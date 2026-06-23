<?php

namespace Modules\Customer\Actions;

use Modules\Customer\Models\Customer;
use Modules\Customer\Services\CustomerService;

class CreateCustomerAction
{
    public function __construct(private readonly CustomerService $service)
    {
    }

    public function execute(array $data): Customer
    {
        return $this->service->create($data);
    }
}