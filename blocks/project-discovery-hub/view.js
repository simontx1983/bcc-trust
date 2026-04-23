/**
 * Project Discovery Hub – Frontend View Script
 *
 * Handles search, filtering, sorting, lazy loading, pagination,
 * and next-page prefetch. Integrates with bccPageStore for
 * shared data where possible.
 */
(function () {
    'use strict';

    /* ── Config ─────────────────────────────────────────── */
    var DEBOUNCE_MS = 350;

    /* ── Data fetching via centralized store ────────────── */
    // Uses bccDiscoveryStore (shared/discovery-store.js) for request
    // deduplication, caching, and cross-block data sharing.
    function fetchDiscovery(params) {
        if (window.bccDiscoveryStore) {
            return window.bccDiscoveryStore.get(params);
        }
        // Inline fallback if store script failed to load.
        var ENDPOINT = (window.bccDiscoveryData && window.bccDiscoveryData.endpoint)
            || '/wp-json/bcc/v1/discover';
        var NONCE = (window.bccDiscoveryData && window.bccDiscoveryData.nonce) || '';
        var url = new URL(ENDPOINT, window.location.origin);
        Object.keys(params).forEach(function (k) {
            if (params[k]) url.searchParams.set(k, params[k]);
        });
        return fetch(url.toString(), {
            credentials: 'same-origin',
            headers: NONCE ? { 'X-WP-Nonce': NONCE } : {}
        }).then(function (res) { return res.json(); });
    }

    /* ── Lazy loader (IntersectionObserver) ──────────── */
    var lazyObserver = null;
    if ('IntersectionObserver' in window) {
        lazyObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    var img = entry.target;
                    var src = img.getAttribute('data-src');
                    if (src) {
                        img.src = src;
                        img.removeAttribute('data-src');
                        img.classList.remove('lazyload');
                        img.classList.add('lazyloaded');
                    }
                    lazyObserver.unobserve(img);
                }
            });
        }, { rootMargin: '200px' });
    }

    function observeLazy(container) {
        if (!lazyObserver) {
            // Fallback: just set src immediately.
            container.querySelectorAll('img[data-src]').forEach(function (img) {
                img.src = img.getAttribute('data-src');
                img.removeAttribute('data-src');
            });
            return;
        }
        container.querySelectorAll('img[data-src]').forEach(function (img) {
            lazyObserver.observe(img);
        });
    }

    /* ── Tier colors ────────────────────────────────────── */
    var tierColors = {
        elite:   'var(--bcc-tier-elite)',
        trusted: 'var(--bcc-tier-trusted)',
        neutral: 'var(--bcc-tier-neutral)',
        caution: 'var(--bcc-tier-caution)',
        risky:   'var(--bcc-tier-risky)'
    };

    /* ── Format count ───────────────────────────────────── */
    function formatCount(n) {
        if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
        if (n >= 1000) return (n / 1000).toFixed(1) + 'K';
        return String(n);
    }

    /* ── Build card HTML ────────────────────────────────── */
    function buildCardHtml(project, opts) {
        var tier      = project.tier || 'neutral';
        var tierColor = tierColors[tier] || tierColors.neutral;
        var hasCover  = opts.showCover && project.cover_url;
        var cardClass = 'bcc-discovery-hub__card' + (hasCover ? ' has-cover' : '');

        var coverHtml;
        if (hasCover) {
            coverHtml = '<div class="bcc-discovery-hub__card-cover">'
                + '<img src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==" data-src="' + escAttr(project.cover_url) + '" alt="" class="bcc-discovery-hub__card-cover-img lazyload" loading="lazy"/>'
                + '<div class="bcc-discovery-hub__card-overlay"></div>'
                + '</div>';
        } else {
            coverHtml = '<div class="bcc-discovery-hub__card-cover bcc-discovery-hub__card-cover--default">'
                + '<div class="bcc-discovery-hub__card-overlay"></div>'
                + '</div>';
        }

        var avatarHtml;
        if (project.avatar_url) {
            avatarHtml = '<img src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==" data-src="' + escAttr(project.avatar_url) + '" alt="' + escAttr(project.page_name) + '" class="bcc-discovery-hub__card-avatar lazyload" loading="lazy" width="48" height="48"/>';
        } else {
            var initial = (project.page_name || '?').charAt(0).toUpperCase();
            avatarHtml = '<div class="bcc-discovery-hub__card-avatar bcc-discovery-hub__card-avatar--placeholder">' + escHtml(initial) + '</div>';
        }

        var verifiedHtml = '';
        if (opts.showVerified && project.verified) {
            verifiedHtml = '<svg class="bcc-discovery-hub__verified-badge" width="16" height="16" viewBox="0 0 24 24" fill="var(--bcc-primary)" aria-label="Verified"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>';
        }

        var claimHtml = '';
        if (project.claimed && project.claimed_by) {
            claimHtml = '<span class="bcc-discovery-hub__claim-badge bcc-discovery-hub__claim-badge--claimed">'
                + '<svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg> '
                + escHtml(project.claimed_by)
                + '</span>';
        } else if (project.claimed === false) {
            claimHtml = '<span class="bcc-discovery-hub__claim-badge bcc-discovery-hub__claim-badge--unclaimed">'
                + '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> '
                + 'Unclaimed'
                + '</span>';
        }

        var endorseHtml = '';
        if (opts.showEndorsements) {
            endorseHtml = '<div class="bcc-discovery-hub__card-stat">'
                + '<span class="bcc-discovery-hub__card-stat-value">' + escHtml(String(project.endorsements || 0)) + '</span>'
                + '<span class="bcc-discovery-hub__card-stat-label">Endorsed</span>'
                + '</div>';
        }

        var followersHtml = '';
        if (opts.showFollowers) {
            followersHtml = '<div class="bcc-discovery-hub__card-stat">'
                + '<span class="bcc-discovery-hub__card-stat-value">' + escHtml(formatCount(project.followers || 0)) + '</span>'
                + '<span class="bcc-discovery-hub__card-stat-label">Followers</span>'
                + '</div>';
        }

        return '<a href="' + escAttr(project.page_url) + '" class="' + cardClass + '" role="listitem" data-page-id="' + project.page_id + '" data-page-type="' + escAttr(project.page_type) + '">'
            + coverHtml
            + '<div class="bcc-discovery-hub__card-content">'
                + '<div class="bcc-discovery-hub__card-header">'
                    + avatarHtml
                    + '<div class="bcc-discovery-hub__card-title-group">'
                        + '<h3 class="bcc-discovery-hub__card-name">' + escHtml(project.page_name) + verifiedHtml + '</h3>'
                        + '<span class="bcc-discovery-hub__card-type bcc-discovery-hub__card-type--' + escAttr(project.page_type) + '">' + escHtml(ucfirst(project.page_type)) + '</span>'
                        + claimHtml
                    + '</div>'
                + '</div>'
                + '<div class="bcc-discovery-hub__card-stats">'
                    + '<div class="bcc-discovery-hub__card-score" style="--tier-color:' + tierColor + '">'
                        + '<span class="bcc-discovery-hub__card-score-value">' + escHtml(String(project.trust_score)) + '</span>'
                        + '<span class="bcc-discovery-hub__card-score-label">Trust</span>'
                    + '</div>'
                    + endorseHtml
                    + followersHtml
                + '</div>'
            + '</div>'
            + '</a>';
    }

    /* ── Escape helpers ──────────────────────────────────── */
    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }
    function escAttr(s) {
        return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
    function ucfirst(s) {
        return s ? s.charAt(0).toUpperCase() + s.slice(1) : '';
    }

    /* ── Skeleton cards ─────────────────────────────────── */
    function buildSkeletonHtml(count) {
        var html = '';
        for (var i = 0; i < count; i++) {
            html += '<div class="bcc-discovery-hub__card bcc-discovery-hub__skeleton" role="listitem" aria-hidden="true">'
                + '<div class="bcc-discovery-hub__card-cover bcc-discovery-hub__card-cover--default">'
                    + '<div class="bcc-discovery-hub__card-overlay"></div>'
                + '</div>'
                + '<div class="bcc-discovery-hub__card-content">'
                    + '<div class="bcc-discovery-hub__card-header">'
                        + '<div class="bcc-discovery-hub__skeleton-bone bcc-discovery-hub__skeleton-avatar"></div>'
                        + '<div class="bcc-discovery-hub__card-title-group">'
                            + '<div class="bcc-discovery-hub__skeleton-bone bcc-discovery-hub__skeleton-title"></div>'
                            + '<div class="bcc-discovery-hub__skeleton-bone bcc-discovery-hub__skeleton-type"></div>'
                        + '</div>'
                    + '</div>'
                    + '<div class="bcc-discovery-hub__card-stats">'
                        + '<div class="bcc-discovery-hub__skeleton-bone bcc-discovery-hub__skeleton-stat"></div>'
                        + '<div class="bcc-discovery-hub__skeleton-bone bcc-discovery-hub__skeleton-stat"></div>'
                        + '<div class="bcc-discovery-hub__skeleton-bone bcc-discovery-hub__skeleton-stat"></div>'
                    + '</div>'
                + '</div>'
            + '</div>';
        }
        return html;
    }

    function showSkeletons(grid, count) {
        grid.innerHTML = buildSkeletonHtml(count);
    }

    function removeSkeletons(grid) {
        var skeletons = grid.querySelectorAll('.bcc-discovery-hub__skeleton');
        skeletons.forEach(function (s) { s.remove(); });
    }

    /* ── Debounce ──────────────────────────────────────── */
    function debounce(fn, ms) {
        var timer;
        return function () {
            var self = this, args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () { fn.apply(self, args); }, ms);
        };
    }

    /* ── Init each discovery hub instance ────────────── */
    function initHub(el) {
        var state = {
            types:        el.getAttribute('data-types') || '',
            sort:         el.getAttribute('data-sort') || 'trust',
            verifiedOnly: el.getAttribute('data-verified-only') === '1',
            limit:        parseInt(el.getAttribute('data-limit'), 10) || 12,
            page:         1,
            totalPages:   parseInt(el.getAttribute('data-total-pages'), 10) || 0,
            total:        parseInt(el.getAttribute('data-total'), 10) || 0,
            search:       '',
            filterType:   '',
            trustFilter:  '',
            activeTab:    'top',
            loading:      false
        };

        var opts = {
            showCover:        el.getAttribute('data-show-cover') === '1',
            showFollowers:    el.getAttribute('data-show-followers') === '1',
            showEndorsements: el.getAttribute('data-show-endorsements') === '1',
            showVerified:     el.getAttribute('data-show-verified') === '1',
            showTabs:         el.getAttribute('data-show-tabs') === '1',
            needsEvalMax:     parseInt(el.getAttribute('data-needs-eval-max'), 10) || 5
        };

        /* ── Tab presets ── */
        var TAB_PRESETS = {
            'top':        { sort: 'trust',  new_only: false, max_votes: 0 },
            'rising':     { sort: 'trust',  new_only: true,  max_votes: 0 },
            'needs-eval': { sort: 'newest', new_only: false, max_votes: opts.needsEvalMax }
        };

        var grid      = el.querySelector('.bcc-discovery-hub__grid');
        var loadMore  = el.querySelector('.bcc-discovery-hub__load-more');
        var footer    = el.querySelector('.bcc-discovery-hub__footer');
        var searchEl  = el.querySelector('.bcc-discovery-hub__search-input');
        var sortEl    = el.querySelector('.bcc-discovery-hub__sort-select');
        var filterBtns = el.querySelectorAll('.bcc-discovery-hub__filter-tab');
        var trustChips = el.querySelectorAll('.bcc-discovery-hub__trust-chip');

        // Observe initial lazy images.
        observeLazy(el);

        /* ── Reload grid (replace) ── */
        function reloadGrid() {
            state.page = 1;
            state.loading = true;
            setLoadingState(true);

            // Show skeleton placeholders while fetching.
            showSkeletons(grid, Math.min(state.limit, 8));

            var params = buildParams();
            fetchDiscovery(params).then(function (data) {
                state.total      = data.total;
                state.totalPages = data.pages;
                state.page       = 1;

                renderGrid(data.results, false);
                updateFooter();
                state.loading = false;
                setLoadingState(false);

                // Prefetch next page.
                prefetchNext();
            }).catch(function () {
                removeSkeletons(grid);
                state.loading = false;
                setLoadingState(false);
            });
        }

        /* ── Load more (append) ── */
        function loadNextPage() {
            if (state.loading || state.page >= state.totalPages) return;
            state.page++;
            state.loading = true;
            setLoadMoreLoading(true);

            var params = buildParams();
            params.page = state.page;

            fetchDiscovery(params).then(function (data) {
                renderGrid(data.results, true);
                updateFooter();
                state.loading = false;
                setLoadMoreLoading(false);

                // Prefetch next page.
                prefetchNext();
            }).catch(function () {
                state.page--;
                state.loading = false;
                setLoadMoreLoading(false);
            });
        }

        /* ── Prefetch next page ── */
        function prefetchNext() {
            if (state.page < state.totalPages) {
                var params = buildParams();
                params.page = state.page + 1;
                fetchDiscovery(params); // Fire and forget.
            }
        }

        /* ── Build query params from state ── */
        function buildParams() {
            var types = state.types;

            // If a filter tab overrides the type.
            if (state.filterType && state.filterType !== 'verified') {
                types = state.filterType;
            }

            // Map trust filter chips to REST params.
            var tier           = '';
            var min_confidence = 0;
            var new_only       = false;
            var max_votes      = 0;
            var trustVerified  = false;

            switch (state.trustFilter) {
                case 'verified':        trustVerified = true; break;
                case 'tier-a':          tier = 'elite';    break;
                case 'tier-b':          tier = 'trusted';  break;
                case 'high-confidence': min_confidence = 0.7; break;
                case 'new':             new_only = true;   break;
            }

            // Apply tab preset (overrides sort, new_only, max_votes).
            var preset = TAB_PRESETS[state.activeTab] || TAB_PRESETS['top'];

            return {
                types:          types,
                sort:           preset.sort,
                verified_only:  state.verifiedOnly || state.filterType === 'verified' || trustVerified,
                tier:           tier,
                min_confidence: min_confidence,
                new_only:       new_only || preset.new_only,
                max_votes:      max_votes || preset.max_votes,
                limit:          state.limit,
                page:           state.page,
                search:         state.search
            };
        }

        /* ── Render cards into grid ── */
        function renderGrid(projects, append) {
            if (!append) {
                grid.innerHTML = '';
            }

            if (!projects || projects.length === 0) {
                if (!append) {
                    grid.innerHTML = '<div class="bcc-discovery-hub__empty"><p>No projects found.</p></div>';
                }
                return;
            }

            var fragment = document.createDocumentFragment();
            var temp = document.createElement('div');

            projects.forEach(function (project) {
                temp.innerHTML = buildCardHtml(project, opts);
                var card = temp.firstElementChild;
                if (card) {
                    fragment.appendChild(card);
                }
            });

            grid.appendChild(fragment);
            observeLazy(grid);
        }

        /* ── Update footer / load more ── */
        function updateFooter() {
            if (!footer) return;
            var shown = grid.querySelectorAll('.bcc-discovery-hub__card').length;

            if (state.page >= state.totalPages || state.total === 0) {
                footer.style.display = 'none';
            } else {
                footer.style.display = '';
                var countEl = footer.querySelector('.bcc-discovery-hub__count');
                if (countEl) {
                    countEl.textContent = '(' + shown + ' of ' + state.total + ')';
                }
            }
        }

        /* ── Loading states ── */
        function setLoadingState(loading) {
            el.classList.toggle('is-loading', loading);
        }
        function setLoadMoreLoading(loading) {
            if (!loadMore) return;
            if (loading) {
                loadMore.disabled = true;
                loadMore.setAttribute('data-original-text', loadMore.textContent);
                loadMore.textContent = loadMore.getAttribute('data-loading-text') || 'Loading...';
            } else {
                loadMore.disabled = false;
                var original = loadMore.getAttribute('data-original-text');
                if (original) loadMore.textContent = original;
            }
        }

        /* ── Event: Search ── */
        if (searchEl) {
            searchEl.addEventListener('input', debounce(function () {
                state.search = searchEl.value.trim();
                reloadGrid();
            }, DEBOUNCE_MS));
        }

        /* ── Event: Sort ── */
        if (sortEl) {
            sortEl.addEventListener('change', function () {
                state.sort = sortEl.value;
                reloadGrid();
            });
        }

        /* ── Event: Filter tabs ── */
        filterBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                filterBtns.forEach(function (b) {
                    b.classList.remove('is-active');
                    b.setAttribute('aria-selected', 'false');
                });
                btn.classList.add('is-active');
                btn.setAttribute('aria-selected', 'true');

                state.filterType = btn.getAttribute('data-type') || '';
                reloadGrid();
            });
        });

        /* ── Event: Trust filter chips ── */
        trustChips.forEach(function (chip) {
            chip.addEventListener('click', function () {
                trustChips.forEach(function (c) { c.classList.remove('is-active'); });
                chip.classList.add('is-active');

                state.trustFilter = chip.getAttribute('data-trust-filter') || '';
                reloadGrid();
            });
        });

        /* ── Event: Discovery tabs (Top / Rising / Needs Evaluation) ── */
        var tabBtns = el.querySelectorAll('.bcc-discovery-hub__tab');
        tabBtns.forEach(function (tab) {
            tab.addEventListener('click', function () {
                tabBtns.forEach(function (t) {
                    t.classList.remove('is-active');
                    t.setAttribute('aria-selected', 'false');
                });
                tab.classList.add('is-active');
                tab.setAttribute('aria-selected', 'true');

                state.activeTab = tab.getAttribute('data-tab') || 'top';

                // Update the sort dropdown to reflect the tab's preset.
                var preset = TAB_PRESETS[state.activeTab] || TAB_PRESETS['top'];
                if (sortEl) {
                    sortEl.value = preset.sort;
                }

                reloadGrid();
            });
        });

        /* ── Event: Load More ── */
        if (loadMore) {
            loadMore.addEventListener('click', loadNextPage);
        }

        // Prefetch page 2 on init.
        prefetchNext();
    }

    /* ── Boot ──────────────────────────────────────────── */
    function boot() {
        document.querySelectorAll('.bcc-discovery-hub').forEach(initHub);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();