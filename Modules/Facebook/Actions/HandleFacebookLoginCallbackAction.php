<?php

namespace Modules\Facebook\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Modules\Facebook\Repositories\FacebookAccountRepository;
use Modules\Facebook\Services\FacebookOAuthService;
use RuntimeException;

class HandleFacebookLoginCallbackAction
{
    public function __construct(
        private readonly FacebookOAuthService $facebook,
        private readonly FacebookAccountRepository $accounts,
    ) {
    }

    public function execute(string $code, string $state): User
    {
        $sessionState = session('facebook_oauth_state');
        $cachedStateExists = $state !== '' && Cache::pull('facebook_oauth_state:'.$state, false);

        if ($state === '' || ($state !== $sessionState && ! $cachedStateExists)) {
            throw new RuntimeException('Facebook OAuth state is invalid.');
        }

        session()->forget('facebook_oauth_state');

        $facebookUser = $this->facebook->userFromCode($code);
        $account = $this->accounts->findByFacebookUserId($facebookUser->id);
        $user = Auth::user() ?? $account?->user ?? $this->resolveUser($facebookUser->email, $facebookUser->name);

        $this->accounts->upsertForUser($user, $facebookUser);

        if (! Auth::check()) {
            Auth::login($user, true);
        }

        return $user;
    }

    private function resolveUser(?string $email, string $name): User
    {
        if ($email) {
            $existing = User::query()->where('email', $email)->first();

            if ($existing) {
                return $existing;
            }
        }

        return User::query()->create([
            'name' => $name,
            'email' => $email ?: 'facebook-'.Str::uuid().'@facebook.local',
            'password' => Str::password(32),
            'is_active' => true,
        ]);
    }
}
