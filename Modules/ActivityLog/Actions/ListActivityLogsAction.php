<?php

namespace Modules\ActivityLog\Actions;

use Modules\ActivityLog\Repositories\ActivityLogRepository;

class ListActivityLogsAction
{
    /** Nhận ActivityLogRepository để đọc và lưu dữ liệu. */
    public function __construct(private readonly ActivityLogRepository $repository)
    {
    }

    /** Truy vấn activity log theo bộ lọc và trả kết quả phân trang. */
    public function execute(array $filters): mixed
    {
        return $this->repository->paginate($filters);
    }
}
