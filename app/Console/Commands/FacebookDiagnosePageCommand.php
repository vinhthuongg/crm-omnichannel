<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Modules\Facebook\Models\FacebookPage;
use Modules\Facebook\Services\FacebookWebhookSubscriptionService;

class FacebookDiagnosePageCommand extends Command
{
    protected $signature = 'facebook:diagnose-page {--page_id= : Facebook page ID to inspect}';

    protected $description = 'Diagnose Facebook page token, app subscription, webhook and latest outbound errors.';

    /** Chẩn đoán Page token, App subscription, Page subscription và lỗi outbound gần đây. */
    public function handle(Http $http, FacebookWebhookSubscriptionService $webhooks): int
    {
        $page = $this->page();

        if (! $page) {
            $this->error('No connected Facebook page found.');

            return self::FAILURE;
        }

        $this->info('Facebook page');
        $this->table(['field', 'value'], [
            ['page_id', $page->page_id],
            ['page_name', $page->page_name],
            ['stored_messenger_app_id', $page->messenger_app_id ?: '(empty)'],
            ['expected_messenger_app_id', (string) config('services.facebook.messenger_app_id')],
            ['token_status', $page->token_status ?: '(empty)'],
            ['token_last_error', $page->token_last_error ?: '(empty)'],
            ['subscribed_at', optional($page->subscribed_at)->toDateTimeString() ?: '(empty)'],
            ['app_url', (string) config('app.url')],
            ['webhook_callback', $webhooks->callbackUrl()],
        ]);

        $this->newLine();
        $this->diagnoseToken($http, $page);
        $this->newLine();
        $this->diagnoseAppSubscriptions($webhooks);
        $this->newLine();
        $this->diagnosePageSubscriptions($http, $page);
        $this->newLine();
        $this->latestOutboundErrors($page);

        return self::SUCCESS;
    }

    /** Thu thập và hiển thị dữ liệu chẩn đoán Facebook cho bước page. */
    private function page(): ?FacebookPage
    {
        $query = FacebookPage::query()->latest('id');

        if ($pageId = (string) $this->option('page_id')) {
            $query->where('page_id', $pageId);
        }

        return $query->first();
    }

    /** Thu thập và hiển thị dữ liệu chẩn đoán Facebook cho bước diagnoseToken. */
    private function diagnoseToken(Http $http, FacebookPage $page): void
    {
        $this->info('Page token debug');

        try {
            $response = $http
                ->connectTimeout(5)
                ->timeout(15)
                ->get($this->graphUrl('/debug_token'), [
                    'input_token' => $page->page_access_token,
                    'access_token' => $this->messengerAppAccessToken(),
                ]);

            $payload = $response->json();
            $data = (array) Arr::get($payload, 'data', []);

            $this->table(['field', 'value'], [
                ['http_status', (string) $response->status()],
                ['is_valid', (bool) Arr::get($data, 'is_valid') ? 'yes' : 'no'],
                ['token_app_id', (string) Arr::get($data, 'app_id', '(empty)')],
                ['expected_app_id', (string) config('services.facebook.messenger_app_id')],
                ['type', (string) Arr::get($data, 'type', '(empty)')],
                ['expires_at', $this->timestampLabel(Arr::get($data, 'expires_at'))],
                ['scopes', implode(', ', (array) Arr::get($data, 'scopes', [])) ?: '(empty)'],
                ['granular_scopes', $this->granularScopes($data)],
            ]);
        } catch (\Throwable $exception) {
            $this->error('Token debug failed: '.$exception->getMessage());
        }
    }

    /** Thu thập và hiển thị dữ liệu chẩn đoán Facebook cho bước diagnoseAppSubscriptions. */
    private function diagnoseAppSubscriptions(FacebookWebhookSubscriptionService $webhooks): void
    {
        $this->info('App page webhook subscription');

        try {
            $rows = collect($webhooks->pageSubscriptions())
                ->map(fn (array $subscription): array => [
                    (string) Arr::get($subscription, 'object', '(empty)'),
                    (string) Arr::get($subscription, 'callback_url', '(empty)'),
                    collect((array) Arr::get($subscription, 'fields', []))
                        ->map(fn ($field): string => is_array($field) ? (string) Arr::get($field, 'name') : (string) $field)
                        ->filter()
                        ->implode(', '),
                ])
                ->all();

            $this->table(['object', 'callback_url', 'fields'], $rows ?: [['(none)', '(none)', '(none)']]);
        } catch (\Throwable $exception) {
            $this->error('App subscription lookup failed: '.$exception->getMessage());
        }
    }

    /** Thu thập và hiển thị dữ liệu chẩn đoán Facebook cho bước diagnosePageSubscriptions. */
    private function diagnosePageSubscriptions(Http $http, FacebookPage $page): void
    {
        $this->info('Page subscribed apps');

        try {
            $response = $http
                ->connectTimeout(5)
                ->timeout(15)
                ->get($this->graphUrl('/'.$page->page_id.'/subscribed_apps'), [
                    'access_token' => $page->page_access_token,
                ]);

            $rows = collect((array) Arr::get($response->json(), 'data', []))
                ->map(fn (array $app): array => [
                    (string) Arr::get($app, 'id', '(empty)'),
                    (string) Arr::get($app, 'name', '(empty)'),
                    collect((array) Arr::get($app, 'subscribed_fields', []))->implode(', ') ?: '(empty)',
                ])
                ->all();

            $this->table(['app_id', 'name', 'subscribed_fields'], $rows ?: [['(none)', '(none)', '(none)']]);
        } catch (\Throwable $exception) {
            $this->error('Page subscribed apps lookup failed: '.$exception->getMessage());
        }
    }

    /** Thu thập và hiển thị dữ liệu chẩn đoán Facebook cho bước latestOutboundErrors. */
    private function latestOutboundErrors(FacebookPage $page): void
    {
        $this->info('Latest failed outbound messages');

        $rows = DB::table('messages')
            ->join('conversations', 'messages.conversation_id', '=', 'conversations.id')
            ->where('conversations.facebook_page_id', $page->page_id)
            ->where('messages.outbound_status', 'failed')
            ->latest('messages.id')
            ->limit(5)
            ->get([
                'messages.id',
                'messages.conversation_id',
                'messages.content',
                'messages.outbound_error',
                'messages.created_at',
            ])
            ->map(fn ($message): array => [
                $message->id,
                $message->conversation_id,
                str($message->content ?? '')->limit(40)->toString(),
                $message->outbound_error ?: '(empty)',
                $message->created_at,
            ])
            ->all();

        $this->table(['message_id', 'conversation_id', 'content', 'outbound_error', 'created_at'], $rows ?: [['(none)', '(none)', '(none)', '(none)', '(none)']]);
    }

    /** Thu thập và hiển thị dữ liệu chẩn đoán Facebook cho bước messengerAppAccessToken. */
    private function messengerAppAccessToken(): string
    {
        $appId = (string) config('services.facebook.messenger_app_id');
        $appSecret = (string) config('services.facebook.messenger_app_secret');

        if ($appId === '' || $appSecret === '') {
            throw new \RuntimeException('MESSENGER_APP_ID or MESSENGER_APP_SECRET is missing.');
        }

        return $appId.'|'.$appSecret;
    }

    /** Thu thập và hiển thị dữ liệu chẩn đoán Facebook cho bước graphUrl. */
    private function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/'.config('services.facebook.graph_version', 'v25.0').$path;
    }

    /** Thu thập và hiển thị dữ liệu chẩn đoán Facebook cho bước timestampLabel. */
    private function timestampLabel(mixed $timestamp): string
    {
        $value = (int) $timestamp;

        return $value > 0 ? Carbon::createFromTimestamp($value)->toDateTimeString() : '(none)';
    }

    /** Thu thập và hiển thị dữ liệu chẩn đoán Facebook cho bước granularScopes. */
    private function granularScopes(array $data): string
    {
        return collect((array) Arr::get($data, 'granular_scopes', []))
            ->map(function (array $scope): string {
                $targets = collect((array) Arr::get($scope, 'target_ids', []))->implode(',');

                return (string) Arr::get($scope, 'scope', '(empty)').($targets !== '' ? ':'.$targets : '');
            })
            ->implode(' | ') ?: '(empty)';
    }
}
