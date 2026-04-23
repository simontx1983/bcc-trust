/**
 * Endorsement Leaderboard – REST-driven view script.
 *
 * Fetches data from GET /bcc/v1/endorsements/top and renders rows.
 * Data source: REST endpoint (read model backed).
 */
(function () {
    'use strict';

    var ENDPOINT = (window.bccELB && window.bccELB.restUrl) || '/wp-json/bcc/v1/endorsements/top';
    var NONCE    = (window.bccELB && window.bccELB.nonce) || '';

    function initELB(el) {
        var limit   = parseInt(el.getAttribute('data-limit'), 10) || 10;
        var context = el.getAttribute('data-context') || '';
        var showCount = el.getAttribute('data-show-endorser-count') === '1';
        var tierLabels = {};
        try { tierLabels = JSON.parse(el.getAttribute('data-tier-labels') || '{}'); } catch (e) {}

        var table = el.querySelector('.bcc-elb__table');
        if (!table) return;

        var url = ENDPOINT + '?limit=' + limit;
        if (context) url += '&context=' + encodeURIComponent(context);

        var headers = { 'Content-Type': 'application/json' };
        if (NONCE) headers['X-WP-Nonce'] = NONCE;

        fetch(url, { credentials: 'same-origin', headers: headers })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                var items = data.items || [];
                if (!items.length) {
                    table.innerHTML = '<div class="bcc-elb__empty"><p>No endorsements yet.</p></div>';
                    // Update title with count
                    var titleEl = el.querySelector('.bcc-elb__title');
                    if (titleEl && !context) {
                        titleEl.textContent = 'Top 0 Endorsed Pages';
                    }
                    return;
                }

                // Update title with count
                var titleEl = el.querySelector('.bcc-elb__title');
                if (titleEl) {
                    if (context) {
                        titleEl.textContent = 'Top ' + items.length + ' Endorsed Pages (' + context.charAt(0).toUpperCase() + context.slice(1) + ')';
                    } else {
                        titleEl.textContent = 'Top ' + items.length + ' Endorsed Pages';
                    }
                }

                // Find max weight for bar scaling
                var maxWeight = 0;
                items.forEach(function (item) {
                    if (item.total_weight > maxWeight) maxWeight = item.total_weight;
                });

                var html = '<div class="bcc-elb__thead">' +
                    '<span class="bcc-elb__th bcc-elb__col--rank">#</span>' +
                    '<span class="bcc-elb__th bcc-elb__col--name">Page</span>';
                if (showCount) {
                    html += '<span class="bcc-elb__th bcc-elb__col--count">Endorsements</span>';
                }
                html += '<span class="bcc-elb__th bcc-elb__col--weight">Weight</span>' +
                    '<span class="bcc-elb__th bcc-elb__col--tier">Tier</span>' +
                    '</div>';

                items.forEach(function (item) {
                    var weightPct = maxWeight > 0 ? Math.round((item.total_weight / maxWeight) * 100) : 0;
                    var tierLabel = tierLabels[item.tier] || item.tier.charAt(0).toUpperCase() + item.tier.slice(1);
                    var initial = (item.page_title || '?').charAt(0).toUpperCase();

                    html += '<div class="bcc-elb__row">';
                    html += '<span class="bcc-elb__td bcc-elb__col--rank">' + escHtml(String(item.rank)) + '</span>';
                    html += '<span class="bcc-elb__td bcc-elb__col--name">';
                    if (item.avatar_url) {
                        html += '<img src="' + escAttr(item.avatar_url) + '" alt="" class="bcc-elb__avatar" width="32" height="32" loading="lazy" />';
                    } else {
                        html += '<span class="bcc-elb__avatar bcc-elb__avatar--placeholder">' + escHtml(initial) + '</span>';
                    }
                    if (item.page_url) {
                        html += '<a href="' + escAttr(item.page_url) + '" class="bcc-elb__page-link">' + escHtml(item.page_title) + '</a>';
                    } else {
                        html += '<span class="bcc-elb__page-link">' + escHtml(item.page_title) + '</span>';
                    }
                    html += '</span>';

                    if (showCount) {
                        html += '<span class="bcc-elb__td bcc-elb__col--count" data-label="Endorsements">' + escHtml(String(item.endorsement_count)) + '</span>';
                    }

                    html += '<span class="bcc-elb__td bcc-elb__col--weight" data-label="Weight">';
                    html += '<span class="bcc-elb__weight-bar"><span class="bcc-elb__weight-fill" style="width:' + weightPct + '%"></span></span>';
                    html += '<span class="bcc-elb__weight-val">' + escHtml(item.total_weight.toFixed(1)) + '</span>';
                    html += '</span>';

                    html += '<span class="bcc-elb__td bcc-elb__col--tier" data-label="Tier">';
                    html += '<span class="bcc-tier-badge bcc-tier-badge--' + escAttr(item.tier) + '">' + escHtml(tierLabel) + '</span>';
                    html += '</span>';

                    html += '</div>';
                });

                table.innerHTML = html;
            })
            .catch(function () {
                table.innerHTML = '<div class="bcc-elb__empty"><p>Failed to load endorsement data.</p></div>';
            });
    }

    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function escAttr(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML.replace(/"/g, '&quot;');
    }

    function boot() {
        document.querySelectorAll('.bcc-elb').forEach(initELB);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
