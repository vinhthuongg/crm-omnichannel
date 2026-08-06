<?php

namespace Modules\Customer\Actions;

use Modules\Customer\Models\Customer;
use Modules\Customer\Services\CustomerService;

class CreateCustomerAction
{
    /** Nhận CustomerService để tạo và cập nhật hồ sơ khách hàng. */
    public function __construct(private readonly CustomerService $service)
    {
    }

    /** Tạo hồ sơ khách hàng mới từ dữ liệu đã xác thực. */
    public function execute(array $data): Customer
    {
        return $this->service->create($data);
    }
}
