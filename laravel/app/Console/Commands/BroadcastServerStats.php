<?php

namespace App\Console\Commands;

use App\Events\ServerStatsUpdated;
use App\Services\ServerMetrics;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sample host metrics once and broadcast them on the admin dashboard
 * channel. Driven by the sub-minute scheduler in routes/console.php.
 *
 * Broadcast failures are downgraded to debug logs — Reverb being briefly
 * unavailable should never fail the scheduler tick (which is also driving
 * unrelated work like scrape:enqueue and bex:refresh-sessions).
 */
class BroadcastServerStats extends Command
{
    protected $signature = 'server-stats:broadcast';

    protected $description = 'Sample host CPU/memory/disk and broadcast to the admin dashboard';

    public function handle(ServerMetrics $metrics): int
    {
        try {
            ServerStatsUpdated::dispatch($metrics->snapshot());
        } catch (BroadcastException $e) {
            Log::debug('server-stats broadcast skipped', ['error' => $e->getMessage()]);
        }

        return self::SUCCESS;
    }
}
