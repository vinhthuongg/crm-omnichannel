<?php

namespace Modules\Auth\Actions;

use Modules\Auth\DTO\LoginData;
use Modules\Auth\Services\AuthService;

class LoginAction
{
    public function __construct(private readonly AuthService $service)
    {
    }

    public function execute(LoginData $data): array
    {
        return $this->service->login($data);
    }
}