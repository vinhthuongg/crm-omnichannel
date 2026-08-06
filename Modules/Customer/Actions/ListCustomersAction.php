<?php

namespace Modules\Customer\Actions;

use Modules\Customer\Repositories\CustomerRepository;

class ListCustomersAction
{
    /** Nhận CustomerRepository để đọc và lưu dữ liệu. */
    public function __construct(private readonly CustomerRepository $repository)
    {
    }

    /** Trả danh sách khách hàng phân trang theo từ khóa và bộ lọc. */
    public function execute(array $filters): mixed
    {
        return $this->repository->paginate($filters);
    }
}
