<?php

namespace Modules\Auth\Actions;

use App\Models\User;
use Modules\Auth\Services\AuthService;

class ChangePasswordAction
{
    public function __construct(private readonly AuthService $service)
    {
    }

    public function execute(User $user, string $password): void
    {
        $this->service->changePassword($user, $password);
    }
}