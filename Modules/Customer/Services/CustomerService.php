<?php

namespace Modules\Customer\Services;

use Modules\Customer\Models\Customer;
use Modules\Customer\Repositories\CustomerRepository;

class CustomerService
{
    /** Nhận CustomerRepository để đọc và lưu dữ liệu. */
    public function __construct(private readonly CustomerRepository $repository)
    {
    }

    /** Tạo khách hàng cùng các kênh liên hệ trong một transaction. */
    public function create(array $data): Customer
    {
        return $this->repository->createWithChannels($data);
    }

    /** Lưu thay đổi hồ sơ và đồng bộ lại các kênh liên hệ của khách hàng. */
    public function update(Customer $customer, array $data): Customer
    {
        return $this->repository->update($customer, $data);
    }
}
