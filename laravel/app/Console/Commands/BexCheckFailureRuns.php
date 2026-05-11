<?php

namespace App\Console\Commands;

use App\Models\ScrapeJob;
use App\Models\Subscription;
use App\Services\AlertDelivery\SystemAlertEmitter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * B5: detect two infrastructure-level failure modes at the
 * subscription granularity and emit system alerts.
 *
 * Detection #1 — consecutive failures of the same kind:
 *   The last 3 finished scrape_jobs (completed OR failed) for a
 *   subscription are ALL `failed` AND share the same `stop_reason`.
 *   Three is the threshold because two failures could be coincidence
 *   (a worker pod crash + an unrelated 502); three with the same
 *   stop_reason is signal — usually a session expired without the
 *   worker noticing, a permanent configuration issue, or BE rolling
 *   out a breaking change.
 *
 * Detection #2 — quiet subscription:
 *   `auto_scrape = true` but `last_scraped_at IS NULL` OR older than
 *   24h. Catches the silent-failure mode where the scheduler is
 *   skipping a subscription (no usable session, repeatedly denied by
 *   the concurrency guard, etc.) and no scrape has succeeded in a
 *   day. Without this detector the operator only finds out when they
 *   notice their dashboard isn't updating.
 *
 * Both detections share the same command (one cron entry, one
 * artisan invocation) because the subscription scan they need is
 * identical and folding them keeps the cron schedule readable.
 *
 * Idempotency comes from {@see SystemAlertEmitter} — re-running this
 * command inside the dedupe window is a no-op for already-paged
 * subscriptions, so the 15-minute cron cadence doesn't generate a
 * page every cron tick during an extended outage.
 */
class BexCheckFailureRuns extends Command
{
    protected $signature = 'bex:check-failure-runs
        {--subscription= : Limit to a single subscription id}
        {--dry-run : Detect but do not dispatch alerts}';

    protected $description = 'Page operators about consecutive scrape failures and quiet (>24h) auto-scraped subscriptions.';

    /**
     * Number of consecutive failed scrape_jobs of the same
     * stop_reason that triggers the consecutive-failures alert.
     * Set as a const so tests can reference the same value.
     */
    public const FAILURE_RUN_THRESHOLD = 3;

    /**
     * Quiet-subscription threshold: an auto-scraped subscription
     * whose latest successful scrape is older than this is paged
     * even if no failures have been recorded (the scheduler may
     * just be silently skipping it).
     */
    public const QUIET_THRESHOLD_HOURS = 24;

    public function handle(SystemAlertEmitter $emitter): int
    {
        $now = CarbonImmutable::now();
        $quietThreshold = $now->subHours(self::QUIET_THRESHOLD_HOURS);

        $query = Subscription::query()
            ->with('application.organization.user');

        if ($id = $this->option('subscription')) {
            $query->where('id', $id);
        }

        $subs = $query->get();
        $checked = 0;
        $failureAlerts = 0;
        $quietAlerts = 0;

        foreach ($subs as $sub) {
            $checked++;
            $owner = $sub->application?->organization?->user;
            if ($owner === null) {
                continue;
            }

            $this->maybeAlertConsecutiveFailures(
                emitter: $emitter,
                sub: $sub,
                owner: $owner,
                counter: $failureAlerts,
            );

            // Only the auto-scrape path qualifies for a quiet-
            // subscription alert. A subscription with auto_scrape=false
            // is "quiet" by operator design — pinging the operator
            // about it would be cross-cutting noise.
            if ($sub->auto_scrape) {
                $this->maybeAlertQuietSubscription(
                    emitter: $emitter,
                    sub: $sub,
                    owner: $owner,
                    quietThreshold: $quietThreshold,
                    counter: $quietAlerts,
                );
            }
        }

        $this->info("checked={$checked} failure_alerts={$failureAlerts} quiet_alerts={$quietAlerts}");

        if (($failureAlerts + $quietAlerts) > 0) {
            Log::info('bex:check-failure-runs: emitted system alerts', [
                'checked' => $checked,
                'failure_alerts' => $failureAlerts,
                'quiet_alerts' => $quietAlerts,
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * Inspect the most recent finished scrape_jobs for `$sub` and
     * page if the last N share the same `stop_reason` and are all
     * failed. `$counter` is incremented in place for the summary line.
     */
    private function maybeAlertConsecutiveFailures(
        SystemAlertEmitter $emitter,
        Subscription $sub,
        \App\Models\User $owner,
        int &$counter,
    ): void {
        // Pull the most recent N finished jobs (completed or failed).
        // We can't filter on `failed` only — if the run goes
        // failed/failed/completed/failed/failed/failed we want the
        // completed in the middle to RESET the counter rather than
        // collapse 5 failed jobs into a streak.
        $recent = ScrapeJob::query()
            ->where('subscription_id', $sub->id)
            ->whereIn('status', [ScrapeJob::STATUS_COMPLETED, ScrapeJob::STATUS_FAILED])
            ->orderByDesc('id')
            ->limit(self::FAILURE_RUN_THRESHOLD)
            ->get();

        if ($recent->count() < self::FAILURE_RUN_THRESHOLD) {
            return;
        }

        if ($recent->contains(fn (ScrapeJob $j) => $j->status !== ScrapeJob::STATUS_FAILED)) {
            return;
        }

        // All N are failed — now require they share a stop_reason.
        // A streak of (session_expired, runaway_safety, pagination_error)
        // is still 3 unrelated failures and probably noise; only
        // same-cause streaks point at a fixable systemic issue.
        $reasons = $recent
            ->map(fn (ScrapeJob $j) => (string) ($j->stats['stop_reason'] ?? ''))
            ->unique();

        if ($reasons->count() !== 1) {
            return;
        }

        $reason = (string) $reasons->first();
        if ($reason === '') {
            // No stop_reason at all on any of the failed jobs — older
            // worker, generic exception path, etc. Without a reason
            // we can't even render a useful body, so we'd be paging
            // the operator with "3 failures, no idea why". Skip.
            return;
        }

        if ($this->option('dry-run')) {
            $this->line("would alert: subscription {$sub->id} has ".self::FAILURE_RUN_THRESHOLD." consecutive {$reason} failures");
            $counter++;

            return;
        }

        $emitter->emit(
            user: $owner,
            kind: SystemAlertEmitter::KIND_CONSECUTIVE_FAILURES,
            title: "Subscription «{$sub->name}» — ".self::FAILURE_RUN_THRESHOLD." consecutive {$reason}",
            body: "The last ".self::FAILURE_RUN_THRESHOLD." scrape jobs for {$sub->name} ({$sub->environment}) have all failed with stop_reason={$reason}. Investigate the underlying cause before the queue backs up.",
            context: [
                'subscription_id' => $sub->id,
                'subscription_name' => $sub->name,
                'environment' => $sub->environment,
                'stop_reason' => $reason,
                'recent_job_ids' => $recent->pluck('id')->all(),
            ],
            // Fingerprint segments by reason: a subscription that
            // flips from session_expired to runaway_safety pages
            // again under the new reason instead of staying suppressed
            // by the previous dedupe slot.
            fingerprint: "sub:{$sub->id}:reason:{$reason}",
        );

        $counter++;
    }

    /**
     * Page when an auto-scraped subscription hasn't seen a successful
     * scrape in the last 24h. `$counter` is incremented in place.
     */
    private function maybeAlertQuietSubscription(
        SystemAlertEmitter $emitter,
        Subscription $sub,
        \App\Models\User $owner,
        CarbonImmutable $quietThreshold,
        int &$counter,
    ): void {
        $lastScraped = $sub->last_scraped_at;
        if ($lastScraped !== null && $lastScraped->greaterThan($quietThreshold)) {
            return;
        }

        if ($this->option('dry-run')) {
            $this->line("would alert: subscription {$sub->id} quiet since ".($lastScraped?->toIso8601String() ?? 'never'));
            $counter++;

            return;
        }

        $description = $lastScraped === null
            ? "{$sub->name} ({$sub->environment}) has never completed a scrape despite auto_scrape being on. Check that a session is paired and that the scheduler isn't skipping it."
            : "{$sub->name} ({$sub->environment}) hasn't completed a scrape since {$lastScraped->toIso8601String()} (>24h). Auto-scrape is on but the scheduler appears to be skipping it.";

        $emitter->emit(
            user: $owner,
            kind: SystemAlertEmitter::KIND_QUIET_SUBSCRIPTION,
            title: "Subscription «{$sub->name}» — quiet for >".self::QUIET_THRESHOLD_HOURS."h",
            body: $description,
            context: [
                'subscription_id' => $sub->id,
                'subscription_name' => $sub->name,
                'environment' => $sub->environment,
                'last_scraped_at' => $lastScraped?->toIso8601String() ?? '(never)',
            ],
            fingerprint: "sub:{$sub->id}:quiet",
        );

        $counter++;
    }
}
