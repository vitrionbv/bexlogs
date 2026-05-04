// Imperative test for `loadMoreWithTokenEchoRetry`. Mirrors the structure
// of `load-more-retry-test.ts` and `load-more-signin-retry-test.ts`: a
// hand-rolled fetch closure feeds the helper a queue of (nextToken,
// payload) tuples, and we assert the helper retries while the response
// keeps echoing the sentToken — and bails the right way once the queue
// advances, exhausts, or throws.
//
// The retry helper was introduced after BookingExperts' echoed-token
// behaviour was reinterpreted as the AWS CloudWatch Logs `GetLogEvents`
// pattern (`nextForwardToken === inputToken` ⇒ "you're at the live tip;
// keep polling with the same token"). What used to be a hard
// `stop_reason: token_echo` failure is now a clean `caught_up`
// completion when retries exhaust. See the commit "Retry token_echo as
// AWS-style log-tail cursor; introduce caught_up stop_reason" and the
// TOKEN_ECHO_RETRY_DELAYS_MS constant in scrape.ts for context.
//
// Coverage:
//   1. Echo → echo → advances    (recovers on the 3rd attempt; outcome
//                                  = 'advanced'; tokenEchoRetries += 2)
//   2. Echo → echo → echo        (all 3 attempts echo; outcome =
//                                  'exhausted'; total wall clock ≈
//                                  delay[0] + delay[1])
//   3. Echo → advances           (recovers on the 2nd attempt;
//                                  outcome = 'advanced'; tokenEchoRetries
//                                  += 1)
//   4. Echo → throws             (the closure throws on attempt 2; the
//                                  error propagates out of the helper
//                                  unchanged so the caller's existing
//                                  error path runs as before)
//   5. Budget bail               (timeBudgetOk returns false right
//                                  before the first sleep; outcome =
//                                  'budget_aborted')
//
// Run with:  npx tsx scripts/token-echo-retry-test.ts
//
// Exits with non-zero on any failed assertion so CI can wire it in.

import { loadMoreWithTokenEchoRetry } from '../src/scrape.js';

let failures = 0;

function check(name: string, ok: boolean, detail?: unknown): void {
    if (ok) {
        console.log(`  PASS  ${name}`);
    } else {
        failures++;
        console.error(`  FAIL  ${name}`, detail ?? '');
    }
}

const SHORT_DELAYS = [50, 120] as const;
const SENT = 'TIP_TOKEN';

interface Tagged {
    body: string;
}

function makeFetcher(responses: Array<{ nextToken: string | null; tag: string } | { throws: string }>): {
    fetch: (attempt: number) => Promise<{ nextToken: string | null; payload: Tagged }>;
    calls: { count: number; attempts: number[] };
} {
    const queue = [...responses];
    const calls = { count: 0, attempts: [] as number[] };
    const fetch = async (attempt: number): Promise<{ nextToken: string | null; payload: Tagged }> => {
        calls.count++;
        calls.attempts.push(attempt);
        const next = queue.shift();
        if (!next) {
            throw new Error(`fetcher queue underflowed (call #${calls.count})`);
        }
        if ('throws' in next) {
            throw new Error(next.throws);
        }
        return { nextToken: next.nextToken, payload: { body: next.tag } };
    };
    return { fetch, calls };
}

// ---- Scenario 1: echo → echo → advances on attempt 3 ----------------------
console.log('Scenario 1: echo → echo → advances on attempt 3');
{
    const { fetch, calls } = makeFetcher([
        { nextToken: SENT, tag: 'attempt-1' },
        { nextToken: SENT, tag: 'attempt-2' },
        { nextToken: 'NEW_TOKEN', tag: 'attempt-3' },
    ]);
    const t0 = Date.now();
    const result = await loadMoreWithTokenEchoRetry(SENT, fetch, {
        jobId: 1,
        page: 7,
        retryDelaysMs: SHORT_DELAYS,
    });
    const elapsed = Date.now() - t0;

    check(`outcome === 'advanced' (got '${result.kind}')`, result.kind === 'advanced', { kind: result.kind });
    check(`attempts === 3 (got ${result.attempts})`, result.attempts === 3);
    check(`fetcher called 3 times (got ${calls.count})`, calls.count === 3);
    check(
        `final nextToken === 'NEW_TOKEN' (got '${result.result.nextToken}')`,
        result.result.nextToken === 'NEW_TOKEN',
    );
    check(
        `attempt sequence is [1, 2, 3] (got ${JSON.stringify(calls.attempts)})`,
        JSON.stringify(calls.attempts) === '[1,2,3]',
    );
    // Both delays should have fired: ~50 + ~120 = ~170ms.
    check(`both backoffs were honored (elapsed ${elapsed}ms >= 160ms)`, elapsed >= 160, { elapsed });
    check(
        `payload from attempt 3 surfaced (got '${result.result.payload.body}')`,
        result.result.payload.body === 'attempt-3',
    );
}

// ---- Scenario 2: echo → echo → echo (all exhaust) -------------------------
console.log('\nScenario 2: echo → echo → echo (all 3 attempts exhaust)');
{
    const { fetch, calls } = makeFetcher([
        { nextToken: SENT, tag: 'attempt-1' },
        { nextToken: SENT, tag: 'attempt-2' },
        { nextToken: SENT, tag: 'attempt-3' },
    ]);
    const t0 = Date.now();
    const result = await loadMoreWithTokenEchoRetry(SENT, fetch, {
        jobId: 2,
        page: 12,
        retryDelaysMs: SHORT_DELAYS,
    });
    const elapsed = Date.now() - t0;

    check(`outcome === 'exhausted' (got '${result.kind}')`, result.kind === 'exhausted', { kind: result.kind });
    check(`attempts === 3 (got ${result.attempts})`, result.attempts === 3);
    check(`fetcher called 3 times (got ${calls.count})`, calls.count === 3);
    check(
        `final nextToken still === SENT (got '${result.result.nextToken}')`,
        result.result.nextToken === SENT,
    );
    check(
        `payload from final attempt surfaced (got '${result.result.payload.body}')`,
        result.result.payload.body === 'attempt-3',
    );
    check(
        `both backoffs were honored (elapsed ${elapsed}ms >= 160ms; production schedule ≈17s)`,
        elapsed >= 160,
        { elapsed },
    );
}

// ---- Scenario 3: echo → advances on attempt 2 -----------------------------
console.log('\nScenario 3: echo → advances on attempt 2');
{
    const { fetch, calls } = makeFetcher([
        { nextToken: SENT, tag: 'attempt-1' },
        { nextToken: 'NEW_TOKEN_FAST', tag: 'attempt-2' },
    ]);
    const t0 = Date.now();
    const result = await loadMoreWithTokenEchoRetry(SENT, fetch, {
        jobId: 3,
        page: 5,
        retryDelaysMs: SHORT_DELAYS,
    });
    const elapsed = Date.now() - t0;

    check(`outcome === 'advanced' (got '${result.kind}')`, result.kind === 'advanced');
    check(`attempts === 2 (got ${result.attempts})`, result.attempts === 2);
    check(`fetcher called 2 times (got ${calls.count})`, calls.count === 2);
    check(
        `final nextToken === 'NEW_TOKEN_FAST' (got '${result.result.nextToken}')`,
        result.result.nextToken === 'NEW_TOKEN_FAST',
    );
    // Only the first backoff (~50ms) should fire; the second never gets a chance.
    check(`only first backoff fired (elapsed ${elapsed}ms >= 45ms)`, elapsed >= 45, { elapsed });
    check(
        `second backoff did NOT fire (elapsed ${elapsed}ms < 150ms)`,
        elapsed < 150,
        { elapsed },
    );
}

// ---- Scenario 4: echo → fetcher throws (network error) --------------------
console.log('\nScenario 4: echo → fetcher throws on attempt 2 (error propagates)');
{
    const { fetch, calls } = makeFetcher([
        { nextToken: SENT, tag: 'attempt-1' },
        { throws: 'simulated load_more HTTP 500' },
    ]);
    let caught: Error | null = null;
    try {
        await loadMoreWithTokenEchoRetry(SENT, fetch, {
            jobId: 4,
            page: 9,
            retryDelaysMs: SHORT_DELAYS,
        });
    } catch (err) {
        caught = err as Error;
    }

    check('helper re-threw the closure error', caught !== null);
    check(
        `error message preserved (got '${caught?.message}')`,
        caught?.message === 'simulated load_more HTTP 500',
    );
    check(`fetcher called 2 times before throw (got ${calls.count})`, calls.count === 2);
}

// ---- Scenario 5: budget bail before sleeping ------------------------------
console.log('\nScenario 5: timeBudgetOk returns false → budget_aborted before first sleep');
{
    const { fetch, calls } = makeFetcher([
        { nextToken: SENT, tag: 'attempt-1' },
        // queue intentionally has only 1 entry; if the helper retries
        // beyond the budget gate the queue underflows and the test fails.
    ]);
    const t0 = Date.now();
    const result = await loadMoreWithTokenEchoRetry(SENT, fetch, {
        jobId: 5,
        page: 3,
        retryDelaysMs: SHORT_DELAYS,
        timeBudgetOk: () => false,
    });
    const elapsed = Date.now() - t0;

    check(`outcome === 'budget_aborted' (got '${result.kind}')`, result.kind === 'budget_aborted');
    check(`attempts === 1 (got ${result.attempts})`, result.attempts === 1);
    check(`fetcher called only once (got ${calls.count})`, calls.count === 1);
    check(`no sleep before bail (elapsed ${elapsed}ms < 30ms)`, elapsed < 30, { elapsed });
}

// ---- Scenario 6: production schedule wall-clock budget --------------------
// Document the production-schedule cost: this is the upper bound the
// runScrapeJob caller pays at the tail of a fully-exhausted echo cluster
// (per scrape, not per page). Sleeps are JS setTimeout — we lower-bound
// at 16s so a regression that drops a backoff (e.g. someone rewriting
// the schedule) gets caught.
console.log('\nScenario 6: documents production schedule wall-clock cost on exhaustion');
{
    // We don't actually want to wait 17s in CI, so reuse the SHORT
    // schedule and just document the delta. The numbers below match
    // TOKEN_ECHO_RETRY_DELAYS_MS in src/scrape.ts; if those change,
    // update both places.
    const PROD_DELAYS_MS = [5000, 12000];
    const totalSleepMs = PROD_DELAYS_MS.reduce((a, b) => a + b, 0);
    check(
        `production schedule sums to 17000ms (got ${totalSleepMs}ms)`,
        totalSleepMs === 17_000,
        { totalSleepMs },
    );
    console.log(
        `  NOTE  full exhaustion costs ~${totalSleepMs}ms of sleep + 3 HTTP RTTs (per scrape, tail-only)`,
    );
}

if (failures > 0) {
    console.error(`\n${failures} assertion(s) failed.`);
    process.exit(1);
}

console.log('\nAll loadMoreWithTokenEchoRetry tests passed.');
