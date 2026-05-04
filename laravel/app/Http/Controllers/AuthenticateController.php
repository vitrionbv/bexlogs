<?php

namespace App\Http\Controllers;

use App\Events\BexSessionDeleted;
use App\Models\BexSession;
use App\Models\PairingToken;
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
        $sessions = $user->bexSessions()
            ->latest('captured_at')
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
        ];
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

    public function destroy(Request $request, BexSession $bexSession): RedirectResponse
    {
        abort_unless($bexSession->user_id === $request->user()->id, 403);

        $deletedId = (int) $bexSession->id;
        $deletedUserId = (int) $bexSession->user_id;
        $deletedEnv = (string) $bexSession->environment;
        $deletedEmail = $bexSession->account_email;

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
