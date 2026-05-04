// DOM extractors that run inside `page.evaluate()` in the Playwright browser
// context. Returned values must be JSON-serializable.
//
// Two extractors:
//   1) extractRowsFromMain  — initial page load (full HTML in DOM)
//   2) parseLoadMoreResponse — text/javascript response from
//      /load_more_logs.js?next_token=… (Rails JS that does
//      $('selector').append("HTML") and replaces the load-more button HTML)

export interface RawRow {
    timestamp: string;
    type: string;
    action: string;
    method: string;
    path: string | null;
    status: string | null;
    detailHtml: string | null;
}

export interface InitialPageResult {
    rows: RawRow[];
    nextToken: string | null;
    diagnostics: {
        rowSelectorTried: string[];
        rowsFoundBySelector: Record<string, number>;
        loadMoreFound: boolean;
        loadMoreFormFound: boolean;
        loadMoreActionUrl: string | null;
    };
}

export interface LoadMoreResult {
    rowsHtml: string | null;
    nextToken: string | null;
    raw: string;
}

/**
 * Runs inside page.evaluate(). Walks the rendered logs page and pulls one
 * record per row. Tries multiple row-selector strategies in order, picks the
 * one that returns the most rows.
 */
export function extractRowsFromMain(): InitialPageResult {
    const ISO_RE = /\b\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\b/;
    const METHOD_RE = /\b(GET|POST|PUT|DELETE|PATCH|HEAD|OPTIONS)\b/;

    const candidateSelectors = [
        '#log-events tr.table__row',
        'tr.table__row',
        '#log-events > *',
        '[data-controller~="log-event"]',
        '[data-log-event]',
        'main table tbody tr',
        'main [role="list"] > [role="listitem"]',
        'main [role="row"]',
    ];

    const rowsFoundBySelector: Record<string, number> = {};
    let chosen: { selector: string; nodes: Element[] } | null = null;

    for (const sel of candidateSelectors) {
        const nodes = Array.from(document.querySelectorAll(sel));
        rowsFoundBySelector[sel] = nodes.length;
        if (nodes.length > 0 && (!chosen || nodes.length > chosen.nodes.length)) {
            chosen = { selector: sel, nodes };
        }
    }

    // Last-resort fallback: derive rows from the per-row "Details" button.
    if (!chosen || chosen.nodes.length === 0) {
        const detailsButtons = Array.from(document.querySelectorAll('button')).filter(
            (b) => (b.textContent ?? '').trim() === 'Details',
        );
        const inferredRows: Element[] = [];
        for (const btn of detailsButtons) {
            const row = btn.closest('tr, li, [role="row"], [role="listitem"], div[class*="row"], div[class*="entry"]');
            if (row && !inferredRows.includes(row)) inferredRows.push(row);
        }
        if (inferredRows.length > 0) {
            chosen = { selector: 'button:has-text("Details") -> closest(row)', nodes: inferredRows };
            rowsFoundBySelector['inferred-via-details'] = inferredRows.length;
        }
    }

    const rows: RawRow[] = [];
    if (chosen) {
        for (const node of chosen.nodes) {
            const text = (node.textContent ?? '').replace(/\s+/g, ' ').trim();
            const tsMatch = text.match(ISO_RE);
            const methodMatch = text.match(METHOD_RE);

            const cells = Array.from(node.querySelectorAll(':scope > td'));
            let timestamp = '';
            let type = '';
            let action = '';
            let method = '';
            let status: string | null = null;
            let path: string | null = null;

            if (cells.length >= 4) {
                // Current BookingExperts column order is action+type, method+path,
                // timestamp, status, details — but legacy admin views may swap it,
                // so detect each field by content marker rather than position.
                for (const cell of cells) {
                    const cellText = (cell.textContent ?? '').replace(/\s+/g, ' ').trim();

                    if (!timestamp) {
                        const m = cellText.match(ISO_RE);
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
                            // BookingExperts uses a small but open set of
                            // event labels (Webhook, Api Call, Command, …).
                            // Accept any non-empty highlight text so new
                            // labels don't silently become empty strings
                            // and trip the Laravel `type required` check.
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

                // Final fallbacks if structured selectors didn't find anything.
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
                // Non-table layout: derive everything from row text.
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
                if (!action) {
                    const heading = node.querySelector('h3, h2, .title, [class*="action"]');
                    action = (heading?.textContent ?? '').trim();
                }
            }

            // Detail payload: prefer inline <template> or hidden <div>.
            let detailHtml: string | null = null;
            const tmpl = node.querySelector('template') as HTMLTemplateElement | null;
            if (tmpl) {
                detailHtml = tmpl.innerHTML;
            } else {
                const hidden = node.querySelector('[hidden], [data-modal-content], dialog');
                if (hidden) detailHtml = hidden.innerHTML;
            }

            if (timestamp || method || action) {
                rows.push({ timestamp, type, action, method, path, status, detailHtml });
            }
        }
    }

    // Find load-more button + extract next_token from any obvious place.
    const loadMoreEl =
        document.querySelector<HTMLElement>('#load-more-button')
        ?? document.querySelector<HTMLElement>('[data-load-more]')
        ?? Array.from(document.querySelectorAll<HTMLElement>('button, a')).find((el) =>
            (el.textContent ?? '').trim().startsWith('Laad meer'),
        )
        ?? null;

    let actionUrl: string | null = null;
    let formFound = false;

    if (loadMoreEl) {
        const form = loadMoreEl.closest('form') ?? loadMoreEl.querySelector('form');
        if (form) {
            formFound = true;
            actionUrl = form.getAttribute('action');
        }
        if (!actionUrl) {
            const anchor = loadMoreEl.tagName === 'A' ? (loadMoreEl as HTMLAnchorElement) : loadMoreEl.querySelector('a');
            actionUrl = anchor?.getAttribute('href') ?? null;
        }
        if (!actionUrl) {
            actionUrl =
                loadMoreEl.getAttribute('data-url')
                ?? loadMoreEl.getAttribute('data-href')
                ?? loadMoreEl.getAttribute('data-load-more-url')
                ?? loadMoreEl.dataset.url
                ?? null;
        }
    }

    let nextToken: string | null = null;
    if (actionUrl) {
        try {
            const u = new URL(actionUrl, window.location.href);
            nextToken = u.searchParams.get('next_token');
        } catch {
            const m = actionUrl.match(/[?&]next_token=([^&]+)/);
            if (m && m[1]) nextToken = decodeURIComponent(m[1]);
        }
    }

    return {
        rows,
        nextToken,
        diagnostics: {
            rowSelectorTried: candidateSelectors,
            rowsFoundBySelector,
            loadMoreFound: !!loadMoreEl,
            loadMoreFormFound: formFound,
            loadMoreActionUrl: actionUrl,
        },
    };
}

/**
 * Parse a /load_more_logs.js?next_token=… response (could be Turbo Streams or
 * legacy Rails-UJS JS).
 *
 * Strategy precedence:
 *   1. Turbo Stream blocks (`<turbo-stream action="…" target="…"><template>…
 *      </template></turbo-stream>`) — preferred. Concatenate the inner-template
 *      HTML of every block whose target looks like a rows container (or whose
 *      inner HTML clearly contains row-shaped elements). Read `next_token`
 *      from any block whose target looks like load-more / pagination.
 *   2. Legacy jQuery patterns (`$('…').append("…")`, `$('…').html("…")`) —
 *      fallback when the response has no `<turbo-stream>` element.
 *   3. Last resort: any double-quoted string >200 chars that looks like row
 *      HTML (e.g. contains `<tr`, `<article`, `data-controller="log-event"`,
 *      `Webhook`, `Api Call`, or `Details`).
 *
 * `rowsHtml` semantics:
 *   - non-empty `string` — row payload extracted; feed to extractRowsFromHtmlString.
 *   - `''` (empty string) — recognized response shape (Turbo Stream OR jQuery
 *     $().append/.html) but no row payload found. This is the BookingExperts
 *     "quiet window" shape: server returned zero events for this slice but
 *     still handed us a forward-moving next_token in the load-more button.
 *     The caller should advance via `nextToken` instead of bailing.
 *   - `null` — couldn't recognize the response shape at all. The caller may
 *     fall back to in-page JS eval / regex strategies.
 *
 * `nextToken` is whatever the response surfaced — including a value that
 * matches the request's `next_token`. The pagination layer in `scrape.ts`
 * is the single authority for echo detection (see the "pagination appears
 * stuck" check). Pre-emptively nulling echoes here would collapse two
 * distinct outcomes ("server echoed our token" vs "server returned no
 * token at all") into the same `nextToken === null` signal, which is
 * exactly how `token_echo` failures used to leak into the `token_missing`
 * bucket.
 */
export function parseLoadMoreResponse(jsBody: string): LoadMoreResult {
    const result: LoadMoreResult = { rowsHtml: null, nextToken: null, raw: jsBody };

    const ROWS_TARGET_RE = /log[-_]?event|logs|events/i;
    const PAGER_TARGET_RE = /load[-_]?more|paginat|next/i;
    const ROW_SHAPE_RE = /<tr\b|<article\b|data-controller=["'][^"']*log-event|Webhook|Api ?Call/i;

    let pagerInnerHtml: string | null = null;
    // Whether we recognized the response as a known shape (Turbo Stream or
    // legacy Rails-UJS jQuery). Used at the end to distinguish "explicit
    // zero-row page" (`rowsHtml = ''`) from "totally unparseable" (`null`).
    let recognizedShape = false;

    // Strategy 1: Turbo Stream blocks.
    const hasTurboStream = /<turbo-stream\b/i.test(jsBody);
    if (hasTurboStream) {
        recognizedShape = true;
        const rowsParts: string[] = [];
        const turboBlockRe = /<turbo-stream\b([^>]*)>([\s\S]*?)<\/turbo-stream>/gi;
        let match: RegExpExecArray | null;
        while ((match = turboBlockRe.exec(jsBody)) !== null) {
            const attrs = match[1] ?? '';
            const blockBody = match[2] ?? '';
            const templateMatch = blockBody.match(/<template\b[^>]*>([\s\S]*?)<\/template>/i);
            const innerHtml = (templateMatch?.[1] ?? blockBody).trim();
            if (!innerHtml) continue;

            const targetAttr =
                attrs.match(/\btargets?\s*=\s*"([^"]*)"/i)?.[1]
                ?? attrs.match(/\btargets?\s*=\s*'([^']*)'/i)?.[1]
                ?? '';

            const isPager = PAGER_TARGET_RE.test(targetAttr);
            const isRows = ROWS_TARGET_RE.test(targetAttr);

            if (isPager) {
                pagerInnerHtml = (pagerInnerHtml ?? '') + innerHtml;
            } else if (isRows || ROW_SHAPE_RE.test(innerHtml)) {
                rowsParts.push(innerHtml);
            }
        }

        if (rowsParts.length > 0) {
            result.rowsHtml = rowsParts.join('\n');
        }
    }

    // Strategy 2: legacy jQuery patterns. Only as a fallback when no
    // <turbo-stream> element is present in the body.
    if (!result.rowsHtml && !hasTurboStream) {
        const appendChunks: string[] = [];
        const htmlChunks: string[] = [];
        // NOTE: we keep empty captures (e.g. `.append("")`) so the presence of
        // the call alone marks the body as a recognized shape. The row /
        // pager filters below will reject empty strings on their merits.
        for (const m of jsBody.matchAll(/\.append\(\s*"((?:\\.|[^"\\])*)"\s*\)/g)) {
            appendChunks.push(unescapeJs(m[1] ?? ''));
        }
        for (const m of jsBody.matchAll(/\.html\(\s*"((?:\\.|[^"\\])*)"\s*\)/g)) {
            htmlChunks.push(unescapeJs(m[1] ?? ''));
        }

        if (appendChunks.length > 0 || htmlChunks.length > 0) {
            recognizedShape = true;
        }

        const rowCandidates = [...appendChunks, ...htmlChunks].filter(
            (c) => ROW_SHAPE_RE.test(c) || /Details/i.test(c),
        );
        if (rowCandidates.length > 0) {
            result.rowsHtml = rowCandidates
                .sort((a, b) => b.length - a.length)
                .join('\n');
        }

        // Pager candidate: an .html(...) chunk that contains a next_token URL.
        const pager = htmlChunks.find((c) => /next_token=/.test(c));
        if (pager) pagerInnerHtml = pager;
    }

    // Strategy 3: any quoted string >200 chars whose decoded content looks
    // like row HTML.
    if (!result.rowsHtml) {
        const big = jsBody.matchAll(/"((?:\\.|[^"\\])*)"/g);
        const candidates: string[] = [];
        for (const m of big) {
            const s = m[1] ?? '';
            if (s.length <= 200) continue;
            const decoded = unescapeJs(s);
            if (ROW_SHAPE_RE.test(decoded) || /Details/i.test(decoded)) {
                candidates.push(decoded);
            }
        }
        if (candidates.length > 0) {
            result.rowsHtml = candidates.sort((a, b) => b.length - a.length)[0] ?? null;
        }
    }

    // Token extraction. Prefer the pager block; fall back to anywhere in the body.
    const tokenSources: string[] = [];
    if (pagerInnerHtml) tokenSources.push(pagerInnerHtml);
    tokenSources.push(jsBody);

    let extracted: string | null = null;
    for (const src of tokenSources) {
        // URL-style: ?next_token=… / &next_token=…
        const urlMatch = src.match(/next_token=([^"'&\\\s<>]+)/);
        if (urlMatch && urlMatch[1]) {
            extracted = urlMatch[1];
            break;
        }
        // Hidden input style: <input … name="next_token" value="…"> (either order).
        const hiddenMatch =
            src.match(/<input\b[^>]*\bname\s*=\s*["']next_token["'][^>]*\bvalue\s*=\s*["']([^"']+)["']/i)
            ?? src.match(/<input\b[^>]*\bvalue\s*=\s*["']([^"']+)["'][^>]*\bname\s*=\s*["']next_token["']/i);
        if (hiddenMatch && hiddenMatch[1]) {
            extracted = hiddenMatch[1];
            break;
        }
    }

    if (extracted !== null) {
        let decoded = extracted;
        try {
            decoded = decodeURIComponent(extracted);
        } catch {
            // Keep raw value.
        }
        result.nextToken = decoded;
    }

    // Recognized response shape (Turbo Stream or jQuery $().append/.html) but
    // no row payload extracted: surface as `rowsHtml = ''` so the caller can
    // treat this as a quiet-window page (no rows, advance via next_token)
    // rather than as an unparseable response. Leave `null` if we never saw a
    // recognized shape — the caller has its own in-page eval fallback.
    if (result.rowsHtml === null && recognizedShape) {
        result.rowsHtml = '';
    }

    return result;
}

function unescapeJs(s: string): string {
    // NOTE: \n and \t become actual whitespace, not empty strings. The HTML
    // parser collapses them anyway, but `Element.textContent` preserves them
    // as separators between sibling text nodes — which is what later
    // text-based regexes (\b\d{4}-… for timestamps, \b(GET|POST|…) for
    // methods) rely on. Stripping them concatenates words like
    // "POST2026-04-30T…" and breaks the \b word-boundary anchor.
    return s
        .replace(/\\"/g, '"')
        .replace(/\\'/g, "'")
        .replace(/\\n/g, '\n')
        .replace(/\\t/g, '\t')
        .replace(/\\\//g, '/')
        .replace(/\\\\/g, '\\');
}
