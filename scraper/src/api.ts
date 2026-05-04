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

export async function postBatch(
    jobId: number,
    messages: ParsedLogMessage[],
    pagesProcessed?: number,
): Promise<{ received: number; inserted: number }> {
    const body: Record<string, unknown> = { messages };
    if (pagesProcessed != null) {
        body.pages_processed = pagesProcessed;
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
