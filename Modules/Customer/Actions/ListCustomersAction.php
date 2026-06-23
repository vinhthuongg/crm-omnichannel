<?php

namespace Modules\Customer\Actions;

use Modules\Customer\Repositories\CustomerRepository;

class ListCustomersAction
{
    public function __construct(private readonly CustomerRepository $repository)
    {
    }

    public function execute(array $filters): mixed
    {
        return $this->repository->paginate($filters);
    }
}