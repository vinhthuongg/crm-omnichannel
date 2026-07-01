<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory as Http;
use Modules\Facebook\Models\FacebookPage;

class FacebookHandoverStatusCommand extends Command
{
    protected $signature = 'facebook:handover-status {--page_id= : Facebook Page ID}';

    protected $description = 'Show Messenger handover receiver configuration for connected Facebook pages.';

    public function handle(Http $http): int
    {
        $pages = FacebookPage::query()
            ->when($this->option('page_id'), fn ($query, string $pageId) => $query->where('page_id', $pageId))
            ->whereNotNull('page_access_token')
            ->get(['id', 'page_id', 'page_name', 'page_access_token']);

        if ($pages->isEmpty()) {
            $this->warn('No connected Facebook page with page access token was found.');

            return self::SUCCESS;
        }

        foreach ($pages as $page) {
            $this->newLine();
            $this->line("Page: {$page->page_name} ({$page->page_id})");

            $response = $http
                ->connectTimeout(3)
                ->timeout(8)
                ->get($this->graphUrl('/me/messenger_profile'), [
                    'fields' => 'primary_receiver,secondary_receivers',
                    'access_token' => $page->page_access_token,
                ]);

            if ($response->failed()) {
                $this->error("Messenger profile request failed: HTTP {$response->status()}");
                $this->line($response->body());
                continue;
            }

            $payload = $response->json();
            $primary = is_array($payload) && is_array($payload['primary_receiver'] ?? null)
                ? $payload['primary_receiver']
                : null;
            $secondaryReceivers = is_array($payload)
                ? collect($payload['secondary_receivers'] ?? [])->filter(fn ($receiver) => is_array($receiver))->values()
                : collect();

            if (! $primary && $secondaryReceivers->isEmpty()) {
                $this->warn('No handover receivers found. Enable Messenger Conversation Routing/Handover on this Page.');
                continue;
            }

            if ($primary) {
                $this->info('Primary receiver: '.($primary['name'] ?? 'Unknown').' / '.($primary['id'] ?? 'missing id'));
            } else {
                $this->warn('Primary receiver: not configured');
            }

            if ($secondaryReceivers->isEmpty()) {
                $this->warn('Secondary receivers: none');
            } else {
                $this->line('Secondary receivers:');
                foreach ($secondaryReceivers as $receiver) {
                    $this->line('- '.($receiver['name'] ?? 'Unknown').' / '.($receiver['id'] ?? 'missing id'));
                }
            }
        }

        return self::SUCCESS;
    }

    private function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/'.((string) config('services.facebook.graph_version', 'v25.0')).$path;
    }
}
