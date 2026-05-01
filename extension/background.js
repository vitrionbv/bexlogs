// BexLogs extension — background service worker.
//
// Listens for a "PAIR_AND_CAPTURE" message from the popup, opens the
// BookingExperts sign-in tab, polls for a successful login (presence of a
// session cookie + URL no longer at /sign_in), then POSTs all captured
// cookies + the pairing token back to the configured BexLogs server.

const POLL_INTERVAL_MS = 1000;
const POLL_TIMEOUT_MS = 5 * 60 * 1000;

const URLS = {
    production: 'https://app.bookingexperts.com',
    staging: 'https://app.staging.bookingexperts.com',
};

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
    if (message?.type !== 'PAIR_AND_CAPTURE') return false;

    const { token, baseUrl, environment } = message;

    pairAndCapture({ token, baseUrl, environment })
        .then((result) => sendResponse({ ok: true, ...result }))
        .catch((err) => sendResponse({ ok: false, error: String(err?.message ?? err) }));

    return true; // keep the channel open for the async response
});

// Chrome doesn't auto-inject content scripts into pre-existing tabs when the
// extension first loads, gets reloaded, or the service worker wakes up.
// onInstalled also notably does NOT fire for chrome://extensions "Reload"
// when the version string is unchanged (the common dev-loop case).
//
// So we run the inject pass on every plausible SW activation: top-level
// (every cold start), onInstalled (install/update), and onStartup (browser
// boot). content.js is idempotent — it guards against double-mounting via a
// flag on its isolated-world window — so re-injecting is harmless.
async function injectIntoAllOpenTabs(reason) {
    try {
        const tabs = await chrome.tabs.query({});
        let attempted = 0;
        let succeeded = 0;
        for (const tab of tabs) {
            if (!tab.id || !tab.url) continue;
            if (!shouldInject(tab.url)) continue;
            attempted++;
            try {
                await chrome.scripting.executeScript({
                    target: { tabId: tab.id, allFrames: false },
                    files: ['content.js'],
                });
                succeeded++;
            } catch (err) {
                // Some pages (chrome://, web store, PDFs, etc.) refuse injection — that's fine.
                console.debug('[bexlogs] could not inject into', tab.url, err?.message);
            }
        }
        console.debug('[bexlogs] inject pass', { reason, attempted, succeeded });
    } catch (err) {
        console.warn('[bexlogs] inject pass failed', { reason, err });
    }
}

chrome.runtime.onInstalled.addListener((details) => {
    if (details.reason !== 'install' && details.reason !== 'update') return;
    injectIntoAllOpenTabs(`onInstalled:${details.reason}`);
});

chrome.runtime.onStartup?.addListener(() => injectIntoAllOpenTabs('onStartup'));

// SW boot — fires every time the service worker activates, including after
// dev-mode "Reload" in chrome://extensions where onInstalled is silent.
injectIntoAllOpenTabs('sw-boot');

function shouldInject(url) {
    // Mirror manifest exclude_matches: BookingExperts itself runs no content script.
    if (url.startsWith('https://app.bookingexperts.com/')) return false;
    if (url.startsWith('https://app.staging.bookingexperts.com/')) return false;
    // Only http/https — no chrome://, file://, devtools://, etc.
    return /^https?:\/\//.test(url);
}

async function pairAndCapture({ token, baseUrl, environment }) {
    if (!token || !baseUrl || !environment) {
        throw new Error('Missing token / baseUrl / environment');
    }

    const target = URLS[environment];
    if (!target) {
        throw new Error(`Unknown environment: ${environment}`);
    }

    // Persist active pairing so we can recover if the popup closes.
    await chrome.storage.session.set({
        pairing: { token, baseUrl, environment, startedAt: Date.now() },
    });

    const tab = await chrome.tabs.create({
        url: `${target}/sign_in`,
        active: true,
    });

    const cookies = await waitForLogin(tab.id, environment);

    const response = await fetch(`${baseUrl}/api/bex-sessions`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
        },
        body: JSON.stringify({ token, cookies }),
    });

    if (!response.ok) {
        const text = await response.text();
        throw new Error(`BexLogs server rejected the cookies (${response.status}): ${text}`);
    }

    const body = await response.json().catch(() => ({}));

    try {
        await chrome.notifications.create({
            type: 'basic',
            iconUrl: chrome.runtime.getURL('icons/icon128.png'),
            title: 'BexLogs',
            message: body?.message ?? 'Authenticated successfully.',
            priority: 1,
        });
    } catch {
        // notifications permission may be missing; ignore
    }

    return { sessionId: body?.id ?? null, message: body?.message };
}

async function waitForLogin(tabId, environment) {
    const target = URLS[environment];
    const deadline = Date.now() + POLL_TIMEOUT_MS;

    while (Date.now() < deadline) {
        await sleep(POLL_INTERVAL_MS);

        let tab;
        try {
            tab = await chrome.tabs.get(tabId);
        } catch {
            throw new Error('Login tab was closed before the user finished signing in.');
        }

        if (!tab?.url) continue;

        // Still on the sign-in page — keep waiting.
        if (tab.url.includes('/sign_in')) continue;

        // Must be on the BookingExperts host (could be SSO redirects in between).
        if (!tab.url.startsWith(target)) continue;

        const cookies = await chrome.cookies.getAll({
            domain: new URL(target).hostname.replace(/^app\./, ''),
        });

        const hasSession = cookies.some(
            (c) => /session/i.test(c.name) || /_app_session/i.test(c.name),
        );
        if (!hasSession) continue;

        return cookies.map(toExtensionCookie);
    }

    throw new Error('Timed out waiting for login (5 minutes).');
}

function toExtensionCookie(c) {
    return {
        name: c.name,
        value: c.value,
        domain: c.domain,
        path: c.path,
        expirationDate: c.expirationDate ?? null,
        httpOnly: !!c.httpOnly,
        secure: !!c.secure,
        sameSite: c.sameSite ?? 'unspecified',
    };
}

function sleep(ms) {
    return new Promise((r) => setTimeout(r, ms));
}
