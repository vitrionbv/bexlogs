<?php

namespace App\Http\Controllers\Api;

use App\Events\BexSessionRelinked;
use App\Http\Controllers\Controller;
use App\Models\BexSession;
use App\Models\PairingToken;
use App\Services\BookingExpertsClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BexSessionController extends Controller
{
    /**
     * Endpoint hit by the browser extension after the user has logged into
     * BookingExperts. Body:
     *   { token: "<pairing token>", cookies: [{name, value, domain, path, ...}, ...] }
     *
     * No auth middleware — the pairing token IS the auth.
     *
     * Update-vs-create decision:
     *   - Cookies are validated against BookingExperts first, which also
     *     gives us the signed-in user's email + display name.
     *   - If the caller has an existing BexSession for this (environment,
     *     account_email) pair, we UPDATE that row in place: new cookies,
     *     clear expired_at, bump last_validated_at, and broadcast a
     *     BexSessionRelinked event. No new row is created.
     *   - **Empty-email back-fill** (added after the email extractor was
     *     fixed): if no email match is found AND there's exactly one
     *     expired row in (user_id, environment) whose `account_email`
     *     is empty/null, treat that row as the relink target and
     *     populate its email. This is what rescues the orphan rows
     *     captured before the BookingExpertsClient extractor reliably
     *     returned an email — without it, the operator's first
     *     re-auth after the fix produces a duplicate row alongside
     *     the email-less ghost.
     *     Guard: if there are *multiple* email-less rows we refuse to
     *     pick one (could be a real cross-account collision the
     *     operator wants to triage by hand) and fall back to the
     *     INSERT branch.
     *   - Otherwise (no match, no email-less back-fill candidate, or
     *     no extractable email at all) we INSERT a fresh row — this
     *     is the first-time-link path and the "user switched
     *     BookingExperts accounts" path.
     *
     * This makes "re-authenticate an expired session" reuse the original
     * row so the operator's scrape jobs stay linked to the same BexSession
     * id across re-auths, which is what users expect.
     */
    public function store(Request $request, BookingExpertsClient $client): JsonResponse
    {
        $data = $request->validate([
            'token' => 'required|string|size:48',
            'cookies' => 'required|array|min:1',
            'cookies.*.name' => 'required|string',
            'cookies.*.value' => 'required|string',
            'cookies.*.domain' => 'nullable|string',
            'cookies.*.path' => 'nullable|string',
            'cookies.*.expirationDate' => 'nullable|numeric',
            'cookies.*.httpOnly' => 'nullable|boolean',
            'cookies.*.secure' => 'nullable|boolean',
            'cookies.*.sameSite' => 'nullable|string',
        ]);

        $token = PairingToken::find($data['token']);

        if (! $token || ! $token->isUsable()) {
            return response()->json([
                'error' => 'invalid_token',
                'message' => 'Pairing token is unknown, expired, or already consumed.',
            ], 422);
        }

        // Probe the captured cookies against BookingExperts once, on a
        // detached in-memory session, so we have an `email` / `name`
        // to drive the update-vs-create decision BEFORE touching the
        // database. A failed probe doesn't abort — we fall back to
        // "always create" (safe default: we can't identify the
        // account, so we don't risk clobbering someone else's row).
        $beClient = new BookingExpertsClient($token->environment);
        $probeSession = $this->detachedSessionForProbe($token, $data['cookies']);
        $probe = $beClient->validateSession($probeSession);

        [$session, $wasRelink] = DB::transaction(function () use (
            $data,
            $token,
            $probe,
        ) {
            $existing = null;
            if (! empty($probe['email'])) {
                $existing = BexSession::query()
                    ->where('user_id', $token->user_id)
                    ->where('environment', $token->environment)
                    ->where('account_email', $probe['email'])
                    ->orderByDesc('captured_at')
                    ->first();
            }

            // Empty-email back-fill: pre-extractor rows have
            // account_email='' and would otherwise be orphaned by the
            // strict (user_id, environment, account_email) match key.
            // Only collapse when (a) the new probe has a real email,
            // (b) the orphan candidate is expired, and (c) there's
            // exactly one such candidate — multiple empty-email rows
            // could be ambiguous identities the operator wants to
            // triage by hand.
            if (! $existing && ! empty($probe['email'])) {
                $emptyEmailCandidates = BexSession::query()
                    ->where('user_id', $token->user_id)
                    ->where('environment', $token->environment)
                    ->where(function ($q) {
                        $q->whereNull('account_email')->orWhere('account_email', '');
                    })
                    ->whereNotNull('expired_at')
                    ->orderByDesc('captured_at')
                    ->get();

                if ($emptyEmailCandidates->count() === 1) {
                    $existing = $emptyEmailCandidates->first();
                } elseif ($emptyEmailCandidates->count() > 1) {
                    Log::warning('bex relink: multiple empty-email rows in bucket; refusing to merge identities, falling back to INSERT', [
                        'user_id' => $token->user_id,
                        'environment' => $token->environment,
                        'candidate_ids' => $emptyEmailCandidates->pluck('id')->all(),
                        'incoming_email' => $probe['email'],
                    ]);
                }
            }

            if ($existing) {
                // `cookies` is a virtual Attribute whose setter writes
                // `cookies_encrypted` — it's not in $fillable, so
                // ->update() would silently drop it. Assign via the
                // mutator and then save alongside the scalar fields.
                $existing->cookies = $data['cookies'];
                $existing->captured_at = now();
                $existing->last_validated_at = $probe['valid'] ? now() : null;
                $existing->expired_at = $probe['valid'] ? null : now();
                if (! empty($probe['email'])) {
                    $existing->account_email = $probe['email'];
                }
                if (! empty($probe['name'])) {
                    $existing->account_name = $probe['name'];
                }
                $existing->save();

                $token->update([
                    'consumed_at' => now(),
                    'bex_session_id' => $existing->id,
                ]);

                return [$existing->fresh(), true];
            }

            $session = new BexSession;
            $session->user_id = $token->user_id;
            $session->environment = $token->environment;
            $session->cookies = $data['cookies'];
            $session->account_email = $probe['email'] ?? null;
            $session->account_name = $probe['name'] ?? null;
            $session->captured_at = now();
            if ($probe['valid']) {
                $session->last_validated_at = now();
            } else {
                $session->expired_at = now();
            }
            $session->save();

            $token->update([
                'consumed_at' => now(),
                'bex_session_id' => $session->id,
            ]);

            return [$session->fresh(), false];
        });

        if ($wasRelink) {
            broadcast(BexSessionRelinked::fromSession($session));
        }

        $isActive = $session->expired_at === null;

        return response()->json([
            'id' => $session->id,
            'environment' => $session->environment,
            'account_email' => $session->account_email,
            'account_name' => $session->account_name,
            'is_active' => $isActive,
            'relinked' => $wasRelink,
            'message' => $this->statusMessage($wasRelink, $isActive),
        ], $wasRelink ? 200 : 201);
    }

    /**
     * Build a transient BexSession (never saved) wrapping the captured
     * cookies so BookingExpertsClient::validateSession can talk to BE
     * before we decide whether to UPDATE an existing row or INSERT a
     * new one.
     */
    private function detachedSessionForProbe(PairingToken $token, array $cookies): BexSession
    {
        $session = new BexSession;
        $session->user_id = $token->user_id;
        $session->environment = $token->environment;
        $session->cookies = $cookies;

        return $session;
    }

    private function statusMessage(bool $wasRelink, bool $isActive): string
    {
        if ($wasRelink) {
            return $isActive
                ? 'Re-authenticated your existing BookingExperts session.'
                : 'Cookies updated on the existing session but BookingExperts rejected them. You may need to log in again.';
        }

        return $isActive
            ? 'Cookies accepted and validated against BookingExperts.'
            : 'Cookies stored but validation against BookingExperts failed. You may need to log in again.';
    }
}
