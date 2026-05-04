// Imperative test for `loadMoreWithRetry`. Verifies the new 422-aware
// retry contract introduced when `natural_end` was retired in favor of
// `pagination_error` (hard failure). We intentionally avoid Playwright
// here — the helper only cares about `apiCtx.get(...)` returning
// something with `.status()`, so a hand-rolled stub keeps the test fast
// and offline.
//
// Coverage:
//   1. Non-422 first response → returned verbatim, no retry, no sleep.
//   2. 422 then 200 → retried once, returned 200.
//   3. 422 × (1 + retries) → returns '422_exhausted'.
//   4. Backoff delays roughly match the schedule (we don't assert exact
//      ms, but lower-bound the wall clock so a regression to "no sleep"
//      gets caught).
//
// Run with:  npx tsx scripts/load-more-retry-test.ts
//
// Exits non-zero on any failed assertion so CI can wire it in.

import type { APIRequestContext, APIResponse } from 'playwright';
import { loadMoreWithRetry } from '../src/scrape.js';

let failures = 0;

function check(name: string, ok: boolean, detail?: unknown): void {
    if (ok) {
        console.log(`  PASS  ${name}`);
    } else {
        failures++;
        console.error(`  FAIL  ${name}`, detail ?? '');
    }
}

/**
 * Build a stub APIRequestContext whose `get()` returns the next status
 * from `statuses` (treated as a queue). Records each call so we can
 * assert the retry count.
 */
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
            } as unknown as APIResponse;
        },
    } as unknown as APIRequestContext;
    return { apiCtx, calls };
}

// ---- Scenario 1: non-422 first response → returned immediately ------------
console.log('Scenario 1: non-422 first response is returned without retry');
{
    const { apiCtx, calls } = makeStubApiCtx([200]);
    const t0 = Date.now();
    const result = await loadMoreWithRetry(apiCtx, 'https://example.test/load_more', 'https://example.test', {
        jobId: 1,
        page: 1,
        // Use real-looking delays; the helper shouldn't reach them on a
        // 200 response, so total wall time should be near-zero.
        retryDelaysMs: [50, 100],
    });
    const elapsed = Date.now() - t0;

    check('result is a response (not "422_exhausted")', result !== '422_exhausted');
    if (result !== '422_exhausted') {
        check('response.status() === 200', result.status() === 200, { status: result.status() });
    }
    check(`apiCtx.get called once (got ${calls.count})`, calls.count === 1, { calls: calls.count });
    check(`no retry sleep on success (elapsed ${elapsed}ms < 50ms)`, elapsed < 50, { elapsed });
}

// ---- Scenario 2: 422 then 200 → one retry, returned 200 -------------------
console.log('\nScenario 2: 422 then 200 → retried once, returned 200');
{
    const { apiCtx, calls } = makeStubApiCtx([422, 200]);
    const t0 = Date.now();
    const result = await loadMoreWithRetry(apiCtx, 'https://example.test/load_more', 'https://example.test', {
        jobId: 2,
        page: 5,
        retryDelaysMs: [50, 100],
    });
    const elapsed = Date.now() - t0;

    check('result is a response (not "422_exhausted")', result !== '422_exhausted');
    if (result !== '422_exhausted') {
        check('response.status() === 200', result.status() === 200, { status: result.status() });
    }
    check(`apiCtx.get called twice (got ${calls.count})`, calls.count === 2, { calls: calls.count });
    // Lower bound only — we slept 50ms between the 422 and the 200.
    check(`first backoff was honored (elapsed ${elapsed}ms >= 45ms)`, elapsed >= 45, { elapsed });
}

// ---- Scenario 3: 422 × 3 → '422_exhausted' --------------------------------
console.log('\nScenario 3: 422 on every attempt → "422_exhausted"');
{
    const { apiCtx, calls } = makeStubApiCtx([422, 422, 422]);
    const t0 = Date.now();
    const result = await loadMoreWithRetry(apiCtx, 'https://example.test/load_more', 'https://example.test', {
        jobId: 3,
        page: 12,
        retryDelaysMs: [50, 100],
    });
    const elapsed = Date.now() - t0;

    check('result === "422_exhausted"', result === '422_exhausted', { result });
    check(`apiCtx.get called 3 times (1 initial + 2 retries; got ${calls.count})`, calls.count === 3, { calls: calls.count });
    // Both delays should fire: ~50ms + ~100ms = ~150ms.
    check(`both backoffs were honored (elapsed ${elapsed}ms >= 140ms)`, elapsed >= 140, { elapsed });
}

// ---- Scenario 4: 422 then 422 then 500 → 500 returned (not exhausted) -----
// 500 is not 422, so the helper bails out early and hands back the 500
// for the caller's existing `!xhr.ok()` branch. Proves the helper doesn't
// require a 2xx to short-circuit out of the retry loop.
console.log('\nScenario 4: 422 → 422 → 500 returns the 500 response (no further retry)');
{
    const { apiCtx, calls } = makeStubApiCtx([422, 422, 500]);
    const result = await loadMoreWithRetry(apiCtx, 'https://example.test/load_more', 'https://example.test', {
        jobId: 4,
        page: 7,
        retryDelaysMs: [10, 10],
    });

    check('result is a response (not "422_exhausted")', result !== '422_exhausted');
    if (result !== '422_exhausted') {
        check('response.status() === 500', result.status() === 500, { status: result.status() });
    }
    check(`apiCtx.get called 3 times (got ${calls.count})`, calls.count === 3, { calls: calls.count });
}

if (failures > 0) {
    console.error(`\n${failures} assertion(s) failed.`);
    process.exit(1);
}

console.log('\nAll loadMoreWithRetry tests passed.');
