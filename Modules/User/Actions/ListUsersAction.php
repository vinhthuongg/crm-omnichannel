<?php

namespace Modules\User\Actions;

use Modules\User\Repositories\UserRepository;

class ListUsersAction
{
    public function __construct(private readonly UserRepository $repository)
    {
    }

    public function execute(array $filters): mixed
    {
        return $this->repository->paginate($filters);
    }
}