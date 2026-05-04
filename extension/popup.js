// BexLogs extension — popup controller.
//
// The popup can render three mutually-exclusive states:
//
//   1. `linked` — The currently-active tab is on an origin we've already
//                 paired with. Show the session summary + Re-authenticate
//                 / Unlink actions. NEVER show an "Enter code" form here
//                 (that was the old bug — popup re-prompted for a code on
//                 every open, even when Laravel considered the session
//                 healthy).
//
//   2. `other-instances` — No match for the current tab's origin, but the
//                 extension knows about OTHER linked instances. Show a
//                 quick-jump list + the pair form beneath, so a power
//                 user with multiple BexLogs deployments can still link
//                 a new one from here.
//
//   3. `pair` — Nothing linked yet (fresh install) or user wants a
//                 brand-new link. Show the legacy code-entry UI.
//
// Link state lives in `chrome.storage.local.linkedInstances`, keyed by
// origin:
//
//   linkedInstances = {
//     'https://bexlogs.vitrion.dev': {
//       origin, baseUrl, environment, accountEmail, accountName,
//       bexSessionId, linkedAt, lastValidatedAt,
//     },
//     'http://localhost': { ... },
//   }
//
// We deliberately use `chrome.storage.local` (not .sync) for the per-
// instance map: it can hold dozens of origins and we don't need cross-
// device sync. The legacy single-origin keys in `chrome.storage.sync`
// (`linkedOrigin`, `linkedName`, `baseUrl`) are still read by content.js
// for the "is this page linked?" ping handshake, so we mirror the
// currently-active linked instance back into .sync on any write.

const els = {
    subtitle: document.getElementById('subtitle'),
    linkedCard: document.getElementById('linked-card'),
    linkedHost: document.getElementById('linked-host'),
    linkedEmail: document.getElementById('linked-email'),
    linkedAt: document.getElementById('linked-at'),
    linkedValidated: document.getElementById('linked-validated'),
    linkedSessionId: document.getElementById('linked-session-id'),
    linkedEnv: document.getElementById('linked-env'),
    linkedStatus: document.getElementById('linked-status'),
    reauthBtn: document.getElementById('reauth'),
    unlinkBtn: document.getElementById('unlink'),

    otherInstances: document.getElementById('other-instances'),
    otherList: document.getElementById('other-instances-list'),

    pairForm: document.getElementById('pair-form'),
    server: document.getElementById('server'),
    token: document.getElementById('token'),
    go: document.getElementById('go'),
    pairStatus: document.getElementById('pair-status'),
};

init().catch((err) => {
    console.error('[bexlogs-popup] init failed', err);
    setSubtitle(`Init failed: ${err?.message ?? err}`);
});

async function init() {
    const [linkedInstances, activeTab] = await Promise.all([
        getLinkedInstances(),
        getActiveTab(),
    ]);

    const activeOrigin = activeTab?.url ? safeOrigin(activeTab.url) : null;
    const linkedHere = activeOrigin ? linkedInstances[activeOrigin] : null;

    if (linkedHere) {
        renderLinked(linkedHere);
        attachLinkedHandlers(linkedHere);

        return;
    }

    const others = Object.values(linkedInstances);

    if (others.length > 0) {
        renderOtherInstances(others);
    }
    renderPairForm(activeOrigin);
    attachPairHandlers();
}

function setSubtitle(text, kind = '') {
    els.subtitle.textContent = text;
    els.subtitle.className = 'muted ' + kind;
}

// --------------------------------------------------------------------
// Rendering
// --------------------------------------------------------------------

function renderLinked(entry) {
    els.linkedCard.hidden = false;
    els.otherInstances.hidden = true;
    els.pairForm.hidden = true;

    const host = safeHost(entry.origin) ?? entry.origin;
    setSubtitle(`Linked to ${host}`, 'ok');

    els.linkedHost.textContent = host;
    els.linkedEmail.textContent = entry.accountEmail || '— not captured —';
    els.linkedEmail.title = entry.accountEmail || '';
    els.linkedAt.textContent = relative(entry.linkedAt);
    els.linkedAt.title = entry.linkedAt ?? '';
    els.linkedValidated.textContent = relative(entry.lastValidatedAt);
    els.linkedValidated.title = entry.lastValidatedAt ?? '';
    els.linkedSessionId.textContent = entry.bexSessionId
        ? `#${entry.bexSessionId}`
        : '—';

    if (entry.environment) {
        els.linkedEnv.textContent = entry.environment;
        els.linkedEnv.hidden = false;
    } else {
        els.linkedEnv.hidden = true;
    }
}

function renderOtherInstances(entries) {
    els.otherInstances.hidden = false;
    els.otherList.innerHTML = '';

    for (const entry of entries) {
        const li = document.createElement('li');

        const info = document.createElement('div');
        info.className = 'instance-host';
        const host = document.createElement('div');
        host.textContent = safeHost(entry.origin) ?? entry.origin;
        host.className = 'mono';
        info.appendChild(host);
        if (entry.accountEmail) {
            const email = document.createElement('div');
            email.className = 'instance-email';
            email.textContent = entry.accountEmail;
            email.title = entry.accountEmail;
            info.appendChild(email);
        }
        li.appendChild(info);

        const openBtn = document.createElement('button');
        openBtn.type = 'button';
        openBtn.textContent = 'Open';
        openBtn.addEventListener('click', () => {
            chrome.tabs.create({ url: entry.origin + '/authenticate' });
            window.close();
        });
        li.appendChild(openBtn);

        els.otherList.appendChild(li);
    }
}

function renderPairForm(activeOrigin) {
    els.pairForm.hidden = false;
    if (els.linkedCard.hidden) {
        setSubtitle('Not linked yet. Paste a code to pair.');
    }

    if (activeOrigin && /^https?:/.test(activeOrigin)) {
        els.server.placeholder = activeOrigin;
    }

    // Prefer the legacy single-origin "linkedOrigin" so existing users
    // don't see their previous URL vanish.
    chrome.storage.sync
        .get(['linkedOrigin', 'baseUrl', 'serverUrl'])
        .then((stored) => {
            const previous =
                stored.linkedOrigin ?? stored.baseUrl ?? stored.serverUrl ?? '';
            if (previous) {
                els.server.value = previous;
            } else if (activeOrigin && /^https?:/.test(activeOrigin)) {
                els.server.value = activeOrigin;
            }
        })
        .catch(() => {});
}

// --------------------------------------------------------------------
// Linked-state handlers
// --------------------------------------------------------------------

function attachLinkedHandlers(entry) {
    els.reauthBtn.addEventListener('click', async () => {
        // "Re-authenticate" reuses the normal pair flow. We don't have
        // a fresh pairing token here (those can only be minted by
        // Laravel on a request from an authenticated user), so just
        // open the Sessions page with a ?relink=1 hint. The Laravel UI
        // auto-starts a pair flow when it sees that query, and its
        // existing window.postMessage handoff (see content.js) feeds
        // the new token to this extension.
        const url = new URL(entry.origin + '/authenticate');
        url.searchParams.set('relink', '1');
        url.searchParams.set('session', String(entry.bexSessionId ?? ''));
        await chrome.tabs.create({ url: url.toString() });
        window.close();
    });

    els.unlinkBtn.addEventListener('click', async () => {
        els.reauthBtn.disabled = true;
        els.unlinkBtn.disabled = true;
        setLinkedStatus('Unlinking…');

        const response = await chrome.runtime.sendMessage({
            type: 'UNLINK_INSTANCE',
            origin: entry.origin,
            bexSessionId: entry.bexSessionId ?? null,
        });

        if (response?.ok) {
            if (response?.revoked) {
                setLinkedStatus('Unlinked and server session revoked.', 'success');
            } else {
                setLinkedStatus(
                    'Unlinked locally. Revoke the session on the BexLogs Sessions page when you can.',
                    'warning',
                );
            }
            // Rerender: the entry we were showing is gone.
            els.linkedCard.hidden = true;
            const remaining = await getLinkedInstances();
            const others = Object.values(remaining);
            if (others.length > 0) {
                renderOtherInstances(others);
            }
            renderPairForm(null);
            attachPairHandlers();
            setSubtitle('Unlinked. Paste a new code to re-pair.');
        } else {
            setLinkedStatus(
                response?.error ?? 'Unlink failed. Check the BexLogs Sessions page.',
                'error',
            );
            els.reauthBtn.disabled = false;
            els.unlinkBtn.disabled = false;
        }
    });
}

function setLinkedStatus(text, kind = '') {
    els.linkedStatus.textContent = text;
    els.linkedStatus.className = 'status ' + kind;
}

// --------------------------------------------------------------------
// Pair-form handlers
// --------------------------------------------------------------------

function attachPairHandlers() {
    els.server.addEventListener('change', async () => {
        const cleaned = (els.server.value || '').trim().replace(/\/+$/, '');
        els.server.value = cleaned;
        if (cleaned) {
            await chrome.storage.sync.set({
                linkedOrigin: cleaned,
                baseUrl: cleaned,
            });
        } else {
            await chrome.storage.sync.remove([
                'linkedOrigin',
                'linkedName',
                'baseUrl',
            ]);
        }
    });

    els.go.addEventListener('click', async () => {
        const baseUrl = (els.server.value || '').trim().replace(/\/+$/, '');
        const token = (els.token.value || '').trim();
        const env =
            document.querySelector('input[name="env"]:checked')?.value ?? 'production';

        setPairStatus('');

        if (!baseUrl || !/^https?:\/\//.test(baseUrl)) {
            return setPairStatus('Link a BexLogs server URL first.', 'error');
        }
        if (!token) {
            return setPairStatus('Paste the pairing token from BexLogs.', 'error');
        }
        if (token.length < 12) {
            return setPairStatus('That pairing token looks too short.', 'error');
        }

        els.go.disabled = true;
        setPairStatus('Opening BookingExperts. Log in there and come back.');

        try {
            const res = await chrome.runtime.sendMessage({
                type: 'PAIR_AND_CAPTURE',
                token,
                baseUrl,
                environment: env,
            });

            if (!res?.ok) {
                throw new Error(res?.error ?? 'Unknown error');
            }

            setPairStatus(
                res.message ?? 'Cookies delivered. You can close this popup.',
                'success',
            );
            els.token.value = '';
        } catch (err) {
            setPairStatus(String(err?.message ?? err), 'error');
        } finally {
            els.go.disabled = false;
        }
    });
}

function setPairStatus(text, kind = '') {
    els.pairStatus.textContent = text;
    els.pairStatus.className = 'status ' + kind;
}

// --------------------------------------------------------------------
// Storage helpers — mirror background.js (we want the popup to be
// resilient if background.js is missing keys, and vice-versa).
// --------------------------------------------------------------------

async function getLinkedInstances() {
    const { linkedInstances = {} } =
        (await chrome.storage.local.get(['linkedInstances'])) ?? {};

    return typeof linkedInstances === 'object' && linkedInstances !== null
        ? linkedInstances
        : {};
}

async function getActiveTab() {
    try {
        const [tab] = await chrome.tabs.query({
            active: true,
            currentWindow: true,
        });

        return tab ?? null;
    } catch {
        return null;
    }
}

function safeOrigin(url) {
    try {
        return new URL(url).origin;
    } catch {
        return null;
    }
}

function safeHost(url) {
    try {
        return new URL(url).host;
    } catch {
        return null;
    }
}

function relative(iso) {
    if (!iso) return 'never';
    const then = new Date(iso).getTime();
    if (!Number.isFinite(then)) return 'never';
    const diff = Date.now() - then;
    const sec = Math.round(diff / 1000);
    if (sec < 60) return `${sec}s ago`;
    const min = Math.round(sec / 60);
    if (min < 60) return `${min}m ago`;
    const hr = Math.round(min / 60);
    if (hr < 48) return `${hr}h ago`;
    const day = Math.round(hr / 24);

    return `${day}d ago`;
}
