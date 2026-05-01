<?php

namespace App\Console\Commands;

use App\Models\BexSession;
use App\Models\ScrapeJob;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class ScrapeEnqueue extends Command
{
    protected $signature = 'scrape:enqueue
        {--subscription= : Limit to a single subscription id}
        {--force : Skip the auto_scrape + interval check}';

    protected $description = 'Queue scrape jobs for any subscriptions whose interval has elapsed.';

    public function handle(): int
    {
        $query = Subscription::query();
        if ($id = $this->option('subscription')) {
            $query->where('id', $id);
        } elseif (! $this->option('force')) {
            $query->where('auto_scrape', true);
        }

        $subs = $query->get();
        if ($subs->isEmpty()) {
            $this->info('No subscriptions matched.');

            return self::SUCCESS;
        }

        $queued = 0;
        $skipped = 0;

        foreach ($subs as $sub) {
            if (! $this->option('force')
                && $sub->last_scraped_at
                && $sub->last_scraped_at->copy()->addMinutes($sub->scrape_interval_minutes)->isFuture()
            ) {
                $skipped++;

                continue;
            }

            $session = BexSession::query()
                ->whereHas(
                    'user',
                    fn ($q) => $q->whereHas(
                        'organizations',
                        fn ($q) => $q->whereHas(
                            'applications',
                            fn ($q) => $q->where('id', $sub->application_id),
                        ),
                    ),
                )
                ->where('environment', $sub->environment)
                ->whereNull('expired_at')
                ->latest('captured_at')
                ->first();

            if (! $session) {
                $this->warn("subscription {$sub->id}: no active session for {$sub->environment}, skipping");
                $skipped++;

                continue;
            }

            // Fast-path: skip the INSERT entirely if we can already see a
            // queued/running row. The DB partial unique index
            // `scrape_jobs_active_unique_idx` is the airtight guard for
            // the SELECT-then-INSERT race (e.g. scheduler tick colliding
            // with a manual "Scrape now" click within the same second).
            $alreadyQueued = ScrapeJob::query()
                ->where('subscription_id', $sub->id)
                ->whereIn('status', [ScrapeJob::STATUS_QUEUED, ScrapeJob::STATUS_RUNNING])
                ->exists();
            if ($alreadyQueued) {
                $skipped++;

                continue;
            }

            try {
                ScrapeJob::create([
                    'subscription_id' => $sub->id,
                    'bex_session_id' => $session->id,
                    'status' => ScrapeJob::STATUS_QUEUED,
                    'params' => $this->buildParams($sub),
                ]);
                $queued++;
            } catch (QueryException $e) {
                if ($e->getCode() !== '23505') {
                    throw $e;
                }
                // Concurrent request beat us to the insert; the partial
                // unique index caught it. Same outcome as the fast-path
                // above — skip cleanly, log for observability.
                Log::info('scrape:enqueue skipped (active job already exists)', [
                    'subscription_id' => $sub->id,
                ]);
                $skipped++;
            }
        }

        $this->info("queued={$queued} skipped={$skipped}");

        return self::SUCCESS;
    }

    /**
     * Default scrape window:
     *  - subsequent scrapes: from last_scraped_at minus a 30-min lookback to
     *    tolerate clock skew + late-arriving log entries.
     *  - first scrape: go back `lookback_days_first_scrape` (default 30).
     *
     * Per-subscription overrides for max_pages and max_duration also feed
     * into the worker so noisy subscriptions can't burn the whole budget.
     *
     * @return array<string, string|int>
     */
    private function buildParams(Subscription $sub): array
    {
        $start = $sub->last_scraped_at
            ? $sub->last_scraped_at->copy()->subMinutes(30)->toIso8601String()
            : now()->subDays($sub->lookback_days_first_scrape ?? 30)->toIso8601String();

        return [
            'start_time' => $start,
            'end_time' => now()->toIso8601String(),
            'max_pages' => (int) ($sub->max_pages_per_scrape ?? 200),
            'max_duration_minutes' => (int) ($sub->max_duration_minutes ?? 10),
        ];
    }
}
