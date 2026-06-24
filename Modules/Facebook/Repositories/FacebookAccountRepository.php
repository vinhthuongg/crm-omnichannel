<?php

namespace Modules\Facebook\Repositories;

use App\Models\User;
use Modules\Facebook\DTO\FacebookUserData;
use Modules\Facebook\Models\FacebookAccount;

class FacebookAccountRepository
{
    public function upsertForUser(User $user, FacebookUserData $data): FacebookAccount
    {
        return FacebookAccount::query()->updateOrCreate(
            ['facebook_user_id' => $data->id],
            [
                'user_id' => $user->id,
                'name' => $data->name,
                'email' => $data->email,
                'access_token' => $data->accessToken,
                'refresh_token' => $data->refreshToken,
            ],
        );
    }

    public function findByFacebookUserId(string $facebookUserId): ?FacebookAccount
    {
        return FacebookAccount::query()->where('facebook_user_id', $facebookUserId)->first();
    }
}
