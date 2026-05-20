<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Best-effort broadcast wrapper that prevents broadcaster failures from
 * propagating into data-mutating HTTP requests.
 *
 * Why this exists: the Reverb / Pusher driver chain is synchronous for
 * `ShouldBroadcastNow` events — `broadcast(new SomeEvent(...))` does an
 * outbound HTTPS POST to the broadcaster while the controller still
 * holds the user's request. A single 10s WAN timeout there will
 * propagate as a 500 to the caller. For worker-path controllers
 * (`WorkerController::batch`, `WorkerController::fail`, etc.) that
 * means a failed broadcast can fail the worker job *after* the
 * authoritative DB write has already succeeded — exactly what
 * happened to job 38616, which ingested 15820 rows successfully but
 * was marked failed because a downstream broadcast tipped over.
 *
 * Contract:
 *   - Broadcast is best-effort. The data write is authoritative.
 *   - Any `\Throwable` from the broadcaster is logged at warning
 *     level and swallowed. The caller's flow continues unchanged.
 *   - The `$context` string is included in the warning so an
 *     operator scanning logs can tell a `WorkerController::batch`
 *     failure apart from a `JobsController::cancel` one without
 *     having to chase the stack trace.
 *
 * The fix to the underlying *cause* of the failure mode (Reverb
 * routing through the public hostname instead of the internal docker
 * network) lives in `docker-compose.production.yml`'s `app` service
 * `environment:` block. This helper is the defense-in-depth layer
 * that keeps a future routing regression — or any other transient
 * Reverb wobble — from killing live jobs.
 */
class SafeBroadcast
{
    /**
     * Dispatch an event through Laravel's broadcast pipeline, catching
     * and logging any failure instead of letting it propagate.
     *
     * @param  object  $event  any broadcastable event (typically one
     *                         that implements `ShouldBroadcast` /
     *                         `ShouldBroadcastNow`)
     * @param  string  $context  a short call-site identifier included
     *                           in the log entry on failure — convention
     *                           is `Class::method` so log scanners can
     *                           group failures by call site
     */
    public static function dispatch(object $event, string $context): void
    {
        try {
            broadcast($event);
        } catch (\Throwable $e) {
            Log::warning('Broadcast failed in '.$context, [
                'event' => $event::class,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
