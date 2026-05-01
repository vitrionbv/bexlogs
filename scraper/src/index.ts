import { config } from './config.js';
import { fetchNextJob } from './api.js';
import { runScrapeJob } from './scrape.js';
import { log } from './log.js';

const inflight = new Set<Promise<unknown>>();
let stopping = false;

async function loop(): Promise<void> {
    log.info('worker started', {
        baseUrl: config.LARAVEL_BASE_URL,
        pollIntervalMs: config.POLL_INTERVAL_MS,
        maxConcurrent: config.MAX_CONCURRENT_SCRAPES,
        headless: config.HEADLESS,
    });

    while (!stopping) {
        try {
            if (inflight.size >= config.MAX_CONCURRENT_SCRAPES) {
                await Promise.race([...inflight]);
                continue;
            }

            const job = await fetchNextJob();
            if (!job) {
                await sleep(config.POLL_INTERVAL_MS);
                continue;
            }

            const promise = runScrapeJob(job)
                .catch((err) => {
                    const message = err instanceof Error ? err.message : String(err);
                    log.error('job error (already reported to Laravel)', { jobId: job.id, message });
                })
                .finally(() => inflight.delete(promise));
            inflight.add(promise);
        } catch (err) {
            const message = err instanceof Error ? err.message : String(err);
            log.error('worker loop error', { message });
            await sleep(config.POLL_INTERVAL_MS);
        }
    }

    if (inflight.size > 0) {
        log.info('draining in-flight jobs before exit', { remaining: inflight.size });
        await Promise.allSettled([...inflight]);
    }
    log.info('worker stopped');
}

function shutdown(signal: string): void {
    log.info(`received ${signal}, draining…`);
    stopping = true;
}

process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));

function sleep(ms: number): Promise<void> {
    return new Promise((r) => setTimeout(r, ms));
}

loop().catch((err) => {
    const message = err instanceof Error ? err.message : String(err);
    log.error('fatal worker error', { message });
    process.exit(1);
});
