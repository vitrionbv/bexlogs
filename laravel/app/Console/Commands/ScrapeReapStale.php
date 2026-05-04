<?php

namespace App\Console\Commands;

use App\Events\ScrapeJobUpdated;
use App\Models\ScrapeJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Reap `running` scrape_jobs whose worker died mid-flight.
 *
 * Source of truth for `last_heartbeat_at` freshness is the Node
 * scraper's per-job heartbeat ticker (see `scraper/src/heartbeat.ts`
 * + `runScrapeJob()` in `scraper/src/scrape.ts`). It fires every
 * `HEARTBEAT_INTERVAL_MS` (default 30 s) for the entire lifetime of
 * an in-flight job — covering browser startup, slow page loads, and
 * quiet pagination windows where no batch flush would otherwise
 * refresh the column. The default 3-minute reaper threshold below
 * leaves ~6× slack so a single missed tick (transient network blip)
 * doesn't trigger a false reap.
 *
 * If you find yourself wanting to reduce heartbeat load, raise this
 * threshold rather than lower the scraper's tick rate — the reaper
 * is the safer knob (a slow reap is just delayed recovery; a stale
 * threshold can falsely fail a live job).
 *
 * When the Docker stack redeploys (or the worker container is
 * OOM-killed) the row it was processing stays `running` forever —
 * and ScrapeEnqueue refuses to queue a fresh job while one is still
 * `running`, so the subscription silently stops updating.
 *
 * Running this every minute via the scheduler unwedges those rows by
 * flipping them to `failed` so the next `scrape:enqueue` tick can put
 * a fresh `queued` row in their place. Idempotent — a second pass
 * back-to-back finds nothing because the rows are no longer `running`.
 */
class ScrapeReapStale extends Command
{
    protected $signature = 'scrape:reap-stale
        {--minutes=3 : Consider a running job stale after this many minutes without a heartbeat.}';

    protected $description = 'Mark running scrape_jobs as failed when the worker has gone silent.';

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $threshold = now()->subMinutes($minutes);

        // Two cases of "stale":
        //   1. We have a heartbeat, but it's older than the threshold —
        //      the worker died after at least one batch.
        //   2. We never got a heartbeat, but `started_at` is older than
        //      the threshold — the worker died before its first batch.
        // A NULL heartbeat with a fresh `started_at` is just a job that
        // started <minutes> ago and hasn't shipped its first batch yet;
        // leave it alone.
        $stale = ScrapeJob::query()
            ->where('status', ScrapeJob::STATUS_RUNNING)
            ->where(function ($q) use ($threshold) {
                $q->where('last_heartbeat_at', '<', $threshold)
                    ->orWhere(function ($q2) use ($threshold) {
                        $q2->whereNull('last_heartbeat_at')
                            ->where('started_at', '<', $threshold);
                    });
            })
            ->get();

        if ($stale->isEmpty()) {
            $this->info('reaped=0');

            return self::SUCCESS;
        }

        $errorMessage = "Worker did not send a heartbeat for over {$minutes} minutes; job reaped as stale.";

        $reaped = 0;
        foreach ($stale as $job) {
            $reference = $job->last_heartbeat_at ?? $job->started_at;
            $minutesSilent = $reference
                ? (int) round($reference->diffInSeconds(now()) / 60)
                : null;

            // Stamp `stop_reason` into the existing stats blob so the Jobs
            // UI can show the "Worker reaped" badge alongside whatever
            // partial per-batch counters the worker managed to ship before
            // it died. Preserves prior keys (rows_received, batches, etc.).
            // Note: a worker batch landing between SELECT and UPDATE could
            // grow `stats` further; we'd lose those new counters here.
            // Acceptable trade-off — the bug we're fixing is much worse
            // (overwriting `completed` back to `failed`).
            $newStats = array_merge(
                $job->stats ?? [],
                ['stop_reason' => 'worker_reaped'],
            );

            // Atomic compare-and-set on `status='running'`. If a worker
            // /complete or /fail landed between our SELECT above and this
            // UPDATE, the row is no longer `running` and `affected` is 0
            // — the worker's terminal state stands and we skip the
            // broadcast/log so the UI doesn't see a `failed` event for a
            // job that already settled as `completed`.
            //
            // `stats` is JSON-encoded explicitly because Eloquent\Builder
            // ::update() bypasses model casts; passing a PHP array would
            // bind through PDO as text and Postgres would reject it for
            // the json column.
            $affected = ScrapeJob::query()
                ->where('id', $job->id)
                ->where('status', ScrapeJob::STATUS_RUNNING)
                ->update([
                    'status' => ScrapeJob::STATUS_FAILED,
                    'completed_at' => now(),
                    'error' => $errorMessage,
                    'stats' => json_encode($newStats),
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                Log::info('scrape:reap-stale skipped (worker won the race)', [
                    'job_id' => $job->id,
                    'subscription_id' => $job->subscription_id,
                ]);

                continue;
            }

            // Reflect the freshly-applied state in the in-memory model
            // so fromJob() emits the post-reap snapshot without an extra
            // SELECT (the relationship chain is already loaded eagerly
            // by the stale query).
            $job->status = ScrapeJob::STATUS_FAILED;
            $job->completed_at = now();
            $job->error = $errorMessage;
            $job->stats = $newStats;

            // Match the rest of the app: dispatch via broadcast() so the
            // sidebar/Jobs page refresh on Echo just like they do for
            // worker-driven completions and failures.
            broadcast(ScrapeJobUpdated::fromJob($job));

            Log::info('scrape:reap-stale reaped job', [
                'job_id' => $job->id,
                'subscription_id' => $job->subscription_id,
                'minutes_since_last_heartbeat' => $minutesSilent,
                'threshold_minutes' => $minutes,
            ]);

            $reaped++;
        }

        $this->info("reaped={$reaped}");

        return self::SUCCESS;
    }
}
