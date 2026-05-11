import { ref  } from 'vue';
import type {Ref} from 'vue';

/**
 * Streaming chat client for the per-subscription "ask your logs" agent.
 *
 * The server emits `text/event-stream` lines like
 *   data: {"type":"text","delta":"..."}
 *   data: {"type":"tool_call","name":"...","args":{...}}
 *   data: {"type":"tool_result","name":"...","summary":"..."}
 *   data: {"type":"done","usage":{...}}
 *   data: {"type":"error","message":"..."}
 *
 * We POST with `fetch` (not EventSource — EventSource is GET-only and we
 * need a body + CSRF header), then read the response body as a
 * ReadableStream and decode UTF-8 chunks as they arrive. Tool calls are
 * exposed inline on the assistant message so the UI can render them in a
 * collapsible <details> block.
 */

export type ChatTextEvent = { type: 'text'; delta: string };
export type ChatToolCallEvent = {
    type: 'tool_call';
    id?: string | null;
    name: string;
    args: unknown;
};
/**
 * The server emits `summary` as a small JSON object (e.g.
 * `{rows_count: 20, total: 100}` or `{error: '…'}`); the panel
 * stringifies it for display in the collapsed tool-call section.
 */
export type ChatToolResultEvent = {
    type: 'tool_result';
    id?: string | null;
    name: string;
    summary: Record<string, unknown> | string | null;
};
export type ChatDoneEvent = {
    type: 'done';
    usage?: { input_tokens?: number; output_tokens?: number };
};
export type ChatErrorEvent = { type: 'error'; message: string };
export type ChatEvent =
    | ChatTextEvent
    | ChatToolCallEvent
    | ChatToolResultEvent
    | ChatDoneEvent
    | ChatErrorEvent;

export interface ToolInvocation {
    id?: string | null;
    name: string;
    args: unknown;
    summary?: Record<string, unknown> | string | null;
}

export interface ChatMessage {
    id: string;
    role: 'user' | 'assistant';
    content: string;
    toolCalls: ToolInvocation[];
    pending: boolean;
    error?: string;
    usage?: { input_tokens?: number; output_tokens?: number };
}

interface UseChatStreamOptions {
    /**
     * Either a static URL string or a getter that resolves to one at
     * call-time. The chat panel uses a getter so the URL can refer to
     * a conversation id that's only known after the first `start`
     * round-trip; pages that already know the id can pass a string.
     */
    streamUrl: string | (() => string);
}

function resolveUrl(spec: UseChatStreamOptions['streamUrl']): string {
    return typeof spec === 'function' ? spec() : spec;
}

function csrfToken(): string {
    if (typeof document === 'undefined') {
        return '';
    }

    return (
        document.head
            .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

function freshId(prefix: string): string {
    return `${prefix}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
}

export function useChatStream(opts: UseChatStreamOptions) {
    const messages: Ref<ChatMessage[]> = ref([]);
    const streaming = ref(false);
    const error: Ref<string | null> = ref(null);
    let controller: AbortController | null = null;

    function reset(): void {
        messages.value = [];
        error.value = null;
    }

    async function send(text: string): Promise<void> {
        if (!text.trim() || streaming.value) {
            return;
        }

        error.value = null;
        controller = new AbortController();

        const userMsg: ChatMessage = {
            id: freshId('user'),
            role: 'user',
            content: text,
            toolCalls: [],
            pending: false,
        };
        const assistantMsg: ChatMessage = {
            id: freshId('asst'),
            role: 'assistant',
            content: '',
            toolCalls: [],
            pending: true,
        };
        messages.value.push(userMsg, assistantMsg);

        streaming.value = true;
        const target = resolveUrl(opts.streamUrl);

        if (!target) {
            const message = 'Stream URL is not ready yet.';
            assistantMsg.error = message;
            error.value = message;
            assistantMsg.pending = false;
            streaming.value = false;
            controller = null;

            return;
        }

        try {
            const response = await fetch(target, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'text/event-stream',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ message: text }),
                signal: controller.signal,
            });

            if (!response.ok) {
                const body = await response.text().catch(() => '');

                throw new Error(
                    response.status === 429
                        ? 'Rate limit hit. Try again in a minute.'
                        : `HTTP ${response.status}: ${body || response.statusText}`,
                );
            }

            if (!response.body) {
                throw new Error('Empty response body — streaming unsupported.');
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder('utf-8');
            let buffer = '';

             
            while (true) {
                const { done, value } = await reader.read();

                if (done) {
break;
}

                buffer += decoder.decode(value, { stream: true });

                // SSE event boundaries are blank lines (\n\n).
                let sep: number;

                while ((sep = buffer.indexOf('\n\n')) !== -1) {
                    const raw = buffer.slice(0, sep);
                    buffer = buffer.slice(sep + 2);
                    const dataLines = raw
                        .split('\n')
                        .filter((line) => line.startsWith('data:'))
                        .map((line) => line.slice(5).trimStart());

                    if (dataLines.length === 0) {
continue;
}

                    const payload = dataLines.join('\n');

                    if (!payload) {
continue;
}

                    try {
                        applyEvent(assistantMsg, JSON.parse(payload) as ChatEvent);
                    } catch {
                        // Bad JSON in stream; skip the frame rather than
                        // killing the whole turn.
                    }
                }
            }
        } catch (err) {
            const message = err instanceof Error ? err.message : String(err);

            // AbortError fires on user-initiated cancel; suppress it.
            if (!(err instanceof DOMException && err.name === 'AbortError')) {
                assistantMsg.error = message;
                error.value = message;
            }
        } finally {
            assistantMsg.pending = false;
            streaming.value = false;
            controller = null;
        }
    }

    function cancel(): void {
        if (controller !== null) {
            controller.abort();
        }
    }

    return { messages, streaming, error, send, cancel, reset };
}

function applyEvent(target: ChatMessage, event: ChatEvent): void {
    switch (event.type) {
        case 'text':
            target.content += event.delta;
            break;
        case 'tool_call':
            target.toolCalls.push({
                id: event.id ?? null,
                name: event.name,
                args: event.args,
            });
            break;
        case 'tool_result': {
            const existing = target.toolCalls.find(
                (t) =>
                    (event.id != null && t.id === event.id) ||
                    (event.id == null && t.name === event.name && !t.summary),
            );

            if (existing) {
                existing.summary = event.summary;
            } else {
                target.toolCalls.push({
                    id: event.id ?? null,
                    name: event.name,
                    args: undefined,
                    summary: event.summary,
                });
            }

            break;
        }
        case 'done':
            target.usage = event.usage;
            break;
        case 'error':
            target.error = event.message;
            break;
    }
}
