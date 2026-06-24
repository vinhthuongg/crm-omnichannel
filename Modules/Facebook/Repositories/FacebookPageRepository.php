<?php

namespace Modules\Facebook\Repositories;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Arr;
use Modules\Facebook\DTO\FacebookPageData;
use Modules\Facebook\Models\FacebookPage;

class FacebookPageRepository
{
    public function upsertForUser(User $user, FacebookPageData $data): FacebookPage
    {
        return FacebookPage::query()->updateOrCreate(
            ['page_id' => $data->id],
            [
                'user_id' => $user->id,
                'page_name' => $data->name,
                'page_access_token' => $data->accessToken,
                'page_avatar' => $data->avatar,
            ],
        );
    }

    public function markValid(FacebookPage $page, array $debugToken): FacebookPage
    {
        $expiresAt = (int) Arr::get($debugToken, 'expires_at');

        $page->forceFill([
            'meta_app_id' => Arr::get($debugToken, 'app_id'),
            'token_expires_at' => $expiresAt > 0 ? Carbon::createFromTimestamp($expiresAt) : null,
            'token_status' => 'valid',
            'token_invalid_at' => null,
            'token_last_error' => null,
        ])->save();

        return $page;
    }

    public function markInvalid(FacebookPage $page, string $error): FacebookPage
    {
        $page->forceFill([
            'token_status' => 'invalid',
            'token_invalid_at' => now(),
            'token_last_error' => $error,
        ])->save();

        return $page;
    }

    public function forUser(User $user): Collection
    {
        return FacebookPage::query()
            ->where('user_id', $user->id)
            ->latest()
            ->get();
    }

    public function pageIdsForUser(User $user): array
    {
        return $this->forUser($user)->pluck('page_id')->all();
    }

    public function findByPageId(string $pageId): ?FacebookPage
    {
        return FacebookPage::query()->where('page_id', $pageId)->first();
    }
}
