/**
 * Dedicated thread for per-job heartbeat timers.
 *
 * Node's main thread can stall for minutes when several Playwright
 * Chromium instances are busy (page.goto / load_more timeouts). A
 * setInterval on the main thread then stops firing, `last_heartbeat_at`
 * goes stale, and Laravel's reaper marks live jobs as `worker_reaped`.
 *
 * Timers in this worker run on their own libuv loop, independent of
 * Playwright, so heartbeats keep reaching Laravel while a page load
 * blocks the scrape coroutine.
 */
import { parentPort } from 'node:worker_threads';
import { fetch } from 'undici';

interface StartMessage {
    type: 'start';
    jobId: number;
    intervalMs: number;
    baseUrl: string;
    token: string;
}

interface StopMessage {
    type: 'stop';
    jobId: number;
}

interface StopAllMessage {
    type: 'stopAll';
}

type WorkerMessage = StartMessage | StopMessage | StopAllMessage;

interface TickContext {
    jobId: number;
    intervalMs: number;
    baseUrl: string;
    token: string;
    handle: ReturnType<typeof setInterval>;
    inFlight: boolean;
}

const tickers = new Map<number, TickContext>();

function heartbeatUrl(baseUrl: string, jobId: number): string {
    return `${baseUrl.replace(/\/+$/, '')}/api/worker/jobs/${jobId}/heartbeat`;
}

async function fireTick(ctx: TickContext): Promise<void> {
    if (ctx.inFlight) {
        return;
    }

    ctx.inFlight = true;
    try {
        const res = await fetch(heartbeatUrl(ctx.baseUrl, ctx.jobId), {
            method: 'POST',
            headers: {
                Authorization: `Bearer ${ctx.token}`,
                Accept: 'application/json',
            },
        });
        if (!res.ok) {
            parentPort?.postMessage({
                type: 'error',
                jobId: ctx.jobId,
                error: `heartbeat HTTP ${res.status}`,
            });
        }
    } catch (err) {
        parentPort?.postMessage({
            type: 'error',
            jobId: ctx.jobId,
            error: err instanceof Error ? err.message : String(err),
        });
    } finally {
        ctx.inFlight = false;
    }
}

function startTicker(msg: StartMessage): void {
    stopTicker(msg.jobId);

    const ctx: TickContext = {
        jobId: msg.jobId,
        intervalMs: msg.intervalMs,
        baseUrl: msg.baseUrl,
        token: msg.token,
        handle: setInterval(() => {
            void fireTick(ctx);
        }, msg.intervalMs),
        inFlight: false,
    };

    tickers.set(msg.jobId, ctx);

    // Immediate first tick — Laravel stamps on /jobs/next, but an early
    // ping narrows the window where a slow first page looks stale.
    void fireTick(ctx);
}

function stopTicker(jobId: number): void {
    const ctx = tickers.get(jobId);
    if (!ctx) {
        return;
    }

    clearInterval(ctx.handle);
    tickers.delete(jobId);
}

function stopAllTickers(): void {
    for (const jobId of [...tickers.keys()]) {
        stopTicker(jobId);
    }
}

parentPort?.on('message', (msg: WorkerMessage) => {
    switch (msg.type) {
        case 'start':
            startTicker(msg);
            break;
        case 'stop':
            stopTicker(msg.jobId);
            break;
        case 'stopAll':
            stopAllTickers();
            break;
        default:
            break;
    }
});
