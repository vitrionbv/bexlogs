<?php

namespace App\Services;

use App\Models\ScrapeJob;
use App\Models\Subscription;
use App\Models\SubscriptionBaseline;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Recomputes per-subscription baselines from the last 30 days of completed
 * scrape_jobs. Runs nightly via `bex:compute-baselines`. Pure
 * read-then-upsert; no events, no broadcasts.
 *
 * Computation is in PHP (single SELECT per subscription scoped by
 * `completed_at` window, then linear-interpolated percentiles in memory).
 * The 30-day window keeps the row count bounded — even a sub on a
 * 1-minute interval tops out at ~43k rows, well below the threshold
 * where shipping the data to PHP would matter. Same code path runs on
 * Postgres (prod) and SQLite (test driver) so we don't have to maintain
 * two parallel implementations.
 *
 * Linear interpolation matches Postgres's `percentile_cont` exactly on
 * the same input — the test suite locks in that the SQLite-driven
 * results are byte-for-byte identical to what PG would have computed.
 */
class BaselineCalculator
{
    /**
     * Window length for the percentile sample.
     */
    public const PERCENTILE_DAYS = 30;

    /**
     * Window length for the per-hour average sample.
     */
    public const SAME_HOUR_DAYS = 7;

    /**
     * Recompute and upsert baselines for every subscription that has at
     * least one completed scrape_job inside the percentile window.
     *
     * @return array{computed:int, skipped:int}
     */
    public function recomputeAll(?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        $subs = Subscription::query()
            ->orderBy('id')
            ->get(['id', 'scrape_interval_minutes']);

        $computed = 0;
        $skipped = 0;

        foreach ($subs as $sub) {
            $result = $this->recomputeOne($sub, $now);

            if ($result === null) {
                $skipped++;

                continue;
            }

            $computed++;
        }

        return ['computed' => $computed, 'skipped' => $skipped];
    }

    /**
     * Recompute and upsert a baseline for one subscription. Returns the
     * persisted model, or null when there are no completed jobs in the
     * window (the row is left alone — a stale baseline beats no signal).
     */
    public function recomputeOne(Subscription $subscription, ?Carbon $now = null): ?SubscriptionBaseline
    {
        $now ??= Carbon::now();

        $percentileFrom = $now->copy()->subDays(self::PERCENTILE_DAYS);
        $sameHourFrom = $now->copy()->subDays(self::SAME_HOUR_DAYS);

        $rows = ScrapeJob::query()
            ->where('subscription_id', $subscription->id)
            ->where('status', ScrapeJob::STATUS_COMPLETED)
            ->where('completed_at', '>=', $percentileFrom)
            ->whereNotNull('completed_at')
            ->orderBy('completed_at')
            ->get(['id', 'started_at', 'completed_at', 'stats'])
            ->map(fn (ScrapeJob $j) => [
                'completed_at' => $j->completed_at,
                'duration_ms' => $this->durationMs($j),
                'rows_inserted' => isset($j->stats['rows_inserted'])
                    ? (int) $j->stats['rows_inserted']
                    : null,
            ]);

        if ($rows->isEmpty()) {
            return null;
        }

        $durations = $rows->pluck('duration_ms')->filter(fn ($v) => $v !== null && $v > 0)->values()->all();
        $rowsInserted = $rows->pluck('rows_inserted')->filter(fn ($v) => $v !== null)->values()->all();

        $sameHourRows = $rows
            ->filter(fn (array $r) => $r['completed_at'] >= $sameHourFrom)
            ->values();

        $sameHourAvg = $this->buildSameHourAverages($sameHourRows);

        return SubscriptionBaseline::query()->updateOrCreate(
            ['subscription_id' => $subscription->id],
            [
                'duration_p50' => $this->percentile($durations, 0.50),
                'duration_p95' => $this->percentile($durations, 0.95),
                'duration_p99' => $this->percentile($durations, 0.99),
                'rows_inserted_p50' => $this->percentile($rowsInserted, 0.50),
                'rows_inserted_p95' => $this->percentile($rowsInserted, 0.95),
                'rows_inserted_p99' => $this->percentile($rowsInserted, 0.99),
                'same_hour_avg_rows_inserted' => $sameHourAvg,
                'sample_size' => $rows->count(),
                'window_from' => $percentileFrom,
                'window_to' => $now,
                'computed_at' => $now,
            ],
        );
    }

    /**
     * Worker /complete persists an explicit `stats.duration_ms`. Older
     * rows that predate that field can be back-filled from the
     * `(started_at, completed_at)` pair — both are real timestamps even
     * when `duration_ms` is missing. Returns null when neither path
     * yields a positive duration so the percentile pass can skip the
     * row instead of treating it as a 0 ms outlier.
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
     * Linear-interpolated percentile over a numeric array. Mirrors
     * Postgres's `percentile_cont` (the test-suite expectation) so the
     * SQLite path produces the same numbers as the PG path on small
     * samples.
     *
     * Returns null on an empty input so the persisted column is null
     * (the "no data" signal) rather than a misleading 0.
     */
    public function percentile(array $values, float $p): ?float
    {
        $count = count($values);

        if ($count === 0) {
            return null;
        }

        sort($values, SORT_NUMERIC);

        if ($count === 1) {
            return (float) $values[0];
        }

        $rank = $p * ($count - 1);
        $lower = (int) floor($rank);
        $upper = (int) ceil($rank);

        if ($lower === $upper) {
            return (float) $values[$lower];
        }

        $weight = $rank - $lower;

        return (float) ($values[$lower] * (1 - $weight) + $values[$upper] * $weight);
    }

    /**
     * Build a 24-entry map of "average rows_inserted per UTC hour" from
     * the rolling 7-day sample. Hours with no sample are omitted from
     * the map so the consumer can distinguish "no data" from "zero
     * inserts" — both render differently in the drift rule.
     *
     * @param  Collection<int, array{completed_at: Carbon, rows_inserted: ?int}>  $rows
     * @return array<string, float>
     */
    private function buildSameHourAverages(Collection $rows): array
    {
        $byHour = [];

        foreach ($rows as $r) {
            if ($r['rows_inserted'] === null) {
                continue;
            }

            $hour = str_pad((string) $r['completed_at']->hour, 2, '0', STR_PAD_LEFT);
            $byHour[$hour][] = (int) $r['rows_inserted'];
        }

        $out = [];
        foreach ($byHour as $hour => $samples) {
            $out[$hour] = array_sum($samples) / count($samples);
        }

        ksort($out);

        return $out;
    }
}
