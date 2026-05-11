<?php

namespace App\Console\Commands;

use App\Services\BaselineCalculator;
use Illuminate\Console\Command;

/**
 * Recompute per-subscription drift baselines from the last 30 days of
 * completed scrape_jobs. Persists into `subscription_baselines`.
 *
 * Wired into the schedule (routes/console.php) to run nightly. Also
 * safe to invoke ad-hoc:
 *
 *   php artisan bex:compute-baselines
 *
 * Output line mirrors the `bex:refresh-sessions` shape so the schedule
 * log stays scannable: `computed=N skipped=M`.
 */
class BexComputeBaselines extends Command
{
    protected $signature = 'bex:compute-baselines';

    protected $description = 'Recompute per-subscription rolling baselines for AnomalyDetector.';

    public function handle(BaselineCalculator $calc): int
    {
        $stats = $calc->recomputeAll();

        $this->info(sprintf(
            'computed=%d skipped=%d',
            $stats['computed'],
            $stats['skipped'],
        ));

        return self::SUCCESS;
    }
}
