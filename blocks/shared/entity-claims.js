/**
 * Entity Claim Handler – Shared view script for Validator Stats + Collection Showcase blocks.
 *
 * Handles "Claim as Operator/Creator/Holder" button clicks via REST API.
 * Uses POST /bcc/v1/claim endpoint (replaces legacy admin-ajax).
 *
 * If the user has no wallet on the entity's chain, auto-triggers the wallet
 * connect flow (Keplr / MetaMask / Phantom) and retries the claim.
 */
(function () {
    'use strict';

    var CLAIM_URL = (window.bccEntityClaim && window.bccEntityClaim.restUrl) || '/wp-json/bcc/v1/claim';
    var NONCE     = (window.bccEntityClaim && window.bccEntityClaim.nonce)
                 || (window.bccVLB && window.bccVLB.nonce)
                 || '';

    /**
     * Send the claim REST request.
     * @returns {Promise<object>} Raw JSON response from server.
     */
    function sendClaim(entityType, entityId) {
        var headers = { 'Content-Type': 'application/json' };
        if (NONCE) headers['X-WP-Nonce'] = NONCE;

        return fetch(CLAIM_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: headers,
            body: JSON.stringify({
                entity_type: entityType,
                entity_id: parseInt(entityId, 10)
            })
        }).then(function (res) { return res.json(); });
    }

    function claimEntity(btn) {
        var entityType = btn.getAttribute('data-entity-type');
        var entityId   = btn.getAttribute('data-entity-id');

        if (!entityType || !entityId) return;

        btn.disabled = true;
        var originalText = btn.innerHTML;
        btn.innerHTML = '<span class="bcc-entity-block__spinner"></span> Verifying...';

        sendClaim(entityType, entityId)
        .then(function (response) {
            // REST responses: success field is in the response body directly.
            if (response.success) {
                onClaimSuccess(btn, response);
                return;
            }

            // If user needs to connect a wallet on this chain, auto-trigger
            // the wallet connect flow and retry the claim afterwards.
            if (response.needs_wallet && response.chain_slug && window.BCCWallet) {
                btn.innerHTML = '<span class="bcc-entity-block__spinner"></span> Connecting wallet...';

                window.BCCWallet.connectAndVerify(response.chain_slug, 0, 'user', '')
                    .then(function () {
                        // Wallet connected — retry the claim.
                        btn.innerHTML = '<span class="bcc-entity-block__spinner"></span> Verifying claim...';
                        return sendClaim(entityType, entityId);
                    })
                    .then(function (retryResponse) {
                        if (retryResponse.success) {
                            onClaimSuccess(btn, retryResponse);
                        } else {
                            var retryMsg = retryResponse.message || 'Claim failed after wallet connect.';
                            btn.innerHTML = originalText;
                            btn.disabled = false;
                            showToast(retryMsg, 'error');
                        }
                    })
                    .catch(function (err) {
                        btn.innerHTML = originalText;
                        btn.disabled = false;
                        // User cancelled the wallet popup — not an error, just reset.
                        var msg = err.message || '';
                        if (msg.toLowerCase().indexOf('reject') !== -1 || msg.toLowerCase().indexOf('cancel') !== -1) {
                            showToast('Wallet connection cancelled.', 'info');
                        } else {
                            showToast(msg || 'Wallet connection failed.', 'error');
                        }
                    });
                return;
            }

            // Standard error — no wallet connect needed.
            btn.innerHTML = originalText;
            btn.disabled = false;
            showToast(response.message || 'Claim failed.', 'error');
        })
        .catch(function () {
            btn.innerHTML = originalText;
            btn.disabled = false;
            showToast('Network error. Please try again.', 'error');
        });
    }

    function onClaimSuccess(btn, data) {
        btn.classList.add('bcc-entity-block__claim-btn--verified');
        btn.disabled = true;
        btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg> '
            + escHtml(data.message || 'Verified');

        // Add badge to the card header.
        var card = btn.closest('.bcc-entity-block__card, .bcc-vlb__row, .bcc-nft-lb__row');
        if (card) {
            var header = card.querySelector('.bcc-validator-card__header, .bcc-collection-card__header, .bcc-vlb__col--name, .bcc-nft-lb__col--name');
            if (header && !header.querySelector('.bcc-entity-block__badge')) {
                var badge = document.createElement('span');
                badge.className = 'bcc-entity-block__badge bcc-entity-block__badge--verified';
                badge.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg> '
                    + escHtml(data.message || 'Verified');
                header.appendChild(badge);
            }
        }
    }

    function showToast(message, type) {
        var toast = document.createElement('div');
        toast.className = 'bcc-entity-block__toast bcc-entity-block__toast--' + (type || 'info');
        toast.textContent = message;
        document.body.appendChild(toast);

        requestAnimationFrame(function () {
            toast.classList.add('is-visible');
        });

        setTimeout(function () {
            toast.classList.remove('is-visible');
            setTimeout(function () { toast.remove(); }, 300);
        }, 4000);
    }

    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    // Boot: attach click handlers to all claim buttons.
    function boot() {
        document.querySelectorAll('.bcc-entity-block__claim-btn').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                claimEntity(btn);
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
