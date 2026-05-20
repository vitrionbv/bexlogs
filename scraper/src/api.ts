import { fetch } from 'undici';
import { config } from './config.js';
import { log } from './log.js';
import type { ParsedLogMessage, ScrapeJob, StopReason } from './types.js';

const headers = (): Record<string, string> => ({
    Authorization: `Bearer ${config.WORKER_API_TOKEN}`,
    Accept: 'application/json',
});

const jsonHeaders = (): Record<string, string> => ({
    ...headers(),
    'Content-Type': 'application/json',
});

const url = (path: string) => `${config.LARAVEL_BASE_URL.replace(/\/+$/, '')}${path}`;

export async function fetchNextJob(): Promise<ScrapeJob | null> {
    const res = await fetch(url('/api/worker/jobs/next'), {
        method: 'GET',
        headers: headers(),
    });
    if (res.status === 204) return null;
    if (!res.ok) {
        throw new Error(`fetchNextJob failed: ${res.status} ${await res.text()}`);
    }
    return (await res.json()) as ScrapeJob;
}

export async function heartbeat(jobId: number): Promise<void> {
    await fetch(url(`/api/worker/jobs/${jobId}/heartbeat`), {
        method: 'POST',
        headers: headers(),
    });
}

/**
 * Per-page entry in the token-echo retry attempts list — see
 * `scrape.ts::echoAttemptsByPage`. Page numbers are 1-based to match
 * the worker's `pageCount + 1` semantics; `page: 0` is a sentinel for
 * the initial-page retry helper (`loadInitialPageWithRetry`), which
 * runs before the load_more loop starts. Only pages where the helper
 * actually retried (`attempts > 1`) are included — clean pages stay
 * out of the array to keep the payload small.
 */
export interface PageEchoAttempts {
    page: number;
    attempts: number;
}

export async function postBatch(
    jobId: number,
    messages: ParsedLogMessage[],
    pagesProcessed?: number,
    echoAttemptsByPage?: readonly PageEchoAttempts[],
): Promise<{ received: number; inserted: number }> {
    const body: Record<string, unknown> = { messages };
    if (pagesProcessed != null) {
        body.pages_processed = pagesProcessed;
    }
    // Min/max event-timestamps in THIS batch. Laravel's /batch
    // handler rolls them up across batches into a job-lifetime
    // `stats.oldest_event_at` / `stats.newest_event_at` so operators
    // can see the actual data window the scrape ingested
    // (independent of the requested window in
    // `params.start_time` / `params.end_time` — useful for
    // verifying a backfill actually reached the requested depth).
    //
    // `Date.parse` is used because BookingExperts' timestamps are
    // ISO 8601 with varying offsets and millisecond precision; it
    // returns NaN on garbage which we filter out.
    //
    // Empty / all-unparseable batches just skip the fields entirely
    // — Laravel's merge logic treats missing fields as "no signal"
    // (the contract is "missing = no signal", never `null`).
    if (messages.length > 0) {
        let minMs = Number.POSITIVE_INFINITY;
        let maxMs = Number.NEGATIVE_INFINITY;
        for (const m of messages) {
            const ms = Date.parse(m.timestamp);
            if (Number.isNaN(ms)) continue;
            if (ms < minMs) minMs = ms;
            if (ms > maxMs) maxMs = ms;
        }
        if (Number.isFinite(minMs) && Number.isFinite(maxMs)) {
            body.batch_oldest_event_at = new Date(minMs).toISOString();
            body.batch_newest_event_at = new Date(maxMs).toISOString();
        }
    }
    // Always re-send the full list on every batch — sender-of-truth
    // semantics. Laravel overwrites rather than merges, so we don't
    // have to track diffs. Skipped entirely when the array is empty
    // to avoid sending `[]` on every clean batch (no signal, just
    // noise in the merged stats blob).
    if (echoAttemptsByPage && echoAttemptsByPage.length > 0) {
        body.echo_attempts_by_page = echoAttemptsByPage;
    }
    const res = await fetch(url(`/api/worker/jobs/${jobId}/batch`), {
        method: 'POST',
        headers: jsonHeaders(),
        body: JSON.stringify(body),
    });
    if (!res.ok) {
        throw new Error(`postBatch failed: ${res.status} ${await res.text()}`);
    }
    return (await res.json()) as { received: number; inserted: number };
}

export async function completeJob(
    jobId: number,
    stats: {
        pages: number;
        duration_ms: number;
        aborted_due_to_time?: boolean;
        early_stopped_due_to_duplicates?: boolean;
        total_duplicates?: number;
        stop_reason?: StopReason;
        // Diagnostic counter for the token_echo → caught_up retry layer
        // (see `scraper/src/scrape.ts`). Always sent, even when 0, so the
        // operator can tell at a glance whether the helper fired on a
        // given run vs. wasn't exercised. The Laravel /complete validator
        // accepts this key explicitly.
        token_echo_retries?: number;
        // Diagnostic counter for the initial-page retry layer (mirrors
        // `token_echo_retries`). Always sent, even when 0, so the
        // operator can tell at a glance whether the helper fired on a
        // given run — zero across many `empty_window` completions would
        // flag that the retry loop somehow isn't arming; non-zero
        // values confirm we walked the full policy before declaring the
        // window empty. The Laravel /complete validator accepts this
        // key explicitly.
        initial_page_retries?: number;
        /**
         * Per-page token-echo attempt list — see
         * `scrape.ts::echoAttemptsByPage`. Always sent on /complete,
         * even when empty (`[]`), so a job whose last page exhausted
         * with no row flush still gets the final retry count
         * persisted (the trailing /batch never fires in that case).
         * Pages with `attempts <= 1` are intentionally omitted; only
         * the helper-fired-and-retried entries make the list.
         */
        echo_attempts_by_page?: readonly PageEchoAttempts[];
    },
): Promise<void> {
    const res = await fetch(url(`/api/worker/jobs/${jobId}/complete`), {
        method: 'POST',
        headers: jsonHeaders(),
        body: JSON.stringify(stats),
    });
    if (!res.ok) {
        log.warn('completeJob non-OK', { status: res.status });
    }
}

export async function failJob(
    jobId: number,
    payload: { error: string; retryable?: boolean; stop_reason?: StopReason },
): Promise<void> {
    // JSON.stringify drops `undefined` keys, so when the caller doesn't
    // know a typed reason (generic HTTP failure, etc.) we send the
    // existing `{error, retryable}` shape verbatim — Laravel's fail()
    // endpoint then leaves stats.stop_reason untouched (or applies its
    // own SESSION_EXPIRED fallback). When stop_reason IS supplied,
    // Laravel persists it onto stats.stop_reason verbatim.
    const res = await fetch(url(`/api/worker/jobs/${jobId}/fail`), {
        method: 'POST',
        headers: jsonHeaders(),
        body: JSON.stringify(payload),
    });
    if (!res.ok) {
        log.warn('failJob non-OK', { status: res.status });
    }
}

export async function reportSessionExpired(sessionId: number): Promise<void> {
    const res = await fetch(url(`/api/worker/sessions/${sessionId}/expired`), {
        method: 'POST',
        headers: headers(),
    });
    if (!res.ok) {
        log.warn('reportSessionExpired non-OK', { status: res.status });
    }
}
