<?php

namespace Modules\Customer\Actions;

use Modules\Customer\Models\Customer;
use Modules\Customer\Services\CustomerService;

class UpdateCustomerAction
{
    /** Nhận CustomerService để tạo và cập nhật hồ sơ khách hàng. */
    public function __construct(private readonly CustomerService $service)
    {
    }

    /** Cập nhật tên, liên hệ và metadata của khách hàng được chọn. */
    public function execute(Customer $customer, array $data): Customer
    {
        return $this->service->update($customer, $data);
    }
}
