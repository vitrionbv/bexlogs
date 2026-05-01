// BexLogs extension — content script.
//
// Bridges window.postMessage on whatever page the user is browsing with the
// background service worker. Lets BexLogs Laravel pages:
//   - detect the extension in real time (BEXLOGS_EXT_PING -> _PONG)
//   - link this origin so future pair flows are auto-handed-off
//   - hand off pairing tokens (BEXLOGS_EXT_PAIR -> background does the rest)
//
// We deliberately respond to *every* page (the manifest <all_urls> match
// excludes BookingExperts itself so we don't interfere there). Only postMessage
// envelopes tagged { source: 'bexlogs-app' } are honored, and we only ever
// reply to window.postMessage on `window` — no globals leak.
//
// Idempotency: background.js calls chrome.scripting.executeScript on every SW
// boot so already-open tabs get the script without a refresh. Re-running this
// file would double-register listeners, so we guard on the isolated-world
// `window`. On a re-injection we just fire a fresh HELLO so the page can
// re-detect us, then bail out.

if (window.__bexlogsContentLoaded) {
    try {
        const VERSION_RE = chrome.runtime.getManifest().version;
        window.postMessage(
            { source: 'bexlogs-ext', type: 'BEXLOGS_EXT_HELLO', version: VERSION_RE },
            window.location.origin,
        );
    } catch {}
} else {
    window.__bexlogsContentLoaded = true;
    bootstrap();
}

function bootstrap() {

const MANIFEST = chrome.runtime.getManifest();
const VERSION = MANIFEST.version;

let cachedLinkedOrigin = null;

(async () => {
    try {
        const stored = await chrome.storage.sync.get(['linkedOrigin', 'baseUrl']);
        cachedLinkedOrigin = stored.linkedOrigin ?? stored.baseUrl ?? null;
    } catch {}
    // Announce we're here. Pages that mount their listener after this fires
    // can recover by sending BEXLOGS_EXT_PING.
    post({
        type: 'BEXLOGS_EXT_HELLO',
        version: VERSION,
        linkedOrigin: cachedLinkedOrigin,
    });
})();

window.addEventListener('message', async (event) => {
    if (event.source !== window) return;
    const data = event.data;
    if (!data || typeof data !== 'object') return;
    if (data.source !== 'bexlogs-app') return;

    switch (data.type) {
        case 'BEXLOGS_EXT_PING':
            post({
                type: 'BEXLOGS_EXT_PONG',
                version: VERSION,
                linkedOrigin: cachedLinkedOrigin,
            });
            return;

        case 'BEXLOGS_EXT_LINK': {
            const origin = String(data.origin || window.location.origin);
            const name = String(data.name || origin);
            try {
                await chrome.storage.sync.set({
                    linkedOrigin: origin,
                    linkedName: name,
                    baseUrl: origin, // back-compat with popup.js
                });
                cachedLinkedOrigin = origin;
                post({
                    type: 'BEXLOGS_EXT_LINKED',
                    version: VERSION,
                    linkedOrigin: origin,
                });
            } catch (err) {
                post({
                    type: 'BEXLOGS_EXT_ERROR',
                    error: String(err?.message ?? err),
                });
            }
            return;
        }

        case 'BEXLOGS_EXT_UNLINK':
            try {
                await chrome.storage.sync.remove(['linkedOrigin', 'linkedName']);
                cachedLinkedOrigin = null;
                post({
                    type: 'BEXLOGS_EXT_UNLINKED',
                    version: VERSION,
                    linkedOrigin: null,
                });
            } catch (err) {
                post({
                    type: 'BEXLOGS_EXT_ERROR',
                    error: String(err?.message ?? err),
                });
            }
            return;

        case 'BEXLOGS_EXT_PAIR': {
            const origin = String(data.origin || window.location.origin);
            const token = String(data.token || '');
            const environment = String(data.environment || 'production');
            if (!token) {
                post({ type: 'BEXLOGS_EXT_ERROR', error: 'Missing pairing token' });
                return;
            }
            try {
                // Refuse handoffs from origins we're not linked to. The user can
                // bind explicitly via BEXLOGS_EXT_LINK first.
                if (cachedLinkedOrigin && cachedLinkedOrigin !== origin) {
                    post({
                        type: 'BEXLOGS_EXT_ERROR',
                        error: `Refused: extension is linked to ${cachedLinkedOrigin}, not ${origin}.`,
                    });
                    return;
                }
                if (!cachedLinkedOrigin) {
                    await chrome.storage.sync.set({ linkedOrigin: origin, baseUrl: origin });
                    cachedLinkedOrigin = origin;
                }
                chrome.runtime.sendMessage(
                    {
                        type: 'PAIR_AND_CAPTURE',
                        token,
                        baseUrl: origin,
                        environment,
                    },
                    (response) => {
                        post({
                            type: response?.ok ? 'BEXLOGS_EXT_PAIRED' : 'BEXLOGS_EXT_ERROR',
                            sessionId: response?.sessionId ?? null,
                            error: response?.error ?? null,
                            version: VERSION,
                        });
                    },
                );
            } catch (err) {
                post({
                    type: 'BEXLOGS_EXT_ERROR',
                    error: String(err?.message ?? err),
                });
            }
            return;
        }
    }
});

// Reflect storage changes back to listeners on the page (e.g. when the popup
// edits the linked origin out-of-band).
chrome.storage.onChanged?.addListener((changes, area) => {
    if (area !== 'sync') return;
    if (changes.linkedOrigin || changes.baseUrl) {
        cachedLinkedOrigin =
            changes.linkedOrigin?.newValue ?? changes.baseUrl?.newValue ?? null;
        post({
            type: 'BEXLOGS_EXT_LINKED',
            version: VERSION,
            linkedOrigin: cachedLinkedOrigin,
        });
    }
});

function post(payload) {
    try {
        window.postMessage({ source: 'bexlogs-ext', ...payload }, window.location.origin);
    } catch {}
}

} // end bootstrap()
