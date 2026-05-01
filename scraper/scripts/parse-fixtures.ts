// Imperative test fixtures for parseLoadMoreResponse. Proves the parser
// behaves correctly for both legacy jQuery and Turbo Stream response shapes,
// and that it defensively rejects a token that didn't advance.
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
    const result = parseLoadMoreResponse(legacyBody, null);
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
    const result = parseLoadMoreResponse(turboBody, null);
    check('rowsHtml is non-null', result.rowsHtml !== null);
    check(
        'rowsHtml contains table__row',
        !!result.rowsHtml && result.rowsHtml.includes('table__row'),
        { rowsHtml: result.rowsHtml },
    );
    check('nextToken === "XYZ"', result.nextToken === 'XYZ', { nextToken: result.nextToken });
}

// ---- Fixture C: Turbo Stream with token == previousToken -------------------
console.log('\nFixture C: Turbo Stream where token did not advance');
const turboSameTokenBody =
    `<turbo-stream action="append" target="log-events">` +
    `<template><tr class="table__row"><td>x</td></tr></template>` +
    `</turbo-stream>` +
    `<turbo-stream action="replace" target="load-more-button">` +
    `<template><a href="/foo?next_token=ABC">Load more</a></template>` +
    `</turbo-stream>`;
{
    const result = parseLoadMoreResponse(turboSameTokenBody, 'ABC');
    check(
        'nextToken === null (rejected because token did not advance)',
        result.nextToken === null,
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
    const result = parseLoadMoreResponse(realBody, null);
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

if (failures > 0) {
    console.error(`\n${failures} assertion(s) failed.`);
    process.exit(1);
}

console.log('\nAll fixtures passed.');
