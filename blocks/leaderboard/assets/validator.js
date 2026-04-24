/**
 * Validator Leaderboard – Load more, copy address, tooltip.
 * Sorting is server-side via <a> links (page reload with ?vlb_sort=&vlb_dir=).
 * Claim buttons handled by shared entity-claims.js.
 */
(function () {
    'use strict';

    var REST_URL = (window.bccVLB && window.bccVLB.restUrl) || '/wp-json/bcc/v1/validators/top';
    var NONCE    = (window.bccVLB && window.bccVLB.nonce) || '';

    function initVLB(el) {
        var copyButtons  = el.querySelectorAll('.bcc-vlb__copy');
        var loadMoreBtn  = el.querySelector('.bcc-vlb__load-more');
        var addrToggle   = el.querySelector('.bcc-vlb__addr-toggle');

        /* ── Address type toggle (Validator / Account) ── */
        if (addrToggle) {
            addrToggle.addEventListener('click', function () {
                var current = addrToggle.getAttribute('data-mode');
                var next    = current === 'operator' ? 'account' : 'operator';
                addrToggle.setAttribute('data-mode', next);

                // Update header label with line breaks
                var label = addrToggle.querySelector('.bcc-vlb__addr-toggle-label');
                if (label) {
                    var raw = next === 'operator'
                        ? label.getAttribute('data-label-operator')
                        : label.getAttribute('data-label-account');
                    label.textContent = '';
                    (raw || '').split('\n').forEach(function (part, i) {
                        if (i > 0) label.appendChild(document.createElement('br'));
                        label.appendChild(document.createTextNode(part));
                    });
                }

                // Toggle all address values in every row
                el.querySelectorAll('.bcc-vlb__addr-val').forEach(function (span) {
                    var type = span.getAttribute('data-addr-type');
                    span.style.display = (type === next) ? '' : 'none';
                });
            });
        }

        /* ── Copy address to clipboard ── */
        function bindCopy(btn) {
            if (btn.hasAttribute('data-bound')) return;
            btn.setAttribute('data-bound', '1');

            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var text = btn.getAttribute('data-copy');
                if (!text) return;

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(function () {
                        showCopyFeedback(btn);
                    });
                } else {
                    var ta = document.createElement('textarea');
                    ta.value = text;
                    ta.style.position = 'fixed';
                    ta.style.opacity = '0';
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                    showCopyFeedback(btn);
                }
            });
        }

        function showCopyFeedback(btn) {
            btn.classList.add('is-copied');
            setTimeout(function () { btn.classList.remove('is-copied'); }, 1500);
        }

        copyButtons.forEach(bindCopy);

        /* ── Load More ── */
        if (loadMoreBtn) {
            loadMoreBtn.addEventListener('click', function () {
                var currentPage = parseInt(el.getAttribute('data-current-page'), 10) || 1;
                var totalPages  = parseInt(el.getAttribute('data-total-pages'), 10) || 0;
                var nextPage    = currentPage + 1;

                if (nextPage > totalPages) return;

                var originalText = loadMoreBtn.textContent;
                loadMoreBtn.disabled = true;
                loadMoreBtn.textContent = loadMoreBtn.getAttribute('data-loading-text') || 'Loading...';

                var perPage = el.getAttribute('data-per-page') || '25';
                var chainId = el.getAttribute('data-chain-id') || '';
                var sort    = el.getAttribute('data-sort') || 'voting_power_rank';
                var dir     = el.getAttribute('data-dir') || '';

                var url = REST_URL + '?page=' + nextPage + '&per_page=' + perPage;
                if (chainId) url += '&chain_id=' + chainId;
                if (sort) url += '&sort=' + sort;
                if (dir) url += '&dir=' + dir;

                var headers = {};
                if (NONCE) headers['X-WP-Nonce'] = NONCE;

                fetch(url, { credentials: 'same-origin', headers: headers })
                    .then(function (res) { return res.json(); })
                    .then(function (response) {
                        var items = response.items || [];
                        if (items.length) {
                            var table = el.querySelector('.bcc-vlb__table');
                            var stakeIsSelf = (sort === 'self_stake');
                            var addrMode = addrToggle ? (addrToggle.getAttribute('data-mode') || 'operator') : 'operator';

                            items.forEach(function (v) {
                                var row = buildValidatorRow(v, stakeIsSelf, addrMode);
                                table.appendChild(row);
                            });

                            el.setAttribute('data-current-page', String(nextPage));

                            // Bind copy on new rows.
                            el.querySelectorAll('.bcc-vlb__copy').forEach(bindCopy);

                            // Update count label.
                            var shown = nextPage * parseInt(perPage, 10);
                            if (shown > (response.total || 0)) shown = response.total || 0;
                            var countEl = loadMoreBtn.querySelector('.bcc-vlb__load-count')
                                       || el.querySelector('.bcc-vlb__load-count');
                            if (countEl) {
                                countEl.textContent = '(' + shown + ' of ' + (response.total || 0) + ')';
                            }

                            if (!response.has_more) {
                                loadMoreBtn.parentElement.style.display = 'none';
                            } else {
                                loadMoreBtn.disabled = false;
                                loadMoreBtn.textContent = originalText;
                            }
                        } else {
                            loadMoreBtn.parentElement.style.display = 'none';
                        }
                    })
                    .catch(function () {
                        loadMoreBtn.disabled = false;
                        loadMoreBtn.textContent = originalText;
                    });
            });
        }

        /* ── Tooltip hover/focus ── */
        el.querySelectorAll('.bcc-vlb__tooltip').forEach(function (tooltip) {
            tooltip.addEventListener('mouseenter', function () { tooltip.classList.add('is-open'); });
            tooltip.addEventListener('mouseleave', function () { tooltip.classList.remove('is-open'); });
            tooltip.addEventListener('focus',      function () { tooltip.classList.add('is-open'); });
            tooltip.addEventListener('blur',       function () { tooltip.classList.remove('is-open'); });
        });
    }

    function formatNum(n, token) {
        if (n === null || n === undefined) return '--';
        n = parseFloat(n);
        var s = n >= 1000000 ? (n / 1000000).toFixed(1) + 'M'
              : (n >= 1000 ? (n / 1000).toFixed(1) + 'K'
              : String(Math.round(n)));
        return token ? s + ' ' + token : s;
    }

    function truncAddr(addr) {
        if (!addr || addr.length <= 19) return addr || '';
        return addr.substring(0, 12) + '...' + addr.substring(addr.length - 4);
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function buildValidatorRow(v, stakeIsSelf, addrMode) {
        var div = document.createElement('div');
        div.className = 'bcc-vlb__row';
        div.setAttribute('data-entity-type', 'validator');
        div.setAttribute('data-entity-id', String(v.id));
        div.setAttribute('data-chain', v.chain_name || '');

        var stakeVal = stakeIsSelf ? v.self_stake : v.total_stake;
        var stakeDisplay = (stakeVal !== null && stakeVal > 0)
            ? formatNum(stakeVal, v.native_token)
            : (stakeIsSelf ? '--' : formatNum(v.total_stake, v.native_token));
        var stakeLabel = stakeIsSelf ? 'Self' : 'Bonded';

        var uptime = v.uptime_30d !== null ? v.uptime_30d : null;
        var uptimeClass = uptime === null ? '' : (uptime >= 99 ? 'is-excellent' : (uptime >= 95 ? 'is-good' : 'is-warning'));

        var operator = v.operator_address || '';
        var avatarHtml = v.avatar_url
            ? '<img src="' + esc(v.avatar_url) + '" alt="" class="bcc-vlb__avatar" width="36" height="36" loading="lazy" />'
            : '<span class="bcc-vlb__avatar bcc-vlb__avatar--placeholder">' + esc((v.moniker || '?').charAt(0).toUpperCase()) + '</span>';

        var claimedBadge = v.claimed
            ? '<span class="bcc-vlb__badge bcc-vlb__badge--claimed"><svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg></span>'
            : '';

        var operatorDisplay = addrMode === 'operator' ? '' : 'display:none';
        var accountDisplay  = addrMode === 'account' ? '' : 'display:none';

        div.innerHTML =
            '<span class="bcc-vlb__td bcc-vlb__col--rank">' + esc(String(v.voting_power_rank)) + '</span>' +
            '<span class="bcc-vlb__td bcc-vlb__col--name">' + avatarHtml +
                '<span class="bcc-vlb__name-wrap"><strong class="bcc-vlb__moniker">' + esc(v.moniker) + '</strong>' + claimedBadge + '</span></span>' +
            '<span class="bcc-vlb__td bcc-vlb__col--addr">' +
                '<span class="bcc-vlb__addr-val" data-addr-type="operator" style="' + operatorDisplay + '">' +
                    '<code class="bcc-vlb__addr" title="' + esc(operator) + '" data-full="' + esc(operator) + '">' + esc(truncAddr(operator)) + '</code>' +
                    '<button class="bcc-vlb__copy" data-copy="' + esc(operator) + '" title="Copy"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg></button>' +
                '</span>' +
                '<span class="bcc-vlb__addr-val" data-addr-type="account" style="' + accountDisplay + '">' +
                    '<code class="bcc-vlb__addr" title="' + esc(operator) + '" data-full="' + esc(operator) + '">' + esc(truncAddr(operator)) + '</code>' +
                    '<button class="bcc-vlb__copy" data-copy="' + esc(operator) + '" title="Copy"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg></button>' +
                '</span>' +
            '</span>' +
            '<span class="bcc-vlb__td bcc-vlb__col--stake" data-label="' + esc(stakeLabel) + '">' + esc(stakeDisplay) + '</span>' +
            '<span class="bcc-vlb__td bcc-vlb__col--sm" data-label="Comm">' + (v.commission_rate !== null ? esc(v.commission_rate.toFixed(1) + '%') : '--') + '</span>' +
            '<span class="bcc-vlb__td bcc-vlb__col--sm ' + uptimeClass + '" data-label="Uptime">' + (uptime !== null && uptime > 0 ? esc(uptime + '%') : '--') + '</span>' +
            '<span class="bcc-vlb__td bcc-vlb__col--action">' +
                (v.explorer_url && operator ? '<a href="' + esc(v.explorer_url + '/validators/' + operator) + '" class="bcc-vlb__explorer-link" target="_blank" rel="noopener"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg></a>' : '') +
                (v.claimed ? '<span class="bcc-vlb__claimed-label">' + esc(v.claimer_name || 'Claimed') + '</span>' : '') +
            '</span>';

        return div;
    }

    function boot() {
        document.querySelectorAll('.bcc-vlb').forEach(initVLB);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
