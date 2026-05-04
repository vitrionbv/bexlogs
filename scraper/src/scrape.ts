import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium, type APIRequestContext, type Browser, type BrowserContext, type Page } from 'playwright';
import { config, BEX_BASE_URLS, type Environment } from './config.js';
import { extractRowsFromMain, parseLoadMoreResponse, type RawRow } from './extractors.js';
import { rowToMessage } from './rowParser.js';
import { log } from './log.js';
import { postBatch, completeJob, failJob, heartbeat, reportSessionExpired } from './api.js';
import { startHeartbeatTicker } from './heartbeat.js';
import type { ParsedLogMessage, ScrapeJob, StopReason } from './types.js';

const SESSION_EXPIRED_SENTINEL = 'SESSION_EXPIRED';

const DEBUG_DUMP_DIR = path.resolve(
    path.dirname(fileURLToPath(import.meta.url)),
    '..',
    'debug',
);

export interface ScrapeResult {
    pages: number;
    rows: number;
    duration_ms: number;
    aborted_due_to_time: boolean;
    early_stopped_due_to_duplicates: boolean;
    total_duplicates: number;
    stop_reason: StopReason;
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
    // Track which break/return path the main loop exits through. Default
    // to `natural_end` so a clean fall-through (nextToken === null with no
    // earlier reason set) reports the boring "we ran out of pages" case.
    // `stopReasonSet` distinguishes an explicit assignment from the default
    // — needed to tell `natural_end` (token went null) from `pagination_limit`
    // (loop condition exit while a token is still in hand).
    let stopReason: StopReason = 'natural_end';
    let stopReasonSet = false;
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
    const earlyStopPages =
        job.params.early_stop_duplicate_pages ?? config.EARLY_STOP_DUPLICATE_PAGES;
    const earlyStopMinDups =
        job.params.early_stop_min_duplicates ?? config.EARLY_STOP_MIN_DUPLICATES;

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

        const response = await page.goto(initialUrl, { waitUntil: 'domcontentloaded', timeout: 30_000 });

        if (!response) throw new Error('no response from initial GET');
        if (page.url().includes('/sign_in')) throw new Error(SESSION_EXPIRED_SENTINEL);
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

        // Job 72 pattern: server gave us a fully-rendered page with zero
        // rows AND no next_token to chase. There's nothing to paginate
        // through and nothing to show — distinct enough from "natural_end"
        // (which implies we walked at least some real data) that it gets
        // its own label so operators can spot quiet windows at a glance.
        if (initial.rows.length === 0 && !initial.nextToken) {
            stopReason = 'empty_window';
            stopReasonSet = true;
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
                stopReasonSet = true;
                nextToken = null;
                break;
            }

            const url = buildLoadMoreUrl(job, nextToken);
            log.debug('load_more', { jobId: job.id, page: pageCount, url });

            const xhr = await apiCtx.get(url, {
                headers: {
                    Accept: 'text/javascript, application/javascript, */*; q=0.01',
                    'X-Requested-With': 'XMLHttpRequest',
                    Referer: referer,
                },
                failOnStatusCode: false,
                timeout: 30_000,
            });

            if (xhr.status() === 422) {
                log.info('load_more returned 422 — pagination exhausted', { jobId: job.id });
                stopReason = 'natural_end';
                stopReasonSet = true;
                nextToken = null;
                break;
            }
            if (xhr.status() === 401 || xhr.status() === 403) {
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

            const sentToken = nextToken;
            const parsed = parseLoadMoreResponse(body, sentToken);
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
                        nextToken =
                            live.nextToken && live.nextToken !== sentToken
                                ? live.nextToken
                                : null;
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
                        log.warn('load_more response had no parseable HTML payload — stopping', {
                            jobId: job.id,
                            bodyPreview: previewBody(body, 240),
                        });
                        stopReason = 'unparseable';
                        stopReasonSet = true;
                        break;
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
                log.info('early-stop: pagination is in already-scraped territory', {
                    jobId: job.id,
                    consecutiveAllDuplicatePages,
                    totalDuplicatesObserved,
                    page: pageCount,
                });
                nextToken = null;
                earlyStoppedDueToDuplicates = true;
                stopReason = 'duplicate_detection';
                stopReasonSet = true;
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
                    log.warn('runaway-safety cap hit: too many consecutive zero-row pages', {
                        jobId: job.id,
                        page: pageCount,
                        consecutive: consecutiveZeroRowPages,
                        lastTokenPrefix: nextToken ? nextToken.slice(0, 12) : null,
                    });
                    stopReason = 'runaway_safety';
                    stopReasonSet = true;
                    break;
                }
            } else {
                consecutiveZeroRowPages = 0;
            }

            // Belt-and-suspenders: if the new token equals the one we just
            // sent, the server is echoing it back. Stop instead of looping.
            if (nextToken !== null && nextToken === sentToken) {
                log.warn('pagination appears stuck (next_token did not advance)', {
                    jobId: job.id,
                    page: pageCount,
                });
                stopReason = 'token_echo';
                stopReasonSet = true;
                break;
            }

            // Redundant with the dedicated ticker started at the top of
            // runScrapeJob — kept as a cheap belt-and-suspenders for the
            // exact moment a long batch flush completes. The ticker in
            // `heartbeat.ts` is the authoritative source of liveness.
            await heartbeat(job.id).catch(() => undefined);
            await sleep(jitter(200, 800));
        }

        // Loop exit. If nothing inside the loop captured a specific reason,
        // distinguish "ran out of pages" (token went null) from "hit the
        // page-cap" (max_pages clamp). Either is a clean exit, but the
        // operator-visible labels carry different signals: `pagination_limit`
        // hints that raising the cap might recover more rows; `natural_end`
        // means we're caught up.
        if (!stopReasonSet) {
            const maxPages = job.params.max_pages ?? config.MAX_PAGES_PER_JOB;
            stopReason = pageCount >= maxPages ? 'pagination_limit' : 'natural_end';
        }

        const stats: ScrapeResult = {
            pages: pageCount,
            rows: rowCount,
            duration_ms: Date.now() - startedAt,
            aborted_due_to_time: abortedDueToTime,
            early_stopped_due_to_duplicates: earlyStoppedDueToDuplicates,
            total_duplicates: totalDuplicatesObserved,
            stop_reason: stopReason,
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
        // Laravel's WorkerController::fail() detects this same sentinel on
        // the server side and writes `stop_reason: session_expired` into
        // stats; setting it here too keeps scraper logs honest about why
        // the loop unwound (especially useful for replaying via
        // `npm run scrape:once`).
        if (isExpired) {
            stopReason = 'session_expired';
            log.warn('session expired — reporting back to Laravel', {
                jobId: job.id,
                sessionId: job.session.id,
                stopReason,
            });
            await reportSessionExpired(job.session.id).catch(() => undefined);
        } else {
            log.error('scrape failed', { jobId: job.id, error: message });
        }
        await failJob(job.id, { error: message, retryable: !isExpired }).catch(() => undefined);
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

function previewBody(body: string, max: number = 800): string {
    const single = body.replace(/\r?\n/g, '\\n').replace(/\s+/g, ' ').trim();
    return single.length > max ? single.substring(0, max) + '…' : single;
}
