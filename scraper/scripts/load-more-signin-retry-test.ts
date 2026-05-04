// Imperative test for `loadMoreWithSignInRetry`. Mirrors the structure of
// `load-more-retry-test.ts`: a hand-rolled APIRequestContext stub feeds
// the helper a queue of statuses, and we assert the helper retries with
// the configured backoff schedule on 401/403 (while still letting the
// inner loadMoreWithRetry handle 422).
//
// The 401/403 retry path was added to fix false-positive `session_expired`
// failures observed when BookingExperts bounces parallel same-cookie
// requests to /sign_in. See the commit "Fix false session_expired
// failures: retry transient sign-in bounces under concurrency" and the
// SIGN_IN_RETRY_DELAYS_MS constant in scrape.ts for context.
//
// Run with:  npx tsx scripts/load-more-signin-retry-test.ts
//
// Exits non-zero on any failed assertion.

import type { APIRequestContext, APIResponse } from 'playwright';
import { loadMoreWithSignInRetry } from '../src/scrape.js';

let failures = 0;

function check(name: string, ok: boolean, detail?: unknown): void {
    if (ok) {
        console.log(`  PASS  ${name}`);
    } else {
        failures++;
        console.error(`  FAIL  ${name}`, detail ?? '');
    }
}

function makeStubApiCtx(statuses: number[]): {
    apiCtx: APIRequestContext;
    calls: { count: number };
} {
    const queue = [...statuses];
    const calls = { count: 0 };
    const apiCtx = {
        get: async (): Promise<APIResponse> => {
            calls.count++;
            const status = queue.shift() ?? 500;
            return {
                status: () => status,
                ok: () => status >= 200 && status < 300,
                url: () => 'https://example.test/load_more_logs.js',
                text: async () => '<stub body>',
            } as unknown as APIResponse;
        },
    } as unknown as APIRequestContext;
    return { apiCtx, calls };
}

// ---- Scenario 1: 200 first response → no retry --------------------------------
console.log('Scenario 1: 200 first response is returned without retry');
{
    const { apiCtx, calls } = makeStubApiCtx([200]);
    const result = await loadMoreWithSignInRetry(apiCtx, 'https://example.test/load_more', 'https://example.test', {
        jobId: 1,
        page: 1,
        signInRetryDelaysMs: [50, 100],
        rate422RetryDelaysMs: [10, 20],
    });

    check('result is a response (not "422_exhausted")', result !== '422_exhausted');
    if (result !== '422_exhausted') {
        check('response.status() === 200', result.status() === 200, { status: result.status() });
    }
    check(`apiCtx.get called once (got ${calls.count})`, calls.count === 1, { calls: calls.count });
}

// ---- Scenario 2: 401 then 200 → recovered after one sign-in retry ------------
console.log('\nScenario 2: 401 then 200 → retried once, returned 200');
{
    const { apiCtx, calls } = makeStubApiCtx([401, 200]);
    const t0 = Date.now();
    const result = await loadMoreWithSignInRetry(apiCtx, 'https://example.test/load_more', 'https://example.test', {
        jobId: 2,
        page: 5,
        signInRetryDelaysMs: [50, 100],
        rate422RetryDelaysMs: [10, 20],
    });
    const elapsed = Date.now() - t0;

    check('result is a response (not "422_exhausted")', result !== '422_exhausted');
    if (result !== '422_exhausted') {
        check('response.status() === 200', result.status() === 200, { status: result.status() });
    }
    check(`apiCtx.get called twice (got ${calls.count})`, calls.count === 2, { calls: calls.count });
    check(`first sign-in backoff was honored (elapsed ${elapsed}ms >= 45ms)`, elapsed >= 45, { elapsed });
}

// ---- Scenario 3: 403 on every attempt → final 403 returned -------------------
console.log('\nScenario 3: 403 on every attempt → returns final 403 (caller throws SESSION_EXPIRED)');
{
    const { apiCtx, calls } = makeStubApiCtx([403, 403, 403]);
    const t0 = Date.now();
    const result = await loadMoreWithSignInRetry(apiCtx, 'https://example.test/load_more', 'https://example.test', {
        jobId: 3,
        page: 12,
        signInRetryDelaysMs: [50, 100],
        rate422RetryDelaysMs: [10, 20],
    });
    const elapsed = Date.now() - t0;

    check('result is a response (not "422_exhausted")', result !== '422_exhausted');
    if (result !== '422_exhausted') {
        check('response.status() === 403 after exhausting retries', result.status() === 403, { status: result.status() });
    }
    check(`apiCtx.get called 3 times (1 initial + 2 retries; got ${calls.count})`, calls.count === 3, { calls: calls.count });
    check(`both sign-in backoffs were honored (elapsed ${elapsed}ms >= 140ms)`, elapsed >= 140, { elapsed });
}

// ---- Scenario 4: 422 forwarded straight through ------------------------------
console.log('\nScenario 4: 422 on every attempt → "422_exhausted" forwarded from inner helper');
{
    const { apiCtx, calls } = makeStubApiCtx([422, 422, 422]);
    const result = await loadMoreWithSignInRetry(apiCtx, 'https://example.test/load_more', 'https://example.test', {
        jobId: 4,
        page: 7,
        signInRetryDelaysMs: [50, 100],
        rate422RetryDelaysMs: [10, 20],
    });

    check('result === "422_exhausted"', result === '422_exhausted', { result });
    // Inner helper makes 3 calls (1 + 2 retries) on its 422 schedule. The
    // outer sign-in retry doesn't loop because '422_exhausted' is forwarded
    // immediately on the first iteration.
    check(`apiCtx.get called 3 times (got ${calls.count})`, calls.count === 3, { calls: calls.count });
}

// ---- Scenario 5: 401 then 401 then 200 → recovered after second retry --------
console.log('\nScenario 5: 401 → 401 → 200 → recovered after second sign-in retry');
{
    const { apiCtx, calls } = makeStubApiCtx([401, 401, 200]);
    const result = await loadMoreWithSignInRetry(apiCtx, 'https://example.test/load_more', 'https://example.test', {
        jobId: 5,
        page: 9,
        signInRetryDelaysMs: [50, 100],
        rate422RetryDelaysMs: [10, 20],
    });

    check('result is a response (not "422_exhausted")', result !== '422_exhausted');
    if (result !== '422_exhausted') {
        check('response.status() === 200', result.status() === 200, { status: result.status() });
    }
    check(`apiCtx.get called 3 times (got ${calls.count})`, calls.count === 3, { calls: calls.count });
}

if (failures > 0) {
    console.error(`\n${failures} assertion(s) failed.`);
    process.exit(1);
}

console.log('\nAll loadMoreWithSignInRetry tests passed.');
