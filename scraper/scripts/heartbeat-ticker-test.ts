// Imperative test for the per-job heartbeat ticker. Verifies:
//   1. The ticker fires `heartbeatFn` on a steady cadence while running.
//   2. `stop()` halts further ticks immediately.
//   3. `stop()` is idempotent (calling it twice doesn't crash / fire ticks).
//   4. A failing `heartbeatFn` does NOT crash the ticker — the next tick
//      still fires (the production path swallows transient errors).
//
// Mirrors the parse-fixtures.ts harness style: imperative, exits non-zero
// on assertion failure so CI can wire it in. Intentionally avoids real
// HTTP — a bare interval is what we're testing here.
//
// Run with:  npx tsx scripts/heartbeat-ticker-test.ts

import { startHeartbeatTicker } from '../src/heartbeat.js';

let failures = 0;

function check(name: string, ok: boolean, detail?: unknown): void {
    if (ok) {
        console.log(`  PASS  ${name}`);
    } else {
        failures++;
        console.error(`  FAIL  ${name}`, detail ?? '');
    }
}

const sleep = (ms: number) => new Promise<void>((r) => setTimeout(r, ms));

// ---- Scenario 1: ticker fires on cadence and stops cleanly ----------------
console.log('Scenario 1: ticker fires on cadence and stops cleanly');
{
    const calls: number[] = [];
    // 50ms interval, observe for ~210ms → expect 3-5 ticks (timer jitter ok).
    const ticker = startHeartbeatTicker(123, {
        intervalMs: 50,
        heartbeatFn: async (jobId) => {
            calls.push(jobId);
        },
    });

    await sleep(210);
    const inflight = calls.length;
    ticker.stop();

    check(
        `fired ~3-5 times during ~210ms window (got ${inflight})`,
        inflight >= 3 && inflight <= 5,
        { calls: inflight },
    );
    check(
        `each call carries the jobId (123)`,
        calls.every((id) => id === 123),
        { calls },
    );

    await sleep(150);
    check(
        `no ticks fire after stop() (was ${inflight}, now ${calls.length})`,
        calls.length === inflight,
        { before: inflight, after: calls.length },
    );
}

// ---- Scenario 2: stop() is idempotent --------------------------------------
console.log('\nScenario 2: stop() is idempotent');
{
    const ticker = startHeartbeatTicker(7, {
        intervalMs: 50,
        heartbeatFn: async () => undefined,
    });
    ticker.stop();
    let threw = false;
    try {
        ticker.stop();
        ticker.stop();
    } catch (err) {
        threw = true;
        console.error('  stop() threw on second call', err);
    }
    check('stop() can be called multiple times safely', !threw);
}

// ---- Scenario 3: failing heartbeatFn doesn't kill the ticker ---------------
console.log('\nScenario 3: failing heartbeatFn is logged + swallowed');
{
    let attempts = 0;
    const ticker = startHeartbeatTicker(42, {
        intervalMs: 40,
        heartbeatFn: async () => {
            attempts++;
            throw new Error('simulated network blip');
        },
    });

    await sleep(170);
    ticker.stop();

    check(
        `ticker kept firing despite rejections (attempts=${attempts}, expected >= 3)`,
        attempts >= 3,
        { attempts },
    );
}

if (failures > 0) {
    console.error(`\n${failures} assertion(s) failed.`);
    process.exit(1);
}

console.log('\nAll heartbeat ticker tests passed.');
