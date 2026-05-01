// Diagnostic CLI: pulls one job from Laravel and runs it. Useful for
// debugging selector/parser drift without leaving the worker loop running.
//
// Usage:
//   pnpm run scrape:once
import { fetchNextJob } from '../api.js';
import { runScrapeJob } from '../scrape.js';
import { log } from '../log.js';

async function main(): Promise<void> {
    const job = await fetchNextJob();
    if (!job) {
        log.info('no queued jobs');
        return;
    }
    log.info('starting one-shot job', { jobId: job.id });
    await runScrapeJob(job);
}

main().catch((err) => {
    const message = err instanceof Error ? err.message : String(err);
    log.error('one-shot job failed', { message });
    process.exit(1);
});
