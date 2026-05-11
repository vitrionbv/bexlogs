<?php

namespace App\Services\AlertDelivery;

use App\Jobs\DeliverAlertJob;
use App\Models\AlertChannel;
use App\Models\User;

/**
 * Fans a system-emitted alert (B5: session-expiry warning, consecutive
 * scrape failures, quiet subscription) out to every AlertChannel the
 * user opted in to via the `system_alert_channels` pivot.
 *
 * Distinct from the saved-query → channel pipeline (B4) on three axes:
 *
 *   1. There's no SavedQuery. The alert_deliveries row gets
 *      `saved_query_id = null` and the listener never runs — these
 *      alerts originate from cron commands, not log-batch events.
 *   2. The dedupe window is per-(user, kind, fingerprint), keyed by
 *      this service rather than the per-pivot column. Operators don't
 *      configure system-alert dedupe directly; we hard-code sensible
 *      windows per kind so a flapping session doesn't page them every
 *      6 hours when the bex:check-sessions cron runs.
 *   3. The fan-out target is the user's system channel set, not the
 *      per-query channel set, so an operator can pipe noisy log
 *      alerts to one Slack channel and infrastructure alerts to a
 *      separate "ops" channel without tag soup on either side.
 *
 * Idempotency: each `emit()` call resolves the user's enabled system
 * channels and dispatches one DeliverAlertJob per channel. The job
 * itself is idempotent w.r.t. the audit row (creates + updates inside
 * a single handle()), so a duplicate emit() inside the dedupe window
 * is collapsed at the cache layer before any job is dispatched.
 */
class SystemAlertEmitter
{
    /**
     * Hard-coded dedupe windows per system alert kind. Tuned so a
     * recurring detector tick (every 6h for sessions, every 15min
     * for failures + quiet subscriptions) doesn't re-page the
     * operator on every tick once they've already seen the alert.
     *
     * Session-expiry uses a 24h window because the cron runs every
     * 6h and we want at most one ping per session per day until the
     * operator re-pairs. Failure runs and quiet subscriptions use a
     * 1h window — short enough to remind the operator if they miss
     * the first ping, long enough that the 15min cron doesn't
     * become a pager generator.
     */
    public const KIND_SESSION_EXPIRING = 'session_expiring';

    public const KIND_CONSECUTIVE_FAILURES = 'consecutive_failures';

    public const KIND_QUIET_SUBSCRIPTION = 'quiet_subscription';

    public const DEDUPE_WINDOW_SECONDS = [
        self::KIND_SESSION_EXPIRING => 86400,
        self::KIND_CONSECUTIVE_FAILURES => 3600,
        self::KIND_QUIET_SUBSCRIPTION => 3600,
    ];

    public function __construct(
        private readonly AlertPayloadBuilder $builder,
        private readonly DedupeWindow $dedupe,
    ) {}

    /**
     * Emit one system alert to every enabled system channel for `user`.
     *
     * @param  string  $kind         One of the KIND_* constants. Used for
     *                               dedupe-key segmentation and for the
     *                               cron-side "what fired" log line.
     * @param  string  $title        Operator-facing headline (one line).
     * @param  string  $body         Markdown-friendly description of
     *                               what happened.
     * @param  array<string,mixed>  $context  Structured metadata —
     *                               renders as a Slack context block,
     *                               webhook JSON property, or email
     *                               key/value table. Should always
     *                               include the resource id (session_id,
     *                               subscription_id, …) so the
     *                               fingerprint stays stable across
     *                               cron runs.
     * @param  string  $fingerprint  Stable string identifying the
     *                               alert subject. The dedupe key is
     *                               built from (kind, fingerprint) so
     *                               a single failing session pings
     *                               once per dedupe window even if
     *                               the body text changes between
     *                               cron ticks.
     * @return int                   Number of channels the alert was
     *                               dispatched to (0 means the user
     *                               has no system channels configured
     *                               or every channel collapsed under
     *                               the dedupe window).
     */
    public function emit(
        User $user,
        string $kind,
        string $title,
        string $body,
        array $context,
        string $fingerprint,
    ): int {
        $channels = $user->systemAlertChannels()
            ->where('enabled', true)
            ->get();

        if ($channels->isEmpty()) {
            // No system-channel opt-in for this user yet. Detector
            // commands log at info level so the operator knows the
            // detection fired even when there's nowhere to send it.
            return 0;
        }

        $payload = $this->builder->forSystemAlert($title, $body, $context);
        $window = self::DEDUPE_WINDOW_SECONDS[$kind] ?? 3600;

        $dispatched = 0;
        foreach ($channels as $channel) {
            // Per-channel dedupe key segmentation: same kind +
            // fingerprint going to TWO different channels gets
            // through twice (each channel has its own dedupe slot)
            // because the operator opted in to multiple destinations
            // on purpose. Same kind + fingerprint going to the SAME
            // channel inside the window collapses to one delivery,
            // which is the actual spam-prevention contract.
            $key = DedupeWindow::buildKey(
                queryId: 0,
                channelId: (int) $channel->id,
                fingerprint: ['kind' => $kind, 'subject' => $fingerprint],
            );

            if (! $this->dedupe->reserve($key, $window)) {
                continue;
            }

            DeliverAlertJob::dispatch(
                alertChannelId: (int) $channel->id,
                savedQueryId: null,
                logMessageId: null,
                payload: $payload,
            );

            $dispatched++;
        }

        return $dispatched;
    }
}
