<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Centralised writer for the audit_logs table.
 *
 * Two-tier capture strategy:
 *
 *   1. Controllers call `AuditLogger::record(...)` explicitly with a
 *      hand-curated `old / new` payload (or whatever shape best
 *      describes the action). This is the authoritative path for
 *      "important" mutations like subscription create/update/delete
 *      and session capture/expiry — controllers know the intent
 *      better than the model does.
 *
 *   2. Model observers under `App\Observers\Audit*` act as the
 *      fallback for routes/commands the controllers don't cover.
 *      They keep an in-process `seen` set so we don't double-record
 *      when both layers fire on the same request.
 *
 * The service is intentionally tiny:
 *   - No queueing. Audit writes happen synchronously inside the
 *     request lifecycle so the row exists by the time the response
 *     hits the user.
 *   - No swallowing of exceptions in production: a misconfigured
 *     audit table should surface fast. In tests we still rely on
 *     RefreshDatabase migrating the table first.
 *
 * Action naming convention (`<aggregate>.<verb>`):
 *   - subscription.created / subscription.updated / subscription.deleted
 *   - subscription.budget_updated / subscription.auto_scrape_toggled
 *   - scrape.manual_triggered / scrape.denied
 *   - session.captured / session.expired / session.disabled
 * The Activity page treats these as opaque strings; the namespacing
 * is a human-readability convention, not enforced by the schema.
 */
class AuditLogger
{
    /**
     * Cross-request dedup. When a controller calls `record()` with the
     * same (action, subject) pair that an observer would also fire
     * within the same lifecycle, the second write is a no-op. Cleared
     * automatically at the end of a request (PHP's process death) and
     * between tests by the static accessor in {@see resetSeen()}.
     *
     * @var array<string, true>
     */
    protected array $seen = [];

    /**
     * Persist one audit row.
     *
     * @param  string  $action  Stable namespaced identifier (`subscription.updated`, …).
     * @param  Model  $subject  The Eloquent record the action targets.
     * @param  array<string, mixed>  $payload  Free-form context (typically `old` + `new` keys).
     * @param  bool  $deduplicate  When true (the default), suppress a re-emission of the same
     *                             (action, subject) within the current request. Controllers pass
     *                             this through so the matching observer skips its own write.
     */
    public function record(string $action, Model $subject, array $payload = [], bool $deduplicate = true): ?AuditLog
    {
        $key = $this->fingerprint($action, $subject);

        if ($deduplicate && isset($this->seen[$key])) {
            return null;
        }

        // The model's `$timestamps = false` plus the column-level
        // `useCurrent()` default mean Eloquent never sends `created_at`
        // and Postgres/SQLite fall back to the DB clock — which IGNORES
        // `Carbon::setTestNow()`. Setting it here explicitly keeps the
        // row's timestamp aligned with the application's view of
        // "now", which matters for tests that pin the clock to verify
        // date-range filtering.
        $row = new AuditLog([
            'user_id' => Auth::id(),
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => (string) $subject->getKey(),
            'payload' => $payload ?: null,
            'ip_address' => $this->resolveIp(),
            'user_agent' => $this->resolveUserAgent(),
        ]);
        $row->created_at = now();
        $row->save();

        if ($deduplicate) {
            $this->seen[$key] = true;
        }

        return $row;
    }

    /**
     * Mark an (action, subject) pair as already handled so the
     * fallback observer skips its emission. Controllers call this
     * before performing the underlying mutation so the order of
     * observer-vs-controller writes doesn't matter.
     */
    public function suppressNext(string $action, Model $subject): void
    {
        $this->seen[$this->fingerprint($action, $subject)] = true;
    }

    /**
     * Clear the in-process dedup map. Primarily a testing affordance —
     * production code doesn't need to call this because the container
     * binding is reset per-request via Laravel's singleton lifecycle.
     */
    public function resetSeen(): void
    {
        $this->seen = [];
    }

    /**
     * Best-effort old/new diff helper. Pass the model BEFORE applying
     * the changes plus the array of incoming values; we'll return a
     * shape the Activity page can render cleanly:
     *
     *   [
     *     'old' => ['auto_scrape' => true,  'scrape_interval_minutes' => 5],
     *     'new' => ['auto_scrape' => false, 'scrape_interval_minutes' => 5],
     *   ]
     *
     * Only the intersection of (input keys ∩ attribute keys whose
     * value actually changed) is included. This keeps the payload
     * tight and avoids surfacing untouched columns just because the
     * caller passed an over-eager array.
     *
     * @param  array<string, mixed>  $newValues
     * @return array{old: array<string, mixed>, new: array<string, mixed>}
     */
    public static function diff(Model $original, array $newValues): array
    {
        $old = [];
        $new = [];

        foreach ($newValues as $key => $proposed) {
            $existing = $original->getOriginal($key, $original->getAttribute($key));

            if ($existing == $proposed) {
                continue;
            }

            $old[$key] = $existing;
            $new[$key] = $proposed;
        }

        return ['old' => $old, 'new' => $new];
    }

    private function fingerprint(string $action, Model $subject): string
    {
        return $action.'|'.$subject->getMorphClass().'|'.((string) $subject->getKey());
    }

    private function resolveIp(): ?string
    {
        try {
            return Request::ip();
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveUserAgent(): ?string
    {
        try {
            $ua = Request::userAgent();

            return $ua === null ? null : substr($ua, 0, 512);
        } catch (\Throwable) {
            return null;
        }
    }
}
