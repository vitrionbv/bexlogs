import { mkdir, readdir, stat, unlink, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
    chromium,
    type APIRequestContext,
    type APIResponse,
    type Browser,
    type BrowserContext,
    type Page,
    type Response,
} from 'playwright';
import { config, BEX_BASE_URLS, type Environment } from './config.js';
import { extractRowsFromMain, parseLoadMoreResponse, type RawRow } from './extractors.js';
import { rowToMessage } from './rowParser.js';
import { log } from './log.js';
import { postBatch, completeJob, failJob, heartbeat, reportSessionExpired } from './api.js';
import { startHeartbeatTicker } from './heartbeat.js';
import type { ParsedLogMessage, ScrapeJob, StopReason } from './types.js';

const SESSION_EXPIRED_SENTINEL = 'SESSION_EXPIRED';

/**
 * Backoff schedule (ms between attempts) for /load_more_logs.js when
 * BookingExperts replies with 422. The user-facing semantics: 422 means
 * we're hitting BE too hard (rate limit / concurrency cap), so a single
 * 422 isn't a job-killer — but if it survives a 2s and a 5s pause we
 * surrender and fail the job so the operator notices and lowers
 * MAX_CONCURRENT_SCRAPES. Total wall-clock cost on full exhaustion:
 * 7s + the three HTTP round-trips themselves (well within the 30s per-
 * request timeout, well below the 3-min reaper threshold).
 */
const LOAD_MORE_422_RETRY_DELAYS_MS: readonly number[] = [2000, 5000];

/**
 * Backoff schedule (ms between attempts) for the "BookingExperts redirected
 * us to /sign_in" path. The schedule covers two distinct scenarios:
 *
 *   1. **Stale-cookie expiry** (most common in production right now): the
 *      cookies in BexSession.cookies are no longer accepted by BE for
 *      deep `/organizations/.../logs` URLs even though the validator
 *      (`BookingExpertsClient::validateSession`) reports the session as
 *      "still valid". The validator is too lenient — it hits `GET /`,
 *      sees a 301 to `/redirect?locale=nl` and accepts that as valid
 *      because the immediate Location doesn't contain `/sign_in`. Follow
 *      the chain and `/redirect?locale=nl` itself 302s to `/sign_in` for
 *      a stale session. Retries here will not recover (every retry hits
 *      the same `/sign_in`); we accept the session_expired classification
 *      after the schedule exhausts, the user re-links via the extension,
 *      and the next scrape uses fresh cookies. Validator-side fix is
 *      tracked separately by the sibling worker handling the re-auth
 *      flow.
 *
 *   2. **Transient anti-bot / concurrency bounce** (defensive — observed
 *      historically but not currently the dominant failure mode): BE
 *      occasionally bounces parallel same-cookie requests to /sign_in
 *      even when the cookies are valid; a 3-7s pause is usually enough
 *      for the burst window to elapse and the second attempt to
 *      succeed. The retry layer earns its keep here.
 *
 * Total wall-clock cost on full exhaustion: 10s of sleep + the three
 * navigations themselves. Comfortably under the 30s per-nav timeout and
 * the 3-min reaper threshold.
 *
 * Operator note: if `Session expired` failures cluster while the
 * Authenticate page still shows the BexSession as linked, see
 * `deploy/README.md` ("Diagnosing Session expired failures") — the
 * usual fix is re-linking via the extension popup, NOT a scraper
 * change.
 */
const SIGN_IN_RETRY_DELAYS_MS: readonly number[] = [3000, 7000];

/**
 * Defensive floor for the token-echo retry delay (ms). Even if an
 * operator drops `TOKEN_ECHO_RETRY_DELAY_MS` to 0 or 1ms by accident,
 * the helper waits at least this long between attempts — hammering BE
 * with zero-delay retries defeats the point of the helper and would
 * either trip rate-limiting or load the upstream needlessly during a
 * quiet-tip window. Production default is 3000ms; the floor only
 * matters for misconfigured / test setups.
 *
 * Exported so the offline test harness can opt out of the floor (via
 * `minDelayMs: 0`) for fast wall-clock-bounded scenarios. Production
 * callers don't pass `minDelayMs` and inherit this default.
 */
export const TOKEN_ECHO_MIN_DELAY_MS = 500;

/**
 * Token-echo retry policy. When BookingExperts returns the same
 * `next_token` we just sent (the AWS CloudWatch Logs `nextForwardToken
 * === inputToken` "you're at the live tip" signal), we re-poll on a
 * flat schedule (no ramp) up to a configurable cap. Defaults — both
 * env-driven, see `scraper/src/config.ts`:
 *
 *   - `TOKEN_ECHO_MAX_ATTEMPTS = 100` (1 initial + 99 retries)
 *   - `TOKEN_ECHO_RETRY_DELAY_MS = 3000`
 *
 * Wall-clock cost on full exhaustion at the defaults: 99 × 3s ≈ 5 min
 * of sleep + 100 RTTs. Comfortably inside the 45-min per-subscription
 * job budget, so the budget-bail (`time_limit`) only fires when the
 * scrape actually spent its budget elsewhere. Heartbeat liveness is
 * preserved across the sleeps because the dedicated ticker (see
 * `heartbeat.ts`) runs on its own interval.
 *
 * Outcomes from `loadMoreWithTokenEchoRetry`:
 *   - any retry advances the token → `advanced` (continue scraping)
 *   - max attempts exhausted with no advance → `exhausted`, caller
 *     stops with `stop_reason: caught_up` (the AWS-style "we're at
 *     the live tip" completion).
 *   - per-job `budgetMs` would be exceeded by the next sleep →
 *     `budget_aborted`, caller stops with the existing `time_limit`
 *     exit. Budget is checked BEFORE each sleep so we never oversleep
 *     past the boundary.
 *
 * History: the previous policy was 1 initial + 2 retries with
 * `[5000, 12000]ms` backoffs (hard-fail-into-`caught_up` on
 * exhaustion). Operators chose to lift the cap dramatically because
 * the retry budget is dwarfed by the per-job time budget anyway —
 * "keep pushing the button" is cheap and gives BE many more chances
 * to flush a pending batch before we declare the subscription caught
 * up.
 */

const DEBUG_DUMP_DIR = path.resolve(
    path.dirname(fileURLToPath(import.meta.url)),
    '..',
    'debug',
);

// Retention guard for `scraper/debug/` (≈ /app/debug inside the container).
// Bound by both age and total bytes so a chatty failure mode can't blow the
// container's filesystem. Pruning runs once per dump (cheap — single readdir
// + stat per entry) so an idle scraper never touches the directory.
const DEBUG_RETENTION_MS = 14 * 24 * 60 * 60 * 1000;
const DEBUG_MAX_TOTAL_BYTES = 200 * 1024 * 1024;

export interface ScrapeResult {
    pages: number;
    rows: number;
    duration_ms: number;
    aborted_due_to_time: boolean;
    early_stopped_due_to_duplicates: boolean;
    total_duplicates: number;
    stop_reason: StopReason;
    /**
     * Number of `next_token` echoes the retry helper had to absorb during
     * the scrape. Diagnostic only: lets the operator tell whether the
     * helper fired at all (zero across many runs would mean the helper
     * is over-scoped and could be tightened) or always exhausts (means
     * BE's cursor is strictly "at tip" and `caught_up` accurately
     * reflects natural-end). NOT a `stop_reason` on its own — we never
     * fail or label a job because of this value.
     */
    token_echo_retries: number;
}

/**
 * Run a single scrape job end-to-end:
 *   1. Launch a fresh browser context with the user's BookingExperts cookies.
 *   2. GET the initial logs page once (Playwright handles any anti-bot JS).
 *   3. Parse the rendered DOM for rows + extract the first next_token.
 *   4. Loop XHR-fetching /load_more_logs.js?next_token=… via APIRequestContext
 *      (no clicks, no DOM-update waits) until exhausted.
 *   5. Convert each row's inline detail HTML into a ParsedLogMessage and POST
 *      in batches of `BATCH_SIZE` to Laravel.
 */
export async function runScrapeJob(job: ScrapeJob): Promise<ScrapeResult> {
    const startedAt = Date.now();
    // Per-subscription wall-clock budget. Defaults to 10 minutes when the
    // server didn't supply max_duration_minutes (older Laravel API or
    // worker run before Subscription budget knobs existed).
    const budgetMs = (job.params.max_duration_minutes ?? 10) * 60_000;
    const env = job.subscription.environment;

    // Start the liveness ticker the moment the job is picked up, BEFORE
    // chromium.launch(). Browser startup, slow page loads, and quiet
    // pagination windows don't flush batches, so without a dedicated
    // ticker the row's `last_heartbeat_at` would drift past the reaper's
    // 3-minute threshold (see `app/Console/Commands/ScrapeReapStale.php`)
    // and a perfectly alive job would be falsely failed.
    const heartbeatTicker = startHeartbeatTicker(job.id);

    let browser: Browser | null = null;
    let context: BrowserContext | null = null;
    let pageCount = 0;
    let rowCount = 0;
    let abortedDueToTime = false;
    // Tracks which break/return path the main loop took. `undefined` until
    // a branch sets it explicitly: the post-loop fall-through then decides
    // between the two ambiguous cases (pagination_limit vs token_missing)
    // by inspecting pageCount/maxPages. The catch block forwards whatever
    // is set when the job fails so failJob can persist a typed reason
    // alongside the freeform error message.
    let stopReason: StopReason | undefined;
    // Early-stop tracking: count how many consecutive load_more pages came
    // back as 100% duplicates (received > 0 && inserted === 0), and the
    // running total of (received - inserted) across the whole job. The
    // initial-page batch contributes to totalDuplicatesObserved (so tiny
    // subscriptions that already hold all rows can still trip the threshold)
    // but NOT to the consecutive-pages counter (that only makes sense for
    // paginated batches).
    let consecutiveAllDuplicatePages = 0;
    let totalDuplicatesObserved = 0;
    let earlyStoppedDueToDuplicates = false;
    // Counts every wasted echo attempt across the scrape (across all
    // pages). We compute as `attempts - 1` per retry-helper invocation
    // so a clean run (no echoes) records zero and an exhausted tail
    // contributes `TOKEN_ECHO_MAX_ATTEMPTS - 1` (default 99). Surfaced
    // via `stats.token_echo_retries` for diagnostic use only.
    let tokenEchoRetries = 0;
    const earlyStopPages =
        job.params.early_stop_duplicate_pages ?? config.EARLY_STOP_DUPLICATE_PAGES;
    const earlyStopMinDups =
        job.params.early_stop_min_duplicates ?? config.EARLY_STOP_MIN_DUPLICATES;

    // Most recent BookingExperts request (initial page or load_more). Read by
    // the catch block so a single ERROR line gives the operator enough
    // context to triage without grepping through earlier log entries or
    // SSHing into the container for a debug artifact. Updated at the
    // detection points only — no per-request overhead on the success path.
    let lastDiagnostic: LastDiagnostic | null = null;

    try {
        browser = await chromium.launch({ headless: config.HEADLESS });
        context = await browser.newContext({
            userAgent:
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/127.0.0.0 Safari/537.36',
            viewport: { width: 1440, height: 900 },
            locale: 'nl-NL',
        });

        await context.addCookies(toPlaywrightCookies(job.session.cookies, env));

        await context.addInitScript(() => {
            Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
        });

        const page = await context.newPage();
        const initialUrl = buildLogsUrl(job);
        log.info('navigating to logs page', { jobId: job.id, url: initialUrl });

        const navResult = await navigateInitialWithSignInRetry(page, initialUrl, job.id);
        const response = navResult.response;
        lastDiagnostic = {
            phase: 'initial',
            requestUrl: initialUrl,
            finalUrl: page.url(),
            status: response.status(),
            bodyPreview: navResult.bouncedToSignIn
                ? previewBody(navResult.lastBody)
                : null,
        };

        if (navResult.bouncedToSignIn) {
            await dumpDebugArtifact(job.id, 0, navResult.lastBody, 'session_expired', {
                subscription: job.subscription,
                pageCount: 0,
                previousToken: null,
                url: page.url(),
            });
            throw new Error(SESSION_EXPIRED_SENTINEL);
        }
        if (response.status() >= 400) throw new Error(`initial page HTTP ${response.status()}`);

        // First batch: parse the rendered DOM.
        const initial = await page.evaluate(extractRowsFromMain);
        log.debug('initial extraction diagnostics', initial.diagnostics);
        log.info('initial batch parsed', { jobId: job.id, rows: initial.rows.length, hasNextToken: !!initial.nextToken });

        if (initial.rows.length === 0) {
            log.warn('no rows on initial page — selector resolution may need an update', initial.diagnostics);
        }

        const initialStats = await flushRows(job, initial.rows, (n) => (rowCount += n), 1);
        totalDuplicatesObserved += initialStats.duplicates;
        pageCount++;

        // Server gave us a fully-rendered page with zero rows AND no
        // next_token to chase — there's nothing to paginate through and
        // nothing to show. This is the only "no next_token" case that
        // counts as a clean completion; everywhere else a missing
        // next_token is treated as `token_missing` (failure).
        if (initial.rows.length === 0 && !initial.nextToken) {
            stopReason = 'empty_window';
        }

        // Subsequent pages: pure XHR via APIRequestContext (no clicks).
        let nextToken = initial.nextToken;
        const apiCtx: APIRequestContext = context.request;
        const referer = page.url();
        // Runaway-safety counter: BE legitimately returns zero-row pages
        // during quiet windows on a subscription (e.g. overnight) while
        // next_token still advances normally. We keep walking through them
        // until we hit real data or one of the real stop conditions
        // (max_pages, max_duration, token-not-advancing, 422, …). This
        // counter only exists to bound an upstream bug where next_token
        // would be eternally valid AND every page was empty.
        let consecutiveZeroRowPages = 0;

        // Most-recent /load_more_logs.js exchange, captured at the top of
        // each loop iteration (after we receive the body). Tracked outside
        // the loop so the post-loop `token_missing` fall-through can dump
        // the body that produced the null next_token. The inline failure
        // sites for `unparseable` and the eval-fallback `caught_up` guard
        // already have `body`, `url`, and `sentToken` in lexical scope so
        // they don't read these — but they're cheap to maintain, so we
        // keep them updated.
        let lastBody: string | null = null;
        let lastBodyPage = 0;
        let lastUrl: string | null = null;
        let lastSentToken: string | null = null;

        while (nextToken && pageCount < (job.params.max_pages ?? config.MAX_PAGES_PER_JOB)) {
            if (Date.now() - startedAt > budgetMs) {
                log.warn('scrape budget exceeded — aborting cleanly', {
                    jobId: job.id,
                    budgetMs,
                    pages: pageCount,
                    rows: rowCount,
                });
                abortedDueToTime = true;
                stopReason = 'time_limit';
                nextToken = null;
                break;
            }

            // Capture the token before issuing the request so the retry
            // layer (which may re-fetch on echo) and the post-loop
            // `token_missing` dump have a stable reference even after
            // `nextToken` is reassigned to whatever the response advanced
            // to.
            const sentToken = nextToken;

            const echoOutcome = await loadMoreWithTokenEchoRetry(
                sentToken,
                async (attempt) => {
                    const url = buildLoadMoreUrl(job, sentToken);
                    log.debug('load_more', { jobId: job.id, page: pageCount, url, attempt });

                    const xhrResult = await loadMoreWithSignInRetry(apiCtx, url, referer, {
                        jobId: job.id,
                        page: pageCount + 1,
                    });

                    if (xhrResult === '422_exhausted') {
                        // We retried twice with backoffs and BookingExperts is
                        // still rate-limiting us. Surface this as a hard failure
                        // so the operator notices and can lower
                        // MAX_CONCURRENT_SCRAPES — silently completing here would
                        // hide a systemic concurrency problem. Throwing inside
                        // the retry-helper closure propagates straight up to
                        // the outer try/catch with the typed reason set.
                        stopReason = 'pagination_error';
                        throw new Error(
                            `BookingExperts returned 422 after ${LOAD_MORE_422_RETRY_DELAYS_MS.length + 1} attempts `
                                + `(1 initial + ${LOAD_MORE_422_RETRY_DELAYS_MS.length} retries). `
                                + 'Consider lowering MAX_CONCURRENT_SCRAPES.',
                        );
                    }

                    const xhr: APIResponse = xhrResult;
                    lastDiagnostic = {
                        phase: 'load_more',
                        requestUrl: url,
                        finalUrl: xhr.url(),
                        status: xhr.status(),
                        bodyPreview: null,
                    };
                    if (xhr.status() === 401 || xhr.status() === 403) {
                        // We've already exhausted the SIGN_IN_RETRY schedule
                        // inside loadMoreWithSignInRetry. The status here is
                        // final.
                        const failBody = await xhr.text().catch(() => '<xhr.text() failed>');
                        lastDiagnostic.bodyPreview = previewBody(failBody);
                        await dumpDebugArtifact(job.id, pageCount + 1, failBody, 'session_expired', {
                            subscription: job.subscription,
                            pageCount,
                            previousToken: sentToken,
                            url,
                        });
                        throw new Error(SESSION_EXPIRED_SENTINEL);
                    }
                    if (!xhr.ok()) {
                        throw new Error(`load_more HTTP ${xhr.status()}`);
                    }

                    const body = await xhr.text();

                    if (config.DEBUG_DUMP_LOADMORE || config.LOG_LEVEL === 'debug') {
                        await dumpLoadMoreResponse(job.id, pageCount + 1, body);
                        log.info('load_more raw body', {
                            jobId: job.id,
                            page: pageCount + 1,
                            preview: previewBody(body),
                        });
                    }

                    const parsed = parseLoadMoreResponse(body);
                    return {
                        nextToken: parsed.nextToken,
                        payload: { xhr, body, url, parsed },
                    };
                },
                {
                    jobId: job.id,
                    page: pageCount + 1,
                    maxAttempts: config.TOKEN_ECHO_MAX_ATTEMPTS,
                    delayMs: config.TOKEN_ECHO_RETRY_DELAY_MS,
                    // Bail before sleeping if the per-job time budget
                    // would be exceeded by the time we'd wake up. The
                    // helper checks BEFORE each sleep so we never
                    // oversleep past the budget; the loop's existing
                    // `time_limit` handling then takes over below.
                    timeBudgetOk: (plannedDelayMs) =>
                        Date.now() + plannedDelayMs - startedAt < budgetMs,
                },
            );

            tokenEchoRetries += Math.max(0, echoOutcome.attempts - 1);

            if (echoOutcome.kind === 'budget_aborted') {
                log.warn('token-echo retry would exceed time budget — aborting cleanly', {
                    jobId: job.id,
                    page: pageCount,
                    attempts: echoOutcome.attempts,
                    tokenPrefix: sentToken.slice(0, 12),
                });
                abortedDueToTime = true;
                stopReason = 'time_limit';
                nextToken = null;
                break;
            }

            const { xhr, body, url, parsed } = echoOutcome.result.payload;

            // Snapshot the exchange so the post-loop `token_missing`
            // fall-through can dump the body that produced the null
            // next_token. Captured BEFORE the rowsHtml-handling block
            // mutates `nextToken`, so the dump reflects what the server
            // actually sent us in this iteration (the final retry
            // attempt, if the helper retried).
            lastBody = body;
            lastBodyPage = pageCount + 1;
            lastUrl = url;
            lastSentToken = sentToken;

            if (echoOutcome.kind === 'exhausted') {
                // Echo retries exhausted → BookingExperts has nothing new
                // for us right now and is sitting at the live tip of the
                // log stream (the AWS CloudWatch `nextForwardToken ===
                // inputToken` semantic). This is the healthy "we have all
                // currently-available data" completion, equivalent in
                // spirit to `duplicate_detection`; the next scheduled
                // scrape run will pick up new events as BE appends them.
                // Dump the body for diagnostics — the first few production
                // exhaustions confirm the pattern still holds.
                log.info('caught up to BookingExperts log-tip after token-echo retries', {
                    jobId: job.id,
                    page: pageCount + 1,
                    attempts: echoOutcome.attempts,
                    tokenPrefix: sentToken.slice(0, 12),
                });
                await dumpDebugArtifact(job.id, pageCount + 1, body, 'token_echo', {
                    subscription: job.subscription,
                    pageCount,
                    previousToken: sentToken,
                    url,
                });
                stopReason = 'caught_up';
                nextToken = null;
                break;
            }

            // echoOutcome.kind === 'advanced' — proceed with rowsHtml
            // handling using the final attempt's parse result. Same logic
            // as before the retry layer existed; the only thing that
            // changed is that the body in hand belongs to the attempt
            // whose nextToken was no longer an echo.
            let rows: RawRow[] = [];
            let parsedViaEval = false;

            if (typeof parsed.rowsHtml === 'string' && parsed.rowsHtml.length > 0) {
                rows = await extractRowsFromHtmlString(page, parsed.rowsHtml);
                nextToken = parsed.nextToken;
            } else if (parsed.rowsHtml === '') {
                // Recognized response shape but server returned zero rows
                // (typical BookingExperts quiet window — overnight, weekend,
                // any time-slice without events). Skip the in-page eval
                // fallback (it would just re-run a no-op `.append("")`) and
                // advance through the next_token the server still handed us.
                // The pageReceived === 0 branch below logs the quiet-window
                // page and the runaway-safety cap is still enforced.
                nextToken = parsed.nextToken;
            } else {
                // parsed.rowsHtml === null: couldn't parse the response shape
                // at all. If the body looks like Rails-UJS JS, fall back to
                // executing it inside the live page so any DOM-mutating side
                // effects happen naturally.
                const looksLikeJs =
                    /^\s*[;$]/.test(body)
                    || body.includes('$(')
                    || body.includes('document.querySelector(');

                if (looksLikeJs) {
                    const COUNT_SELECTOR = 'tr.table__row, article, [data-log-event]';
                    const beforeCount = await page.evaluate(
                        (sel) => document.querySelectorAll(sel).length,
                        COUNT_SELECTOR,
                    );
                    await page.evaluate((src) => {
                        try {
                            (0, eval)(src);
                        } catch (e) {
                            console.error('[bexlogs] in-page eval failed', e);
                        }
                    }, body);
                    const afterCount = await page.evaluate(
                        (sel) => document.querySelectorAll(sel).length,
                        COUNT_SELECTOR,
                    );
                    if (afterCount > beforeCount) {
                        parsedViaEval = true;
                        const live = await page.evaluate(extractRowsFromMain);
                        rows = live.rows.slice(beforeCount);
                        // Pass the live page's next_token through unchanged.
                        // Echoes from this code path are handled by the
                        // defensive check at the bottom of this iteration
                        // (the AWS-style `caught_up` outcome — equivalent
                        // to the retry helper's exhaustion). Pre-emptively
                        // nulling an echoed live token here used to
                        // misclassify it as `token_missing` once the loop
                        // exited.
                        nextToken = live.nextToken ?? null;
                        log.info('load_more batch via in-page eval', {
                            jobId: job.id,
                            page: pageCount + 1,
                            rows: rows.length,
                            beforeCount,
                            afterCount,
                        });
                    }
                }

                if (!parsedViaEval) {
                    // Last-resort: parseLoadMoreResponse already scans the
                    // full body for next_token=… (see tokenSources fallback)
                    // and applied the "didn't advance" guard. If a forward-
                    // moving token still exists, keep walking — the body was
                    // unparseable but pagination state is intact. Stop only
                    // when there's truly nothing left to chase.
                    if (parsed.nextToken !== null) {
                        log.warn('load_more body unparseable but next_token advances — continuing', {
                            jobId: job.id,
                            page: pageCount + 1,
                            bodyPreview: previewBody(body, 240),
                        });
                        nextToken = parsed.nextToken;
                    } else {
                        // Hard fail: response shape is unrecognized AND
                        // pagination state is gone. Pretending this is a
                        // clean completion would mask a real upstream
                        // change (BookingExperts revising the load_more
                        // JS shape). Surface as a destructive badge.
                        log.error('load_more response had no parseable HTML payload — failing', {
                            jobId: job.id,
                            bodyPreview: previewBody(body, 240),
                        });
                        stopReason = 'unparseable';
                        await dumpDebugArtifact(job.id, pageCount + 1, body, 'unparseable', {
                            subscription: job.subscription,
                            pageCount,
                            previousToken: sentToken,
                            url,
                        });
                        throw new Error(
                            'load_more response had no parseable HTML payload and no next_token — '
                                + 'BookingExperts response shape may have changed.',
                        );
                    }
                }
            }

            if (!parsedViaEval) {
                log.info('load_more batch parsed', {
                    jobId: job.id,
                    page: pageCount + 1,
                    rows: rows.length,
                });
            }
            const pageStats = await flushRows(job, rows, (n) => (rowCount += n), pageCount + 1);
            pageCount++;

            // Track duplicate-density to detect re-walking already-scraped
            // territory. `received` reflects how many rows the API ingested
            // (post in-batch dedup); `inserted` is the number that survived
            // the (page_id, content_hash) unique index. The gap is the
            // duplicates rejected on the server side.
            const pageInserted = pageStats.inserted;
            const pageReceived = pageStats.received;
            totalDuplicatesObserved += Math.max(0, pageReceived - pageInserted);

            if (pageReceived > 0 && pageInserted === 0) {
                consecutiveAllDuplicatePages++;
            } else {
                consecutiveAllDuplicatePages = 0;
            }

            if (
                consecutiveAllDuplicatePages >= earlyStopPages
                && totalDuplicatesObserved >= earlyStopMinDups
            ) {
                // The healthy completion path: pagination has caught up
                // with already-scraped data. This is the ONLY signal that
                // means "we're done in the natural sense" — every other
                // exit condition is either an operator-visible cap or a
                // hard failure.
                log.info('early-stop: caught up with already-scraped territory', {
                    jobId: job.id,
                    consecutiveAllDuplicatePages,
                    totalDuplicatesObserved,
                    page: pageCount,
                });
                nextToken = null;
                earlyStoppedDueToDuplicates = true;
                stopReason = 'duplicate_detection';
                break;
            }

            // Quiet-window handling: a load_more page with zero rows is
            // expected when the subscription has no events in this slice
            // (BE keeps advancing next_token through quiet hours). Log it
            // so the operator can tell the scraper is walking a quiet
            // window rather than looking stuck, and only break if the
            // streak grows past the runaway-safety ceiling.
            if (pageReceived === 0) {
                consecutiveZeroRowPages++;
                log.info('quiet window page', {
                    jobId: job.id,
                    page: pageCount,
                    nextTokenPresent: !!nextToken,
                });
                if (consecutiveZeroRowPages >= config.MAX_CONSECUTIVE_ZERO_ROW_PAGES) {
                    log.error('runaway-safety cap hit: too many consecutive zero-row pages — failing', {
                        jobId: job.id,
                        page: pageCount,
                        consecutive: consecutiveZeroRowPages,
                        lastTokenPrefix: nextToken ? nextToken.slice(0, 12) : null,
                    });
                    stopReason = 'runaway_safety';
                    throw new Error(
                        `Hit the cap on consecutive zero-row pages (${consecutiveZeroRowPages}); `
                            + 'BookingExperts is handing out an apparently-infinite quiet window.',
                    );
                }
            } else {
                consecutiveZeroRowPages = 0;
            }

            // Defensive belt-and-suspenders for the eval-fallback path.
            // Echoes from the parse-extracted next_token are absorbed by
            // `loadMoreWithTokenEchoRetry` above (it never returns
            // `'advanced'` with `nextToken === sentToken`). The only way
            // we can reach this point with `nextToken === sentToken` is
            // the eval-fallback branch on an unparseable body that
            // happened to surface an echoed token via `live.nextToken`.
            // That's still "we're at the tip" semantically, so finish
            // with `caught_up` instead of looping. Dump the body so we
            // can confirm the pattern if it ever fires.
            if (nextToken !== null && nextToken === sentToken) {
                log.info('eval-fallback surfaced an echoed next_token — treating as caught up', {
                    jobId: job.id,
                    page: pageCount,
                    pageReceived,
                    tokenPrefix: sentToken.slice(0, 12),
                });
                await dumpDebugArtifact(job.id, pageCount, body, 'token_echo', {
                    subscription: job.subscription,
                    pageCount,
                    previousToken: sentToken,
                    url,
                });
                stopReason = 'caught_up';
                nextToken = null;
                break;
            }

            // Redundant with the dedicated ticker started at the top of
            // runScrapeJob — kept as a cheap belt-and-suspenders for the
            // exact moment a long batch flush completes. The ticker in
            // `heartbeat.ts` is the authoritative source of liveness.
            await heartbeat(job.id).catch(() => undefined);
            await sleep(jitter(200, 800));
        }

        // Loop exit fall-through. If nothing inside the loop captured a
        // specific reason, distinguish:
        //   - pageCount hit the max_pages cap → `pagination_limit`
        //     (operator-visible cap, completion).
        //   - nextToken became null with pages still in budget →
        //     `token_missing` (anomaly: BookingExperts stopped giving us
        //     a forward-moving cursor; per the new semantics that's a
        //     hard failure rather than a clean end).
        // empty_window (set BEFORE the loop) short-circuits this block —
        // its initial-page-with-zero-rows case is the one legitimate
        // "no next_token" completion.
        if (stopReason === undefined) {
            const maxPages = job.params.max_pages ?? config.MAX_PAGES_PER_JOB;
            if (pageCount >= maxPages) {
                stopReason = 'pagination_limit';
            } else {
                stopReason = 'token_missing';
                // Dump the body that returned the null next_token. The
                // null-token branch could be the loop's initial-page
                // path (lastBody === null because we never made a
                // load_more request — initial.nextToken was null with
                // rows on the page), in which case there's no upstream
                // body to capture and we just throw with the context-
                // free message. Operator can still see the failure on
                // the Jobs page; the error message is unambiguous.
                if (lastBody !== null && lastUrl !== null) {
                    await dumpDebugArtifact(job.id, lastBodyPage, lastBody, 'token_missing', {
                        subscription: job.subscription,
                        pageCount,
                        previousToken: lastSentToken,
                        url: lastUrl,
                    });
                }
                throw new Error(
                    'BookingExperts stopped returning a next_token mid-scrape; '
                        + 'pagination state lost — treating as anomaly.',
                );
            }
        }

        const stats: ScrapeResult = {
            pages: pageCount,
            rows: rowCount,
            duration_ms: Date.now() - startedAt,
            aborted_due_to_time: abortedDueToTime,
            early_stopped_due_to_duplicates: earlyStoppedDueToDuplicates,
            total_duplicates: totalDuplicatesObserved,
            stop_reason: stopReason,
            token_echo_retries: tokenEchoRetries,
        };
        // Stop the liveness ticker before reporting completion so a
        // lingering tick can't land on a row that's about to flip to
        // `completed` (would be harmless — the reaper only touches
        // `running` rows — but it keeps the contract clean).
        heartbeatTicker.stop();
        await completeJob(job.id, stats);
        log.info('scrape complete', { jobId: job.id, ...stats });
        return stats;
    } catch (err) {
        // Same logic as the success path: stop the ticker before reporting
        // failure so we don't backstamp `last_heartbeat_at` after the row
        // has been flipped to `failed`.
        heartbeatTicker.stop();
        const message = err instanceof Error ? err.message : String(err);
        const isExpired = message === SESSION_EXPIRED_SENTINEL || /sign_in/.test(message);
        // session_expired has its own remediation (re-auth via the
        // extension) and overrides any pre-set reason. Laravel's
        // WorkerController::fail() also detects the same sentinel on the
        // server side as a fallback for older worker builds.
        if (isExpired) {
            stopReason = 'session_expired';
            log.warn('session expired — reporting back to Laravel', {
                jobId: job.id,
                sessionId: job.session.id,
                stopReason,
                ...(lastDiagnostic ? lastDiagnosticForLog(lastDiagnostic) : {}),
            });
            await reportSessionExpired(job.session.id).catch(() => undefined);
        } else {
            log.error('scrape failed', {
                jobId: job.id,
                error: message,
                stopReason: stopReason ?? null,
                ...(lastDiagnostic ? lastDiagnosticForLog(lastDiagnostic) : {}),
            });
        }
        // Forward the typed reason so Laravel persists it onto
        // stats.stop_reason instead of relying on the freeform error
        // string. Generic failures (no reason captured upstream, no
        // SESSION_EXPIRED match) pass `stop_reason: undefined`, which
        // JSON.stringify drops — the server-side fail() then leaves
        // stats.stop_reason absent (or applies its own SESSION_EXPIRED
        // fallback if the error string matches the sentinel).
        await failJob(job.id, {
            error: message,
            retryable: !isExpired,
            stop_reason: stopReason,
        }).catch(() => undefined);
        throw err;
    } finally {
        // Idempotent — the success/failure paths above already called
        // stop() before reporting completion. This is the safety net for
        // any path that bypasses both (synchronous throws before we even
        // reach the try/catch terminals, future refactors, etc.).
        heartbeatTicker.stop();
        await context?.close().catch(() => undefined);
        await browser?.close().catch(() => undefined);
    }
}

interface FlushStats {
    received: number;
    inserted: number;
    duplicates: number;
}

async function flushRows(
    job: ScrapeJob,
    rows: RawRow[],
    track: (n: number) => void,
    pagesProcessed?: number,
): Promise<FlushStats> {
    if (rows.length === 0) return { received: 0, inserted: 0, duplicates: 0 };
    // Drop rows missing a timestamp or type before posting — Laravel's batch
    // validator rejects the entire batch if any single row fails, so a
    // single malformed row would otherwise wipe out 24 valid neighbors.
    const messages: ParsedLogMessage[] = rows
        .map(rowToMessage)
        .filter((m) => m.timestamp && m.type);
    const dropped = rows.length - messages.length;
    if (dropped > 0) {
        log.warn('dropped rows missing required fields', {
            jobId: job.id,
            dropped,
            kept: messages.length,
        });
    }
    if (messages.length === 0) return { received: 0, inserted: 0, duplicates: 0 };

    let received = 0;
    let inserted = 0;
    for (let i = 0; i < messages.length; i += config.BATCH_SIZE) {
        const chunk = messages.slice(i, i + config.BATCH_SIZE);
        const result = await postBatch(job.id, chunk, pagesProcessed);
        track(chunk.length);
        received += result.received;
        inserted += result.inserted;
        log.debug('batch posted', { jobId: job.id, ...result });
    }
    return { received, inserted, duplicates: Math.max(0, received - inserted) };
}

/**
 * Re-parse rows from a snippet of HTML by injecting it into a hidden DOM node
 * inside the live page context. Mirrors `extractRowsFromMain`'s field
 * detection so behavior stays consistent across initial + paginated batches.
 *
 * Critical detail: `<tr>` and `<td>` are stripped by the HTML parser when
 * assigned via `innerHTML` to a non-table parent (browser HTML5 spec). We
 * detect leading row-shaped content and wrap in `<table><tbody>` to keep
 * the rows intact.
 */
async function extractRowsFromHtmlString(page: Page, html: string): Promise<RawRow[]> {
    return await page.evaluate(
        ({ html }) => {
            const sandbox = document.createElement('div');
            sandbox.style.display = 'none';
            const needsTableWrap = /^\s*<tr\b/i.test(html);
            sandbox.innerHTML = needsTableWrap
                ? `<table><tbody>${html}</tbody></table>`
                : html;
            document.body.appendChild(sandbox);
            try {
                const candidateSelectors = [
                    'tr.table__row',
                    '[data-controller~="log-event"]',
                    '[data-log-event]',
                    '[data-log-entry]',
                    '[data-event]',
                    'article',
                    '[role="row"]',
                    '[role="listitem"]',
                    'li',
                    'tr',
                    'div[class*="row"]',
                ];
                let rows: Element[] = [];
                for (const sel of candidateSelectors) {
                    const nodes = Array.from(sandbox.querySelectorAll(sel));
                    if (nodes.length > rows.length) rows = nodes;
                }

                const ISO_RE = /\b\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\b/;
                const METHOD_RE = /\b(GET|POST|PUT|DELETE|PATCH|HEAD|OPTIONS)\b/;

                const out: Array<{
                    timestamp: string;
                    type: string;
                    action: string;
                    method: string;
                    path: string | null;
                    status: string | null;
                    detailHtml: string | null;
                }> = [];

                for (const node of rows) {
                    const text = (node.textContent ?? '').replace(/\s+/g, ' ').trim();
                    if (!text) continue;
                    const tsMatch = text.match(ISO_RE);
                    const methodMatch = text.match(METHOD_RE);
                    if (!tsMatch && !methodMatch) continue;

                    const cells = Array.from(node.querySelectorAll(':scope > td'));
                    let timestamp = '';
                    let type = '';
                    let action = '';
                    let method = '';
                    let status: string | null = null;
                    let path: string | null = null;

                    if (cells.length >= 4) {
                        for (const cell of cells) {
                            if (!timestamp) {
                                const m = (cell.textContent ?? '').match(ISO_RE);
                                if (m) timestamp = m[0];
                            }
                            if (!action) {
                                const heading = cell.querySelector('.text--heading-sm, .text--heading-md, h3, h2, .title');
                                if (heading) action = (heading.textContent ?? '').trim();
                            }
                            if (!type) {
                                const t = cell.querySelector('.text--highlight');
                                if (t) {
                                    const v = (t.textContent ?? '').trim();
                                    // Accept any non-empty highlight text;
                                    // see extractRowsFromMain note about why
                                    // we don't whitelist Webhook/Api Call.
                                    if (v) type = v.replace(/^api ?call$/i, 'Api Call');
                                }
                            }
                            if (!method) {
                                const cands = cell.querySelectorAll('.text--default, .text--label-sm');
                                for (const c of Array.from(cands)) {
                                    const v = (c.textContent ?? '').trim();
                                    const m = v.match(METHOD_RE);
                                    if (m) { method = m[0]; break; }
                                }
                            }
                            if (!path) {
                                const cands = cell.querySelectorAll('.text--muted, .text--body-xs');
                                for (const c of Array.from(cands)) {
                                    const v = (c.textContent ?? '').trim();
                                    if (v.startsWith('/')) { path = v; break; }
                                }
                            }
                            if (!status) {
                                const badge = cell.querySelector('.badge');
                                if (badge) {
                                    const m = (badge.textContent ?? '').trim().match(/\b[1-5]\d{2}\b/);
                                    if (m) status = m[0];
                                }
                            }
                        }
                        if (!timestamp && tsMatch) timestamp = tsMatch[0];
                        if (!method && methodMatch) method = methodMatch[0];
                        if (!type) {
                            const tm = text.match(/(Webhook|Api Call|API Call)/i);
                            if (tm) type = tm[0].replace(/api/i, 'Api');
                        }
                        if (!status) {
                            const sm = text.match(/\b([1-5]\d{2})\b/);
                            status = sm?.[1] ?? null;
                        }
                    } else {
                        timestamp = tsMatch?.[0] ?? '';
                        method = methodMatch?.[0] ?? '';
                        const typeMatch = text.match(/(Webhook|Api Call|API Call)/i);
                        type = (typeMatch?.[0] ?? '').replace(/api/i, 'Api');
                        if (typeMatch && typeMatch.index !== undefined) {
                            action = text.substring(0, typeMatch.index).trim();
                        }
                        const pathMatch = text.match(/\/v\d[^\s]+/);
                        path = pathMatch?.[0] ?? null;
                        const statusMatch = text.match(/\b([1-5]\d{2})\b/);
                        status = statusMatch?.[1] ?? null;
                    }

                    let detailHtml: string | null = null;
                    const tmpl = node.querySelector('template') as HTMLTemplateElement | null;
                    if (tmpl) detailHtml = tmpl.innerHTML;
                    else {
                        const hidden = node.querySelector('[hidden], [data-modal-content], dialog');
                        if (hidden) detailHtml = hidden.innerHTML;
                    }

                    out.push({ timestamp, type, action, method, path, status, detailHtml });
                }

                return out;
            } finally {
                sandbox.remove();
            }
        },
        { html },
    );
}

function buildLogsUrl(job: ScrapeJob): string {
    const base = BEX_BASE_URLS[job.subscription.environment];
    const u = new URL(
        `/organizations/${job.subscription.organization_id}/apps/developer/applications/${job.subscription.application_id}/application_subscriptions/${job.subscription.id}/logs`,
        base,
    );
    if (job.params.start_time) u.searchParams.set('start_time', job.params.start_time);
    if (job.params.end_time) u.searchParams.set('end_time', job.params.end_time);
    return u.toString();
}

/**
 * GET /load_more_logs.js with bounded retries on 422. BookingExperts
 * uses 422 as a "you're going too fast" signal — a single occurrence
 * doesn't mean pagination is broken, but if it survives our backoff
 * schedule we surrender and the caller fails the job so the operator
 * notices the concurrency cap is too aggressive.
 *
 * Return contract:
 *   - `APIResponse` (status !== 422): caller inspects status as before
 *     (401/403 → SESSION_EXPIRED, !ok() → generic HTTP failure, ok() →
 *     proceed with body parsing).
 *   - `'422_exhausted'`: caller MUST treat this as a hard failure (set
 *     `stop_reason = 'pagination_error'` and throw). We don't retry
 *     forever because there's no recovery path a single worker can take
 *     besides slowing down — and that's an operator decision, not ours.
 *
 * Logging:
 *   - Each transient 422: `warn` with attempt number + next backoff.
 *   - Final surrender: `error` with the total attempt count.
 *   - Success on retry: caller emits the normal `load_more batch parsed`
 *     line, so we don't double-log here.
 *
 * Out of scope: this helper deliberately does NOT auto-reduce
 * concurrency. That's a tunable in `MAX_CONCURRENT_SCRAPES` — silently
 * dropping concurrency in code would mask the upstream pressure signal.
 *
 * Exported so the offline test harness in `scripts/load-more-retry-test.ts`
 * can drive it with a stub APIRequestContext; no production caller other
 * than runScrapeJob exists.
 */
export async function loadMoreWithRetry(
    apiCtx: APIRequestContext,
    url: string,
    referer: string,
    opts: { jobId: number; page: number; retryDelaysMs: readonly number[] },
): Promise<APIResponse | '422_exhausted'> {
    const { jobId, page, retryDelaysMs } = opts;
    const maxAttempts = retryDelaysMs.length + 1;

    for (let attempt = 1; attempt <= maxAttempts; attempt++) {
        const xhr = await apiCtx.get(url, {
            headers: {
                Accept: 'text/javascript, application/javascript, */*; q=0.01',
                'X-Requested-With': 'XMLHttpRequest',
                Referer: referer,
            },
            failOnStatusCode: false,
            timeout: 30_000,
        });

        if (xhr.status() !== 422) {
            return xhr;
        }

        if (attempt < maxAttempts) {
            const delay = retryDelaysMs[attempt - 1] ?? 0;
            log.warn('load_more returned 422 — backing off and retrying', {
                jobId,
                page,
                attempt,
                attemptsRemaining: maxAttempts - attempt,
                nextDelayMs: delay,
            });
            await sleep(delay);
            continue;
        }

        log.error('load_more 422 exhausted retries — surrendering', {
            jobId,
            page,
            attempts: maxAttempts,
        });
    }

    return '422_exhausted';
}

/**
 * Wrap `loadMoreWithRetry` (which already handles 422 with backoff) with
 * an additional retry layer for 401/403 → /sign_in bouncing. We discovered
 * empirically that BookingExperts redirects parallel same-cookie requests
 * to /sign_in even when the cookies are valid for sequential traffic; a
 * few-second pause between attempts is usually enough for the burst
 * window to elapse and the response to come back 200.
 *
 * Status codes:
 *   - APIResponse with status not in {401, 403}: caller proceeds as before.
 *   - APIResponse with status 401/403: retries exhausted, caller treats as
 *     genuine session_expired (dump artifact + throw SESSION_EXPIRED).
 *   - '422_exhausted': forwarded straight through from loadMoreWithRetry.
 *
 * Each retry re-issues the entire loadMoreWithRetry call, so a 422 that
 * happens during a 401/403 retry attempt still gets its own backoff. Worst
 * case wall-clock: SIGN_IN_RETRY_DELAYS_MS sum + (per-retry) the 422 retry
 * sum + the actual HTTP round trips. Bounded well under the 30s timeout
 * + 3-min reaper threshold.
 */
export async function loadMoreWithSignInRetry(
    apiCtx: APIRequestContext,
    url: string,
    referer: string,
    opts: {
        jobId: number;
        page: number;
        signInRetryDelaysMs?: readonly number[];
        rate422RetryDelaysMs?: readonly number[];
    },
): Promise<APIResponse | '422_exhausted'> {
    const { jobId, page } = opts;
    const signInDelays = opts.signInRetryDelaysMs ?? SIGN_IN_RETRY_DELAYS_MS;
    const rate422Delays = opts.rate422RetryDelaysMs ?? LOAD_MORE_422_RETRY_DELAYS_MS;
    const maxAttempts = signInDelays.length + 1;
    let lastResult: APIResponse | '422_exhausted' = await loadMoreWithRetry(apiCtx, url, referer, {
        jobId,
        page,
        retryDelaysMs: rate422Delays,
    });

    for (let attempt = 1; attempt < maxAttempts; attempt++) {
        if (lastResult === '422_exhausted') return lastResult;
        const status = lastResult.status();
        if (status !== 401 && status !== 403) return lastResult;

        const delay = signInDelays[attempt - 1] ?? 0;
        log.warn('load_more got 401/403 — retrying after backoff (likely transient sign-in bounce)', {
            jobId,
            page,
            attempt,
            attemptsRemaining: maxAttempts - attempt,
            nextDelayMs: delay,
            status,
        });
        await sleep(delay);

        lastResult = await loadMoreWithRetry(apiCtx, url, referer, {
            jobId,
            page,
            retryDelaysMs: rate422Delays,
        });

        if (lastResult !== '422_exhausted') {
            const newStatus = lastResult.status();
            if (newStatus !== 401 && newStatus !== 403) {
                log.info('load_more recovered after 401/403 bounce', {
                    jobId,
                    page,
                    retries: attempt,
                    finalStatus: newStatus,
                });
                return lastResult;
            }
        }
    }

    if (lastResult !== '422_exhausted') {
        log.error('load_more 401/403 exhausted retries — declaring session_expired', {
            jobId,
            page,
            attempts: maxAttempts,
            finalStatus: lastResult.status(),
        });
    }
    return lastResult;
}

/**
 * One attempt's view of the load_more exchange, returned by the caller's
 * `fetchAttempt` closure to `loadMoreWithTokenEchoRetry`. The retry layer
 * inspects only `nextToken`; `payload` is the opaque caller-defined blob
 * (typically `{ xhr, body, url, parsed }`) returned back to the caller
 * once the helper settles on a final attempt.
 */
export interface TokenEchoAttempt<T> {
    nextToken: string | null;
    payload: T;
}

/**
 * Outcome of `loadMoreWithTokenEchoRetry`:
 *
 *   - `advanced`: at least one attempt returned `nextToken !== sentToken`
 *     (including `nextToken === null`). Caller proceeds with the
 *     normal `rowsHtml` handling using `result.payload`.
 *   - `exhausted`: every attempt (1 initial + retries) returned
 *     `nextToken === sentToken`. Per the new semantics this is the
 *     healthy "we caught up to BookingExperts' live log tip" completion
 *     — caller sets `stop_reason = 'caught_up'` and exits the loop.
 *   - `budget_aborted`: the helper was about to sleep past the per-job
 *     time budget (see `timeBudgetOk`). Caller falls through to its
 *     existing `time_limit` handling.
 *
 * `result` always carries the final attempt's data so the caller can
 * snapshot it for diagnostics regardless of outcome.
 */
export type TokenEchoOutcome<T> =
    | { kind: 'advanced'; attempts: number; result: TokenEchoAttempt<T> }
    | { kind: 'exhausted'; attempts: number; result: TokenEchoAttempt<T> }
    | { kind: 'budget_aborted'; attempts: number; result: TokenEchoAttempt<T> };

/**
 * Retry the caller's `fetchAttempt` closure when BookingExperts echoes
 * the same `next_token` back at us. The historical interpretation was a
 * hard failure (`stop_reason: token_echo` → throw); the AWS CloudWatch
 * Logs `GetLogEvents` mental model fits the observed behaviour better:
 * `nextForwardToken === inputToken` means "no new events yet — keep
 * polling with this same token; new events + a new token appear once
 * data arrives." BookingExperts' `/load_more_logs.js` mirrors this
 * because log events are continuously appended.
 *
 * Policy: flat schedule (no ramp). `maxAttempts` total (1 initial +
 * (maxAttempts - 1) retries) with `delayMs` between each attempt.
 * Production defaults via `TOKEN_ECHO_MAX_ATTEMPTS` (100) and
 * `TOKEN_ECHO_RETRY_DELAY_MS` (3000) — see `scraper/src/config.ts`.
 * The delay is floored at `TOKEN_ECHO_MIN_DELAY_MS` (500ms) regardless
 * of caller input — defensive against a misconfigured env knocking the
 * delay to zero and DOSing BE.
 *
 * Detection: `attempt.nextToken === sentToken` (strict equality). The
 * caller's closure performs the entire HTTP fetch (including 422 /
 * sign-in retry layers) plus body parsing, and returns the
 * `parseLoadMoreResponse` next_token. A null nextToken counts as
 * "advanced" — the caller's existing `token_missing` fall-through
 * handles that case differently.
 *
 * Budget: optional `timeBudgetOk(plannedDelayMs)` callback is
 * consulted BEFORE each sleep. If it returns false the helper bails
 * with `budget_aborted` instead of waiting further — the caller then
 * falls through to its existing `time_limit` exit. The pre-sleep
 * check guarantees we never oversleep past the budget boundary.
 * Heartbeat liveness is preserved across the sleeps because the
 * dedicated ticker (see `heartbeat.ts`) runs on its own interval.
 *
 * Errors thrown inside `fetchAttempt` (network failures, the
 * `pagination_error` / SESSION_EXPIRED / generic-HTTP throws the
 * caller stamps before throwing) propagate straight out of this
 * helper unchanged — the existing outer try/catch handles them, no
 * different from the pre-retry-layer flow.
 *
 * Out of scope: this helper does NOT retry on closure errors. Echo is
 * the only condition we treat as worth re-issuing the same request for.
 *
 * Exported so the offline test harness in
 * `scripts/token-echo-retry-test.ts` can drive it with a stub closure;
 * production has no caller other than `runScrapeJob`.
 */
export async function loadMoreWithTokenEchoRetry<T>(
    sentToken: string,
    fetchAttempt: (attempt: number) => Promise<TokenEchoAttempt<T>>,
    opts: {
        jobId: number;
        page: number;
        maxAttempts: number;
        delayMs: number;
        /**
         * Floor applied to `delayMs`. Defaults to `TOKEN_ECHO_MIN_DELAY_MS`
         * (500ms) — production callers pass nothing and inherit it. The
         * offline test harness passes `0` for fast wall-clock-bounded
         * scenarios; production never does.
         */
        minDelayMs?: number;
        timeBudgetOk?: (plannedDelayMs: number) => boolean;
    },
): Promise<TokenEchoOutcome<T>> {
    const { jobId, page, maxAttempts } = opts;
    const minDelayMs = opts.minDelayMs ?? TOKEN_ECHO_MIN_DELAY_MS;
    const delayMs = Math.max(minDelayMs, opts.delayMs);
    let last: TokenEchoAttempt<T> | null = null;

    for (let attempt = 1; attempt <= maxAttempts; attempt++) {
        // Surface the active policy on the first attempt so an operator
        // reading the logs can confirm what the worker is actually
        // running with (env override, default, etc.). Subsequent
        // attempts log per-retry below.
        if (attempt === 1) {
            log.info('token-echo retry policy active for job/page', {
                jobId,
                page,
                maxAttempts,
                delayMs,
                tokenPrefix: sentToken.slice(0, 12),
            });
        }

        last = await fetchAttempt(attempt);

        if (last.nextToken !== sentToken) {
            if (attempt > 1) {
                log.info('load_more recovered after token-echo retry', {
                    jobId,
                    page,
                    attempts: attempt,
                    advanced: last.nextToken !== null,
                });
            }
            return { kind: 'advanced', attempts: attempt, result: last };
        }

        if (attempt < maxAttempts) {
            // Budget is consulted BEFORE the sleep so we never wake up
            // past the boundary. The callback receives the planned
            // delay so the caller can compute `now + plannedDelay <
            // budget` precisely.
            if (opts.timeBudgetOk && !opts.timeBudgetOk(delayMs)) {
                log.warn('token-echo retry would exceed time budget — skipping further sleeps', {
                    jobId,
                    page,
                    attempt,
                    plannedDelayMs: delayMs,
                    tokenPrefix: sentToken.slice(0, 12),
                });
                return { kind: 'budget_aborted', attempts: attempt, result: last };
            }
            log.warn('load_more next_token echoed — retrying after backoff (BookingExperts log-tail signal)', {
                jobId,
                page,
                attempt,
                attemptsRemaining: maxAttempts - attempt,
                delayMs,
                reason: 'next_token === sentToken (likely "at live tip" per AWS CW Logs convention)',
                tokenPrefix: sentToken.slice(0, 12),
            });
            await sleep(delayMs);
        }
    }

    log.info('token-echo retries exhausted — treating as caught up to BookingExperts log tip', {
        jobId,
        page,
        attempts: maxAttempts,
        tokenPrefix: sentToken.slice(0, 12),
    });
    // last is non-null because the loop runs at least once (maxAttempts >= 1).
    return { kind: 'exhausted', attempts: maxAttempts, result: last as TokenEchoAttempt<T> };
}

/**
 * Re-navigate the initial logs page up to `SIGN_IN_RETRY_DELAYS_MS.length`
 * times if BookingExperts bounces us to /sign_in. Returns the final
 * response, whether we ended up bounced, and the rendered body of the
 * last bounce (for the caller to dump as a debug artifact when the retry
 * schedule was exhausted).
 *
 * The browser context retains the same cookies across retries — we don't
 * need to reapply anything. Each `page.goto()` re-attempts the original
 * URL; if the bounce was anti-bot/concurrency-driven the second or third
 * attempt usually succeeds because the burst window has elapsed.
 *
 * Worst case wall-clock: SIGN_IN_RETRY_DELAYS_MS sum + the three
 * page.goto() round trips. Comfortably bounded.
 */
async function navigateInitialWithSignInRetry(
    page: Page,
    url: string,
    jobId: number,
): Promise<{ response: Response; bouncedToSignIn: boolean; lastBody: string; retries: number }> {
    let response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30_000 });
    if (!response) throw new Error('no response from initial GET');

    if (!page.url().includes('/sign_in')) {
        return { response, bouncedToSignIn: false, lastBody: '', retries: 0 };
    }

    let lastBody = await page.content().catch(() => '<page.content() failed>');
    let retries = 0;

    for (let i = 0; i < SIGN_IN_RETRY_DELAYS_MS.length; i++) {
        const delay = SIGN_IN_RETRY_DELAYS_MS[i] ?? 0;
        log.warn('initial nav landed on /sign_in — retrying after backoff (likely transient sign-in bounce)', {
            jobId,
            attempt: retries + 1,
            attemptsRemaining: SIGN_IN_RETRY_DELAYS_MS.length - i,
            nextDelayMs: delay,
        });
        await sleep(delay);

        const retried = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30_000 });
        if (!retried) throw new Error('no response from initial GET retry');
        retries++;
        response = retried;

        if (!page.url().includes('/sign_in')) {
            log.info('initial nav recovered after sign-in bounce', {
                jobId,
                retries,
                finalUrl: page.url(),
            });
            return { response, bouncedToSignIn: false, lastBody: '', retries };
        }
        lastBody = await page.content().catch(() => '<page.content() failed>');
    }

    log.error('initial nav still on /sign_in after retries — declaring session_expired', {
        jobId,
        retries,
        finalUrl: page.url(),
    });
    return { response, bouncedToSignIn: true, lastBody, retries };
}

interface LastDiagnostic {
    phase: 'initial' | 'load_more';
    requestUrl: string;
    finalUrl: string;
    status: number;
    bodyPreview: string | null;
}

/**
 * Compress a `lastDiagnostic` snapshot into a compact log fragment for the
 * catch block. Splits the body preview into both a raw preview and a few
 * boolean flags (is_signin, is_403, etc.) so an operator can pattern-match
 * without re-reading the artifact.
 */
function lastDiagnosticForLog(d: LastDiagnostic): Record<string, unknown> {
    const body = d.bodyPreview ?? '';
    return {
        last_phase: d.phase,
        last_request_url: d.requestUrl,
        last_final_url: d.finalUrl,
        last_status: d.status,
        last_body_preview: body || null,
        body_contains_sign_in: body ? /\/sign_in|new_user|user_email|user_password/i.test(body) : false,
        body_contains_403: body ? /\b403\b|forbidden/i.test(body) : false,
        body_contains_429: body ? /\b429\b|rate.?limit|too many/i.test(body) : false,
    };
}

function buildLoadMoreUrl(job: ScrapeJob, nextToken: string): string {
    const base = BEX_BASE_URLS[job.subscription.environment];
    const u = new URL(
        `/organizations/${job.subscription.organization_id}/apps/developer/applications/${job.subscription.application_id}/application_subscriptions/${job.subscription.id}/load_more_logs.js`,
        base,
    );
    u.searchParams.set('next_token', nextToken);
    return u.toString();
}

function toPlaywrightCookies(cookies: ScrapeJob['session']['cookies'], env: Environment) {
    const baseHost = new URL(BEX_BASE_URLS[env]).hostname;
    return cookies
        .filter((c) => c.name && c.value)
        .map((c) => {
            const domain = c.domain ?? `.${baseHost}`;
            return {
                name: c.name,
                value: c.value,
                domain: domain.startsWith('.') ? domain : `.${domain}`,
                path: c.path ?? '/',
                expires: typeof c.expirationDate === 'number' ? Math.floor(c.expirationDate) : -1,
                httpOnly: !!c.httpOnly,
                secure: c.secure ?? true,
                sameSite: normalizeSameSite(c.sameSite),
            };
        });
}

function normalizeSameSite(s?: string): 'Strict' | 'Lax' | 'None' {
    const v = (s ?? '').toLowerCase();
    if (v === 'strict' || v === 'lax' || v === 'none') {
        return (v.charAt(0).toUpperCase() + v.slice(1)) as 'Strict' | 'Lax' | 'None';
    }
    return 'Lax';
}

function jitter(min: number, max: number): number {
    return Math.floor(min + Math.random() * (max - min));
}

function sleep(ms: number): Promise<void> {
    return new Promise((r) => setTimeout(r, ms));
}

/**
 * Dump a raw /load_more_logs.js response body to disk for offline debugging.
 * Gated by `LOG_LEVEL=debug` or `DEBUG_DUMP_LOADMORE=true` at the call site.
 */
async function dumpLoadMoreResponse(jobId: number, page: number, body: string): Promise<void> {
    try {
        await mkdir(DEBUG_DUMP_DIR, { recursive: true });
        const file = path.join(DEBUG_DUMP_DIR, `loadmore-${jobId}-${page}.txt`);
        await writeFile(file, body, 'utf8');
        log.debug('dumped load_more response', { jobId, page, file });
    } catch (err) {
        log.warn('failed to dump load_more response', {
            jobId,
            page,
            error: err instanceof Error ? err.message : String(err),
        });
    }
}

/**
 * Reasons that warrant a forensic dump of the BookingExperts response body.
 * Deliberately decoupled from `StopReason`:
 *   - `pagination_error` (422 exhausted) is a known protocol error with no
 *     useful body — dumping a 422 page wastes disk and operator attention.
 *   - `runaway_safety` is an operational anomaly across many pages, not a
 *     parse issue with one body.
 *   - `caught_up` reuses the legacy `token_echo` filename label below
 *     (see next bullet) so historical dumps are grep-comparable.
 *   - Clean completions never trigger.
 *
 * `token_echo` is no longer a `StopReason` (the scraper retries echoes
 * via `loadMoreWithTokenEchoRetry` and completes with `caught_up` on
 * exhaustion), but it survives here as the on-disk filename label for
 * the body that triggered an exhausted retry cluster. Keeping the same
 * filename means an operator can grep `token_echo-*` across both legacy
 * (failure-path) and post-change (caught_up-path) artifacts without
 * remembering when the relabel happened.
 *
 * `session_expired` IS dumped here even though the body is "just" a sign-in
 * redirect. The user reports false positives (scraper says session expired
 * but the cookies are still good in the browser); we need the actual body
 * to disambiguate "BE redirected us to /sign_in" from "BE returned a 403
 * for a non-auth reason that the current detection over-classifies as
 * session_expired". The dump is small, the sign-in HTML is not secret
 * (any unauthed browser sees it), and the directory is bounded by the
 * 14d / 200MB retention guard, so leaving this on is cheap.
 */
type DumpReason = 'token_missing' | 'unparseable' | 'token_echo' | 'session_expired';

interface DumpContext {
    subscription: ScrapeJob['subscription'];
    pageCount: number;
    previousToken: string | null;
    url: string;
}

/**
 * Persist the offending /load_more_logs.js response (and a small JSON
 * sidecar) to `scraper/debug/` so an operator can SSH in and triage why
 * BookingExperts confused the parser. Fires on every parse-class failure
 * (`token_missing`, `unparseable`) AND on `caught_up`-via-token-echo
 * exhaustion (filename labeled `token_echo` for legacy comparability),
 * regardless of LOG_LEVEL or DEBUG_DUMP_LOADMORE — by the time we reach
 * this code path the job is either already failing or about to complete
 * with a non-trivial diagnostic, so the cost of the I/O is irrelevant
 * compared to the value of having the body to inspect.
 *
 * Writes two files per failure (sharing a base name):
 *   - `{reason}-{jobId}-p{page}-{timestamp}.html` — raw response body. The
 *     `.html` extension is a lie (the body is usually Rails-UJS JS or a
 *     Turbo Stream fragment) but it makes the file double-clickable in a
 *     browser for visual inspection.
 *   - `{reason}-{jobId}-p{page}-{timestamp}.json` — small metadata sidecar
 *     so the operator can map the body back to the failed job without
 *     re-reading scraper logs.
 *
 * Retention: bounded by `DEBUG_RETENTION_MS` (14d age) and
 * `DEBUG_MAX_TOTAL_BYTES` (200MB total). Pruned in-line on each write so
 * an idle scraper never touches the directory.
 *
 * Failure mode is a `warn` log + swallow — a debug-dump I/O error must
 * not crash the scrape (the job is already failing for another reason;
 * losing the artifact is not a regression worth surfacing).
 */
async function dumpDebugArtifact(
    jobId: number,
    page: number,
    body: string,
    reason: DumpReason,
    context: DumpContext,
): Promise<void> {
    try {
        await mkdir(DEBUG_DUMP_DIR, { recursive: true });
        await pruneDebugDir();

        const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
        const baseName = `${reason}-${jobId}-p${page}-${timestamp}`;
        const htmlFile = path.join(DEBUG_DUMP_DIR, `${baseName}.html`);
        const jsonFile = path.join(DEBUG_DUMP_DIR, `${baseName}.json`);

        const meta = {
            jobId,
            subscription: {
                id: context.subscription.id,
                environment: context.subscription.environment,
                organization_id: context.subscription.organization_id,
                application_id: context.subscription.application_id,
            },
            pageCount: context.pageCount,
            previousToken: context.previousToken,
            stopReason: reason,
            timestamp: new Date().toISOString(),
            url: context.url,
            previewBody: previewBody(body),
        };

        await writeFile(htmlFile, body, 'utf8');
        await writeFile(jsonFile, JSON.stringify(meta, null, 2), 'utf8');

        log.info('dumped debug artifact', {
            jobId,
            page,
            reason,
            htmlFile,
            jsonFile,
            sizeBytes: Buffer.byteLength(body, 'utf8'),
        });
    } catch (err) {
        log.warn('failed to dump debug artifact', {
            jobId,
            page,
            reason,
            error: err instanceof Error ? err.message : String(err),
        });
    }
}

/**
 * Single-pass retention guard for `scraper/debug/`. Drops files older than
 * `DEBUG_RETENTION_MS` first (cheap age cull), then if the surviving files
 * still exceed `DEBUG_MAX_TOTAL_BYTES` removes oldest-first until under
 * the cap. Treats every regular file in the directory uniformly — covers
 * both the new `dumpDebugArtifact` outputs and the older
 * `dumpLoadMoreResponse` outputs (debug-mode only) so the size cap means
 * something regardless of which helper produced the file.
 *
 * Failure mode (e.g. dir doesn't exist yet, EACCES) is silently ignored
 * — the subsequent `mkdir`/`writeFile` will surface any real I/O error
 * via the caller's catch.
 */
async function pruneDebugDir(): Promise<void> {
    let entries: string[];
    try {
        entries = await readdir(DEBUG_DUMP_DIR);
    } catch {
        return;
    }

    const now = Date.now();
    const survivors: Array<{ name: string; mtimeMs: number; size: number }> = [];
    let totalBytes = 0;

    for (const name of entries) {
        const full = path.join(DEBUG_DUMP_DIR, name);
        let st;
        try {
            st = await stat(full);
        } catch {
            continue;
        }
        if (!st.isFile()) continue;

        if (now - st.mtimeMs > DEBUG_RETENTION_MS) {
            await unlink(full).catch(() => undefined);
            continue;
        }
        survivors.push({ name, mtimeMs: st.mtimeMs, size: st.size });
        totalBytes += st.size;
    }

    if (totalBytes <= DEBUG_MAX_TOTAL_BYTES) return;

    survivors.sort((a, b) => a.mtimeMs - b.mtimeMs);
    for (const f of survivors) {
        if (totalBytes <= DEBUG_MAX_TOTAL_BYTES) break;
        await unlink(path.join(DEBUG_DUMP_DIR, f.name)).catch(() => undefined);
        totalBytes -= f.size;
    }
}

function previewBody(body: string, max: number = 800): string {
    const single = body.replace(/\r?\n/g, '\\n').replace(/\s+/g, ' ').trim();
    return single.length > max ? single.substring(0, max) + '…' : single;
}
