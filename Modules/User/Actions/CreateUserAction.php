<?php

namespace Modules\User\Actions;

use App\Models\User;
use Modules\User\Services\UserService;

class CreateUserAction
{
    public function __construct(private readonly UserService $service)
    {
    }

    public function execute(array $data): User
    {
        return $this->service->create($data);
    }
}