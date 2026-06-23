<?php

namespace Modules\ActivityLog\Actions;

use Modules\ActivityLog\Repositories\ActivityLogRepository;

class ListActivityLogsAction
{
    public function __construct(private readonly ActivityLogRepository $repository)
    {
    }

    public function execute(array $filters): mixed
    {
        return $this->repository->paginate($filters);
    }
}