/**
 * My Endorsements – REST-driven view script.
 *
 * Fetches endorsement list from GET /bcc/v1/endorsements/mine,
 * renders cards with page info + trust score, handles revoke.
 */
(function () {
    'use strict';

    var LIST_URL   = (window.bccMyEndorsements && window.bccMyEndorsements.listUrl) || '/wp-json/bcc/v1/endorsements/mine';
    var REVOKE_URL = (window.bccMyEndorsements && window.bccMyEndorsements.revokeUrl) || '/wp-json/bcc-trust/v1/revoke-endorsement';
    var NONCE      = (window.bccMyEndorsements && window.bccMyEndorsements.nonce) || '';

    var tierLabels = {
        elite: 'Elite', trusted: 'Trusted', neutral: 'Neutral',
        caution: 'Caution', risky: 'Risky', unavailable: 'Unavailable'
    };

    function initBlock(el) {
        var limit     = parseInt(el.getAttribute('data-limit'), 10) || 10;
        var showRevoke = el.getAttribute('data-show-revoke') === '1';
        var listEl    = el.querySelector('.bcc-me__list');

        if (!listEl) return; // Empty state — no endorsements.

        var headers = { 'Content-Type': 'application/json' };
        if (NONCE) headers['X-WP-Nonce'] = NONCE;

        fetch(LIST_URL + '?limit=' + limit, { credentials: 'same-origin', headers: headers })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                var items = data.items || [];
                if (!items.length) {
                    listEl.innerHTML = '<p class="bcc-me__empty">No endorsements found.</p>';
                    return;
                }
                listEl.innerHTML = '';
                items.forEach(function (item) {
                    listEl.appendChild(buildCard(item, showRevoke));
                });
            })
            .catch(function () {
                listEl.innerHTML = '<p class="bcc-me__error">Failed to load endorsements.</p>';
            });
    }

    function buildCard(item, showRevoke) {
        var card = document.createElement('div');
        card.className = 'bcc-me__card';

        var tier = item.tier || 'unavailable';
        var tierLabel = tierLabels[tier] || tier.charAt(0).toUpperCase() + tier.slice(1);
        var scoreText = item.trust_score !== null ? String(item.trust_score) : '\u2014';
        var timeAgo = item.created_at ? timeSince(new Date(item.created_at)) : '';

        var html = '<div class="bcc-me__card-main">';

        if (item.avatar_url) {
            html += '<img src="' + esc(item.avatar_url) + '" alt="" class="bcc-me__avatar" width="40" height="40" loading="lazy" />';
        } else {
            html += '<span class="bcc-me__avatar bcc-me__avatar--placeholder">' + esc((item.page_title || '?').charAt(0).toUpperCase()) + '</span>';
        }

        html += '<div class="bcc-me__info">';
        if (item.page_url) {
            html += '<a href="' + esc(item.page_url) + '" class="bcc-me__page-name">' + esc(item.page_title) + '</a>';
        } else {
            html += '<span class="bcc-me__page-name">' + esc(item.page_title) + '</span>';
        }
        html += '<span class="bcc-me__meta">';
        html += '<span class="bcc-tier-badge bcc-tier-badge--' + esc(tier) + '">' + esc(tierLabel) + '</span>';
        html += '<span class="bcc-me__score">' + esc(scoreText) + '</span>';
        if (timeAgo) {
            html += '<span class="bcc-me__time">' + esc(timeAgo) + '</span>';
        }
        html += '</span>'; // meta
        html += '</div>'; // info
        html += '</div>'; // main

        if (showRevoke) {
            html += '<button class="bcc-me__revoke-btn" data-page-id="' + item.page_id + '" data-context="' + esc(item.context || 'general') + '">Revoke</button>';
        }

        card.innerHTML = html;

        if (showRevoke) {
            var btn = card.querySelector('.bcc-me__revoke-btn');
            if (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    handleRevoke(btn, item.page_id, item.context || 'general');
                });
            }
        }

        return card;
    }

    function handleRevoke(btn, pageId, context) {
        if (!confirm('Are you sure you want to revoke this endorsement?')) return;

        btn.disabled = true;
        btn.textContent = 'Revoking...';

        var store = window.bccPageStore;
        var ver = store && store.nextVersion ? store.nextVersion(pageId) : undefined;

        var headers = { 'Content-Type': 'application/json' };
        if (NONCE) headers['X-WP-Nonce'] = NONCE;

        fetch(REVOKE_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: headers,
            body: JSON.stringify({ page_id: pageId, context: context })
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.success || data.revoked) {
                var card = btn.closest('.bcc-me__card');
                if (card) {
                    card.classList.add('bcc-me__card--revoked');
                    card.style.opacity = '0.5';
                }
                btn.textContent = 'Revoked';

                // Update stats if visible.
                var statsEl = btn.closest('.bcc-me')?.querySelector('.bcc-me__stats');
                if (statsEl) {
                    var countEl = statsEl.querySelector('.bcc-me__stat-value');
                    if (countEl) {
                        var n = parseInt(countEl.textContent, 10);
                        if (!isNaN(n) && n > 0) countEl.textContent = String(n - 1);
                    }
                }

                // Inject versioned response into bccPageStore so other
                // blocks (trust-header, trust-breakdown) update immediately
                // without a full re-fetch that could race the read model sync.
                if (store && store.inject && store.buildStorePayload && data.data) {
                    store.inject(pageId, store.buildStorePayload(data.data), ver);
                } else if (store && store.bust) {
                    store.bust(pageId);
                }
            } else {
                btn.textContent = 'Revoke';
                btn.disabled = false;
                alert(data.message || 'Failed to revoke endorsement.');
            }
        })
        .catch(function () {
            btn.textContent = 'Revoke';
            btn.disabled = false;
            alert('Network error. Please try again.');
        });
    }

    function timeSince(date) {
        var seconds = Math.floor((new Date() - date) / 1000);
        if (seconds < 60) return 'just now';
        var minutes = Math.floor(seconds / 60);
        if (minutes < 60) return minutes + 'm ago';
        var hours = Math.floor(minutes / 60);
        if (hours < 24) return hours + 'h ago';
        var days = Math.floor(hours / 24);
        if (days < 30) return days + 'd ago';
        var months = Math.floor(days / 30);
        if (months < 12) return months + 'mo ago';
        return Math.floor(months / 12) + 'y ago';
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    function boot() {
        document.querySelectorAll('.bcc-me').forEach(initBlock);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
