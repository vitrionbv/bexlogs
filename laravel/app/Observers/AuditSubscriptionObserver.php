<?php

namespace App\Observers;

use App\Models\Subscription;
use App\Services\AuditLogger;

/**
 * Fallback audit hooks for {@see Subscription} mutations. Controllers
 * record the same actions explicitly with hand-curated old/new
 * payloads — when both layers fire on the same request the in-process
 * dedup map on {@see AuditLogger::$seen} suppresses the observer's
 * duplicate. This makes the observer the safety net for routes/commands
 * the controllers haven't been wired into yet (e.g. tinker, future
 * artisan helpers, the scheduler bulk-toggling sub fields).
 *
 * The "updated" hook intentionally diffs `$model->getDirty()` rather
 * than the full attribute set so we only surface columns that actually
 * changed. `auto_scrape` is split out as its own action because the
 * Activity page wants to render "X paused/resumed Y" as a first-class
 * row rather than a generic budget update.
 */
class AuditSubscriptionObserver
{
    public function __construct(private AuditLogger $audit) {}

    public function created(Subscription $sub): void
    {
        $this->audit->record('subscription.created', $sub, [
            'new' => [
                'name' => $sub->name,
                'environment' => $sub->environment,
            ],
        ]);
    }

    public function updated(Subscription $sub): void
    {
        $dirty = $sub->getDirty();
        if (! $dirty) {
            return;
        }

        // `auto_scrape` flips get their own first-class action — the
        // Activity page renders them as "X paused/resumed Y" rather
        // than a generic "updated" row, which matches the operator's
        // mental model of the toggle.
        if (array_key_exists('auto_scrape', $dirty) && count($dirty) === 1) {
            $this->audit->record('subscription.auto_scrape_toggled', $sub, [
                'old' => ['auto_scrape' => (bool) $sub->getOriginal('auto_scrape')],
                'new' => ['auto_scrape' => (bool) $dirty['auto_scrape']],
            ]);

            return;
        }

        $old = [];
        $new = [];
        foreach ($dirty as $key => $value) {
            $old[$key] = $sub->getOriginal($key);
            $new[$key] = $value;
        }

        // Distinguish the "budget" knobs (intervals, page caps,
        // concurrency, retry limits) from everything else so the
        // Activity page can group them sensibly. Any column outside
        // that set falls back to the generic `updated` action.
        $budgetKeys = [
            'scrape_interval_minutes',
            'max_pages_per_scrape',
            'lookback_days_first_scrape',
            'max_duration_minutes',
            'max_concurrent_jobs',
            'job_spacing_minutes',
            'token_echo_max_attempts',
        ];
        $onlyBudget = array_diff(array_keys($dirty), $budgetKeys) === [];

        $this->audit->record(
            $onlyBudget ? 'subscription.budget_updated' : 'subscription.updated',
            $sub,
            ['old' => $old, 'new' => $new],
        );
    }

    public function deleted(Subscription $sub): void
    {
        $this->audit->record('subscription.deleted', $sub, [
            'old' => [
                'name' => $sub->name,
                'environment' => $sub->environment,
            ],
        ]);
    }
}
