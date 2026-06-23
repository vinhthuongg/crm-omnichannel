<?php

namespace Modules\User\Services;

use App\Models\User;
use Modules\User\Repositories\UserRepository;

class UserService
{
    public function __construct(private readonly UserRepository $repository)
    {
    }

    public function create(array $data): User
    {
        $role = $data['role'];
        unset($data['role']);
        $user = $this->repository->create($data);
        $user->assignRole($role);
        return $user->load('roles');
    }

    public function update(User $user, array $data): User
    {
        $role = $data['role'] ?? null;
        unset($data['role']);
        $user = $this->repository->update($user, $data);
        if ($role) {
            $user->syncRoles([$role]);
        }
        return $user->load('roles');
    }
}