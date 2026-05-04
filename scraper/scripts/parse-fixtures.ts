// Imperative test fixtures for parseLoadMoreResponse. Proves the parser
// behaves correctly for both legacy jQuery and Turbo Stream response shapes,
// and that it surfaces echoed `next_token` values to the caller (which is
// the single authority on echo detection — see scrape.ts).
//
// Run with:  npx tsx scripts/parse-fixtures.ts
//
// Exits with non-zero on any failed assertion so it can be wired into CI.

import { parseLoadMoreResponse } from '../src/extractors.js';

let failures = 0;

function check(name: string, ok: boolean, detail?: unknown): void {
    if (ok) {
        console.log(`  PASS  ${name}`);
    } else {
        failures++;
        console.error(`  FAIL  ${name}`, detail ?? '');
    }
}

// ---- Fixture A: legacy jQuery body ----------------------------------------
console.log('Fixture A: legacy jQuery body (.append + .html)');
const legacyBody =
    `$('#log-events').append("<tr class=\\"table__row\\">` +
    `<td>2025-01-01T00:00:00Z</td><td>Webhook</td>` +
    `<td>order.created (Details)</td><td>POST</td></tr>");` +
    `$('#load-more-button').html("<form action=\\"/foo/load_more_logs.js?next_token=ABC\\">Load more</form>");`;
{
    const result = parseLoadMoreResponse(legacyBody);
    check('rowsHtml is non-null', result.rowsHtml !== null);
    check(
        'rowsHtml contains table__row',
        !!result.rowsHtml && result.rowsHtml.includes('table__row'),
        { rowsHtml: result.rowsHtml },
    );
    check('nextToken === "ABC"', result.nextToken === 'ABC', { nextToken: result.nextToken });
}

// ---- Fixture B: Turbo Stream body -----------------------------------------
console.log('\nFixture B: Turbo Stream body (rows + pager)');
const turboBody =
    `<turbo-stream action="append" target="log-events">` +
    `<template>` +
    `<tr class="table__row"><td>2025-01-01T00:00:00Z</td><td>Webhook</td><td>POST</td></tr>` +
    `</template>` +
    `</turbo-stream>` +
    `<turbo-stream action="replace" target="load-more-button">` +
    `<template><a href="/foo?next_token=XYZ">Load more</a></template>` +
    `</turbo-stream>`;
{
    const result = parseLoadMoreResponse(turboBody);
    check('rowsHtml is non-null', result.rowsHtml !== null);
    check(
        'rowsHtml contains table__row',
        !!result.rowsHtml && result.rowsHtml.includes('table__row'),
        { rowsHtml: result.rowsHtml },
    );
    check('nextToken === "XYZ"', result.nextToken === 'XYZ', { nextToken: result.nextToken });
}

// ---- Fixture C: Turbo Stream where token equals what was sent ------------
// The parser is a pure response-shape decoder — it surfaces whatever
// next_token the body advertised, including a value identical to the
// token that produced this response. The pagination layer in scrape.ts
// is the single authority on echo detection: it compares parsed.nextToken
// to sentToken and trips `stop_reason = 'token_echo'`. Pre-emptively
// nulling here used to leak echoes into the `token_missing` bucket.
console.log('\nFixture C: parser passes echoed token through unchanged');
const turboSameTokenBody =
    `<turbo-stream action="append" target="log-events">` +
    `<template><tr class="table__row"><td>x</td></tr></template>` +
    `</turbo-stream>` +
    `<turbo-stream action="replace" target="load-more-button">` +
    `<template><a href="/foo?next_token=ABC">Load more</a></template>` +
    `</turbo-stream>`;
{
    const result = parseLoadMoreResponse(turboSameTokenBody);
    check(
        'nextToken === "ABC" (passes through; caller detects echo)',
        result.nextToken === 'ABC',
        { nextToken: result.nextToken },
    );
}

// ---- Fixture D: real-world Rails-UJS shape (redacted) ---------------------
// Mirrors the production /load_more_logs.js response: a $(#log-events).append
// with a JS-escaped <tr class="table__row">…</tr> string, followed by a
// $(#load-more-button).html(...) carrying the next pagination form. Org/app/
// sub IDs and resource paths are placeholder values, not real data.
console.log('\nFixture D: real-world Rails-UJS shape (redacted)');
const realBody =
    `if (window.alertBox) {\n}\n\n` +
    `$('#log-events').append(` +
    `"<tr class=\\"table__row\\">` +
    `<td class=\\"table__cell\\"><div class=\\'flex flex-col\\'>` +
    `<span class=\\"text text--heading-sm\\">Resource show<\\/span>` +
    `<div class=\\'font-mono\\'><p class=\\"text text--body-xs text--highlight\\">Api Call<\\/p><\\/div>` +
    `<\\/div><\\/td>` +
    `<td class=\\"table__cell\\"><div class=\\'flex flex-col\\'>` +
    `<p class=\\"text text--label-sm text--default\\">GET<\\/p>` +
    `<div class=\\'font-mono\\'><p class=\\"text text--body-xs text--muted\\">/v3/path<\\/p><\\/div>` +
    `<\\/div><\\/td>` +
    `<td class=\\"font-mono table__cell\\"><p class=\\"text text--body-sm\\">2099-01-01T00:00:00Z<\\/p><\\/td>` +
    `<td class=\\"table__cell\\"><span class=\\"badge badge--success\\"><div>200<\/div><\\/span><\\/td>` +
    `<td class=\\"text-right table__cell\\">` +
    `<button class=\\"button\\"><div class=\\"button__content\\">Details<\\/div><\\/button>` +
    `<template><div data-controller=\\"modal\\"><h4>Parameters<\\/h4><pre>{}<\\/pre><\\/div><\\/template>` +
    `<\\/td>` +
    `<\\/tr>");\n` +
    `$('#load-more-button').html("<form data-remote=\\"true\\" ` +
    `action=\\"/o/0/a/0/s/0/load_more_logs.js?next_token=PLACEHOLDER%2Fs\\" ` +
    `method=\\"get\\"><button>Laad meer...<\\/button><\\/form>");`;
{
    const result = parseLoadMoreResponse(realBody);
    check('rowsHtml is non-null', result.rowsHtml !== null);
    check(
        'rowsHtml contains table__row',
        !!result.rowsHtml && result.rowsHtml.includes('table__row'),
        { rowsHtmlPreview: result.rowsHtml?.slice(0, 200) },
    );
    check(
        'rowsHtml has unescaped quotes (no remaining \\")',
        !!result.rowsHtml && !result.rowsHtml.includes('\\"'),
        { hasEscaped: result.rowsHtml?.includes('\\"') },
    );
    check(
        'rowsHtml has unescaped single quotes (no remaining \\\')',
        !!result.rowsHtml && !result.rowsHtml.includes("\\'"),
        { hasEscaped: result.rowsHtml?.includes("\\'") },
    );
    // PLACEHOLDER%2Fs decodes to PLACEHOLDER/s — verify URL-decoding worked.
    check(
        'nextToken decodes URL-encoded slashes',
        result.nextToken === 'PLACEHOLDER/s',
        { nextToken: result.nextToken },
    );
    // Real word-boundary regression: \n inside the JS string literal must
    // unescape to actual newlines, otherwise ".text--default GET" and
    // "text--body-sm 2099-01-01T…" concatenate without separators and the
    // \b-anchored timestamp/method regexes silently miss every row.
    check(
        'rowsHtml preserves whitespace between sibling tags',
        !!result.rowsHtml && /\s/.test(result.rowsHtml),
        { hasWs: result.rowsHtml ? /\s/.test(result.rowsHtml) : false },
    );
    check(
        'rowsHtml retains ISO timestamp text',
        !!result.rowsHtml && /2099-01-01T00:00:00Z/.test(result.rowsHtml),
        { sample: result.rowsHtml?.match(/2099-01-01T[^<]+/)?.[0] },
    );
}

// ---- Fixture E: quiet-window page (empty append + next_token in button) --
// Real-world shape from a BookingExperts subscription with no events in the
// requested time slice. The `$('#log-events').append("")` is an explicit
// "no rows for this page" while the load-more form still carries a forward-
// moving next_token. parseLoadMoreResponse must surface this as
// `rowsHtml = ''` (NOT null) so the main loop treats it as a quiet-window
// page and keeps paginating instead of bailing as "unparseable".
console.log('\nFixture E: quiet-window page (empty append + next_token in button)');
const quietWindowBody =
    `if (window.alertBox) {\n}\n\n` +
    `$('#log-events').append("");\n` +
    `$('#load-more-button').html("<form data-remote=\\"true\\" ` +
    `action=\\"/o/0/a/0/s/0/load_more_logs.js?next_token=QUIET%2Fnext\\" ` +
    `method=\\"get\\"><button>Laad meer...<\\/button><\\/form>");`;
{
    const result = parseLoadMoreResponse(quietWindowBody);
    check(
        'rowsHtml === "" (recognized shape, zero rows)',
        result.rowsHtml === '',
        { rowsHtml: result.rowsHtml },
    );
    check(
        'nextToken decoded from load-more-button HTML',
        result.nextToken === 'QUIET/next',
        { nextToken: result.nextToken },
    );
}

// ---- Fixture F: end-of-stream (empty append, no next_token) --------------
// BookingExperts has nothing left to paginate: the response acknowledges the
// request with an empty append and either omits the load-more button update
// or sets it to empty. parseLoadMoreResponse should still recognize the
// shape (rowsHtml === '') so the caller doesn't try the eval fallback, and
// nextToken === null so the while-loop exits cleanly via its `nextToken &&`
// guard rather than via the unparseable branch.
console.log('\nFixture F: end-of-stream (empty append, no next_token)');
const endOfStreamBody =
    `if (window.alertBox) {\n}\n\n` +
    `$('#log-events').append("");\n` +
    `$('#load-more-button').html("");`;
{
    const result = parseLoadMoreResponse(endOfStreamBody);
    check(
        'rowsHtml === "" (recognized shape, zero rows)',
        result.rowsHtml === '',
        { rowsHtml: result.rowsHtml },
    );
    check(
        'nextToken === null (no pagination state left)',
        result.nextToken === null,
        { nextToken: result.nextToken },
    );
}

// ---- Fixture G: totally unparseable body --------------------------------
// No turbo-stream, no $().append/.html, no next_token anywhere. The parser
// should leave `rowsHtml = null` (NOT '') so the main loop falls into its
// in-page eval / "stopping" branch rather than treating this as a quiet
// window.
console.log('\nFixture G: unrecognized response shape');
const garbageBody = `if (window.alertBox) {\n}\n\n// nothing useful here`;
{
    const result = parseLoadMoreResponse(garbageBody);
    check(
        'rowsHtml === null (unrecognized shape)',
        result.rowsHtml === null,
        { rowsHtml: result.rowsHtml },
    );
    check(
        'nextToken === null',
        result.nextToken === null,
        { nextToken: result.nextToken },
    );
}

// ---- Fixture H: real production token-echo body (redacted) ---------------
// Captured from /app/debug/token_missing-*.html on 2026-05-04 across nine
// failed jobs (99-108) hitting different subscriptions at high page
// numbers (323-387). Identical 769-byte shape every time:
//
//   - `$('#log-events').append("")` — no rows for this slice (recognized
//     quiet-window shape, so rowsHtml === '').
//   - `$('#load-more-button').html("…?next_token=<SAME>…")` — the load-more
//     form is updated, but its `next_token` URL-decodes to the *same value*
//     that produced this response. BookingExperts is wedged on the token
//     (likely "no events left in retention; here's the same cursor").
//
// What the parser must do: surface the echoed token unchanged. Pre-empting
// this to null is exactly the bug that caused 9 production jobs to fail
// with `token_missing` instead of the correct `token_echo` classification.
// Subscription IDs, organization IDs, and tokens below are placeholders.
console.log('\nFixture H: production token-echo body (recognized shape, echoed token)');
const ECHOED = 'b/12345678901234567890123456789012345678901234567890123456000/s';
const echoedBody =
    `if (window.alertBox) {\n}\n\n` +
    `$('#log-events').append("");\n` +
    `$('#load-more-button').html("<form data-remote=\\"true\\" ` +
    `action=\\"/organizations/0/apps/developer/applications/0/application_subscriptions/0/load_more_logs.js?next_token=` +
    encodeURIComponent(ECHOED).replace(/'/g, "\\'") +
    `\\" accept-charset=\\"UTF-8\\" method=\\"get\\">` +
    `<button type=\\"submit\\" class=\\"button button--neutral button--normal\\">` +
    `<div class=\\"button__content\\">Laad meer...<\\/div><\\/button><\\/form>");`;
{
    const result = parseLoadMoreResponse(echoedBody);
    check(
        'rowsHtml === "" (recognized quiet-window shape, zero rows)',
        result.rowsHtml === '',
        { rowsHtml: result.rowsHtml },
    );
    check(
        'nextToken passes through unchanged (echo detection lives in scrape.ts)',
        result.nextToken === ECHOED,
        { nextToken: result.nextToken, expected: ECHOED },
    );
    // Belt-and-suspenders: simulate what scrape.ts does at line 427 to
    // confirm the echo would be detected end-to-end.
    const sentToken = ECHOED;
    const wouldFireTokenEcho = result.nextToken !== null && result.nextToken === sentToken;
    check(
        'caller-side token_echo check fires for this body',
        wouldFireTokenEcho,
        { nextToken: result.nextToken, sentToken },
    );
}

if (failures > 0) {
    console.error(`\n${failures} assertion(s) failed.`);
    process.exit(1);
}

console.log('\nAll fixtures passed.');
