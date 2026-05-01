import { parseHttpContent, convertRubyHashToJson } from './httpParser.js';
import type { RawRow } from './extractors.js';
import type { ParsedLogMessage } from './types.js';

/**
 * Convert a raw row + its detail-modal HTML into a normalized
 * ParsedLogMessage. The detail HTML is expected to contain three labeled
 * sections — Parameters, Request, Response — each rendered with an h4 (new
 * layout) or as rows of a `.description-table__table` (legacy layout).
 */
export function rowToMessage(row: RawRow): ParsedLogMessage {
    const msg: ParsedLogMessage = {
        timestamp: row.timestamp,
        type: row.type,
        action: row.action,
        method: row.method,
        path: row.path ?? null,
        status: row.status ?? null,
    };

    if (!row.detailHtml) return msg;

    const sections = extractDetailSections(row.detailHtml);
    if (sections.parameters !== undefined) msg.parameters = parseSection(sections.parameters, 'parameters');
    if (sections.request !== undefined) msg.request = parseSection(sections.request, 'request');
    if (sections.response !== undefined) msg.response = parseSection(sections.response, 'response');

    if (
        msg.method
        && msg.request
        && typeof msg.request === 'object'
        && msg.request !== null
        && !('method' in msg.request)
    ) {
        (msg.request as Record<string, unknown>).method = msg.method;
    }

    return msg;
}

interface DetailSections {
    parameters?: string;
    request?: string;
    response?: string;
}

function extractDetailSections(html: string): DetailSections {
    const out: DetailSections = {};

    // Strategy A — legacy: <table class="description-table__table"><tr><th>Parameters</th><td>…</td></tr>…</table>
    const legacy = html.matchAll(
        /<tr[^>]*>\s*<th[^>]*>([^<]+)<\/th>\s*<td[^>]*>([\s\S]*?)<\/td>\s*<\/tr>/gi,
    );
    for (const m of legacy) {
        const key = (m[1] ?? '').trim().toLowerCase();
        const value = m[2] ?? '';
        if (key === 'parameters') out.parameters = value;
        else if (key === 'request') out.request = value;
        else if (key === 'response') out.response = value;
    }
    if (out.parameters || out.request || out.response) return out;

    // Strategy B — new layout: <h4>Parameters</h4><div|pre>…</div>
    const sections = ['parameters', 'request', 'response'] as const;
    for (const section of sections) {
        const re = new RegExp(
            `<h(?:3|4)[^>]*>\\s*${section}\\s*</h(?:3|4)>([\\s\\S]*?)(?=<h(?:3|4)[^>]*>|$)`,
            'i',
        );
        const m = html.match(re);
        if (m && m[1]) out[section] = m[1];
    }

    return out;
}

function parseSection(rawHtml: string, kind: 'parameters' | 'request' | 'response'): unknown {
    // For request/response, prefer structured HTTP parser if there's a <pre>.
    const preMatch = rawHtml.match(/<pre[^>]*>([\s\S]*?)<\/pre>/i);
    if (preMatch && preMatch[1] && (kind === 'request' || kind === 'response')) {
        return parseHttpContent(preMatch[1], kind);
    }

    const text = htmlToText(rawHtml);
    if (!text) return null;

    try {
        if (text.includes('=>')) return convertRubyHashToJson(text);
        if (text.startsWith('{') || text.startsWith('[')) return JSON.parse(text);
        return text;
    } catch {
        return text;
    }
}

function htmlToText(html: string): string {
    return html
        .replace(/<br\s*\/?>(?!\n)/gi, '\n')
        .replace(/<\/p>\s*<p[^>]*>/gi, '\n\n')
        .replace(/<[^>]*>/g, '')
        .replace(/&nbsp;/g, ' ')
        .replace(/&amp;/g, '&')
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/&quot;/g, '"')
        .replace(/&#39;/g, "'")
        .trim();
}
