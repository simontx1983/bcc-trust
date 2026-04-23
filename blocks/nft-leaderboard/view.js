/**
 * NFT Leaderboard – Chain-separated tabs with lazy REST loading.
 * Each tab (EVM / Solana / Cosmos) fetches independently.
 * Claim buttons handled by shared entity-claims.js.
 */
(function () {
    'use strict';

    // Uses bccCollectionStore (shared/collection-store.js) for per-chain
    // caching, request deduplication, and cross-block data sharing.

    function initLeaderboard(el) {
        var tabs   = el.querySelectorAll('.bcc-nft-lb__tab');
        var panels = el.querySelectorAll('.bcc-nft-lb__panel');
        var perPage = el.getAttribute('data-per-page') || '20';
        var showClaim = el.getAttribute('data-show-claim') === '1';

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                var chain = tab.getAttribute('data-chain');

                // Switch active tab.
                tabs.forEach(function (t) {
                    t.classList.remove('is-active');
                    t.setAttribute('aria-selected', 'false');
                });
                tab.classList.add('is-active');
                tab.setAttribute('aria-selected', 'true');

                // Switch active panel.
                panels.forEach(function (p) {
                    p.classList.toggle('is-active', p.getAttribute('data-chain-panel') === chain);
                });

                // Lazy-load if this panel hasn't been fetched yet.
                var panel = el.querySelector('[data-chain-panel="' + chain + '"]');
                if (panel && panel.getAttribute('data-needs-fetch') === '1') {
                    fetchChain(el, panel, chain, perPage, showClaim);
                }
            });
        });

        // Chain chip filtering within server-rendered default tab.
        el.querySelectorAll('.bcc-nft-lb__chain-chip').forEach(function (chip) {
            chip.addEventListener('click', function () {
                var panel = chip.closest('.bcc-nft-lb__panel');
                if (!panel) return;

                panel.querySelectorAll('.bcc-nft-lb__chain-chip').forEach(function (c) {
                    c.classList.remove('is-active');
                });
                chip.classList.add('is-active');

                var chainId = chip.getAttribute('data-chain');
                var rows = panel.querySelectorAll('.bcc-nft-lb__row');
                var rank = 0;

                rows.forEach(function (row) {
                    if (!chainId) {
                        row.style.display = '';
                        rank++;
                        updateRank(row, rank);
                    } else {
                        var small = row.querySelector('.bcc-nft-lb__name-group small');
                        var rowChain = small ? small.textContent.trim() : '';
                        var chipLabel = chip.textContent.trim();
                        if (rowChain === chipLabel) {
                            row.style.display = '';
                            rank++;
                            updateRank(row, rank);
                        } else {
                            row.style.display = 'none';
                        }
                    }
                });
            });
        });
    }

    function fetchChain(el, panel, chain, perPage, showClaim) {
        // Show loading state.
        var loader = panel.querySelector('.bcc-nft-lb__loading');
        if (loader) loader.style.display = '';

        var storeGet = window.bccCollectionStore
            ? window.bccCollectionStore.get(chain, 1, parseInt(perPage, 10))
            : fallbackFetch(chain, perPage);

        storeGet
            .then(function (data) {
                renderPanel(panel, data, chain, showClaim);
                panel.removeAttribute('data-needs-fetch');

                // Update tab count.
                var tab = el.querySelector('.bcc-nft-lb__tab[data-chain="' + chain + '"]');
                if (tab && data.total > 0) {
                    var countEl = tab.querySelector('.bcc-nft-lb__tab-count');
                    if (!countEl) {
                        countEl = document.createElement('span');
                        countEl.className = 'bcc-nft-lb__tab-count';
                        tab.appendChild(countEl);
                    }
                    countEl.textContent = formatNum(data.total);
                }
            })
            .catch(function () {
                if (loader) loader.style.display = 'none';
                panel.innerHTML = '<p class="bcc-entity-block__empty">Failed to load collections. Please try again.</p>';
            });
    }

    // Inline fallback if collection-store script failed to load.
    function fallbackFetch(chain, perPage) {
        var base = (window.bccCollectionData && window.bccCollectionData.restUrl)
            || (window.bccNftLb && window.bccNftLb.restUrl)
            || '/wp-json/bcc/v1/nft/collections';
        var nonce = (window.bccCollectionData && window.bccCollectionData.nonce)
            || (window.bccNftLb && window.bccNftLb.nonce) || '';
        var url = base + '?chain=' + encodeURIComponent(chain) + '&per_page=' + perPage;
        var headers = nonce ? { 'X-WP-Nonce': nonce } : {};
        return fetch(url, { credentials: 'same-origin', headers: headers })
            .then(function (res) { return res.json(); });
    }

    function renderPanel(panel, data, chain, showClaim) {
        var items = data.items || [];

        if (items.length === 0) {
            var labels = { evm: 'EVM', solana: 'Solana', cosmos: 'Cosmos' };
            panel.innerHTML = '<p class="bcc-entity-block__empty">No ' + (labels[chain] || chain) + ' collections indexed yet.</p>';
            return;
        }

        var html = '<div class="bcc-nft-lb__table-header">'
            + '<span class="bcc-nft-lb__col bcc-nft-lb__col--rank">#</span>'
            + '<span class="bcc-nft-lb__col bcc-nft-lb__col--name">Collection</span>'
            + '<span class="bcc-nft-lb__col bcc-nft-lb__col--stat">Floor</span>'
            + '<span class="bcc-nft-lb__col bcc-nft-lb__col--stat">Volume</span>'
            + '<span class="bcc-nft-lb__col bcc-nft-lb__col--stat">Holders</span>'
            + '<span class="bcc-nft-lb__col bcc-nft-lb__col--action"></span>'
            + '</div>';

        items.forEach(function (c, i) {
            var currency = c.floor_currency || c.native_token || '';
            var floorVal = parseFloat(c.floor_price);
            var floor = (c.floor_price != null && floorVal > 0) ? formatNum(floorVal) + ' ' + esc(currency) : '--';
            var volVal = parseFloat(c.total_volume);
            var volume = (c.total_volume != null && volVal > 0) ? formatNum(volVal) : '--';
            var holdVal = parseInt(c.unique_holders, 10);
            var holders = (c.unique_holders != null && holdVal > 0) ? formatNum(holdVal) : '--';
            var name = esc(c.collection_name || 'Unnamed');
            var chainName = esc(c.chain_name || '');
            var imgTag = c.image_url
                ? '<img src="' + esc(c.image_url) + '" alt="" class="bcc-nft-lb__avatar" width="32" height="32" loading="lazy" />'
                : '';

            html += '<div class="bcc-nft-lb__row" data-entity-type="collection" data-entity-id="' + esc(String(c.id)) + '">'
                + '<span class="bcc-nft-lb__col bcc-nft-lb__col--rank">' + (i + 1) + '</span>'
                + '<span class="bcc-nft-lb__col bcc-nft-lb__col--name">'
                +   imgTag
                +   '<span class="bcc-nft-lb__name-group">'
                +     '<strong>' + name + '</strong>'
                +     '<small>' + chainName + '</small>'
                +   '</span>'
                +   '<span class="bcc-entity-block__badge bcc-entity-block__badge--unclaimed">Unclaimed</span>'
                + '</span>'
                + '<span class="bcc-nft-lb__col bcc-nft-lb__col--stat">' + floor + '</span>'
                + '<span class="bcc-nft-lb__col bcc-nft-lb__col--stat">' + volume + '</span>'
                + '<span class="bcc-nft-lb__col bcc-nft-lb__col--stat">' + holders + '</span>'
                + '<span class="bcc-nft-lb__col bcc-nft-lb__col--action">';

            if (showClaim) {
                html += '<button class="bcc-entity-block__claim-btn" data-entity-type="collection" data-entity-id="' + esc(String(c.id)) + '">Claim Your Community</button>';
            }

            html += '</span></div>';
        });

        panel.innerHTML = html;
    }

    function formatNum(n) {
        if (n == null || isNaN(n)) return '--';
        if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
        if (n >= 1000) return (n / 1000).toFixed(1) + 'K';
        return n < 100 ? n.toFixed(1) : String(Math.round(n));
    }

    function esc(s) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(s));
        return d.innerHTML;
    }

    function updateRank(row, n) {
        var rankEl = row.querySelector('.bcc-nft-lb__col--rank');
        if (rankEl) rankEl.textContent = String(n);
    }

    function boot() {
        document.querySelectorAll('.bcc-nft-lb').forEach(initLeaderboard);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
