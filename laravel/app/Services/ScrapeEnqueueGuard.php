<?php

namespace App\Services;

use App\Models\ScrapeJob;
use App\Models\Subscription;
use Illuminate\Support\Carbon;

/**
 * Enforces per-subscription scrape-job concurrency. Replaces the DB-level
 * partial unique index `scrape_jobs_active_unique_idx` (dropped in the
 * 2026_05_04_205500 migration) with two configurable knobs on the
 * subscription:
 *
 *   - `max_concurrent_jobs`   — hard cap on simultaneous queued/running jobs
 *   - `job_spacing_minutes`   — minimum delay between successive job starts
 *
 * Spacing rationale: when a subscription is large enough that a single
 * scrape can't keep up with its 5-minute scheduler tick, dispatching the
 * next job ten seconds after the first one started would mean every
 * concurrent job competes to crawl the same back-catalogue. Holding the
 * slot for `job_spacing_minutes` lets the first job push deeper into the
 * past on its own, then the next concurrent job picks a slim recent
 * window. The cross-job dedup (Pages are unique per subscription, so all
 * concurrent jobs share a `Page.id` and the `(page_id, content_hash)`
 * unique index naturally dedupes their writes) makes the worker's
 * `duplicate_detection` early-stop kick in once the new job catches up
 * to the old one's inserts.
 *
 * The guard is intentionally pure: no side effects, no DB writes, no
 * exceptions. Callers (`ScrapeEnqueue` command and `ManageController::
 * enqueueScrape`) decide whether to log/flash/skip on a denial.
 */
class ScrapeEnqueueGuard
{
    /**
     * Decide whether a new scrape job may be enqueued for this subscription
     * right now.
     */
    public function mayEnqueue(Subscription $subscription): MayEnqueueResult
    {
        $cap = max(1, (int) ($subscription->max_concurrent_jobs ?? 1));
        $spacingSeconds = max(60, (int) ($subscription->job_spacing_minutes ?? 10) * 60);

        $activeCount = ScrapeJob::query()
            ->where('subscription_id', $subscription->id)
            ->whereIn('status', [ScrapeJob::STATUS_QUEUED, ScrapeJob::STATUS_RUNNING])
            ->count();

        if ($activeCount >= $cap) {
            return MayEnqueueResult::denied(
                reason: 'concurrency_cap_reached',
                retryAfterSeconds: 60,
                message: "Concurrency cap reached: {$activeCount} of {$cap} slot(s) in use.",
            );
        }

        $mostRecent = ScrapeJob::query()
            ->where('subscription_id', $subscription->id)
            ->whereIn('status', [ScrapeJob::STATUS_QUEUED, ScrapeJob::STATUS_RUNNING])
            ->orderByDesc('id')
            ->first();

        if ($mostRecent === null) {
            return MayEnqueueResult::allowed();
        }

        // A queued-but-not-yet-picked-up row should never count against
        // the spacing window — it hasn't started crawling, so a second
        // queued row would race for the same back-catalogue. Wait for
        // the worker to pick it up first.
        if ($mostRecent->started_at === null) {
            return MayEnqueueResult::denied(
                reason: 'prior_job_not_yet_started',
                retryAfterSeconds: 30,
                message: 'A queued job is waiting for the worker to pick it up; no second job until it starts.',
            );
        }

        $startedAt = $mostRecent->started_at instanceof Carbon
            ? $mostRecent->started_at
            : Carbon::parse((string) $mostRecent->started_at);

        $elapsed = max(0, $startedAt->diffInSeconds(now(), absolute: false));
        if ($elapsed < $spacingSeconds) {
            $remaining = $spacingSeconds - $elapsed;
            $remainingMin = (int) ceil($remaining / 60);

            return MayEnqueueResult::denied(
                reason: 'within_spacing_window',
                retryAfterSeconds: $remaining,
                message: "Spacing window not yet elapsed: prior job started {$elapsed}s ago, "
                    ."retry in ~{$remainingMin}m.",
            );
        }

        return MayEnqueueResult::allowed();
    }
}
