<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Facebook\Models\FacebookPage;

class FacebookWebhookStatusCommand extends Command
{
    protected $signature = 'facebook:webhook-status';

    protected $description = 'Show Facebook webhook callback URL and connected page subscription status.';

    /** Hiển thị callback URL, subscription và các field webhook Facebook hiện tại. */
    public function handle(): int
    {
        $appUrl = rtrim((string) config('app.url'), '/');

        $this->info('Facebook webhook setup');
        $this->line('Callback URL: '.$appUrl.'/api/webhook/facebook');
        $this->line('Verify token: '.((string) config('services.facebook.verify_token') !== '' ? 'set' : 'missing'));
        $this->newLine();

        $pages = FacebookPage::query()
            ->orderByDesc('id')
            ->get(['id', 'user_id', 'page_id', 'page_name', 'messenger_app_id', 'subscribed_at', 'token_status', 'token_last_error']);

        if ($pages->isEmpty()) {
            $this->warn('No connected Facebook pages found.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'user_id', 'page_id', 'page_name', 'messenger_app_id', 'subscribed_at', 'token_status', 'token_last_error'],
            $pages->map(fn (FacebookPage $page): array => [
                $page->id,
                $page->user_id,
                $page->page_id,
                $page->page_name,
                $page->messenger_app_id,
                optional($page->subscribed_at)->toDateTimeString(),
                $page->token_status,
                $page->token_last_error,
            ])->all(),
        );

        return self::SUCCESS;
    }
}
