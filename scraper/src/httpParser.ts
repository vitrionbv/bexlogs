// Port of parseHttpContent / parseMessyHttpContent / convertRubyHashToJson
// from src/renderer/src/logger.js in the BexLogsElectron project.
// These parse the inline HTTP request/response strings BookingExperts renders
// inside its Details modal (headers + JSON body, sometimes concatenated on a
// single line, sometimes formatted with newlines).

interface HttpContent {
    method?: string;
    uri?: string;
    version?: string;
    firstLine?: string;
    headers: Record<string, string>;
    body: unknown;
}

const METHOD_RE = /^(GET|POST|PUT|DELETE|PATCH|HEAD|OPTIONS|CONNECT|TRACE)\s+/i;
const REQUEST_LINE_RE = /^(GET|POST|PUT|DELETE|PATCH|HEAD|OPTIONS|CONNECT|TRACE)\s+\/\S+/i;
const HEADER_NAME_RE = /([a-z][a-z0-9-]*):\s*/gi;

export function parseHttpContent(htmlContent: string, type: 'request' | 'response'): HttpContent | string {
    try {
        const plainText = stripTags(htmlContent).trim();
        if (!plainText) return htmlContent;

        const hasNewlines = plainText.includes('\n');

        if (!hasNewlines && plainText.includes(':')) {
            const m = plainText.match(METHOD_RE);
            if (m) {
                const withoutMethod = plainText.substring(m[0].length);
                return parseMessyHttpContent(withoutMethod, m[1]!.toUpperCase(), type);
            }
            return parseMessyHttpContent(plainText, null, type);
        }

        const lines = plainText
            .split('\n')
            .map((line) => line.trim())
            .filter((line) => line.length > 0);
        if (lines.length === 0) return htmlContent;

        const result: HttpContent = { headers: {}, body: null };

        let startLine = 0;

        if (type === 'request') {
            const first = lines[0]!;
            if (REQUEST_LINE_RE.test(first)) {
                const parts = first.split(/\s+/);
                result.method = parts[0];
                result.uri = parts[1];
                if (parts[2]) result.version = parts[2];
                startLine = 1;
            } else if (!first.includes(':')) {
                result.firstLine = first;
                startLine = 1;
            }
        } else {
            const first = lines[0]!;
            if (!first.includes(':')) {
                result.firstLine = first;
                startLine = 1;
            }
        }

        let bodyStartIndex = lines.length;
        let inHeaders = true;

        for (let i = startLine; i < lines.length; i++) {
            const line = lines[i]!;

            if (inHeaders && line.includes(':')) {
                const single = /^[a-z][a-z0-9-]*:\s+.+$/i.test(line) && !/[a-z][a-z0-9-]*:.*[a-z][a-z0-9-]*:/i.test(line);
                if (single) {
                    const colonIndex = line.indexOf(':');
                    const headerName = line.substring(0, colonIndex).trim();
                    const headerValue = line.substring(colonIndex + 1).trim();
                    result.headers[headerName] = headerValue;
                } else {
                    const jsonStart = line.search(/[{\[]/);
                    let headersPart = line;
                    let bodyPart = '';
                    if (jsonStart !== -1) {
                        headersPart = line.substring(0, jsonStart);
                        bodyPart = line.substring(jsonStart);
                    }
                    extractHeaders(headersPart, result.headers);
                    if (bodyPart) {
                        bodyStartIndex = i;
                        lines[i] = bodyPart;
                        inHeaders = false;
                        break;
                    }
                }
            } else if (line.startsWith('{') || line.startsWith('[')) {
                bodyStartIndex = i;
                inHeaders = false;
                break;
            } else if (line === '') {
                const next = lines[i + 1];
                if (next && (next.startsWith('{') || next.startsWith('['))) {
                    bodyStartIndex = i + 1;
                    inHeaders = false;
                    break;
                }
            }
        }

        if (bodyStartIndex < lines.length) {
            const bodyText = lines.slice(bodyStartIndex).join('\n').trim();
            try {
                result.body = JSON.parse(bodyText);
            } catch {
                result.body = bodyText;
            }
        }

        return result;
    } catch {
        return htmlContent;
    }
}

export function parseMessyHttpContent(
    content: string,
    method: string | null,
    type: 'request' | 'response',
): HttpContent {
    const result: HttpContent = { headers: {}, body: null };
    if (method && type === 'request') result.method = method;

    const jsonStart = Math.min(
        content.indexOf('{') !== -1 ? content.indexOf('{') : Infinity,
        content.indexOf('[') !== -1 ? content.indexOf('[') : Infinity,
    );

    let headersPart = content;
    let bodyPart = '';
    if (jsonStart !== Infinity) {
        headersPart = content.substring(0, jsonStart);
        bodyPart = content.substring(jsonStart);
    }

    extractHeaders(headersPart, result.headers);

    if (bodyPart) {
        try {
            result.body = JSON.parse(bodyPart);
        } catch {
            result.body = bodyPart;
        }
    }
    return result;
}

export function convertRubyHashToJson(rubyHash: string): unknown {
    try {
        const jsonString = rubyHash
            .replace(/=>/g, ':')
            .replace(/&gt;/g, '>')
            .replace(/&lt;/g, '<')
            .replace(/&quot;/g, '"')
            .replace(/&amp;/g, '&');
        return JSON.parse(jsonString);
    } catch {
        return rubyHash;
    }
}

function extractHeaders(headersPart: string, into: Record<string, string>): void {
    const positions: { name: string; start: number; valueStart: number }[] = [];
    HEADER_NAME_RE.lastIndex = 0;
    let m: RegExpExecArray | null;
    while ((m = HEADER_NAME_RE.exec(headersPart)) !== null) {
        positions.push({
            name: m[1]!,
            start: m.index,
            valueStart: m.index + m[0].length,
        });
    }
    for (let i = 0; i < positions.length; i++) {
        const cur = positions[i]!;
        const next = positions[i + 1];
        const value = next
            ? headersPart.substring(cur.valueStart, next.start).trim()
            : headersPart.substring(cur.valueStart).trim();
        if (cur.name && value) into[cur.name] = value;
    }
}

function stripTags(s: string): string {
    return s
        .replace(/<span[^>]*>/g, '')
        .replace(/<\/span>/g, '')
        .replace(/<br\s*\/?>(?!\n)/gi, '\n')
        .replace(/<\/p>\s*<p[^>]*>/gi, '\n\n')
        .replace(/<[^>]*>/g, '');
}
