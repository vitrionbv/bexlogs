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
// AWS-style log-tail cursor; introduce caught_up stop_reason" and
// `TOKEN_ECHO_MAX_ATTEMPTS` / `TOKEN_ECHO_RETRY_DELAY_MS` in
// `scraper/src/config.ts` for context.
//
// History note: the original policy was 1 initial + 2 retries with
// progressive `[5000, 12000]ms` backoffs. Operators chose to lift the
// cap dramatically — defaults are now 100 attempts × flat 3000ms (per
// the `Flat token_echo retry policy` change) because the per-job
// retry budget is dwarfed by the 45-min subscription budget anyway.
//
// Coverage:
//   1. Flat schedule, advance at attempt 7   (tokenEchoRetries += 6;
//                                             total sleep = 6 × delayMs)
//   2. Full exhaust at maxAttempts=100       (outcome = 'exhausted';
//                                             total sleep = 99 × delayMs)
//   3. Budget bail at attempt 42             (timeBudgetOk flips false on
//                                             the 41→42 boundary; outcome
//                                             = 'budget_aborted'; no
//                                             extra sleep beyond what the
//                                             previous 40 retries cost)
//   4. Early advance at attempt 2            (one delay only)
//   5. maxAttempts override = 5              (fast-run honors the cap;
//                                             outcome = 'exhausted' after
//                                             5 attempts)
//   6. Closure throws                        (error propagates unchanged
//                                             through the helper)
//   7. minDelayMs floor                      (delayMs=1, minDelayMs default
//                                             → at least 500ms wait)
//   8. Production schedule wall-clock note   (documents the default
//                                             wall-clock cost without
//                                             actually waiting 5 min)
//
// The tests pass `minDelayMs: 0` to opt out of the production 500ms
// floor — see the `TOKEN_ECHO_MIN_DELAY_MS` export in `src/scrape.ts`.
// Without the opt-out the 100-attempt scenario would take ~50s; with
// it total wall-clock for all scenarios stays well under a second.
//
// Run with:  npx tsx scripts/token-echo-retry-test.ts
//
// Exits with non-zero on any failed assertion so CI can wire it in.

import { loadMoreWithTokenEchoRetry, TOKEN_ECHO_MIN_DELAY_MS } from '../src/scrape.js';

let failures = 0;

function check(name: string, ok: boolean, detail?: unknown): void {
    if (ok) {
        console.log(`  PASS  ${name}`);
    } else {
        failures++;
        console.error(`  FAIL  ${name}`, detail ?? '');
    }
}

const FAST_DELAY_MS = 5;
const SENT = 'TIP_TOKEN';

interface Tagged {
    body: string;
}

type FetcherStep =
    | { nextToken: string | null; tag: string }
    | { throws: string };

function makeFetcher(responses: FetcherStep[]): {
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

/**
 * Build a queue of N echo responses followed by a single advance step.
 * The advance happens on attempt `advanceAt` (1-indexed) so the helper
 * exits with `kind: 'advanced'` after `advanceAt - 1` retries.
 */
function echoesUntil(advanceAt: number): FetcherStep[] {
    const out: FetcherStep[] = [];
    for (let i = 1; i < advanceAt; i++) {
        out.push({ nextToken: SENT, tag: `attempt-${i}` });
    }
    out.push({ nextToken: 'NEW_TOKEN', tag: `attempt-${advanceAt}` });
    return out;
}

/**
 * Build a queue of `maxAttempts` echo responses (every attempt echoes,
 * helper exits with `kind: 'exhausted'`).
 */
function echoesAll(maxAttempts: number): FetcherStep[] {
    const out: FetcherStep[] = [];
    for (let i = 1; i <= maxAttempts; i++) {
        out.push({ nextToken: SENT, tag: `attempt-${i}` });
    }
    return out;
}

// ---- Scenario 1: flat schedule, advance at attempt 7 ----------------------
console.log('Scenario 1: flat schedule, advance at attempt 7');
{
    const { fetch, calls } = makeFetcher(echoesUntil(7));
    const t0 = Date.now();
    const result = await loadMoreWithTokenEchoRetry(SENT, fetch, {
        jobId: 1,
        page: 7,
        maxAttempts: 100,
        delayMs: FAST_DELAY_MS,
        minDelayMs: 0,
    });
    const elapsed = Date.now() - t0;
    const expectedMin = 6 * FAST_DELAY_MS;

    check(`outcome === 'advanced' (got '${result.kind}')`, result.kind === 'advanced');
    check(`attempts === 7 (got ${result.attempts})`, result.attempts === 7);
    check(`fetcher called 7 times (got ${calls.count})`, calls.count === 7);
    check(
        `final nextToken === 'NEW_TOKEN' (got '${result.result.nextToken}')`,
        result.result.nextToken === 'NEW_TOKEN',
    );
    check(
        `flat schedule: 6 × ${FAST_DELAY_MS}ms = ${expectedMin}ms slept (elapsed ${elapsed}ms >= ${expectedMin - 5}ms)`,
        elapsed >= expectedMin - 5,
        { elapsed, expectedMin },
    );
}

// ---- Scenario 2: full exhaust at maxAttempts = 100 ------------------------
console.log('\nScenario 2: full exhaust at maxAttempts = 100');
{
    const MAX = 100;
    const { fetch, calls } = makeFetcher(echoesAll(MAX));
    const t0 = Date.now();
    const result = await loadMoreWithTokenEchoRetry(SENT, fetch, {
        jobId: 2,
        page: 12,
        maxAttempts: MAX,
        delayMs: FAST_DELAY_MS,
        minDelayMs: 0,
    });
    const elapsed = Date.now() - t0;
    const expectedMin = (MAX - 1) * FAST_DELAY_MS;

    check(`outcome === 'exhausted' (got '${result.kind}')`, result.kind === 'exhausted');
    check(`attempts === ${MAX} (got ${result.attempts})`, result.attempts === MAX);
    check(`fetcher called ${MAX} times (got ${calls.count})`, calls.count === MAX);
    check(
        `final nextToken still === SENT (got '${result.result.nextToken}')`,
        result.result.nextToken === SENT,
    );
    check(
        `total sleep ≈ 99 × ${FAST_DELAY_MS}ms = ${expectedMin}ms (elapsed ${elapsed}ms >= ${expectedMin - 50}ms)`,
        elapsed >= expectedMin - 50,
        { elapsed, expectedMin },
    );
}

// ---- Scenario 3: budget bail at attempt 42 --------------------------------
// timeBudgetOk returns true for the first 40 sleeps (between attempts
// 1→2 through 41→42), then flips to false right before the 42→43 sleep.
// The helper should bail with kind='budget_aborted' after attempt 42's
// echo response — total fetcher calls = 42, total sleeps = 41.
console.log('\nScenario 3: budget bail at attempt 42');
{
    const BUDGET_FLIP_AT = 42;
    const { fetch, calls } = makeFetcher(echoesAll(50));
    let budgetOkCalls = 0;
    const t0 = Date.now();
    const result = await loadMoreWithTokenEchoRetry(SENT, fetch, {
        jobId: 3,
        page: 4,
        maxAttempts: 100,
        delayMs: FAST_DELAY_MS,
        minDelayMs: 0,
        timeBudgetOk: () => {
            budgetOkCalls++;
            // The helper consults timeBudgetOk before each sleep
            // (attempt 1 → wait → attempt 2, ..., attempt N → wait →
            // attempt N+1). After attempt N, the gate has been
            // consulted N times. Flip false on the (BUDGET_FLIP_AT)th
            // gate consultation so the bail happens after the 42nd
            // attempt's echo, before the 42→43 sleep.
            return budgetOkCalls < BUDGET_FLIP_AT;
        },
    });
    const elapsed = Date.now() - t0;
    const expectedMaxSleeps = (BUDGET_FLIP_AT - 1) * FAST_DELAY_MS;

    check(
        `outcome === 'budget_aborted' (got '${result.kind}')`,
        result.kind === 'budget_aborted',
        { kind: result.kind },
    );
    check(`attempts === ${BUDGET_FLIP_AT} (got ${result.attempts})`, result.attempts === BUDGET_FLIP_AT);
    check(
        `fetcher called ${BUDGET_FLIP_AT} times (got ${calls.count})`,
        calls.count === BUDGET_FLIP_AT,
    );
    check(
        `no extra sleep after the bail (elapsed ${elapsed}ms < ${expectedMaxSleeps + FAST_DELAY_MS * 5 + 50}ms)`,
        elapsed < expectedMaxSleeps + FAST_DELAY_MS * 5 + 50,
        { elapsed, expectedMaxSleeps },
    );
    check(`timeBudgetOk consulted ${BUDGET_FLIP_AT} times (got ${budgetOkCalls})`, budgetOkCalls === BUDGET_FLIP_AT);
}

// ---- Scenario 4: early advance at attempt 2 -------------------------------
console.log('\nScenario 4: early advance at attempt 2 (single delay)');
{
    const { fetch, calls } = makeFetcher(echoesUntil(2));
    const t0 = Date.now();
    const result = await loadMoreWithTokenEchoRetry(SENT, fetch, {
        jobId: 4,
        page: 5,
        maxAttempts: 100,
        delayMs: FAST_DELAY_MS,
        minDelayMs: 0,
    });
    const elapsed = Date.now() - t0;

    check(`outcome === 'advanced' (got '${result.kind}')`, result.kind === 'advanced');
    check(`attempts === 2 (got ${result.attempts})`, result.attempts === 2);
    check(`fetcher called 2 times (got ${calls.count})`, calls.count === 2);
    check(
        `final nextToken === 'NEW_TOKEN' (got '${result.result.nextToken}')`,
        result.result.nextToken === 'NEW_TOKEN',
    );
    check(
        `exactly one delay fired (elapsed ${elapsed}ms in [${FAST_DELAY_MS - 5}, ${FAST_DELAY_MS * 5}])`,
        elapsed >= FAST_DELAY_MS - 5 && elapsed < FAST_DELAY_MS * 5 + 50,
        { elapsed },
    );
}

// ---- Scenario 5: maxAttempts override (TOKEN_ECHO_MAX_ATTEMPTS=5) ---------
// Confirms that the env-driven cap takes effect: the helper exhausts
// after exactly the configured number of attempts, regardless of
// production defaults. Smaller cap keeps the test fast.
console.log('\nScenario 5: configurable maxAttempts override (TOKEN_ECHO_MAX_ATTEMPTS=5)');
{
    const OVERRIDE = 5;
    const { fetch, calls } = makeFetcher(echoesAll(OVERRIDE));
    const result = await loadMoreWithTokenEchoRetry(SENT, fetch, {
        jobId: 5,
        page: 1,
        maxAttempts: OVERRIDE,
        delayMs: FAST_DELAY_MS,
        minDelayMs: 0,
    });

    check(`outcome === 'exhausted' (got '${result.kind}')`, result.kind === 'exhausted');
    check(
        `attempts === ${OVERRIDE} (got ${result.attempts}) — override honored`,
        result.attempts === OVERRIDE,
    );
    check(`fetcher called ${OVERRIDE} times (got ${calls.count})`, calls.count === OVERRIDE);
}

// ---- Scenario 6: closure throws → error propagates ------------------------
console.log('\nScenario 6: echo → fetcher throws on attempt 2 (error propagates)');
{
    const { fetch, calls } = makeFetcher([
        { nextToken: SENT, tag: 'attempt-1' },
        { throws: 'simulated load_more HTTP 500' },
    ]);
    let caught: Error | null = null;
    try {
        await loadMoreWithTokenEchoRetry(SENT, fetch, {
            jobId: 6,
            page: 9,
            maxAttempts: 100,
            delayMs: FAST_DELAY_MS,
            minDelayMs: 0,
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

// ---- Scenario 7: minDelayMs floor (defensive) -----------------------------
// Production callers don't pass `minDelayMs`; they inherit
// TOKEN_ECHO_MIN_DELAY_MS = 500ms. Confirms that even when the env
// puts delayMs at 1ms, the helper waits at least the floor between
// attempts so we don't accidentally hammer BE.
console.log(`\nScenario 7: minDelayMs floor (delayMs=1 → at least ${TOKEN_ECHO_MIN_DELAY_MS}ms wait)`);
{
    const { fetch } = makeFetcher(echoesUntil(2));
    const t0 = Date.now();
    const result = await loadMoreWithTokenEchoRetry(SENT, fetch, {
        jobId: 7,
        page: 1,
        maxAttempts: 100,
        delayMs: 1,
        // Note: no minDelayMs override — production-default 500ms floor.
    });
    const elapsed = Date.now() - t0;

    check(`outcome === 'advanced' (got '${result.kind}')`, result.kind === 'advanced');
    check(
        `floor honored: elapsed ${elapsed}ms >= ${TOKEN_ECHO_MIN_DELAY_MS - 25}ms`,
        elapsed >= TOKEN_ECHO_MIN_DELAY_MS - 25,
        { elapsed, floor: TOKEN_ECHO_MIN_DELAY_MS },
    );
}

// ---- Scenario 8: production schedule wall-clock budget --------------------
// Document the production-schedule cost: this is the upper bound the
// runScrapeJob caller pays at the tail of a fully-exhausted echo cluster
// (per scrape, not per page). With defaults (100 attempts × 3000ms) the
// upper bound is 99 × 3s ≈ 5 min of sleep. Comfortably inside the
// 45-min per-subscription budget.
console.log('\nScenario 8: documents production schedule wall-clock cost on exhaustion');
{
    const PROD_MAX_ATTEMPTS = 100;
    const PROD_DELAY_MS = 3000;
    const totalSleepMs = (PROD_MAX_ATTEMPTS - 1) * PROD_DELAY_MS;
    const expectedTotalSleepMs = 297_000;
    check(
        `production schedule sums to ${expectedTotalSleepMs}ms (got ${totalSleepMs}ms)`,
        totalSleepMs === expectedTotalSleepMs,
        { totalSleepMs },
    );
    console.log(
        `  NOTE  full exhaustion at defaults costs ~${Math.round(totalSleepMs / 1000)}s of sleep + 100 HTTP RTTs `
            + '(per scrape, tail-only)',
    );
}

if (failures > 0) {
    console.error(`\n${failures} assertion(s) failed.`);
    process.exit(1);
}

console.log('\nAll loadMoreWithTokenEchoRetry tests passed.');
