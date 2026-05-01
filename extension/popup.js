const $server = document.getElementById('server');
const $token = document.getElementById('token');
const $go = document.getElementById('go');
const $status = document.getElementById('status');
const $banner = document.getElementById('linked-banner');

(async function init() {
    const stored = await chrome.storage.sync.get(['linkedOrigin', 'linkedName', 'baseUrl', 'serverUrl']);
    // Prefer the new storage keys; fall back to legacy serverUrl/baseUrl.
    const linked = stored.linkedOrigin ?? stored.baseUrl ?? stored.serverUrl ?? '';
    if (linked) {
        $server.value = linked;
        const niceName = stored.linkedName || linked.replace(/^https?:\/\//, '');
        $banner.textContent = `Linked to ${niceName}`;
        $banner.className = 'muted ok';
    } else {
        $banner.textContent = 'Not linked. Open BexLogs and click "Link extension".';
    }
})();

$server.addEventListener('change', async () => {
    const cleaned = ($server.value || '').trim().replace(/\/+$/, '');
    $server.value = cleaned;
    if (cleaned) {
        await chrome.storage.sync.set({ linkedOrigin: cleaned, baseUrl: cleaned });
    } else {
        await chrome.storage.sync.remove(['linkedOrigin', 'linkedName', 'baseUrl']);
    }
});

$go.addEventListener('click', async () => {
    const baseUrl = ($server.value || '').trim().replace(/\/+$/, '');
    const token = ($token.value || '').trim();
    const env = document.querySelector('input[name="env"]:checked')?.value || 'production';

    setStatus('');

    if (!baseUrl || !/^https?:\/\//.test(baseUrl)) {
        return setStatus('Link a BexLogs server URL first.', 'error');
    }
    if (!token) {
        return setStatus('Paste the pairing token from BexLogs.', 'error');
    }
    if (token.length < 12) {
        return setStatus('That pairing token looks too short.', 'error');
    }

    $go.disabled = true;
    setStatus('Opening BookingExperts. Log in there and come back.');

    try {
        const res = await chrome.runtime.sendMessage({
            type: 'PAIR_AND_CAPTURE',
            token,
            baseUrl,
            environment: env,
        });
        if (!res?.ok) throw new Error(res?.error ?? 'Unknown error');
        setStatus(res.message ?? 'Cookies delivered. You can close this popup.', 'success');
        $token.value = '';
    } catch (err) {
        setStatus(String(err?.message ?? err), 'error');
    } finally {
        $go.disabled = false;
    }
});

function setStatus(text, kind = '') {
    $status.textContent = text;
    $status.className = 'status ' + kind;
}
