<?php

namespace App\Http\Controllers;

use App\Events\BexSessionDeleted;
use App\Models\BexSession;
use App\Models\PairingToken;
use App\Services\AuditLogger;
use App\Services\BexSessionPruner;
use App\Services\BexSessionRefresher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticateController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        // Order by environment → priority → captured_at so the
        // multi-session UI renders sessions grouped by env, with the
        // operator's preferred (lowest-priority) row at the top of
        // each group. Captured_at acts as the tiebreaker for two
        // freshly-paired sessions sharing the default priority of
        // 100 — newer wins, matching the pre-B6 ordering operators
        // were used to.
        $sessions = $user->bexSessions()
            ->orderBy('environment')
            ->orderBy('priority')
            ->orderByDesc('captured_at')
            ->get();

        return Inertia::render('Authenticate/Index', [
            'environments' => ['production', 'staging'],
            'sessions' => $sessions->map(fn (BexSession $s) => self::sessionPayload($s)),
            // Surfaced so the "Delete expired sessions" button only
            // renders when there's actually something to prune. Cheap
            // — at most a handful of rows per user. Computed without
            // hitting the DB twice (we already have the rows in
            // memory).
            'prunable_count' => $sessions
                ->filter(fn (BexSession $s) => $s->expired_at !== null)
                ->count(),
            // Extension download URL & version are exposed globally via the
            // `extension` shared prop in HandleInertiaRequests.
        ]);
    }

    /**
     * Shape one BexSession row for the Authenticate page. Active vs
     * Cookie TTL are intentionally derived from different sources:
     *
     *   - `is_active` reflects the validator (`expired_at IS NULL`),
     *     i.e. whether BookingExperts actually accepted the cookies on
     *     the most recent /up call. This is the authoritative signal
     *     and the one the worker keys off.
     *
     *   - `cookie_ttl` is informational metadata derived from the
     *     cookies' own `Expires` headers, restricted to the
     *     auth-bearing cookies (see BexSession::AUTH_COOKIE_PATTERNS).
     *     Short-lived chaff cookies are deliberately ignored — they'd
     *     otherwise drag the surfaced TTL below the auth cookie's
     *     real lifetime and produce the "Active + cookies expired"
     *     contradiction operators noticed.
     */
    public static function sessionPayload(BexSession $s): array
    {
        $cookies = $s->cookies ?? [];
        $ttl = $s->cookieTtlSummary();

        return [
            'id' => $s->id,
            'environment' => $s->environment,
            'account_email' => $s->account_email,
            'account_name' => $s->account_name,
            'captured_at' => $s->captured_at?->toIso8601String(),
            'last_validated_at' => $s->last_validated_at?->toIso8601String(),
            'expired_at' => $s->expired_at?->toIso8601String(),
            'is_active' => $s->expired_at === null,
            'cookie_count' => count($cookies),
            'cookie_ttl' => $ttl,
            // B6: multi-session rotation surface. The Authenticate
            // page renders these alongside the existing fields so
            // the operator can see (and adjust) which session the
            // scheduler will pick first when several are healthy.
            'priority' => (int) ($s->priority ?? 100),
            'health_status' => $s->health_status ?? BexSession::HEALTH_HEALTHY,
            'expires_at' => $s->expires_at?->toIso8601String(),
        ];
    }

    /**
     * B6: per-session priority + disable controls. Both knobs feed
     * the rotation picker — `priority` shifts a session up or down
     * within the healthy/expiring tier, and the `disabled` health
     * status takes a session out of the rotation entirely without
     * deleting the row (so the cookies are still around when the
     * operator wants to flip it back on).
     */
    public function updateSession(Request $request, BexSession $bexSession): JsonResponse
    {
        abort_unless($bexSession->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'priority' => 'nullable|integer|min:0|max:65535',
            // `disabled` is a boolean knob exposed to the operator —
            // we translate it to the `health_status` value below.
            // Using the boolean (rather than letting the operator
            // pick from the full enum) keeps the UI simple: the
            // other states (healthy / expiring_soon / expired) are
            // computed by the saving hook and shouldn't be set
            // manually.
            'disabled' => 'nullable|boolean',
        ]);

        if (array_key_exists('priority', $data) && $data['priority'] !== null) {
            $bexSession->priority = (int) $data['priority'];
        }

        if (array_key_exists('disabled', $data) && $data['disabled'] !== null) {
            if ($data['disabled']) {
                $bexSession->health_status = BexSession::HEALTH_DISABLED;
            } else {
                // Re-enabling clears the sticky DISABLED tag and lets
                // the saving hook recompute health from the cookies.
                // Mark the row dirty on `expired_at` so the hook
                // takes the recompute branch — without that nudge
                // the hook bails out (no cookie change → no
                // recompute) and the row stays DISABLED in memory
                // even after the column is rewritten.
                $bexSession->health_status = BexSession::HEALTH_HEALTHY;
                $bexSession->touch();
                $bexSession->recomputeHealth();
            }
        }

        $bexSession->save();

        return response()->json([
            'session' => self::sessionPayload($bexSession->fresh()),
        ]);
    }

    /**
     * Generate a fresh pairing token. Returns the token + a human-readable
     * paste code (the token itself; we'll show a short prefix in the UI).
     */
    public function start(Request $request)
    {
        $validated = $request->validate([
            'environment' => 'required|in:production,staging',
        ]);

        $token = PairingToken::generate(
            userId: $request->user()->id,
            environment: $validated['environment'],
            ttlMinutes: config('bex.pairing_token_ttl_minutes', 5),
        );

        return response()->json([
            'token' => $token->token,
            'environment' => $token->environment,
            'expires_at' => $token->expires_at->toIso8601String(),
            'paste_code' => substr($token->token, 0, 12),
        ]);
    }

    /**
     * Status polling endpoint for the UI. Returns one of:
     *   { status: "waiting" }                    -> token still unconsumed
     *   { status: "ready", session: {...} }      -> extension delivered cookies
     *   { status: "expired" }                    -> token TTL elapsed without delivery
     *   { status: "unknown" }                    -> token doesn't exist or wasn't issued to this user
     */
    public function status(Request $request)
    {
        $token = PairingToken::query()
            ->where('user_id', $request->user()->id)
            ->find($request->query('token'));

        if (! $token) {
            return response()->json(['status' => 'unknown']);
        }

        if ($token->consumed_at && $token->bex_session_id) {
            $session = BexSession::find($token->bex_session_id);

            return response()->json([
                'status' => 'ready',
                'session' => $session ? [
                    'id' => $session->id,
                    'environment' => $session->environment,
                    'account_email' => $session->account_email,
                    'account_name' => $session->account_name,
                    'captured_at' => $session->captured_at?->toIso8601String(),
                ] : null,
            ]);
        }

        if ($token->expires_at?->isPast()) {
            return response()->json(['status' => 'expired']);
        }

        return response()->json(['status' => 'waiting']);
    }

    public function destroy(Request $request, BexSession $bexSession, AuditLogger $audit): RedirectResponse
    {
        abort_unless($bexSession->user_id === $request->user()->id, 403);

        $deletedId = (int) $bexSession->id;
        $deletedUserId = (int) $bexSession->user_id;
        $deletedEnv = (string) $bexSession->environment;
        $deletedEmail = $bexSession->account_email;

        // Audit before delete: the observer's matching `session.disabled`
        // hook also fires on delete, but with the morph still pointing
        // at the live row we get a richer controller-side payload.
        $audit->suppressNext('session.disabled', $bexSession);
        $audit->record('session.disabled', $bexSession, [
            'reason' => 'manual_revoke',
            'old' => [
                'environment' => $deletedEnv,
                'account_email' => $deletedEmail,
            ],
        ], deduplicate: false);

        $bexSession->delete();

        // Mirror the prune endpoint: broadcast so any other open
        // Authenticate page (other tabs, the extension popup) drops
        // the card without needing a manual reload.
        broadcast(new BexSessionDeleted(
            userId: $deletedUserId,
            bexSessionId: $deletedId,
            environment: $deletedEnv,
            accountEmail: $deletedEmail,
            reason: 'manual_revoke',
        ));

        return redirect()->route('authenticate.index')
            ->with('status', 'session-revoked');
    }

    /**
     * "Delete expired sessions" button on the Authenticate page.
     * Wraps {@see BexSessionPruner::pruneForUser} so the operator can
     * one-click clean up orphan rows without dropping into SSH +
     * artisan. Always scoped to `auth()->user()` — the prune service
     * never touches another user's rows on this path.
     */
    public function pruneStaleSessions(Request $request, BexSessionPruner $pruner): JsonResponse
    {
        $result = $pruner->pruneForUser($request->user());

        return response()->json([
            'deleted_count' => $result['deleted_count'],
            'plans' => $result['plans'],
        ]);
    }

    /**
     * Synchronously re-validate a single session and return the fresh payload
     * so the UI can update without a full page reload.
     */
    public function validateNow(
        Request $request,
        BexSession $bexSession,
        BexSessionRefresher $refresher,
    ): JsonResponse {
        abort_unless($bexSession->user_id === $request->user()->id, 403);

        $result = $refresher->refresh($bexSession);

        return response()->json([
            'result' => $result,
            'session' => self::sessionPayload($bexSession->fresh()),
        ]);
    }
}
