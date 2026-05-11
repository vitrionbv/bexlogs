<?php

namespace App\Services;

use App\Http\Controllers\Api\WorkerController;
use App\Models\ScrapeJob;
use App\Models\Subscription;
use App\Models\SubscriptionBaseline;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Drift-signal detector for completed scrape_jobs. Three rules, each
 * computable from the persisted baseline + the recent scrape_jobs
 * history:
 *
 *   - DURATION_SPIKE      duration > 5× rolling p95
 *   - INSERT_RATE_DROP    rows_inserted < 0.1× same-hour rolling avg from past 7d
 *   - STOP_REASON_REGRESS stop_reason flipped to a worse class for last 3 runs
 *
 * The detector is consulted in two surfaces:
 *
 *   1. Dashboard "Needs attention" panel       (`forUser($user)`)
 *   2. Manage row badge                        (the same `forUser` payload,
 *                                              looked up by subscription_id)
 *
 * Both surfaces just read; the detector itself is pure (no DB writes,
 * no broadcasts). Alert delivery is owned by Agent 2 — we surface the
 * signal and let them decide if it's worth waking someone.
 *
 * Each detected signal carries a stable `kind` slug, a human-readable
 * `message`, and the `subscription_id` the operator can click through
 * with. Multiple signals per subscription are returned as separate
 * entries — the UI groups them on the read side.
 */
class AnomalyDetector
{
    public const KIND_DURATION_SPIKE = 'duration_spike';

    public const KIND_INSERT_RATE_DROP = 'insert_rate_drop';

    public const KIND_STOP_REASON_REGRESS = 'stop_reason_regress';

    /**
     * Severity ordering of `stats.stop_reason` values, lower index =
     * better. Mirrors the two-tier semantics documented in
     * WorkerController::STOP_REASONS:
     *
     *   - completions  duplicate_detection, caught_up, empty_window
     *                  (everything-is-fine signals)
     *   - completions  pagination_limit, time_limit
     *                  (budget exhausted; not necessarily a problem)
     *   - failures     pagination_error, token_missing, unparseable,
     *                  runaway_safety, session_expired, worker_reaped
     *                  (something went wrong)
     *
     * "Worse" means a higher index here — a flip from
     * `caught_up`(idx=1) → `pagination_error`(idx=4) on three
     * consecutive runs is the regression we surface.
     */
    private const STOP_REASON_RANK = [
        'duplicate_detection' => 0,
        'caught_up' => 1,
        'empty_window' => 2,
        'pagination_limit' => 3,
        'time_limit' => 3,
        'pagination_error' => 4,
        'token_missing' => 4,
        'unparseable' => 4,
        'runaway_safety' => 4,
        'session_expired' => 5,
        'worker_reaped' => 5,
    ];

    /**
     * Maximum age of a baseline before we suppress its drift signals.
     * `bex:compute-baselines` runs nightly; 36h gives one full miss
     * before signals go quiet. (Better silent than misleading: a
     * 7-day-old baseline against a fresh scrape would flag everything.)
     */
    private const MAX_BASELINE_AGE_HOURS = 36;

    /**
     * Detect drift signals across every subscription owned by the user.
     * The result is shaped for direct Inertia rendering.
     *
     * @return array<int, array{
     *   subscription_id: string,
     *   subscription_name: string,
     *   kind: string,
     *   message: string,
     *   detected_at: string,
     * }>
     */
    public function forUser(int $userId): array
    {
        $subs = Subscription::query()
            ->select('subscriptions.id', 'subscriptions.name', 'subscriptions.environment')
            ->join('applications', 'applications.id', '=', 'subscriptions.application_id')
            ->join('organizations', 'organizations.id', '=', 'applications.organization_id')
            ->where('organizations.user_id', $userId)
            ->orderBy('subscriptions.name')
            ->get();

        if ($subs->isEmpty()) {
            return [];
        }

        $subIds = $subs->pluck('id')->all();

        $baselines = SubscriptionBaseline::query()
            ->whereIn('subscription_id', $subIds)
            ->get()
            ->keyBy('subscription_id');

        // Pull the last 5 completed jobs per subscription. We do this
        // in PHP rather than via a window function so the same code
        // path runs on Postgres (prod) and SQLite (test driver). The
        // 5 × subscription_count cap is tiny in operator-facing
        // datasets — dozens of subs, never thousands.
        $recentJobs = $this->recentCompletedJobsForSubs($subIds, perSub: 5);

        $signals = [];

        foreach ($subs as $sub) {
            $baseline = $baselines->get($sub->id);
            $jobs = $recentJobs->get($sub->id) ?? collect();

            if ($jobs->isEmpty()) {
                continue;
            }

            foreach ($this->signalsForSubscription($sub, $baseline, $jobs) as $signal) {
                $signals[] = $signal;
            }
        }

        return $signals;
    }

    /**
     * Inspect one subscription's baseline + recent jobs and yield zero
     * or more drift signals.
     *
     * @param  Collection<int, ScrapeJob>  $jobs  most-recent first
     * @return iterable<int, array<string, mixed>>
     */
    private function signalsForSubscription(
        Subscription $sub,
        ?SubscriptionBaseline $baseline,
        Collection $jobs,
    ): iterable {
        $latest = $jobs->first();
        $latestStats = $latest->stats ?? [];

        $useBaseline = $baseline !== null
            && $baseline->computed_at !== null
            && $baseline->computed_at->diffInHours(Carbon::now(), absolute: true) <= self::MAX_BASELINE_AGE_HOURS;

        // Rule 1 — duration spike. Skip when the baseline is missing
        // or stale; skip when the latest job has no usable duration.
        if ($useBaseline && $baseline->duration_p95 !== null && $baseline->duration_p95 > 0) {
            $latestDuration = $this->durationMs($latest);
            $threshold = 5.0 * $baseline->duration_p95;

            if ($latestDuration !== null && $latestDuration > $threshold) {
                yield [
                    'subscription_id' => $sub->id,
                    'subscription_name' => $sub->name,
                    'kind' => self::KIND_DURATION_SPIKE,
                    'message' => sprintf(
                        'Last scrape took %.1fs — over 5× the rolling p95 (%.1fs).',
                        $latestDuration / 1000.0,
                        $baseline->duration_p95 / 1000.0,
                    ),
                    'detected_at' => ($latest->completed_at ?? Carbon::now())->toIso8601String(),
                ];
            }
        }

        // Rule 2 — insert-rate drop. Compare the latest run's
        // `rows_inserted` to the rolling 7-day average for the
        // hour-of-day it completed in. The 0.1× threshold is
        // intentionally aggressive: any time we drop below 10% of
        // expected we want to know.
        if ($useBaseline
            && is_array($baseline->same_hour_avg_rows_inserted ?? null)
            && $latest->completed_at !== null
            && isset($latestStats['rows_inserted'])
        ) {
            $hourKey = str_pad((string) $latest->completed_at->hour, 2, '0', STR_PAD_LEFT);
            $expected = $baseline->same_hour_avg_rows_inserted[$hourKey] ?? null;

            if (is_numeric($expected) && $expected > 0) {
                $observed = (int) $latestStats['rows_inserted'];

                if ($observed < 0.1 * $expected) {
                    yield [
                        'subscription_id' => $sub->id,
                        'subscription_name' => $sub->name,
                        'kind' => self::KIND_INSERT_RATE_DROP,
                        'message' => sprintf(
                            'Inserted %d rows; same-hour 7-day average is %.0f.',
                            $observed,
                            $expected,
                        ),
                        'detected_at' => $latest->completed_at->toIso8601String(),
                    ];
                }
            }
        }

        // Rule 3 — stop_reason regression. Look at the last three
        // completed runs in chronological order; if every step's
        // severity rank is non-decreasing AND the last is strictly
        // worse than the first, we have a regression. "All three
        // runs land on the same bad reason" also counts (e.g. three
        // `pagination_error` in a row is a stronger signal than one
        // outlier).
        $lastThree = $jobs
            ->take(3)
            ->reverse()
            ->values();

        if ($lastThree->count() === 3) {
            $reasons = $lastThree
                ->map(fn (ScrapeJob $j) => $j->stats['stop_reason'] ?? null)
                ->filter()
                ->values();

            if ($reasons->count() === 3) {
                $ranks = $reasons->map(fn (string $r) => self::STOP_REASON_RANK[$r] ?? null);

                $allKnown = $ranks->every(fn (?int $r) => $r !== null);

                if ($allKnown) {
                    $first = $ranks->first();
                    $last = $ranks->last();
                    $monotonic = $ranks
                        ->sliding(2)
                        ->every(fn (Collection $pair) => $pair->last() >= $pair->first());

                    $regressedFromBetter = $last > $first;
                    $allBadAndSame = $first >= 4 && $last === $first && $reasons->unique()->count() === 1;

                    if ($monotonic && ($regressedFromBetter || $allBadAndSame)) {
                        yield [
                            'subscription_id' => $sub->id,
                            'subscription_name' => $sub->name,
                            'kind' => self::KIND_STOP_REASON_REGRESS,
                            'message' => sprintf(
                                'Last 3 runs: %s — stop_reason worsened.',
                                $reasons->implode(' → '),
                            ),
                            'detected_at' => ($latest->completed_at ?? Carbon::now())->toIso8601String(),
                        ];
                    }
                }
            }
        }
    }

    /**
     * Group the last `perSub` completed jobs across many subscriptions,
     * keyed by subscription_id, most-recent first.
     *
     * Implementation: pull `perSub × subs × 5` rows (worst case) in one
     * query, then group/take in PHP. Window functions would be cheaper
     * but add a PG/SQLite branch we don't need at this size.
     *
     * @param  array<int, string>  $subIds
     * @return Collection<string, Collection<int, ScrapeJob>>
     */
    private function recentCompletedJobsForSubs(array $subIds, int $perSub): Collection
    {
        if (empty($subIds)) {
            return collect();
        }

        $rows = ScrapeJob::query()
            ->whereIn('subscription_id', $subIds)
            ->where('status', ScrapeJob::STATUS_COMPLETED)
            ->orderByDesc('id')
            ->limit(max(1, $perSub) * count($subIds) * 5)
            ->get(['id', 'subscription_id', 'started_at', 'completed_at', 'stats']);

        return $rows
            ->groupBy('subscription_id')
            ->map(fn (Collection $group) => $group->take($perSub)->values());
    }

    /**
     * Same fallback as BaselineCalculator: prefer the explicit
     * `stats.duration_ms` and fall back to (started_at, completed_at).
     */
    private function durationMs(ScrapeJob $job): ?int
    {
        $explicit = $job->stats['duration_ms'] ?? null;

        if (is_numeric($explicit) && $explicit > 0) {
            return (int) $explicit;
        }

        $start = $job->started_at;
        $end = $job->completed_at;

        if ($start === null || $end === null) {
            return null;
        }

        $diff = $end->getTimestamp() - $start->getTimestamp();

        return $diff > 0 ? $diff * 1000 : null;
    }

    /**
     * Public introspection helper — exposes the rank table so callers
     * (and tests) can sanity-check that the WorkerController's
     * STOP_REASONS list and our rank table stay in sync.
     *
     * @return array<string, int>
     */
    public static function stopReasonRanks(): array
    {
        return self::STOP_REASON_RANK;
    }

    /**
     * Sanity check at boot that every WorkerController STOP_REASONS
     * entry has a rank. Called from tests; not in the hot path.
     *
     * @return array<int, string>
     */
    public static function rankCoverage(): array
    {
        $missing = [];
        foreach (WorkerController::STOP_REASONS as $r) {
            if (! array_key_exists($r, self::STOP_REASON_RANK)) {
                $missing[] = $r;
            }
        }

        return $missing;
    }
}
