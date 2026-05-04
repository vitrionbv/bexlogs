import { heartbeat } from './api.js';
import { config } from './config.js';
import { log } from './log.js';

/**
 * Per-job heartbeat ticker. The Node worker POSTs to
 * `/api/worker/jobs/{id}/heartbeat` at a steady cadence for the entire
 * lifetime of an in-flight scrape, independent of batch flushes.
 *
 * Why a dedicated ticker instead of relying on batch-driven heartbeats:
 * a slow page load, a long Turbo Stream parse, or a quiet-window stretch
 * with no inserts can leave `last_heartbeat_at` untouched for several
 * minutes. The Laravel `scrape:reap-stale` command then reaps a perfectly
 * alive job because its row looks frozen. The ticker keeps the row's
 * `last_heartbeat_at` fresh regardless of what the scrape loop is doing.
 *
 * Failure mode: a single failed heartbeat (network blip, Laravel
 * restart) is logged at `warn` and swallowed. We never crash the scrape
 * over a transient heartbeat failure — the next tick will catch up.
 */
export interface HeartbeatTicker {
    stop(): void;
}

export interface StartHeartbeatOptions {
    intervalMs?: number;
    /**
     * Override the heartbeat HTTP call. Used by the offline test harness
     * in `scripts/heartbeat-ticker-test.ts`; production uses the real
     * `heartbeat()` from `api.ts`.
     */
    heartbeatFn?: (jobId: number) => Promise<void>;
}

export function startHeartbeatTicker(
    jobId: number,
    options: StartHeartbeatOptions = {},
): HeartbeatTicker {
    const intervalMs = options.intervalMs ?? config.HEARTBEAT_INTERVAL_MS;
    const send = options.heartbeatFn ?? heartbeat;

    const fire = (): void => {
        send(jobId).catch((err) => {
            log.warn('heartbeat tick failed (transient — continuing scrape)', {
                jobId,
                error: err instanceof Error ? err.message : String(err),
            });
        });
    };

    // setInterval-only — the first tick fires after `intervalMs`. That's
    // intentional: Laravel already stamps `last_heartbeat_at = now()` when
    // the job is handed out via /jobs/next, so the row is fresh at t=0
    // and the first tick at t=interval keeps the rolling window alive.
    const handle = setInterval(fire, intervalMs);
    let stopped = false;

    return {
        stop(): void {
            if (stopped) return;
            stopped = true;
            clearInterval(handle);
        },
    };
}
