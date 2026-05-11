<?php

namespace App\Observers;

use App\Models\BexSession;
use App\Services\AuditLogger;

/**
 * Fallback audit hooks for {@see BexSession}. The controllers wire up
 * the happy-path session lifecycle ("captured", "expired", "revoked")
 * explicitly with curated payloads; this observer catches the routes
 * the controllers don't cover (e.g. the worker telling us a session
 * went stale, the refresher service flipping `expired_at`).
 *
 * Dedup against the explicit controller writes is handled by
 * {@see AuditLogger::$seen}: when both layers fire on the same
 * request the observer's write is a no-op.
 */
class AuditBexSessionObserver
{
    public function __construct(private AuditLogger $audit) {}

    public function created(BexSession $session): void
    {
        $this->audit->record('session.captured', $session, [
            'new' => [
                'environment' => $session->environment,
                'account_email' => $session->account_email,
                'is_active' => $session->expired_at === null,
            ],
        ]);
    }

    public function updated(BexSession $session): void
    {
        // The interesting transition is "active → expired" — we don't
        // care about heartbeat-style updates to `last_validated_at` or
        // cookie-jar refreshes. Anything else gets a generic
        // `session.updated` row so the trail is complete.
        $dirty = $session->getDirty();
        if (! array_key_exists('expired_at', $dirty)) {
            return;
        }

        $oldExpired = $session->getOriginal('expired_at');
        $newExpired = $dirty['expired_at'];

        if ($oldExpired === null && $newExpired !== null) {
            $this->audit->record('session.expired', $session, [
                'old' => ['expired_at' => null],
                'new' => ['expired_at' => (string) $newExpired],
                'account_email' => $session->account_email,
                'environment' => $session->environment,
            ]);
        } elseif ($oldExpired !== null && $newExpired === null) {
            $this->audit->record('session.relinked', $session, [
                'account_email' => $session->account_email,
                'environment' => $session->environment,
            ]);
        }
    }

    public function deleted(BexSession $session): void
    {
        $this->audit->record('session.disabled', $session, [
            'old' => [
                'environment' => $session->environment,
                'account_email' => $session->account_email,
            ],
        ]);
    }
}
