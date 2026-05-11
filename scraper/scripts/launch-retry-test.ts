// Imperative test for `launchBrowserWithRetry`. Mirrors the structure
// of the other retry-helper tests in this directory: a hand-rolled
// launcher closure feeds the helper a queue of (success | retryable
// throw | terminal throw) tuples, and we assert the helper retries on
// the SIGSEGV-class errors, bails immediately on configuration errors,
// and pins `channel: 'chromium'` on every launch attempt.
//
// Why this helper exists (short version): production failure data
// from 2026-05-05 through 2026-05-11 showed 24 of 38 failed scrape
// jobs dying on `browserType.launch: Target page, context or browser
// has been closed`, paired with a SIGSEGV in the chromium_headless_shell
// stderr. The root cause sits in headless_shell, not the scraper —
// we mitigate by (a) opting into the full `chromium` channel via the
// launch options and (b) wrapping the launch in this bounded retry
// layer so even with the full browser, a one-off process crash on
// startup costs a few seconds of sleep rather than a failed job.
//
// Coverage:
//   1. Launch succeeds on attempt 1 (no retries)
//   2. Launch succeeds on attempt 3 (after two retryable SIGSEGVs)
//   3. Full exhaust at maxAttempts (every attempt throws the SIGSEGV
//      signature) → re-throws the final error
//   4. Terminal "Executable doesn't exist" error → no retry, throws
//      on attempt 1
//   5. Terminal "Unsupported channel" error → no retry, throws on
//      attempt 1
//   6. Unknown error message → not retryable by default (defensive;
//      we don't want to silently absorb new failure modes)
//   7. Launch options always pin `channel: 'chromium'` (the headless
//      flag is forwarded from `config.HEADLESS` and is irrelevant
//      to this check — what matters is that headless_shell is opted
//      out of)
//
// Run with:  npx tsx scripts/launch-retry-test.ts
//
// Exits with non-zero on any failed assertion so CI can wire it in.

import type { Browser } from 'playwright';
import { launchBrowserWithRetry } from '../src/scrape.js';

let failures = 0;

function check(name: string, ok: boolean, detail?: unknown): void {
    if (ok) {
        console.log(`  PASS  ${name}`);
    } else {
        failures++;
        console.error(`  FAIL  ${name}`, detail ?? '');
    }
}

const FAST_DELAYS_MS: readonly number[] = [5, 5];

type LauncherStep =
    | { kind: 'success' }
    | { kind: 'throws'; message: string };

interface LauncherRecord {
    options: { headless: boolean; channel: string };
}

function makeLauncher(steps: LauncherStep[]): {
    launcher: (o: { headless: boolean; channel: string }) => Promise<Browser>;
    calls: LauncherRecord[];
} {
    const queue = [...steps];
    const calls: LauncherRecord[] = [];
    const launcher = async (o: { headless: boolean; channel: string }): Promise<Browser> => {
        calls.push({ options: o });
        const next = queue.shift();
        if (!next) {
            throw new Error(`launcher queue underflowed (call #${calls.length})`);
        }
        if (next.kind === 'throws') {
            throw new Error(next.message);
        }
        // Return a stub that satisfies the Browser interface enough for
        // the helper's `Promise<Browser>` return contract. The helper
        // does not interact with the returned object beyond passing it
        // back to runScrapeJob, so an empty object cast is sufficient.
        return {} as Browser;
    };
    return { launcher, calls };
}

const SEGFAULT_MESSAGE =
    'browserType.launch: Target page, context or browser has been closed\n'
    + 'Browser logs:\n'
    + '<process did exit: exitCode=null, signal=SIGSEGV>';

// ---- Scenario 1: launch succeeds on attempt 1 (no retries) ---------------
console.log('Scenario 1: launch succeeds on attempt 1 (no retries)');
{
    const { launcher, calls } = makeLauncher([{ kind: 'success' }]);
    const t0 = Date.now();
    const browser = await launchBrowserWithRetry(201, {
        retryDelaysMs: FAST_DELAYS_MS,
        launcher,
    });
    const elapsed = Date.now() - t0;

    check(`launcher called 1 time (got ${calls.length})`, calls.length === 1);
    check(`browser returned (truthy)`, browser !== null && typeof browser === 'object');
    check(
        `no sleep on success (elapsed ${elapsed}ms < 25ms)`,
        elapsed < 25,
        { elapsed },
    );
    check(
        `channel: 'chromium' pinned on launch options (got '${calls[0]?.options.channel}')`,
        calls[0]?.options.channel === 'chromium',
        { options: calls[0]?.options },
    );
}

// ---- Scenario 2: launch recovers on attempt 3 (two retryable SIGSEGVs) ---
console.log('\nScenario 2: launch recovers on attempt 3 (two retryable SIGSEGVs)');
{
    const { launcher, calls } = makeLauncher([
        { kind: 'throws', message: SEGFAULT_MESSAGE },
        { kind: 'throws', message: SEGFAULT_MESSAGE },
        { kind: 'success' },
    ]);
    const t0 = Date.now();
    const browser = await launchBrowserWithRetry(202, {
        retryDelaysMs: FAST_DELAYS_MS,
        launcher,
    });
    const elapsed = Date.now() - t0;
    const expectedMin = FAST_DELAYS_MS[0]! + FAST_DELAYS_MS[1]!;

    check(`launcher called 3 times (got ${calls.length})`, calls.length === 3);
    check(`browser returned after recovery (truthy)`, browser !== null);
    check(
        `slept between attempts (elapsed ${elapsed}ms >= ${expectedMin}ms)`,
        elapsed >= expectedMin - 2,
        { elapsed, expectedMin },
    );
    check(
        `every attempt pinned channel: 'chromium' (got ${calls.map((c) => c.options.channel).join(', ')})`,
        calls.every((c) => c.options.channel === 'chromium'),
    );
}

// ---- Scenario 3: full exhaust (every attempt throws SIGSEGV) -------------
console.log('\nScenario 3: full exhaust at maxAttempts (every attempt SIGSEGVs)');
{
    const { launcher, calls } = makeLauncher([
        { kind: 'throws', message: SEGFAULT_MESSAGE },
        { kind: 'throws', message: SEGFAULT_MESSAGE },
        { kind: 'throws', message: SEGFAULT_MESSAGE },
    ]);
    let caught: Error | null = null;
    try {
        await launchBrowserWithRetry(203, {
            retryDelaysMs: FAST_DELAYS_MS,
            launcher,
        });
    } catch (err) {
        caught = err as Error;
    }

    check(`helper threw after exhaustion (got '${caught?.message?.split('\n')[0]}')`, caught !== null);
    check(
        `re-thrown error preserves the SIGSEGV signature`,
        caught?.message.startsWith('browserType.launch: Target page, context or browser has been closed') ?? false,
        { message: caught?.message },
    );
    check(
        `launcher called ${FAST_DELAYS_MS.length + 1} times before throw (got ${calls.length})`,
        calls.length === FAST_DELAYS_MS.length + 1,
    );
}

// ---- Scenario 4: terminal "Executable doesn't exist" — no retry ----------
console.log("\nScenario 4: terminal 'Executable doesn't exist' — no retry");
{
    const { launcher, calls } = makeLauncher([
        {
            kind: 'throws',
            message:
                "browserType.launch: Executable doesn't exist at /ms-playwright/chromium-9999/chrome-linux/chrome",
        },
    ]);
    let caught: Error | null = null;
    try {
        await launchBrowserWithRetry(204, {
            retryDelaysMs: FAST_DELAYS_MS,
            launcher,
        });
    } catch (err) {
        caught = err as Error;
    }

    check(`helper threw on first attempt (no retry)`, caught !== null);
    check(
        `launcher called exactly 1 time (got ${calls.length}) — no retry on missing-binary`,
        calls.length === 1,
        { calls: calls.length },
    );
    check(
        `error message preserved (got '${caught?.message?.slice(0, 60)}…')`,
        caught?.message.includes("Executable doesn't exist") ?? false,
    );
}

// ---- Scenario 5: terminal "Unsupported channel" — no retry ---------------
console.log('\nScenario 5: terminal "Unsupported channel" — no retry');
{
    const { launcher, calls } = makeLauncher([
        {
            kind: 'throws',
            message: 'browserType.launch: Unsupported channel "chromium" — install the channel first',
        },
    ]);
    let caught: Error | null = null;
    try {
        await launchBrowserWithRetry(205, {
            retryDelaysMs: FAST_DELAYS_MS,
            launcher,
        });
    } catch (err) {
        caught = err as Error;
    }

    check(`helper threw on first attempt (no retry)`, caught !== null);
    check(
        `launcher called exactly 1 time (got ${calls.length}) — no retry on config error`,
        calls.length === 1,
    );
}

// ---- Scenario 6: unknown error message — not retryable by default --------
// Defensive: we don't want a future failure mode that the codebase
// doesn't yet recognize to be silently absorbed by three retries.
// Unknown errors must surface on the first attempt so an operator
// notices the new failure signature.
console.log('\nScenario 6: unknown error message — not retryable (defensive)');
{
    const { launcher, calls } = makeLauncher([
        { kind: 'throws', message: 'some new failure mode we have not seen before' },
    ]);
    let caught: Error | null = null;
    try {
        await launchBrowserWithRetry(206, {
            retryDelaysMs: FAST_DELAYS_MS,
            launcher,
        });
    } catch (err) {
        caught = err as Error;
    }

    check(`helper threw on first attempt`, caught !== null);
    check(
        `launcher called exactly 1 time (got ${calls.length}) — unknown errors are not retried`,
        calls.length === 1,
    );
}

// ---- Scenario 7: timeout class — IS retryable ----------------------------
console.log('\nScenario 7: timeout class — IS retryable (transient slow start)');
{
    const { launcher, calls } = makeLauncher([
        { kind: 'throws', message: 'browserType.launch: Timeout 30000ms exceeded.' },
        { kind: 'success' },
    ]);
    const browser = await launchBrowserWithRetry(207, {
        retryDelaysMs: FAST_DELAYS_MS,
        launcher,
    });

    check(`browser returned after timeout retry`, browser !== null);
    check(
        `launcher called 2 times (got ${calls.length}) — timeout triggered retry`,
        calls.length === 2,
    );
}

if (failures > 0) {
    console.error(`\n${failures} assertion(s) failed.`);
    process.exit(1);
}

console.log('\nAll launchBrowserWithRetry tests passed.');
