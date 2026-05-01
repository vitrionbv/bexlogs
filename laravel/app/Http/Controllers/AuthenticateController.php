<?php

namespace App\Http\Controllers;

use App\Models\BexSession;
use App\Models\PairingToken;
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

        return Inertia::render('Authenticate/Index', [
            'environments' => ['production', 'staging'],
            'sessions' => $user->bexSessions()
                ->latest('captured_at')
                ->get()
                ->map(fn (BexSession $s) => self::sessionPayload($s)),
            // Extension download URL & version are exposed globally via the
            // `extension` shared prop in HandleInertiaRequests.
        ]);
    }

    public static function sessionPayload(BexSession $s): array
    {
        $cookies = $s->cookies ?? [];
        $earliestExpiry = null;
        foreach ($cookies as $cookie) {
            if (! empty($cookie['expirationDate'])) {
                $ts = (int) $cookie['expirationDate'];
                if ($earliestExpiry === null || $ts < $earliestExpiry) {
                    $earliestExpiry = $ts;
                }
            }
        }

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
            'earliest_cookie_expiry' => $earliestExpiry
                ? date(DATE_ATOM, $earliestExpiry)
                : null,
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

        $bexSession->delete();

        return redirect()->route('authenticate.index')
            ->with('status', 'session-revoked');
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
