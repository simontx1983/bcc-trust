/**
 * Trust Score Breakdown – frontend hydration.
 *
 * Reads from bccPageStore (same request as trust-header),
 * subscribes to live updates, and animates value transitions.
 *
 * RULE: Never render raw score_breakdown floats.
 *       Only integer vote counts and public verification status.
 */
(function () {
    'use strict';

    var store = window.bccPageStore;
    if (!store) return;

    var TIER_LABELS = {
        elite: 'Elite', trusted: 'Trusted', neutral: 'Neutral',
        caution: 'Caution', risky: 'Risky'
    };

    var CONF_THRESHOLDS = [
        { max: 0.30, level: 'low' },
        { max: 0.70, level: 'mid' },
        { max: Infinity, level: 'high' }
    ];

    var PROVIDER_LABELS = { email: 'Email', wallet: 'Wallet', github: 'GitHub', x: 'X' };
    var PROVIDERS = ['email', 'wallet', 'github', 'x'];

    function confLevel(confidence) {
        for (var i = 0; i < CONF_THRESHOLDS.length; i++) {
            if (confidence < CONF_THRESHOLDS[i].max) return CONF_THRESHOLDS[i].level;
        }
        return 'high';
    }

    /**
     * Animate a numeric text node from its current value to a target.
     */
    function animateNumber(el, target, duration) {
        if (!el) return;
        var start = parseInt(el.textContent, 10) || 0;
        if (start === target) return;
        var diff = target - start;
        var startTime = null;

        function step(ts) {
            if (!startTime) startTime = ts;
            var progress = Math.min((ts - startTime) / duration, 1);
            // Ease-out quad
            var eased = 1 - (1 - progress) * (1 - progress);
            el.textContent = String(Math.round(start + diff * eased));
            if (progress < 1) requestAnimationFrame(step);
        }

        requestAnimationFrame(step);
    }

    function initBlock(el) {
        var pageId = parseInt(el.dataset.pageId, 10);
        var isOwner = el.dataset.isOwner === '1';
        if (!pageId) return;

        function field(name) {
            return el.querySelector('[data-field="' + name + '"]');
        }

        function render(data) {
            if (!data || !data.trust) return;

            var trust     = data.trust;
            var breakdown = trust.score_breakdown || {};
            var verif     = data.verification || {};
            var onchain   = data.onchain || {};
            var viewer    = data.viewer || {};

            var score          = (trust.score != null) ? Math.round(trust.score) : 0;
            var tier           = trust.tier || 'neutral';
            var confidence     = trust.confidence || 0;
            var confPct        = Math.round(confidence * 100);
            var votes_up       = trust.votes_up || 0;
            var votes_down     = trust.votes_down || 0;
            var endorsements   = trust.endorsements || 0;
            var communityW     = breakdown.community_weight || 0.5;
            var identityW      = breakdown.identity_weight || 0.5;

            var uniqueVoters   = trust.unique_voters || 0;
            var verifCount     = 0;
            for (var i = 0; i < PROVIDERS.length; i++) {
                if (verif[PROVIDERS[i]]) verifCount++;
            }
            var onchainBoost = onchain.total_boost || 0;
            var isEmpty = (uniqueVoters === 0 && endorsements === 0 && verifCount === 0 && onchainBoost <= 0);

            // Update confidence level class
            var cl = confLevel(confidence);
            el.className = el.className
                .replace(/bcc-trust-breakdown--conf-\w+/, '')
                .trim() + ' bcc-trust-breakdown--conf-' + cl;

            // Score bar + number
            var fillEl = field('score-fill');
            if (fillEl) {
                fillEl.style.width = (isEmpty ? 0 : score) + '%';
                // Update tier color class
                fillEl.className = fillEl.className
                    .replace(/bcc-tier-bg--\w+/, '')
                    .trim() + (isEmpty ? '' : ' bcc-tier-bg--' + tier);
            }
            animateNumber(field('score'), isEmpty ? 0 : score, 400);

            // Tier + confidence
            var tierEl = field('tier');
            if (tierEl) {
                tierEl.textContent = TIER_LABELS[tier] || 'Neutral';
                tierEl.className = tierEl.className
                    .replace(/bcc-tier-text--\w+/, '')
                    .trim() + ' bcc-tier-text--' + tier;
            }
            animateNumber(field('confidence'), confPct, 400);

            // Weight bars
            var communityBar = field('community-bar');
            if (communityBar) {
                communityBar.style.width = (communityW * 100) + '%';
                var cLabel = communityBar.querySelector('.bcc-tb__weight-label');
                if (cLabel) cLabel.textContent = 'Community ' + Math.round(communityW * 100) + '%';
            }
            var identityBar = field('identity-bar');
            if (identityBar) {
                identityBar.style.width = (identityW * 100) + '%';
                var iLabel = identityBar.querySelector('.bcc-tb__weight-label');
                if (iLabel) iLabel.textContent = 'Identity ' + Math.round(identityW * 100) + '%';
            }

            // Vote counts (integers only — never raw floats)
            animateNumber(field('votes-up'), votes_up, 300);
            animateNumber(field('votes-down'), votes_down, 300);
            animateNumber(field('endorsements'), endorsements, 300);

            // Verification count
            animateNumber(field('verif-count'), verifCount, 300);

            // Verification items (toggle classes)
            var verifItems = el.querySelectorAll('.bcc-tb__verif-item');
            for (var j = 0; j < verifItems.length; j++) {
                var provider = PROVIDERS[j];
                if (!provider) continue;
                var isVerified = !!verif[provider];
                verifItems[j].className = 'bcc-tb__verif-item ' +
                    (isVerified ? 'bcc-tb__verif-item--yes' : 'bcc-tb__verif-item--no');
                verifItems[j].innerHTML = (isVerified ? '&#10003; ' : '&#10007; ') +
                    (PROVIDER_LABELS[provider] || provider);
            }

            // Owner hints: update first missing provider
            var currentIsOwner = viewer.is_owner || isOwner;
            var hintEl = field('verif-hint');
            if (hintEl && currentIsOwner && verifCount < PROVIDERS.length) {
                var missing = '';
                for (var k = 0; k < PROVIDERS.length; k++) {
                    if (!verif[PROVIDERS[k]]) {
                        missing = PROVIDER_LABELS[PROVIDERS[k]];
                        break;
                    }
                }
                if (missing) {
                    hintEl.textContent = 'Connect ' + missing + ' to strengthen your score.';
                    hintEl.style.display = '';
                } else {
                    hintEl.style.display = 'none';
                }
            } else if (hintEl && verifCount >= PROVIDERS.length) {
                hintEl.style.display = 'none';
            }

            // ── Flag section (signal only — no score impact) ────────
            var flags     = data.flags || {};
            var flagCount = flags.count || 0;
            var flagEl    = field('flag-section');

            if (flagEl) {
                var hasFlagged   = viewer.has_flagged || false;
                var currentIsOwn = viewer.is_owner || isOwner;
                var countEl      = flagEl.querySelector('[data-field="flag-count"]');
                var btnEl        = flagEl.querySelector('[data-field="flag-btn"]');
                var msgEl        = flagEl.querySelector('[data-field="flag-msg"]');

                // Show count (only when > 0)
                if (countEl) {
                    if (flagCount > 0) {
                        countEl.textContent = flagCount + (flagCount === 1 ? ' user flagged this page' : ' users flagged this page');
                        countEl.style.display = '';
                    } else {
                        countEl.style.display = 'none';
                    }
                }

                // Owner sees neutral message, not a CTA
                if (currentIsOwn) {
                    if (btnEl) btnEl.style.display = 'none';
                    if (msgEl && flagCount > 0) {
                        msgEl.textContent = flagCount + ' community ' + (flagCount === 1 ? 'flag' : 'flags');
                        msgEl.style.display = '';
                    }
                } else if (btnEl) {
                    btnEl.textContent = hasFlagged ? 'Remove Flag' : 'Flag Page';
                    btnEl.dataset.action = hasFlagged ? 'unflag' : 'flag';
                    btnEl.style.display = '';
                    if (msgEl) msgEl.style.display = 'none';
                }
            }
        }

        // ── Flag button click handler ─────────────────────────────────
        var flagBtn = el.querySelector('[data-field="flag-btn"]');
        if (flagBtn) {
            flagBtn.addEventListener('click', function () {
                var action = flagBtn.dataset.action || 'flag';
                flagBtn.disabled = true;
                flagBtn.textContent = 'Saving...';

                wp.apiFetch({
                    path: '/bcc/v1/flag',
                    method: 'POST',
                    data: { page_id: pageId, action: action }
                }).then(function (res) {
                    flagBtn.disabled = false;
                    if (res.success) {
                        // Re-render with updated data via store refresh
                        store.bust(pageId);
                        store.get(pageId).then(render);
                    } else {
                        flagBtn.textContent = res.message || 'Error';
                        setTimeout(function () {
                            flagBtn.textContent = action === 'flag' ? 'Flag Page' : 'Remove Flag';
                        }, 2000);
                    }
                }).catch(function () {
                    flagBtn.disabled = false;
                    flagBtn.textContent = action === 'flag' ? 'Flag Page' : 'Remove Flag';
                });
            });
        }

        // Initial hydration
        store.get(pageId).then(render).catch(function () {});

        // Live updates (votes, endorsements from other blocks)
        store.subscribe(pageId, render);
    }

    // Initialize all breakdown blocks on the page
    function init() {
        var blocks = document.querySelectorAll('.bcc-trust-breakdown');
        for (var i = 0; i < blocks.length; i++) {
            initBlock(blocks[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
