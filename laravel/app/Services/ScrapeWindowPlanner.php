<?php

namespace App\Services;

use App\Models\ScrapeJob;
use App\Models\Subscription;

/**
 * Computes the (start_time, end_time, max_pages, max_duration_minutes)
 * tuple a freshly-enqueued scrape job should crawl.
 *
 * Two modes:
 *
 *  1. No active sibling job → "catch-up" window:
 *     - subsequent scrapes: from `last_scraped_at - 30min` to now
 *       (the 30-min lookback tolerates clock skew + late-arriving log
 *       entries on BookingExperts' side).
 *     - first scrape: now minus `lookback_days_first_scrape` (default 30d).
 *
 *  2. There IS an active sibling (queued/running) → "slim concurrent" window:
 *     - start_time = now - 2 * `job_spacing_minutes`
 *     - end_time   = now
 *     The 2x multiplier intentionally overlaps with the older job so the
 *     worker's duplicate-density early-stop reliably catches up to the
 *     old job's inserts even when BookingExperts is paginating slowly.
 *     Cross-job dedup is correct: pages are unique per subscription
 *     (`pages_unique_idx`), so all concurrent jobs share a `Page.id` and
 *     `(page_id, content_hash)` dedupes their writes regardless of which
 *     job inserts first.
 *
 * `max_pages_per_scrape` and `max_duration_minutes` are passed through
 * verbatim; they remain the hard wall regardless of the window mode.
 */
class ScrapeWindowPlanner
{
    /**
     * @return array<string, string|int>
     */
    public function buildParams(Subscription $subscription): array
    {
        return $this->mergeOverrides(
            base: $this->baseWindow($subscription),
            overrides: [],
        );
    }

    /**
     * Same as `buildParams`, but lets the manual "Scrape now" path supply
     * caller-provided overrides (e.g. an explicit `start_time`/`end_time`
     * pair from the form). Overrides win when set; computed values fill
     * the gaps. Used by `ManageController::enqueueScrape`.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, string|int>
     */
    public function buildParamsWithOverrides(Subscription $subscription, array $overrides): array
    {
        return $this->mergeOverrides(
            base: $this->baseWindow($subscription),
            overrides: $overrides,
        );
    }

    /**
     * @return array<string, string|int>
     */
    private function baseWindow(Subscription $subscription): array
    {
        $hasActiveSibling = ScrapeJob::query()
            ->where('subscription_id', $subscription->id)
            ->whereIn('status', [ScrapeJob::STATUS_QUEUED, ScrapeJob::STATUS_RUNNING])
            ->exists();

        if ($hasActiveSibling) {
            // Slim concurrent window: 2x the spacing so the new job's
            // crawl reliably overlaps the prior job's inserts and the
            // duplicate-density early-stop fires after a couple of
            // pages. Spacing is in minutes; subMinutes() takes minutes.
            $spacing = max(1, (int) ($subscription->job_spacing_minutes ?? 10));
            $start = now()->subMinutes($spacing * 2)->toIso8601String();
        } else {
            $start = $subscription->last_scraped_at
                ? $subscription->last_scraped_at->copy()->subMinutes(30)->toIso8601String()
                : now()->subDays((int) ($subscription->lookback_days_first_scrape ?? 30))->toIso8601String();
        }

        return [
            'start_time' => $start,
            'end_time' => now()->toIso8601String(),
            'max_pages' => (int) ($subscription->max_pages_per_scrape ?? 200),
            'max_duration_minutes' => (int) ($subscription->max_duration_minutes ?? 10),
        ];
    }

    /**
     * @param  array<string, string|int>  $base
     * @param  array<string, mixed>  $overrides
     * @return array<string, string|int>
     */
    private function mergeOverrides(array $base, array $overrides): array
    {
        return array_merge($base, array_filter($overrides, fn ($v) => $v !== null));
    }
}
