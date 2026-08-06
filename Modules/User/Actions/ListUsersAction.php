<?php

namespace Modules\User\Actions;

use Modules\User\Repositories\UserRepository;

class ListUsersAction
{
    /** Nhận UserRepository để đọc và lưu dữ liệu. */
    public function __construct(private readonly UserRepository $repository)
    {
    }

    /** Trả danh sách người dùng phân trang theo từ khóa và bộ lọc. */
    public function execute(array $filters): mixed
    {
        return $this->repository->paginate($filters);
    }
}
