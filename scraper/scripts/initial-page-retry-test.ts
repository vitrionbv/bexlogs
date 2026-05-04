// Imperative test for `loadInitialPageWithRetry`. Mirrors the structure
// of `token-echo-retry-test.ts`: a hand-rolled fetch closure feeds the
// helper a queue of (rows, nextToken) tuples, and we assert the helper
// retries while the response keeps coming back empty (zero rows AND
// null next_token) — and bails the right way once the queue advances,
// exhausts, or throws.
//
// Why this helper exists (short version): the original fast-exit on
// `initial.rows.length === 0 && !initial.nextToken` would turn any
// transient empty (BE load spike, Cloudflare challenge, cookie race,
// delayed hydration) into a clean `stop_reason: empty_window`
// completion in under a second — silently throwing away real data
// when the operator had explicitly configured 100 retries for the
// token_echo case. We now treat "initial page empty" with the same
// flat retry policy (`TOKEN_ECHO_MAX_ATTEMPTS` × `TOKEN_ECHO_RETRY_DELAY_MS`);
// exhaustion still produces `empty_window`, but after the helper has
// done its job. Dumping the final attempt's page HTML on exhaustion
// (via `dumpDebugArtifact` with reason `empty_window`) will let
// operators tell "truly empty window" from a persistent bot challenge
// or login bounce.
//
// Coverage:
//   1. Advance at attempt 1 (rows present immediately)  → attempts=1, no sleeps
//   2. Advance at attempt 7                             → 6 sleeps at flat delay
//   3. Full exhaust at maxAttempts=100                  → 99 sleeps; outcome='exhausted'
//   4. Budget bail at attempt 42                        → no 42nd sleep
//   5. maxAttempts=5 override (fast variant for CI)     → exhaustion at 5 attempts
//   6. Closure throws                                   → error propagates
//   7. Advance via next_token only (rows=[] but token!=null)
//       → helper treats as advance (the runScrapeJob caller will walk
//         the token even with zero initial rows; that path was always
//         valid pre-retry-layer and remains so).
//   8. minDelayMs floor (defensive, delayMs=1 respects floor)
//
// The tests pass `minDelayMs: 0` to opt out of the production 500ms
// floor — see the `TOKEN_ECHO_MIN_DELAY_MS` export in `src/scrape.ts`.
// Scenario 8 deliberately inherits the default to confirm the floor
// still fires; all other scenarios run at 5ms so total wall-clock for
// the suite stays well under a second.
//
// Run with:  npx tsx scripts/initial-page-retry-test.ts
//
// Exits with non-zero on any failed assertion so CI can wire it in.

import { loadInitialPageWithRetry, TOKEN_ECHO_MIN_DELAY_MS } from '../src/scrape.js';
import type { RawRow } from '../src/extractors.js';

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

interface Tagged {
    body: string;
}

type FetcherStep =
    | { rows: RawRow[]; nextToken: string | null; tag: string }
    | { throws: string };

function makeFetcher(responses: FetcherStep[]): {
    fetch: (attempt: number) => Promise<{ rows: RawRow[]; nextToken: string | null; payload: Tagged }>;
    calls: { count: number; attempts: number[] };
} {
    const queue = [...responses];
    const calls = { count: 0, attempts: [] as number[] };
    const fetch = async (
        attempt: number,
    ): Promise<{ rows: RawRow[]; nextToken: string | null; payload: Tagged }> => {
        calls.count++;
        calls.attempts.push(attempt);
        const next = queue.shift();
        if (!next) {
            throw new Error(`fetcher queue underflowed (call #${calls.count})`);
        }
        if ('throws' in next) {
            throw new Error(next.throws);
        }
        return { rows: next.rows, nextToken: next.nextToken, payload: { body: next.tag } };
    };
    return { fetch, calls };
}

// Minimal row factory — the helper doesn't care about field contents,
// only `rows.length`. A single placeholder row is enough to signal
// "this attempt had real data."
function row(): RawRow {
    return {
        timestamp: '2026-05-05T10:00:00Z',
        type: 'Api Call',
        action: 'list-things',
        method: 'GET',
        path: '/v1/things',
        status: '200',
        detailHtml: null,
    };
}

/**
 * Build a queue of N empty responses (zero rows, null next_token)
 * followed by a single "real" response. The advance happens on attempt
 * `advanceAt` (1-indexed) so the helper exits with `kind: 'advanced'`
 * after `advanceAt - 1` retries.
 */
function emptyUntil(advanceAt: number): FetcherStep[] {
    const out: FetcherStep[] = [];
    for (let i = 1; i < advanceAt; i++) {
        out.push({ rows: [], nextToken: null, tag: `empty-${i}` });
    }
    out.push({ rows: [row()], nextToken: 'TOKEN_FROM_ATTEMPT_' + advanceAt, tag: `advance-${advanceAt}` });
    return out;
}

/**
 * Build a queue of `maxAttempts` empty responses (every attempt is
 * empty; helper exits with `kind: 'exhausted'`).
 */
function allEmpty(maxAttempts: number): FetcherStep[] {
    const out: FetcherStep[] = [];
    for (let i = 1; i <= maxAttempts; i++) {
        out.push({ rows: [], nextToken: null, tag: `empty-${i}` });
    }
    return out;
}

// ---- Scenario 1: advance at attempt 1 (non-empty immediately) ------------
console.log('Scenario 1: advance at attempt 1 (rows present, no retries needed)');
{
    const { fetch, calls } = makeFetcher([
        { rows: [row(), row(), row()], nextToken: 'FIRST_TOKEN', tag: 'attempt-1' },
    ]);
    const t0 = Date.now();
    const result = await loadInitialPageWithRetry(fetch, {
        jobId: 101,
        maxAttempts: 100,
        delayMs: FAST_DELAY_MS,
        minDelayMs: 0,
    });
    const elapsed = Date.now() - t0;

    check(`outcome === 'advanced' (got '${result.kind}')`, result.kind === 'advanced');
    check(`attempts === 1 (got ${result.attempts})`, result.attempts === 1);
    check(`fetcher called 1 time (got ${calls.count})`, calls.count === 1);
    check(`rows === 3 (got ${result.result.rows.length})`, result.result.rows.length === 3);
    check(
        `final nextToken === 'FIRST_TOKEN' (got '${result.result.nextToken}')`,
        result.result.nextToken === 'FIRST_TOKEN',
    );
    check(
        `no sleep fired (elapsed ${elapsed}ms < ${FAST_DELAY_MS * 2}ms)`,
        elapsed < FAST_DELAY_MS * 2 + 50,
        { elapsed },
    );
}

// ---- Scenario 2: advance at attempt 7 (6 sleeps at flat delay) -----------
console.log('\nScenario 2: advance at attempt 7 (six flat-schedule retries)');
{
    const { fetch, calls } = makeFetcher(emptyUntil(7));
    const t0 = Date.now();
    const result = await loadInitialPageWithRetry(fetch, {
        jobId: 102,
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
        `final nextToken !== null (got '${result.result.nextToken}')`,
        result.result.nextToken !== null,
    );
    check(
        `rows === 1 on the advancing attempt (got ${result.result.rows.length})`,
        result.result.rows.length === 1,
    );
    check(
        `flat schedule: 6 × ${FAST_DELAY_MS}ms = ${expectedMin}ms slept (elapsed ${elapsed}ms >= ${expectedMin - 5}ms)`,
        elapsed >= expectedMin - 5,
        { elapsed, expectedMin },
    );
}

// ---- Scenario 3: full exhaust at maxAttempts = 100 -----------------------
console.log('\nScenario 3: full exhaust at maxAttempts = 100');
{
    const MAX = 100;
    const { fetch, calls } = makeFetcher(allEmpty(MAX));
    const t0 = Date.now();
    const result = await loadInitialPageWithRetry(fetch, {
        jobId: 103,
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
        `final rows === [] (got ${result.result.rows.length})`,
        result.result.rows.length === 0,
    );
    check(
        `final nextToken === null (got '${result.result.nextToken}')`,
        result.result.nextToken === null,
    );
    check(
        `total sleep ≈ 99 × ${FAST_DELAY_MS}ms = ${expectedMin}ms (elapsed ${elapsed}ms >= ${expectedMin - 50}ms)`,
        elapsed >= expectedMin - 50,
        { elapsed, expectedMin },
    );
}

// ---- Scenario 4: budget bail at attempt 42 -------------------------------
// timeBudgetOk returns true for the first 41 sleeps (between attempts
// 1→2 through 41→42), then flips to false right before the 42→43 sleep.
// The helper should bail with kind='budget_aborted' after attempt 42's
// empty response — total fetcher calls = 42, no 42→43 sleep.
console.log('\nScenario 4: budget bail at attempt 42');
{
    const BUDGET_FLIP_AT = 42;
    const { fetch, calls } = makeFetcher(allEmpty(50));
    let budgetOkCalls = 0;
    const t0 = Date.now();
    const result = await loadInitialPageWithRetry(fetch, {
        jobId: 104,
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
            // attempt's empty response, before the 42→43 sleep.
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
    check(
        `attempts === ${BUDGET_FLIP_AT} (got ${result.attempts})`,
        result.attempts === BUDGET_FLIP_AT,
    );
    check(
        `fetcher called ${BUDGET_FLIP_AT} times (got ${calls.count})`,
        calls.count === BUDGET_FLIP_AT,
    );
    check(
        `no extra sleep after the bail (elapsed ${elapsed}ms < ${expectedMaxSleeps + FAST_DELAY_MS * 5 + 50}ms)`,
        elapsed < expectedMaxSleeps + FAST_DELAY_MS * 5 + 50,
        { elapsed, expectedMaxSleeps },
    );
    check(
        `timeBudgetOk consulted ${BUDGET_FLIP_AT} times (got ${budgetOkCalls})`,
        budgetOkCalls === BUDGET_FLIP_AT,
    );
}

// ---- Scenario 5: maxAttempts override (fast variant for CI) --------------
// Confirms that the env-driven cap takes effect: the helper exhausts
// after exactly the configured number of attempts, regardless of
// production defaults. Smaller cap keeps the test fast.
console.log('\nScenario 5: configurable maxAttempts override (TOKEN_ECHO_MAX_ATTEMPTS=5)');
{
    const OVERRIDE = 5;
    const { fetch, calls } = makeFetcher(allEmpty(OVERRIDE));
    const result = await loadInitialPageWithRetry(fetch, {
        jobId: 105,
        maxAttempts: OVERRIDE,
        delayMs: FAST_DELAY_MS,
        minDelayMs: 0,
    });

    check(`outcome === 'exhausted' (got '${result.kind}')`, result.kind === 'exhausted');
    check(
        `attempts === ${OVERRIDE} (got ${result.attempts}) — override honored`,
        result.attempts === OVERRIDE,
    );
    check(
        `fetcher called ${OVERRIDE} times (got ${calls.count})`,
        calls.count === OVERRIDE,
    );
}

// ---- Scenario 6: closure throws → error propagates -----------------------
console.log('\nScenario 6: empty → fetcher throws on attempt 2 (error propagates)');
{
    const { fetch, calls } = makeFetcher([
        { rows: [], nextToken: null, tag: 'empty-1' },
        { throws: 'simulated navigation timeout' },
    ]);
    let caught: Error | null = null;
    try {
        await loadInitialPageWithRetry(fetch, {
            jobId: 106,
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
        caught?.message === 'simulated navigation timeout',
    );
    check(`fetcher called 2 times before throw (got ${calls.count})`, calls.count === 2);
}

// ---- Scenario 7: advance on nextToken alone (rows=[] but token!=null) ----
// The advance predicate is `rows.length > 0 OR nextToken !== null`.
// A zero-row attempt that still surfaces a forward-moving cursor counts
// as advanced — the caller will walk the token via the load_more loop
// even with no initial rows. This path existed pre-retry-layer; the
// helper must preserve it so log windows whose initial page legitimately
// has no rows but does have a pagination cursor (legacy BE layouts, etc.)
// aren't needlessly retried.
console.log('\nScenario 7: advance on nextToken alone (rows=[] but token present)');
{
    const { fetch, calls } = makeFetcher([
        { rows: [], nextToken: 'CURSOR_WITHOUT_ROWS', tag: 'attempt-1' },
    ]);
    const result = await loadInitialPageWithRetry(fetch, {
        jobId: 107,
        maxAttempts: 100,
        delayMs: FAST_DELAY_MS,
        minDelayMs: 0,
    });

    check(`outcome === 'advanced' (got '${result.kind}')`, result.kind === 'advanced');
    check(`attempts === 1 (got ${result.attempts})`, result.attempts === 1);
    check(`fetcher called 1 time (got ${calls.count})`, calls.count === 1);
    check(
        `final nextToken === 'CURSOR_WITHOUT_ROWS' (got '${result.result.nextToken}')`,
        result.result.nextToken === 'CURSOR_WITHOUT_ROWS',
    );
    check(`rows is empty (got ${result.result.rows.length})`, result.result.rows.length === 0);
}

// ---- Scenario 8: minDelayMs floor (defensive) ----------------------------
// Production callers don't pass `minDelayMs`; they inherit
// TOKEN_ECHO_MIN_DELAY_MS = 500ms. Confirms that even when the env
// puts delayMs at 1ms, the helper waits at least the floor between
// attempts so we don't accidentally hammer BE on a misconfigured
// deploy.
console.log(
    `\nScenario 8: minDelayMs floor (delayMs=1 → at least ${TOKEN_ECHO_MIN_DELAY_MS}ms wait)`,
);
{
    const { fetch } = makeFetcher(emptyUntil(2));
    const t0 = Date.now();
    const result = await loadInitialPageWithRetry(fetch, {
        jobId: 108,
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

if (failures > 0) {
    console.error(`\n${failures} assertion(s) failed.`);
    process.exit(1);
}

console.log('\nAll loadInitialPageWithRetry tests passed.');
