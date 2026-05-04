// BexLogs extension — background service worker.
//
// Handles the two RPC messages from popup / content script:
//
//   PAIR_AND_CAPTURE  — open BookingExperts, wait for login, POST
//                       cookies back to the linked BexLogs server,
//                       persist the resulting link into
//                       chrome.storage.local.linkedInstances so the
//                       popup can render a "Linked" card next time.
//
//   UNLINK_INSTANCE   — remove an instance from linkedInstances AND
//                       best-effort revoke the BexSession on the
//                       linked server (CSRF handshake via the user's
//                       existing Laravel session cookie).
//
// Link state lives in chrome.storage.local.linkedInstances, keyed by
// origin. Shape:
//
//   {
//     'https://bexlogs.vitrion.dev': {
//       origin, baseUrl, environment, accountEmail, accountName,
//       bexSessionId, linkedAt, lastValidatedAt,
//     },
//   }
//
// The legacy single-origin keys (`linkedOrigin`, `linkedName`,
// `baseUrl`) in chrome.storage.sync are still maintained so the
// existing content.js handshake continues to work.

const POLL_INTERVAL_MS = 1000;
const POLL_TIMEOUT_MS = 5 * 60 * 1000;

const URLS = {
    production: 'https://app.bookingexperts.com',
    staging: 'https://app.staging.bookingexperts.com',
};

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
    if (message?.type === 'PAIR_AND_CAPTURE') {
        const { token, baseUrl, environment } = message;

        pairAndCapture({ token, baseUrl, environment })
            .then((result) => sendResponse({ ok: true, ...result }))
            .catch((err) =>
                sendResponse({ ok: false, error: String(err?.message ?? err) }),
            );

        return true;
    }

    if (message?.type === 'UNLINK_INSTANCE') {
        unlinkInstance({
            origin: message.origin,
            bexSessionId: message.bexSessionId ?? null,
        })
            .then((result) => sendResponse({ ok: true, ...result }))
            .catch((err) =>
                sendResponse({ ok: false, error: String(err?.message ?? err) }),
            );

        return true;
    }

    return false;
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

injectIntoAllOpenTabs('sw-boot');

function shouldInject(url) {
    if (url.startsWith('https://app.bookingexperts.com/')) return false;
    if (url.startsWith('https://app.staging.bookingexperts.com/')) return false;
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

    await persistLinkedInstance({
        origin: baseUrl,
        baseUrl,
        environment,
        accountEmail: body?.account_email ?? null,
        accountName: body?.account_name ?? null,
        bexSessionId: body?.id ?? null,
        isRelink: !!body?.relinked,
    });

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

    return {
        sessionId: body?.id ?? null,
        message: body?.message,
        relinked: !!body?.relinked,
    };
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

        if (tab.url.includes('/sign_in')) continue;

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

// --------------------------------------------------------------------
// linkedInstances (chrome.storage.local) management
// --------------------------------------------------------------------

async function persistLinkedInstance({
    origin,
    baseUrl,
    environment,
    accountEmail,
    accountName,
    bexSessionId,
    isRelink,
}) {
    const now = new Date().toISOString();

    const { linkedInstances = {} } = await chrome.storage.local.get([
        'linkedInstances',
    ]);

    const previous =
        typeof linkedInstances === 'object' && linkedInstances !== null
            ? linkedInstances[origin]
            : null;

    const entry = {
        origin,
        baseUrl,
        environment: environment ?? previous?.environment ?? null,
        accountEmail: accountEmail ?? previous?.accountEmail ?? null,
        accountName: accountName ?? previous?.accountName ?? null,
        bexSessionId: bexSessionId ?? previous?.bexSessionId ?? null,
        // On relink keep the *original* `linkedAt` (we've been linked
        // here the whole time; we just refreshed cookies); on a first
        // pair set it to now.
        linkedAt: isRelink && previous?.linkedAt ? previous.linkedAt : now,
        lastValidatedAt: now,
    };

    const next = {
        ...(typeof linkedInstances === 'object' && linkedInstances !== null
            ? linkedInstances
            : {}),
        [origin]: entry,
    };

    await chrome.storage.local.set({ linkedInstances: next });
    await chrome.storage.sync.set({
        linkedOrigin: origin,
        linkedName: safeHost(origin) ?? origin,
        baseUrl: origin,
    });
}

async function unlinkInstance({ origin, bexSessionId }) {
    if (!origin) {
        throw new Error('Missing origin');
    }

    let revoked = false;
    let revokeError = null;

    // Best-effort server-side revoke. The user's Laravel session cookie
    // is already in this browser's jar, so `credentials: 'include'` will
    // authenticate; we just need a fresh CSRF token, which we fetch from
    // the /authenticate page's HTML meta tag.
    if (bexSessionId) {
        try {
            revoked = await revokeSessionOnServer(origin, bexSessionId);
        } catch (err) {
            revokeError = String(err?.message ?? err);
            console.warn('[bexlogs] revoke failed', revokeError);
        }
    }

    // Drop the entry regardless of the server call outcome — local
    // state should match the user's intent.
    const { linkedInstances = {} } = await chrome.storage.local.get([
        'linkedInstances',
    ]);
    const next =
        typeof linkedInstances === 'object' && linkedInstances !== null
            ? { ...linkedInstances }
            : {};
    delete next[origin];
    await chrome.storage.local.set({ linkedInstances: next });

    // Clear the legacy single-origin hints too, so content.js stops
    // advertising "linked here" on that origin until re-paired.
    const legacy = await chrome.storage.sync.get([
        'linkedOrigin',
        'baseUrl',
    ]);
    if (legacy.linkedOrigin === origin || legacy.baseUrl === origin) {
        await chrome.storage.sync.remove([
            'linkedOrigin',
            'linkedName',
            'baseUrl',
        ]);
    }

    return { revoked, revokeError };
}

async function revokeSessionOnServer(origin, bexSessionId) {
    // Step 1 — fetch the Authenticate page to pull a CSRF token out of
    // its HTML. We intentionally don't require a specific status:
    // Laravel serves the full app.blade.php on any auth'd route and the
    // <meta name="csrf-token"> is always there.
    const htmlResp = await fetch(`${origin}/authenticate`, {
        method: 'GET',
        credentials: 'include',
        headers: { Accept: 'text/html' },
    });

    if (!htmlResp.ok) {
        throw new Error(
            `Could not fetch CSRF token (GET /authenticate → HTTP ${htmlResp.status}). ` +
                'Are you still logged into BexLogs on this browser?',
        );
    }

    const html = await htmlResp.text();
    const token = extractCsrfToken(html);
    if (!token) {
        throw new Error(
            'No CSRF token found on /authenticate. Log into BexLogs first, then try Unlink again.',
        );
    }

    // Step 2 — DELETE the bex session. This route lives in the web
    // middleware group, so we need both the CSRF token and the user's
    // session cookie (via credentials: 'include').
    const revokeResp = await fetch(`${origin}/bex-sessions/${bexSessionId}`, {
        method: 'DELETE',
        credentials: 'include',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': token,
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!revokeResp.ok && revokeResp.status !== 302) {
        throw new Error(`Revoke returned HTTP ${revokeResp.status}`);
    }

    return true;
}

function extractCsrfToken(html) {
    const m = /<meta[^>]+name=["']csrf-token["'][^>]+content=["']([^"']+)["']/i.exec(
        html,
    );

    return m ? m[1] : null;
}

function safeHost(origin) {
    try {
        return new URL(origin).host;
    } catch {
        return null;
    }
}
