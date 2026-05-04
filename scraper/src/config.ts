import 'dotenv/config';
import { z } from 'zod';

const envSchema = z.object({
    LARAVEL_BASE_URL: z.string().url(),
    WORKER_API_TOKEN: z.string().min(8),
    POLL_INTERVAL_MS: z.coerce.number().int().positive().default(5000),
    // Cadence for the per-job liveness ticker that POSTs to
    // /api/worker/jobs/{id}/heartbeat independently of batch flushes. Must
    // stay comfortably below Laravel's `scrape:reap-stale --minutes`
    // threshold (default 3 minutes); the default 30s leaves ~6x slack so a
    // single missed tick doesn't trigger a false reap. If an operator
    // wants to reduce heartbeat load, raise the reaper threshold rather
    // than this knob — that's the safer direction.
    HEARTBEAT_INTERVAL_MS: z.coerce.number().int().positive().default(30000),
    MAX_CONCURRENT_SCRAPES: z.coerce.number().int().positive().default(8),
    MAX_PAGES_PER_JOB: z.coerce.number().int().positive().default(2000),
    BATCH_SIZE: z.coerce.number().int().positive().default(100),
    // Early-stop knobs: stop paginating once we've seen at least
    // EARLY_STOP_DUPLICATE_PAGES consecutive load_more pages where every row
    // was a duplicate (received > 0 && inserted === 0), AND the running total
    // of duplicate rows across the whole job is >= EARLY_STOP_MIN_DUPLICATES.
    // Both gates protect tiny subscriptions where the initial page already
    // covered everything from being treated as "stuck".
    EARLY_STOP_DUPLICATE_PAGES: z.coerce.number().int().positive().default(3),
    EARLY_STOP_MIN_DUPLICATES: z.coerce.number().int().positive().default(10),
    // Runaway-safety cap: stop paginating after this many *consecutive* load_more
    // pages came back with zero rows. BookingExperts paginates in time-sliced
    // windows (~5–15 min), so quiet hours legitimately yield empty pages while
    // next_token keeps advancing. The default of 500 covers ~5 days of
    // consecutive silence — far beyond any realistic quiet window — and only
    // fires if the upstream gets stuck handing out an eternally-valid token.
    MAX_CONSECUTIVE_ZERO_ROW_PAGES: z.coerce.number().int().positive().default(500),
    // Token-echo retry policy. When BookingExperts returns the same
    // `next_token` we just sent (the AWS CloudWatch Logs `nextForwardToken
    // === inputToken` "you're at the live tip" signal), we re-poll with
    // a flat backoff — no ramp. Defaults are 1 initial + 99 retries with
    // 3s between each, so a fully-exhausted echo cluster costs ~5 min of
    // sleep + 100 RTTs at the tail of a scrape (well inside the 45-min
    // job budget). Operator-tunable to balance "give BE more chances to
    // flush new events" against scrape wall-clock.
    //
    // The delay is floored at 500ms in the helper (defensive; see
    // `loadMoreWithTokenEchoRetry`) even if the env is set lower —
    // hammering BE with zero-delay retries defeats the point.
    TOKEN_ECHO_MAX_ATTEMPTS: z.coerce.number().int().positive().default(100),
    TOKEN_ECHO_RETRY_DELAY_MS: z.coerce.number().int().positive().default(3000),
    HEADLESS: z
        .union([z.literal('true'), z.literal('false'), z.boolean()])
        .transform((v) => (typeof v === 'boolean' ? v : v === 'true'))
        .default(true),
    LOG_LEVEL: z.enum(['debug', 'info', 'warn', 'error']).default('info'),
    DEBUG_DUMP_LOADMORE: z
        .union([z.literal('true'), z.literal('false'), z.boolean()])
        .transform((v) => (typeof v === 'boolean' ? v : v === 'true'))
        .default(false),
});

export const config = envSchema.parse(process.env);

export type Environment = 'production' | 'staging';

export const BEX_BASE_URLS: Record<Environment, string> = {
    production: 'https://app.bookingexperts.com',
    staging: 'https://app.staging.bookingexperts.com',
};
