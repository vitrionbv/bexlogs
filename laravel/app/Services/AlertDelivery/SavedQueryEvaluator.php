<?php

namespace App\Services\AlertDelivery;

use App\Listeners\AlertOnLogBatchListener;
use App\Models\LogMessage;
use App\Models\SavedQuery;
use Illuminate\Support\Carbon;

/**
 * Pure decision function: given a SavedQuery's filter spec and a
 * LogMessage row, does the row match?
 *
 * Kept deliberately framework-agnostic so {@see AlertOnLogBatchListener}
 * can call it from inside a tight per-row loop without any of the
 * Eloquent overhead of `whereJsonContains` matching. Filter keys
 * unknown to this evaluator are ignored, NOT treated as
 * always-match-failing — operators on a newer schema (post-merge) can
 * push a filter without a coordinating worker deploy.
 *
 * Accepted filter keys (see migration for the canonical doc):
 *   - subscription_id      string, equality match against the log row's
 *                          owning subscription
 *   - environment          'production' | 'staging', equality
 *   - status_regex         PCRE pattern matched against `status`
 *   - action_regex         PCRE pattern matched against `action`
 *   - method               'GET'|'POST'|'PATCH'|'PUT'|'DELETE'
 *   - since                ISO 8601; row.timestamp >= since
 *
 * Regex patterns must already include their delimiters when they
 * arrive from the UI — the UI builder adds `#…#i` for forgiving
 * case-insensitive matching. A malformed pattern logs a warning via
 * the caller but doesn't blow up the listener (defensive: one bad
 * saved query shouldn't break alerts for the operator's other
 * working queries).
 */
class SavedQueryEvaluator
{
    /**
     * @param  array{ subscription_id?: string }  $logContext
     *                                                         Pre-resolved subscription metadata for the row so the
     *                                                         evaluator never has to hit the DB during the per-row
     *                                                         loop. The listener resolves these once per page-id.
     */
    public function matches(SavedQuery $query, LogMessage $log, array $logContext = []): bool
    {
        $filter = $query->filter ?? [];
        if (! is_array($filter) || $filter === []) {
            // Empty `{}` matches every log row by design — see the
            // migration's "heartbeat into a low-noise channel" use case.
            return true;
        }

        if (isset($filter['subscription_id'])) {
            $subId = (string) ($logContext['subscription_id'] ?? '');
            if ($subId !== (string) $filter['subscription_id']) {
                return false;
            }
        }

        if (isset($filter['environment'])) {
            $env = (string) ($logContext['environment'] ?? '');
            if ($env !== (string) $filter['environment']) {
                return false;
            }
        }

        if (isset($filter['method'])) {
            if (strcasecmp((string) $log->method, (string) $filter['method']) !== 0) {
                return false;
            }
        }

        if (isset($filter['status_regex'])) {
            $pattern = (string) $filter['status_regex'];
            $value = (string) ($log->status ?? '');
            // `@` swallows the warning; the bool-false return from a
            // bad pattern is the signal we react to. Wrapping with a
            // try/catch here would be cleaner if `preg_match` raised,
            // but it doesn't — it returns false + emits a warning.
            $rv = @preg_match($pattern, $value);
            if ($rv !== 1) {
                return false;
            }
        }

        if (isset($filter['action_regex'])) {
            $pattern = (string) $filter['action_regex'];
            $rv = @preg_match($pattern, (string) $log->action);
            if ($rv !== 1) {
                return false;
            }
        }

        if (isset($filter['since'])) {
            try {
                $since = Carbon::parse((string) $filter['since']);
                $rowTs = Carbon::parse((string) $log->timestamp);
                if ($rowTs->lessThan($since)) {
                    return false;
                }
            } catch (\Throwable) {
                // Bad ISO string disables the constraint rather than
                // failing the match — same forward-compat principle as
                // unknown filter keys.
            }
        }

        return true;
    }
}
