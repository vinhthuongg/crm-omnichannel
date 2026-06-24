<?php

namespace App\Console\Commands;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Console\Command;
use Modules\Facebook\Models\FacebookPage;
use Modules\Facebook\Repositories\FacebookPageRepository;
use Modules\Facebook\Services\FacebookOAuthService;
use Modules\Facebook\Services\FacebookTokenValidationService;
use Modules\Facebook\Services\FacebookWebhookSubscriptionService;

class FacebookResubscribePagesCommand extends Command
{
    protected $signature = 'facebook:resubscribe-pages {--page_id= : Only resubscribe one Facebook page ID}';

    protected $description = 'Resubscribe connected Facebook pages to Messenger webhook fields.';

    public function handle(
        FacebookOAuthService $facebook,
        FacebookTokenValidationService $tokens,
        FacebookPageRepository $pages,
        FacebookWebhookSubscriptionService $webhooks,
    ): int {
        $this->line('Configuring app-level Page webhook...');

        try {
            $webhooks->ensureAppPageWebhook();
            $this->info('  App webhook OK: '.$webhooks->callbackUrl());
        } catch (\Throwable $exception) {
            $this->error('  App webhook FAILED: '.$exception->getMessage());

            return self::FAILURE;
        }

        $query = FacebookPage::query()->orderBy('id');

        if ($pageId = (string) $this->option('page_id')) {
            $query->where('page_id', $pageId);
        }

        $connectedPages = $query->get();

        if ($connectedPages->isEmpty()) {
            $this->warn('No connected Facebook pages found.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($connectedPages as $page) {
            $this->line("Resubscribing {$page->page_name} ({$page->page_id})...");

            try {
                $tokens->ensurePageBelongsToMessengerApp($page->messenger_app_id);
                $debugToken = $tokens->validatePageToken($page->page_access_token);
                $facebook->subscribePage($page->page_id, $page->page_access_token);

                $pages->markValid($page, $debugToken);
                $page->forceFill(['subscribed_at' => now()])->save();

                $this->info('  OK');
            } catch (\Throwable $exception) {
                $failed = true;
                if (! $exception instanceof ConnectionException) {
                    $pages->markInvalid($page, $exception->getMessage());
                }
                $this->error('  FAILED: '.$exception->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
