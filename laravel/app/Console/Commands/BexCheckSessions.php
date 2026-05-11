<?php

namespace App\Console\Commands;

use App\Models\BexSession;
use App\Models\User;
use App\Services\AlertDelivery\SystemAlertEmitter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * B5: detect BookingExperts sessions whose auth cookies expire within
 * the next 48h and emit a system alert through every channel the
 * operator has opted into.
 *
 * Why a separate command from `bex:refresh-sessions`:
 *
 *   - `bex:refresh-sessions` runs hourly and hits BE's /up endpoint to
 *     verify each session is still ACCEPTED — that's the "did BE just
 *     log me out" signal. A session can stay accepted right up until
 *     its cookie's `Expires` lapses, then refuse the next request
 *     without warning.
 *   - This command runs every 6h (less often because it only
 *     inspects local state — no BE round-trips) and surfaces the
 *     time-bound side: "your auth cookie crosses Expires in <48h,
 *     re-pair before then".
 *
 * The two commands intentionally cover orthogonal failure modes; an
 * operator who runs both gets pinged on either path.
 *
 * Idempotency comes from {@see SystemAlertEmitter} — re-running this
 * command inside the dedupe window is a no-op for already-paged
 * sessions, so the cron interval can be tightened without producing
 * noise.
 */
class BexCheckSessions extends Command
{
    protected $signature = 'bex:check-sessions
        {--user= : Limit to a single user id}
        {--dry-run : Detect but do not dispatch alerts}';

    protected $description = 'Page operators about BookingExperts sessions whose cookies expire within 48h.';

    public function handle(SystemAlertEmitter $emitter): int
    {
        $now = CarbonImmutable::now();
        $window = $now->addHours(BexSession::EXPIRING_SOON_WINDOW_HOURS);

        $query = BexSession::query()
            ->whereNull('expired_at')
            ->where('health_status', '!=', BexSession::HEALTH_DISABLED);

        if ($id = $this->option('user')) {
            $query->where('user_id', $id);
        }

        // Pull rows whose `expires_at` falls inside the warning
        // window. NULL `expires_at` (session-only auth cookies) are
        // already classified as `expiring_soon` by the BexSession
        // saving hook, so the OR-arm on health_status catches them
        // here too — without it we'd silently skip session-only
        // cookies that die on the next browser quit.
        $query->where(function ($q) use ($now, $window) {
            $q->whereBetween('expires_at', [$now, $window])
                ->orWhere('health_status', BexSession::HEALTH_EXPIRING_SOON);
        });

        $candidates = $query->with('user')->get();

        $checked = 0;
        $alerted = 0;
        foreach ($candidates as $session) {
            $checked++;

            // Recompute health BEFORE alerting so an expired-while-we-
            // were-fetching row gets reclassified to `expired` and
            // doesn't trigger an "expiring soon" page after the
            // cookie has already lapsed. The recompute is idempotent
            // for unchanged rows so this loop costs at most one
            // UPDATE per row that crossed a threshold.
            $session->recomputeHealth($now);

            if ($session->health_status !== BexSession::HEALTH_EXPIRING_SOON) {
                continue;
            }

            if (! $session->user) {
                // Orphan row whose user was deleted but the cascade
                // missed (legacy data). Skip rather than crash —
                // BexSessionPruner cleans these up on a separate cron.
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("would alert: session #{$session->id} ({$session->account_email}) expires_at={$session->expires_at?->toIso8601String()}");

                continue;
            }

            $emitter->emit(
                user: $session->user,
                kind: SystemAlertEmitter::KIND_SESSION_EXPIRING,
                title: "Session expiring soon — {$session->account_email}",
                body: $this->describeSession($session, $now),
                context: [
                    'session_id' => $session->id,
                    'environment' => $session->environment,
                    'account_email' => $session->account_email,
                    'expires_at' => $session->expires_at?->toIso8601String() ?? '(session-only cookie)',
                ],
                // Fingerprint is just the session id: same row across
                // ticks → same dedupe slot → at most one alert per
                // dedupe window. Different sessions for the same
                // account on the same env still alert independently
                // because their ids differ.
                fingerprint: 'session:'.$session->id,
            );

            $alerted++;
        }

        $this->info("checked={$checked} alerted={$alerted}");

        if ($alerted > 0) {
            Log::info('bex:check-sessions: emitted session-expiry alerts', [
                'checked' => $checked,
                'alerted' => $alerted,
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * Render a human-readable "X expires in Y" body for the alert
     * payload. Used by every driver via the AlertPayloadBuilder.
     */
    private function describeSession(BexSession $session, CarbonImmutable $now): string
    {
        if ($session->expires_at === null) {
            return "Session-only auth cookie — will be lost on the next browser quit. Re-pair the {$session->environment} session to keep scrapes flowing.";
        }

        $hours = (int) ceil($session->expires_at->diffInHours($now, true));

        return "Auth cookies for {$session->account_email} ({$session->environment}) expire in approximately {$hours}h. Re-pair before then to avoid scrape interruptions.";
    }
}
