<?php

namespace Modules\Auth\Actions;

use App\Models\User;
use Modules\Auth\Services\AuthService;

class LogoutAction
{
    public function __construct(private readonly AuthService $service)
    {
    }

    public function execute(User $user): void
    {
        $this->service->logout($user);
    }
}