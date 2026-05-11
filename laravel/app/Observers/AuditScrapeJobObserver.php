<?php

namespace App\Observers;

use App\Models\ScrapeJob;
use App\Services\AuditLogger;

/**
 * Fallback audit hooks for {@see ScrapeJob}. Only the *creation* of a
 * job is interesting from an audit perspective — the lifecycle status
 * changes (queued → running → completed/failed) are already captured
 * verbosely in the scrape job's own row and surfaced on the Jobs page,
 * so duplicating them here would just bloat the audit_logs table.
 *
 * The matching controller path (`ManageController::enqueueScrape`)
 * records `scrape.manual_triggered` with extra context (overrides
 * payload). Scheduler-emitted rows fall through to this observer and
 * get the generic `scrape.enqueued` action so the trail is still
 * complete for "who/what kicked off this run".
 */
class AuditScrapeJobObserver
{
    public function __construct(private AuditLogger $audit) {}

    public function created(ScrapeJob $job): void
    {
        $this->audit->record('scrape.enqueued', $job, [
            'subscription_id' => $job->subscription_id,
            'status' => $job->status,
        ]);
    }
}
