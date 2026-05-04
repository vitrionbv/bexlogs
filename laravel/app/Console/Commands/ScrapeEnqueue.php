<?php

namespace App\Console\Commands;

use App\Models\BexSession;
use App\Models\ScrapeJob;
use App\Models\Subscription;
use App\Services\ScrapeEnqueueGuard;
use App\Services\ScrapeWindowPlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ScrapeEnqueue extends Command
{
    protected $signature = 'scrape:enqueue
        {--subscription= : Limit to a single subscription id}
        {--force : Skip the auto_scrape + interval check}';

    protected $description = 'Queue scrape jobs for any subscriptions whose interval has elapsed.';

    public function handle(ScrapeEnqueueGuard $guard, ScrapeWindowPlanner $planner): int
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

            // Application-level concurrency gate. Replaces the old DB-unique
            // SQLSTATE 23505 catch with a configurable check on
            // (max_concurrent_jobs, job_spacing_minutes). Denials are
            // benign — a sibling job is still doing useful work — so we
            // log at info level and move on.
            $decision = $guard->mayEnqueue($sub);
            if (! $decision->allowed) {
                Log::info('scrape:enqueue gated by concurrency guard', [
                    'subscription_id' => $sub->id,
                    'reason' => $decision->reason,
                    'retry_after_seconds' => $decision->retryAfterSeconds,
                ]);
                $skipped++;

                continue;
            }

            ScrapeJob::create([
                'subscription_id' => $sub->id,
                'bex_session_id' => $session->id,
                'status' => ScrapeJob::STATUS_QUEUED,
                'params' => $planner->buildParams($sub),
            ]);
            $queued++;
        }

        $this->info("queued={$queued} skipped={$skipped}");

        return self::SUCCESS;
    }
}
