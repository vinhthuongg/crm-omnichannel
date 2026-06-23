<?php

namespace Modules\User\Repositories;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class UserRepository
{
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        return User::query()->with('roles')->latest()->paginate((int) ($filters['per_page'] ?? 20));
    }

    public function create(array $data): User
    {
        return User::query()->create($data);
    }

    public function update(User $user, array $data): User
    {
        $user->update($data);
        return $user->refresh();
    }
}