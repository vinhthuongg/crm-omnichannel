<?php

namespace Modules\Facebook\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Modules\Facebook\DTO\FacebookPageData;
use Modules\Facebook\Models\FacebookPage;
use Modules\Facebook\Models\FacebookAccount;
use Modules\Facebook\Repositories\FacebookPageRepository;
use Modules\Facebook\Services\FacebookOAuthService;
use Modules\Facebook\Services\FacebookTokenValidationService;
use Modules\Facebook\Services\FacebookWebhookSubscriptionService;

class ConnectFacebookPageAction
{
    public function __construct(
        private readonly FacebookPageRepository $pages,
        private readonly FacebookOAuthService $facebook,
        private readonly FacebookTokenValidationService $tokens,
        private readonly FacebookWebhookSubscriptionService $webhooks,
    ) {
    }

    public function execute(User $user, FacebookPageData $page): FacebookPage
    {
        $debugToken = $this->tokens->validatePageToken($page->accessToken);
        $avatar = $page->avatar;
        $account = FacebookAccount::query()->where('user_id', $user->id)->latest()->first();

        try {
            $avatar = $this->facebook->pagePicture($page->id, $page->accessToken) ?: $avatar;
        } catch (\Throwable $exception) {
            Log::warning('Facebook page picture lookup skipped', [
                'page_id' => $page->id,
                'error' => $exception->getMessage(),
            ]);
        }

        if (! $this->webhooks->hasCurrentPageWebhook()) {
            throw new \RuntimeException(
                'Facebook App webhook chua san sang cho tunnel hien tai. Hay chay php artisan facebook:resubscribe-pages roi thu lai.'
            );
        }

        $this->facebook->subscribePage($page->id, $page->accessToken);

        $connected = $this->pages->upsertForUser($user, new FacebookPageData(
            $page->id,
            $page->name,
            $page->accessToken,
            $avatar,
        ));
        $this->pages->markValid($connected, $debugToken);
        $connected->forceFill([
            'facebook_user_id' => $account?->facebook_user_id,
            'messenger_app_id' => config('services.facebook.messenger_app_id'),
        ])->save();
        $this->tokens->ensurePageBelongsToMessengerApp($connected->messenger_app_id);
        $connected->forceFill(['subscribed_at' => now()])->save();

        return $connected;
    }
}
