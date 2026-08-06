<?php

namespace Modules\Message\Services;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Log;

class LocalOutboundQueueKicker
{
    /** Kích hoạt queue worker cục bộ để xử lý tin outbound đang chờ. */
    public function kick(): void
    {
        if (! app()->environment('local') || config('queue.default') !== 'database') return;
        app()->terminating(function (): void {
            try { app(Kernel::class)->call('queue:work', ['connection' => 'database', '--queue' => 'outbound,default',
                '--once' => true, '--sleep' => 0, '--tries' => 2, '--timeout' => 120]); }
            catch (\Throwable $e) { Log::warning('Local queue kick failed', ['error' => $e->getMessage()]); }
        });
    }
}
