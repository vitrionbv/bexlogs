<?php

namespace App\Services;

use App\Models\ScrapeJob;
use App\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Composite per-subscription health score, derived from the recent N
 * scrape_jobs rows. Three orthogonal signals, each clipped to [0, 1]:
 *
 *   - success_rate   completed / (completed + failed + cancelled), 0.5 weight
 *   - freshness      ratio of `scrape_interval_minutes` to time-since-last-success.
 *                    1.0 when the last successful scrape happened within the
 *                    interval; falls off linearly past 1× and bottoms out at
 *                    3× the interval. 0.3 weight.
 *   - stability      1 - rolling stddev of stats.rows_inserted, normalised by
 *                    the mean (coefficient of variation). Captures whether
 *                    insert-rate is steady or wildly bouncing. 0.2 weight.
 *
 * Final score = 0.5*success + 0.3*freshness + 0.2*stability, mapped to a
 * label via these cut-offs:
 *
 *   - healthy    >= 0.8
 *   - degraded   0.4 .. 0.8
 *   - unhealthy  < 0.4
 *
 * Pure read-only — no side effects, no caching here. Callers (Manage badge,
 * Insights page) decide if/where to memoise the result. The only DB query
 * is a single SELECT scoped by subscription_id + recent N, so even when the
 * Manage page calls it once per subscription the cost stays linear in
 * "number of subscriptions" — fine for the operator-facing dataset (dozens,
 * never thousands).
 */
class HealthScoreCalculator
{
    public const LABEL_HEALTHY = 'healthy';

    public const LABEL_DEGRADED = 'degraded';

    public const LABEL_UNHEALTHY = 'unhealthy';

    public const HEALTHY_THRESHOLD = 0.8;

    public const DEGRADED_THRESHOLD = 0.4;

    /**
     * Default lookback window when callers don't specify N. 50 rows is
     * roughly a week of data for a sub on a 5-minute interval, two weeks
     * on a 15-minute interval — long enough to surface meaningful drift,
     * short enough to react when an operator pauses a noisy sub.
     */
    public const DEFAULT_RECENT = 50;

    /**
     * Compute the score + label + breakdown for one subscription.
     *
     * @return array{
     *   score: float,
     *   label: string,
     *   components: array{success_rate: float, freshness: float, stability: float},
     *   sample_size: int,
     *   last_success_at: ?string,
     * }
     */
    public function forSubscription(Subscription $subscription, int $recent = self::DEFAULT_RECENT): array
    {
        $jobs = ScrapeJob::query()
            ->where('subscription_id', $subscription->id)
            ->orderByDesc('id')
            ->limit(max(1, $recent))
            ->get(['id', 'status', 'started_at', 'completed_at', 'stats']);

        // Empty history is its own baseline: not strictly "unhealthy" — the
        // sub may simply be brand new — but no signal either, so we surface
        // a neutral "degraded" label with a 0.5 score so the dot is
        // visually noticeable without being alarming. The Insights page
        // can show "no jobs yet" copy alongside.
        if ($jobs->isEmpty()) {
            return [
                'score' => 0.5,
                'label' => self::LABEL_DEGRADED,
                'components' => [
                    'success_rate' => 0.5,
                    'freshness' => 0.5,
                    'stability' => 0.5,
                ],
                'sample_size' => 0,
                'last_success_at' => null,
            ];
        }

        $completed = $jobs->where('status', ScrapeJob::STATUS_COMPLETED);
        $finals = $jobs->whereIn('status', [
            ScrapeJob::STATUS_COMPLETED,
            ScrapeJob::STATUS_FAILED,
            ScrapeJob::STATUS_CANCELLED,
        ]);

        $successRate = $finals->isEmpty()
            ? 0.5
            : $completed->count() / $finals->count();

        $lastSuccess = $completed
            ->filter(fn (ScrapeJob $j) => $j->completed_at !== null)
            ->sortByDesc(fn (ScrapeJob $j) => $j->completed_at?->getTimestamp())
            ->first();

        $freshness = $this->computeFreshness($subscription, $lastSuccess);
        $stability = $this->computeStability($completed);

        $score = 0.5 * $successRate + 0.3 * $freshness + 0.2 * $stability;
        $score = max(0.0, min(1.0, $score));

        return [
            'score' => round($score, 3),
            'label' => self::label($score),
            'components' => [
                'success_rate' => round($successRate, 3),
                'freshness' => round($freshness, 3),
                'stability' => round($stability, 3),
            ],
            'sample_size' => $jobs->count(),
            'last_success_at' => $lastSuccess?->completed_at?->toIso8601String(),
        ];
    }

    /**
     * Map a numeric score to one of the three labels.
     */
    public static function label(float $score): string
    {
        if ($score >= self::HEALTHY_THRESHOLD) {
            return self::LABEL_HEALTHY;
        }

        if ($score >= self::DEGRADED_THRESHOLD) {
            return self::LABEL_DEGRADED;
        }

        return self::LABEL_UNHEALTHY;
    }

    /**
     * Convert "minutes since last success" into a [0, 1] freshness score.
     * Returns 1.0 if the last success is within the configured interval,
     * 0.0 once we're past 3× the interval, linear in between. The 3×
     * fall-off matches operator intuition: "missed one cycle = warning,
     * missed three = something is broken".
     *
     * Subscriptions without a recent successful scrape (no completed
     * jobs in the window, or no completed_at on the latest success)
     * get freshness=0 — that's the strongest signal in the composite
     * when something has actually stopped working.
     */
    private function computeFreshness(Subscription $subscription, ?ScrapeJob $lastSuccess): float
    {
        if ($lastSuccess === null || $lastSuccess->completed_at === null) {
            return 0.0;
        }

        $interval = max(1, (int) ($subscription->scrape_interval_minutes ?? 60));

        $minutesSince = max(0.0, Carbon::now()
            ->diffInRealMinutes($lastSuccess->completed_at, absolute: true));

        if ($minutesSince <= $interval) {
            return 1.0;
        }

        // Linear ramp from 1.0 at 1× the interval to 0.0 at 3× the
        // interval, clamped below.
        $overdueRatio = ($minutesSince - $interval) / (2 * $interval);

        return max(0.0, 1.0 - $overdueRatio);
    }

    /**
     * Insert-rate stability — coefficient of variation of
     * `stats.rows_inserted` across recent completed jobs, inverted so a
     * steady stream maps to 1.0 and a wildly noisy one toward 0.0.
     *
     * Why CV (stddev / mean) rather than raw stddev: the absolute
     * spread scales with subscription volume — a sub that inserts ~10k
     * rows per scrape has a different "normal" stddev than one that
     * inserts ~10 rows per scrape. CV is volume-independent and gives
     * the same intuitive answer for both ("are inserts roughly the
     * same scrape-to-scrape, or is one outlier hitting 10× the next?").
     *
     * Empty / single-sample / all-zero windows return 0.5 (neutral)
     * rather than 1.0 — we don't have enough evidence to call them
     * "stable", and an all-zero window in particular is its own
     * problem signal even though stddev is 0.
     *
     * @param  Collection<int, ScrapeJob>  $completedJobs
     */
    private function computeStability(Collection $completedJobs): float
    {
        $rows = $completedJobs
            ->map(fn (ScrapeJob $j) => (int) ($j->stats['rows_inserted'] ?? 0))
            ->values()
            ->all();

        $count = count($rows);

        if ($count < 2) {
            return 0.5;
        }

        $mean = array_sum($rows) / $count;

        if ($mean <= 0.0) {
            return 0.5;
        }

        $variance = 0.0;
        foreach ($rows as $r) {
            $variance += ($r - $mean) ** 2;
        }
        $variance /= $count;

        $stddev = sqrt($variance);
        $cv = $stddev / $mean;

        // CV ≥ 1 is "as noisy as the mean itself" — clamp to a 0
        // stability score. CV = 0 (perfectly steady) maps to 1.0.
        return max(0.0, min(1.0, 1.0 - $cv));
    }
}
