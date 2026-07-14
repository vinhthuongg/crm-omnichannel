<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Customer\Models\Customer;
use Modules\Message\DTO\InboundMessageData;
use Modules\Message\Models\Message;
use Modules\Message\Services\MessageService;

class LoadTestChatCommand extends Command
{
    protected $signature = 'crm:load-test-chat
        {--count=30 : Number of simulated customers}
        {--interval=1 : Seconds between inbound messages}
        {--timeout=90 : Seconds to wait for bot replies}
        {--page_id= : Facebook page id to attach to simulated messages}
        {--message=Anh cần tư vấn xe Toyota : Base message content}
        {--cleanup : Delete simulated customers, conversations and messages after the report}';

    protected $description = 'Simulate multiple inbound chat messages and measure CRM/Botpress reply delay.';

    public function handle(MessageService $messages): int
    {
        $count = max(1, (int) $this->option('count'));
        $interval = max(0.0, (float) $this->option('interval'));
        $timeout = max(5, (int) $this->option('timeout'));
        $pageId = trim((string) $this->option('page_id')) ?: $this->defaultPageId();
        $baseMessage = trim((string) $this->option('message')) ?: 'Anh cần tư vấn xe Toyota';
        $runId = now()->format('YmdHis').'_'.Str::lower(Str::random(6));
        $startedAt = microtime(true);
        $rows = [];

        Cache::put('crm_load_test_skip_botpress_outbound', true, now()->addMinutes(15));

        $this->info("CRM chat load test started: {$count} messages, {$interval}s interval, run {$runId}");
        $this->line('Botpress replies will be stored in CRM, but outbound Facebook send is skipped for test messages.');

        try {
            for ($i = 1; $i <= $count; $i++) {
                $externalId = sprintf('crm_loadtest_%s_%02d', $runId, $i);
                $content = sprintf('%s #%02d', $baseMessage, $i);
                $sentAt = microtime(true);

                $message = $messages->storeInbound(new InboundMessageData(
                    channel: 'facebook',
                    externalCustomerId: $externalId,
                    customerName: 'Load Test '.$i,
                    customerAvatar: null,
                    content: $content,
                    messageType: 'text',
                    attachments: [],
                    externalMessageId: 'crm_loadtest_mid_'.$runId.'_'.$i,
                    metadata: [
                        'facebook_page_id' => $pageId,
                        'load_test' => true,
                        'run_id' => $runId,
                        'sequence' => $i,
                    ],
                ));

                $storedAt = microtime(true);
                $rows[$message->id] = [
                    'seq' => $i,
                    'customer_external_id' => $externalId,
                    'conversation_id' => $message->conversation_id,
                    'inbound_message_id' => $message->id,
                    'inbound_content' => $content,
                    'sent_at_ms' => $this->ms($sentAt - $startedAt),
                    'inbound_store_ms' => $this->ms($storedAt - $sentAt),
                    'server_realtime_ready_ms' => $this->ms($storedAt - $sentAt),
                    'bot_reply_message_id' => null,
                    'bot_reply_detect_ms' => null,
                    'bot_reply_total_ms' => null,
                    'bot_reply_preview' => null,
                    'outbound_status' => null,
                ];

                $this->line(sprintf('[%02d/%02d] inbound stored: message=%d conversation=%d', $i, $count, $message->id, $message->conversation_id));

                if ($i < $count && $interval > 0) {
                    usleep((int) ($interval * 1_000_000));
                }
            }

            $deadline = microtime(true) + $timeout;

            while (microtime(true) < $deadline && $this->pendingCount($rows) > 0) {
                $this->collectReplies($rows, $startedAt);
                usleep(500_000);
            }

            $this->collectReplies($rows, $startedAt);
            $this->printReport($rows, $runId);
            $this->writeReport($rows, $runId, $startedAt);

            if ((bool) $this->option('cleanup')) {
                $this->cleanup($runId);
                $this->line('Test data cleaned.');
            }
        } finally {
            Cache::forget('crm_load_test_skip_botpress_outbound');
        }

        return self::SUCCESS;
    }

    private function defaultPageId(): string
    {
        return (string) (DB::table('facebook_pages')->where('token_status', 'valid')->value('page_id')
            ?: DB::table('facebook_pages')->value('page_id')
            ?: 'load_test_page');
    }

    private function collectReplies(array &$rows, float $startedAt): void
    {
        foreach ($rows as $inboundId => &$row) {
            if ($row['bot_reply_message_id']) {
                continue;
            }

            $reply = Message::query()
                ->where('conversation_id', $row['conversation_id'])
                ->where('sender_type', 'system')
                ->where('id', '>', $inboundId)
                ->orderBy('id')
                ->first(['id', 'content', 'outbound_status', 'created_at']);

            if (! $reply) {
                continue;
            }

            $detectedAt = microtime(true);
            $row['bot_reply_message_id'] = $reply->id;
            $row['bot_reply_detect_ms'] = $this->ms($detectedAt - $startedAt);
            $row['bot_reply_total_ms'] = max(0, $row['bot_reply_detect_ms'] - $row['sent_at_ms']);
            $row['bot_reply_preview'] = Str::limit((string) $reply->content, 80);
            $row['outbound_status'] = $reply->outbound_status;
        }
    }

    private function pendingCount(array $rows): int
    {
        return collect($rows)->whereNull('bot_reply_message_id')->count();
    }

    private function printReport(array $rows, string $runId): void
    {
        $answered = collect($rows)->whereNotNull('bot_reply_message_id');
        $delays = $answered->pluck('bot_reply_total_ms')->filter(fn ($value) => is_numeric($value))->values();

        $this->newLine();
        $this->info('CRM chat load test report: '.$runId);
        $this->table(
            ['#', 'Conv', 'Inbound', 'Bot Reply', 'Store ms', 'Bot total ms', 'Outbound', 'Preview'],
            collect($rows)->map(fn (array $row): array => [
                $row['seq'],
                $row['conversation_id'],
                $row['inbound_message_id'],
                $row['bot_reply_message_id'] ?: 'timeout',
                $row['inbound_store_ms'],
                $row['bot_reply_total_ms'] ?? 'timeout',
                $row['outbound_status'] ?: '-',
                $row['bot_reply_preview'] ?: '-',
            ])->values()->all()
        );

        $this->line('Answered: '.$answered->count().'/'.count($rows));
        if ($delays->isNotEmpty()) {
            $this->line('Bot delay avg: '.round($delays->avg()).'ms');
            $this->line('Bot delay min: '.$delays->min().'ms');
            $this->line('Bot delay max: '.$delays->max().'ms');
        }
        $this->line('Server realtime ready ms = time to store inbound + broadcast server event. Real mobile latency must be checked from app WebSocket logs.');
    }

    private function writeReport(array $rows, string $runId, float $startedAt): void
    {
        $path = storage_path('logs/chat-load-test-'.$runId.'.json');
        file_put_contents($path, json_encode([
            'run_id' => $runId,
            'started_at' => now()->toIso8601String(),
            'duration_ms' => $this->ms(microtime(true) - $startedAt),
            'rows' => array_values($rows),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->line('Report file: '.$path);
    }

    private function cleanup(string $runId): void
    {
        $customerIds = DB::table('customer_channels')
            ->where('external_id', 'like', 'crm_loadtest_'.$runId.'_%')
            ->pluck('customer_id')
            ->unique()
            ->values();

        if ($customerIds->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($customerIds): void {
            $conversationIds = DB::table('conversations')
                ->whereIn('customer_id', $customerIds)
                ->pluck('id');

            DB::table('jobs')
                ->where('payload', 'like', '%crm_loadtest_%')
                ->delete();
            DB::table('conversation_reply_suggestions')->whereIn('conversation_id', $conversationIds)->delete();
            DB::table('conversation_tag')->whereIn('conversation_id', $conversationIds)->delete();
            DB::table('conversation_user_access')->whereIn('conversation_id', $conversationIds)->delete();
            DB::table('messages')->whereIn('conversation_id', $conversationIds)->delete();
            DB::table('conversations')->whereIn('id', $conversationIds)->delete();
            DB::table('customer_channels')->whereIn('customer_id', $customerIds)->delete();
            DB::table('customer_customer_tag')->whereIn('customer_id', $customerIds)->delete();
            Customer::query()->whereIn('id', $customerIds)->delete();
        });
    }

    private function ms(float $seconds): int
    {
        return (int) round($seconds * 1000);
    }
}
