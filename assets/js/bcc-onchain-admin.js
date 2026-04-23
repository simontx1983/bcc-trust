/* global bccOnchain */
(function () {
    'use strict';

    const btn      = document.getElementById('bcc-onchain-refresh-btn');
    const input    = document.getElementById('bcc-onchain-page-id');
    const statusEl = document.getElementById('bcc-onchain-refresh-status');

    if (!btn || !input) return;

    btn.addEventListener('click', function () {
        const pageId = parseInt(input.value, 10);
        if (!pageId || pageId < 1) {
            statusEl.textContent = 'Please enter a valid page ID.';
            statusEl.style.color = '#d63638';
            return;
        }

        btn.disabled = true;
        statusEl.textContent = 'Refreshing…';
        statusEl.style.color = '';

        fetch(`${bccOnchain.restUrl}/${pageId}/refresh`, {
            method:  'POST',
            headers: { 'X-WP-Nonce': bccOnchain.nonce, 'Content-Type': 'application/json' },
        })
            .then(async res => {
                const json = await res.json();
                if (!res.ok) throw new Error(json.message || 'Request failed');
                return json;
            })
            .then(data => {
                statusEl.style.color = '#00a32a';
                statusEl.textContent = `Done — refreshed ${data.refreshed} wallet(s).`;
            })
            .catch(err => {
                statusEl.style.color = '#d63638';
                statusEl.textContent = err.message || 'Refresh failed.';
            })
            .finally(() => {
                btn.disabled = false;
            });
    });
})();
