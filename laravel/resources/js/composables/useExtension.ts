import { onBeforeUnmount, onMounted, readonly, ref } from 'vue';

/**
 * Talks to the BexLogs browser extension over window.postMessage.
 *
 * Protocol — both sides use { source, type, … } envelopes; we ignore
 * everything that doesn't match `source: 'bexlogs-app' | 'bexlogs-ext'`.
 *
 *   App  -> Ext   { type: 'BEXLOGS_EXT_PING' }
 *   Ext  -> App   { type: 'BEXLOGS_EXT_PONG', version, linkedOrigin }
 *   App  -> Ext   { type: 'BEXLOGS_EXT_LINK', origin, name }
 *   Ext  -> App   { type: 'BEXLOGS_EXT_LINKED', origin }
 *   App  -> Ext   { type: 'BEXLOGS_EXT_UNLINK' }
 *   Ext  -> App   { type: 'BEXLOGS_EXT_UNLINKED' }
 *   Ext  -> App   { type: 'BEXLOGS_EXT_HELLO', version }   (fired on page load)
 *
 * Polling cadence:
 *   - Fast burst: 6 pings every 1.5s (catches an extension that was just
 *     installed seconds before this page loaded).
 *   - Slow heartbeat: after the burst, if still undetected, keep pinging
 *     every 4s indefinitely so a user who installs the extension while
 *     this page is open gets picked up within a few seconds — no refresh
 *     required.
 *   - On the first successful response (detected = true) all timers are
 *     cleared, so a healthy page goes silent after at most a handful of
 *     pings. Timers are also cleared on unmount.
 */

export type ExtensionInfo = {
    detected: boolean;
    version: string | null;
    linkedOrigin: string | null;
    isLinkedHere: boolean;
};

export function useExtension(currentOrigin: string) {
    const info = ref<ExtensionInfo>({
        detected: false,
        version: null,
        linkedOrigin: null,
        isLinkedHere: false,
    });

    const FAST_BURST_PINGS = 6;
    const FAST_INTERVAL_MS = 1500;
    const SLOW_INTERVAL_MS = 4000;

    let pingTimer: ReturnType<typeof setTimeout> | null = null;

    const clearPing = () => {
        if (pingTimer) {
            clearTimeout(pingTimer);
            pingTimer = null;
        }
    };

    const post = (payload: Record<string, unknown>) => {
        window.postMessage({ source: 'bexlogs-app', ...payload }, window.location.origin);
    };

    const onMessage = (event: MessageEvent) => {
        if (event.source !== window) {
return;
}

        const data = event.data;

        if (!data || typeof data !== 'object') {
return;
}

        if (data.source !== 'bexlogs-ext') {
return;
}

        switch (data.type) {
            case 'BEXLOGS_EXT_HELLO':
            case 'BEXLOGS_EXT_PONG':
            case 'BEXLOGS_EXT_LINKED':
            case 'BEXLOGS_EXT_UNLINKED':
                info.value = {
                    detected: true,
                    version: typeof data.version === 'string' ? data.version : info.value.version,
                    linkedOrigin: typeof data.linkedOrigin === 'string' ? data.linkedOrigin : info.value.linkedOrigin,
                    isLinkedHere: typeof data.linkedOrigin === 'string' ? data.linkedOrigin === currentOrigin : info.value.isLinkedHere,
                };

                if (data.type === 'BEXLOGS_EXT_UNLINKED') {
                    info.value.linkedOrigin = null;
                    info.value.isLinkedHere = false;
                }

                clearPing();
                break;
        }
    };

    const ping = () => post({ type: 'BEXLOGS_EXT_PING' });

    const link = (name: string) => {
        post({ type: 'BEXLOGS_EXT_LINK', origin: currentOrigin, name });
    };

    const unlink = () => {
        post({ type: 'BEXLOGS_EXT_UNLINK' });
    };

    // Recursive setTimeout so the cadence can vary mid-flight: fast for the
    // initial burst, then slow forever until detection (or unmount).
    const scheduleNextPing = (pingsSent: number) => {
        if (info.value.detected) {
            clearPing();

            return;
        }

        const delay = pingsSent < FAST_BURST_PINGS ? FAST_INTERVAL_MS : SLOW_INTERVAL_MS;
        pingTimer = setTimeout(() => {
            ping();
            scheduleNextPing(pingsSent + 1);
        }, delay);
    };

    onMounted(() => {
        window.addEventListener('message', onMessage);
        ping();
        scheduleNextPing(1);
    });

    onBeforeUnmount(() => {
        window.removeEventListener('message', onMessage);
        clearPing();
    });

    return {
        info: readonly(info),
        ping,
        link,
        unlink,
    };
}
