<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BexSession;
use App\Models\PairingToken;
use App\Services\BookingExpertsClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BexSessionController extends Controller
{
    /**
     * Endpoint hit by the browser extension after the user has logged into
     * BookingExperts. Body:
     *   { token: "<pairing token>", cookies: [{name, value, domain, path, ...}, ...] }
     *
     * No auth middleware — the pairing token IS the auth.
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

        $session = DB::transaction(function () use ($data, $token) {
            $session = new BexSession;
            $session->user_id = $token->user_id;
            $session->environment = $token->environment;
            $session->cookies = $data['cookies'];
            $session->captured_at = now();
            $session->save();

            $environment = $token->environment;

            $check = (new BookingExpertsClient($environment))->validateSession($session);
            if ($check['valid']) {
                $session->update(['last_validated_at' => now()]);
            } else {
                $session->update(['expired_at' => now()]);
            }

            $token->update([
                'consumed_at' => now(),
                'bex_session_id' => $session->id,
            ]);

            return $session->fresh();
        });

        return response()->json([
            'id' => $session->id,
            'environment' => $session->environment,
            'is_active' => $session->expired_at === null,
            'message' => $session->expired_at === null
                ? 'Cookies accepted and validated against BookingExperts.'
                : 'Cookies stored but validation against BookingExperts failed. You may need to log in again.',
        ], 201);
    }
}
