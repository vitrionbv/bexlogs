<?php

namespace App\Services;

use App\Models\BexSession;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Validates BexSessions against BookingExperts and updates last_validated_at
 * (or expired_at if the session was rejected). Run from the scheduler hourly
 * so the UI always reflects up-to-date status; also invokable on-demand from
 * the Authenticate page's "Validate now" button.
 */
class BexSessionRefresher
{
    /**
     * Refresh one session. Returns a summary safe to ship over JSON.
     *
     * @return array{
     *   id:int,
     *   was_active:bool,
     *   is_active:bool,
     *   status:int,
     *   email:?string,
     *   name:?string,
     *   message:string,
     * }
     */
    public function refresh(BexSession $session): array
    {
        $wasActive = $session->expired_at === null;
        $client = new BookingExpertsClient($session->environment);

        try {
            $check = $client->validateSession($session);
        } catch (Throwable $e) {
            Log::warning('bex-session refresh threw', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'id' => $session->id,
                'was_active' => $wasActive,
                'is_active' => $wasActive,
                'status' => 0,
                'email' => $session->account_email,
                'name' => $session->account_name,
                'message' => 'Network error talking to BookingExperts: '.$e->getMessage(),
            ];
        }

        $update = ['last_validated_at' => now()];

        if ($check['valid']) {
            $update['expired_at'] = null;
            if (! empty($check['email']) && empty($session->account_email)) {
                $update['account_email'] = $check['email'];
            }
            if (! empty($check['name']) && empty($session->account_name)) {
                $update['account_name'] = $check['name'];
            }
        } else {
            $update['expired_at'] = now();
        }

        $session->update($update);

        return [
            'id' => $session->id,
            'was_active' => $wasActive,
            'is_active' => $check['valid'],
            'status' => $check['status'],
            'email' => $session->fresh()->account_email,
            'name' => $session->fresh()->account_name,
            'message' => $check['valid']
                ? 'Session is valid.'
                : 'Session no longer accepted by BookingExperts (HTTP '.$check['status'].').',
        ];
    }

    /**
     * Refresh every non-expired session. Returns counts.
     *
     * @return array{checked:int, still_valid:int, newly_expired:int}
     */
    public function refreshAll(): array
    {
        $sessions = BexSession::query()
            ->whereNull('expired_at')
            ->get();

        $stillValid = 0;
        $newlyExpired = 0;

        foreach ($sessions as $session) {
            $result = $this->refresh($session);
            if ($result['is_active']) {
                $stillValid++;
            } elseif ($result['was_active']) {
                $newlyExpired++;
            }
        }

        return [
            'checked' => $sessions->count(),
            'still_valid' => $stillValid,
            'newly_expired' => $newlyExpired,
        ];
    }
}
