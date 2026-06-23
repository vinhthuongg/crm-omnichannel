<?php

namespace Modules\User\Actions;

use App\Models\User;
use Modules\User\Services\UserService;

class UpdateUserAction
{
    public function __construct(private readonly UserService $service)
    {
    }

    public function execute(User $user, array $data): User
    {
        return $this->service->update($user, $data);
    }
}