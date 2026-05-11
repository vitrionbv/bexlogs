<?php

namespace App\Http\Controllers;

use App\Models\LogMessage;
use App\Models\Organization;
use App\Models\Page;
use App\Models\ScrapeJob;
use App\Models\Subscription;
use App\Models\SubscriptionBaseline;
use App\Services\HealthScoreCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per-subscription Insights surface: health badge, time-series charts,
 * recent jobs, and an embedded compact live-tail.
 *
 * The page is intentionally read-only — every interactive control is
 * either a window toggle (7/30/90 days) or a click-through to the
 * existing Jobs / Logs surfaces. Keeping it stateless means we can
 * cheaply re-render it on each Inertia visit without juggling client-
 * side filter state across navigations.
 *
 * Authorisation mirrors ManageController::authorize: the user must own
 * the wrapping organization. Anything else 403s before we touch
 * scrape_jobs.
 */
class InsightsController extends Controller
{
    private const ALLOWED_RANGES = [7, 30, 90];

    public function show(
        Request $request,
        Subscription $subscription,
        HealthScoreCalculator $health,
    ): Response {
        $this->authorize($request, $subscription);

        $range = (int) $request->query('range', 30);
        if (! in_array($range, self::ALLOWED_RANGES, true)) {
            $range = 30;
        }

        $rangeFrom = Carbon::now()->subDays($range);

        // Pull jobs in the window in a single SELECT, ordered by
        // creation time so the chart series come out in the same
        // order they happened.
        $jobs = ScrapeJob::query()
            ->where('subscription_id', $subscription->id)
            ->where('created_at', '>=', $rangeFrom)
            ->orderBy('created_at')
            ->get([
                'id',
                'subscription_id',
                'status',
                'started_at',
                'completed_at',
                'created_at',
                'stats',
            ]);

        $series = $this->buildSeries($jobs);
        $statusMix = $this->buildStatusMix($jobs);
        $stopReasons = $this->buildStopReasonDonut($jobs);

        $page = Page::query()
            ->where('subscription_id', $subscription->id)
            ->orderBy('id')
            ->first();

        // Compact embedded live-tail seed: latest 10 messages for the
        // page (if any). The Insights page subscribes to the same
        // `user.{id}` channel as the Live tail page and prepends new
        // batches so the operator can see the freshest activity
        // alongside the charts.
        $recentLogs = $page
            ? LogMessage::query()
                ->where('page_id', $page->id)
                ->orderByDesc('id')
                ->limit(10)
                ->get(['id', 'page_id', 'timestamp', 'type', 'action', 'method', 'status'])
                ->map(fn (LogMessage $m) => [
                    'id' => $m->id,
                    'page_id' => $m->page_id,
                    'timestamp' => $m->timestamp,
                    'type' => $m->type,
                    'action' => $m->action,
                    'method' => $m->method,
                    'status' => $m->status,
                ])
                ->all()
            : [];

        $baseline = SubscriptionBaseline::query()
            ->where('subscription_id', $subscription->id)
            ->first();

        return Inertia::render('Insights/Show', [
            'subscription' => [
                'id' => $subscription->id,
                'name' => $subscription->name,
                'environment' => $subscription->environment,
                'scrape_interval_minutes' => $subscription->scrape_interval_minutes,
                'last_scraped_at' => $subscription->last_scraped_at?->toIso8601String(),
            ],
            'health' => $health->forSubscription($subscription),
            'baseline' => $baseline ? [
                'duration_p50' => $baseline->duration_p50,
                'duration_p95' => $baseline->duration_p95,
                'duration_p99' => $baseline->duration_p99,
                'rows_inserted_p50' => $baseline->rows_inserted_p50,
                'rows_inserted_p95' => $baseline->rows_inserted_p95,
                'rows_inserted_p99' => $baseline->rows_inserted_p99,
                'sample_size' => $baseline->sample_size,
                'computed_at' => $baseline->computed_at?->toIso8601String(),
            ] : null,
            'range' => $range,
            'series' => $series,
            'statusMix' => $statusMix,
            'stopReasons' => $stopReasons,
            'recentJobs' => $jobs
                ->sortByDesc('id')
                ->take(20)
                ->values()
                ->map(fn (ScrapeJob $j) => [
                    'id' => $j->id,
                    'status' => $j->status,
                    'created_at' => $j->created_at?->toIso8601String(),
                    'completed_at' => $j->completed_at?->toIso8601String(),
                    'rows_inserted' => isset($j->stats['rows_inserted'])
                        ? (int) $j->stats['rows_inserted']
                        : null,
                    'duration_ms' => $this->durationMs($j),
                    'stop_reason' => $j->stats['stop_reason'] ?? null,
                ])
                ->all(),
            'recentLogs' => $recentLogs,
            'page_id' => $page?->id,
        ]);
    }

    /**
     * Build the dual-axis time series the rows-per-scrape and duration
     * charts render. One row per completed job; the frontend pairs
     * timestamps with values directly.
     *
     * @param  Collection<int, ScrapeJob>  $jobs
     * @return array<string, mixed>
     */
    private function buildSeries(Collection $jobs): array
    {
        $completed = $jobs->where('status', ScrapeJob::STATUS_COMPLETED)
            ->filter(fn (ScrapeJob $j) => $j->completed_at !== null)
            ->values();

        $points = $completed
            ->map(fn (ScrapeJob $j) => [
                'id' => $j->id,
                't' => $j->completed_at?->toIso8601String(),
                'rows_inserted' => (int) ($j->stats['rows_inserted'] ?? 0),
                'duration_ms' => $this->durationMs($j) ?? 0,
            ])
            ->values()
            ->all();

        return [
            'points' => $points,
        ];
    }

    /**
     * Status mix: a stacked-bar slice per day, with one segment per
     * status. The frontend renders this as a stacked bar chart
     * directly. Days with zero jobs are omitted; the chart axis
     * simply skips them.
     *
     * @param  Collection<int, ScrapeJob>  $jobs
     * @return array<int, array<string, int|string>>
     */
    private function buildStatusMix(Collection $jobs): array
    {
        $byDay = [];

        foreach ($jobs as $j) {
            $day = $j->created_at?->format('Y-m-d');
            if ($day === null) {
                continue;
            }

            $byDay[$day][$j->status] = ($byDay[$day][$j->status] ?? 0) + 1;
        }

        ksort($byDay);

        $out = [];
        foreach ($byDay as $day => $counts) {
            $out[] = [
                'day' => $day,
                'completed' => $counts[ScrapeJob::STATUS_COMPLETED] ?? 0,
                'failed' => $counts[ScrapeJob::STATUS_FAILED] ?? 0,
                'cancelled' => $counts[ScrapeJob::STATUS_CANCELLED] ?? 0,
                'queued' => $counts[ScrapeJob::STATUS_QUEUED] ?? 0,
                'running' => $counts[ScrapeJob::STATUS_RUNNING] ?? 0,
            ];
        }

        return $out;
    }

    /**
     * Stop-reason distribution for the donut chart. We count each reason
     * across both completed and failed runs (failed runs persist their
     * stop_reason via WorkerController::fail too).
     *
     * @param  Collection<int, ScrapeJob>  $jobs
     * @return array<int, array{reason:string, count:int}>
     */
    private function buildStopReasonDonut(Collection $jobs): array
    {
        $counts = [];
        foreach ($jobs as $j) {
            $reason = $j->stats['stop_reason'] ?? null;
            if (! is_string($reason) || $reason === '') {
                continue;
            }

            $counts[$reason] = ($counts[$reason] ?? 0) + 1;
        }

        arsort($counts);

        return collect($counts)
            ->map(fn (int $count, string $reason) => [
                'reason' => $reason,
                'count' => $count,
            ])
            ->values()
            ->all();
    }

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
     * Replicates the access pattern from ManageController::authorize.
     * The user must own the wrapping organization. We deliberately
     * don't reuse ManageController's protected method — we'd have to
     * widen its visibility for a single line of code.
     */
    private function authorize(Request $request, Subscription $sub): void
    {
        $owns = Organization::query()
            ->where('user_id', $request->user()->id)
            ->whereExists(fn ($q) => $q
                ->from('applications')
                ->whereColumn('applications.organization_id', 'organizations.id')
                ->where('applications.id', $sub->application_id),
            )
            ->exists();
        abort_unless($owns, 403);
    }
}
