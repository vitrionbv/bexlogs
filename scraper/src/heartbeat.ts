import { Worker } from 'node:worker_threads';
import { fileURLToPath } from 'node:url';
import { heartbeat } from './api.js';
import { config } from './config.js';
import { log } from './log.js';

/**
 * Per-job heartbeat ticker. The Node worker POSTs to
 * `/api/worker/jobs/{id}/heartbeat` at a steady cadence for the entire
 * lifetime of an in-flight scrape, independent of batch flushes.
 *
 * Production uses a dedicated worker thread (`heartbeat-worker.ts`) so
 * timers keep firing while Playwright blocks the main event loop on slow
 * page loads. Tests can inject `heartbeatFn` to stay on the main thread.
 */
export interface HeartbeatTicker {
    stop(): void;
}

export interface StartHeartbeatOptions {
    intervalMs?: number;
    /**
     * Override the heartbeat HTTP call. Used by the offline test harness
     * in `scripts/heartbeat-ticker-test.ts`; production uses the worker
     * thread path.
     */
    heartbeatFn?: (jobId: number) => Promise<void>;
}

let sharedWorker: Worker | null = null;

function workerScriptPath(): string {
    return fileURLToPath(new URL('./heartbeat-worker.js', import.meta.url));
}

function ensureWorker(): Worker {
    if (sharedWorker) {
        return sharedWorker;
    }

    const worker = new Worker(workerScriptPath());
    worker.on('message', (msg: { type: string; jobId?: number; error?: string }) => {
        if (msg.type === 'error' && msg.jobId !== undefined) {
            log.warn('heartbeat tick failed (transient — continuing scrape)', {
                jobId: msg.jobId,
                error: msg.error ?? 'unknown',
            });
        }
    });
    worker.on('error', (err) => {
        log.error('heartbeat worker thread error', {
            error: err instanceof Error ? err.message : String(err),
        });
    });

    sharedWorker = worker;
    return worker;
}

function startMainThreadTicker(
    jobId: number,
    intervalMs: number,
    send: (jobId: number) => Promise<void>,
): HeartbeatTicker {
    const fire = (): void => {
        send(jobId).catch((err) => {
            log.warn('heartbeat tick failed (transient — continuing scrape)', {
                jobId,
                error: err instanceof Error ? err.message : String(err),
            });
        });
    };

    const handle = setInterval(fire, intervalMs);
    let stopped = false;

    return {
        stop(): void {
            if (stopped) {
                return;
            }
            stopped = true;
            clearInterval(handle);
        },
    };
}

function startWorkerThreadTicker(jobId: number, intervalMs: number): HeartbeatTicker {
    const worker = ensureWorker();
    worker.postMessage({
        type: 'start',
        jobId,
        intervalMs,
        baseUrl: config.LARAVEL_BASE_URL,
        token: config.WORKER_API_TOKEN,
    });

    let stopped = false;

    return {
        stop(): void {
            if (stopped) {
                return;
            }
            stopped = true;
            worker.postMessage({ type: 'stop', jobId });
        },
    };
}

export function startHeartbeatTicker(
    jobId: number,
    options: StartHeartbeatOptions = {},
): HeartbeatTicker {
    const intervalMs = options.intervalMs ?? config.HEARTBEAT_INTERVAL_MS;

    if (options.heartbeatFn) {
        return startMainThreadTicker(jobId, intervalMs, options.heartbeatFn);
    }

    return startWorkerThreadTicker(jobId, intervalMs);
}

/** Test / shutdown hook — stops every ticker and tears down the worker. */
export async function shutdownHeartbeatWorker(): Promise<void> {
    if (!sharedWorker) {
        return;
    }

    sharedWorker.postMessage({ type: 'stopAll' });
    await sharedWorker.terminate();
    sharedWorker = null;
}
