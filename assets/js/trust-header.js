/**
 * BCC Trust Header Panel
 *
 * Hydrates the server-rendered .bcc-trust-header component with live
 * data from /bcc/v1/page/{id} and handles vote / endorse / tooltip
 * interactions.  Reuses the global bccTrust + bccPageData config
 * injected by enqueue.php.
 *
 * @version 1.0.0
 */
(function () {
    'use strict';

    /* ----------------------------------------------------------
       Helpers
    ---------------------------------------------------------- */

    /**
     * Convert a numeric score to a letter grade.
     * Thresholds are provided by PHP via wp_localize_script (bccGradeThresholds).
     * Formatting::scoreToGrade() in PHP is the single source of truth.
     */
    function scoreToGrade(score) {
        score = Math.round(score);
        var thresholds = (typeof bccGradeThresholds !== 'undefined') ? bccGradeThresholds : [];
        for (var i = 0; i < thresholds.length; i++) {
            if (score >= parseInt(thresholds[i].min, 10)) return thresholds[i].grade;
        }
        return 'F';
    }

    function qs(el, sel) { return el.querySelector(sel); }
    function qsa(el, sel) { return el.querySelectorAll(sel); }

    /**
     * Build the endorsement status message using context label.
     */
    function endorseMessage(header, revoked) {
        var label = header.getAttribute('data-context-label') || 'Project';
        if (revoked) {
            return 'Endorsement revoked';
        }
        return 'Endorsed ' + label;
    }

    /* ----------------------------------------------------------
       REST helpers (uses bccTrust config from trust-frontend.js)
    ---------------------------------------------------------- */

    function getNonce() {
        if (window.bccTrust && window.bccTrust.nonce) return window.bccTrust.nonce;
        if (window.bccPageData && window.bccPageData.nonce) return window.bccPageData.nonce;
        console.error('BCC Trust Header: no nonce found. bccTrust=', window.bccTrust, 'bccPageData=', window.bccPageData);
        return '';
    }

    function getRestUrl() {
        if (window.bccTrust && window.bccTrust.rest_url) return window.bccTrust.rest_url;
        console.error('BCC Trust Header: no rest_url. bccTrust=', window.bccTrust);
        return '/wp-json/bcc-trust/v1/';
    }

    /** Generate a unique idempotency key per request. */
    function idemKey() {
        return Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
    }

    function restHeaders() {
        return {
            'Content-Type': 'application/json',
            'X-WP-Nonce': getNonce(),
            'X-Idempotency-Key': idemKey(),
        };
    }

    /* ----------------------------------------------------------
       Update the header DOM from API data
    ---------------------------------------------------------- */

    function updateHeader(header, data) {
        // If an optimistic write is in flight, block subscriber-driven
        // updates from overwriting the optimistic DOM state.
        // The write handler will apply the authoritative response itself.
        if (header._bccWriteInFlight) {
            return;
        }

        var trust = data.trust || {};

        var score        = trust.score       || 0;
        var confidence   = Math.round((trust.confidence || 0) * 100);
        var votesUp      = trust.votes_up    || 0;
        var votesDown    = trust.votes_down  || 0;
        var endorsements = trust.endorsements || 0;
        var uniqueVoters = trust.unique_voters || 0;

        // Grade — prefer backend-computed grade; fall back to local only if absent
        var gradeEl = qs(header, '.bcc-trust-header__grade');
        if (gradeEl) {
            gradeEl.textContent = trust.grade || scoreToGrade(score);
            gradeEl.setAttribute('data-score', score);
        }

        // Confidence
        var confEl = qs(header, '.bcc-trust-header__confidence-val');
        if (confEl) confEl.textContent = confidence + '%';

        // Counts
        var posCount = qs(header, '.bcc-trust-header__count--positive');
        if (posCount) posCount.textContent = votesUp;

        var riskCount = qs(header, '.bcc-trust-header__count--risk');
        if (riskCount) riskCount.textContent = votesDown;

        var endorseCount = qs(header, '.bcc-trust-header__count--endorsements');
        if (endorseCount) endorseCount.textContent = endorsements;

        // Unique supporters
        var supportersCount = qs(header, '.bcc-trust-header__supporters-count');
        if (supportersCount) supportersCount.textContent = uniqueVoters;

        // Viewer state — only update if data explicitly includes viewer.
        // CRITICAL: When viewer is null (e.g. from inject() after a write),
        // we must NOT touch button states. The write handler already set
        // the correct active state directly on the DOM.
        if (data.viewer != null) {
            var viewer      = data.viewer;
            var voteType    = viewer.vote_type   || 0;
            var hasEndorsed = viewer.has_endorsed || false;

            qsa(header, '.bcc-trust-header__vote-btn').forEach(function (btn) {
                var v = parseInt(btn.getAttribute('data-vote'), 10);
                var pressed = v === voteType;
                btn.classList.toggle('is-active', pressed);
                btn.setAttribute('aria-pressed', pressed ? 'true' : 'false');
            });

            var endorseBtn = qs(header, '.bcc-trust-header__endorse-btn');
            if (endorseBtn) {
                endorseBtn.classList.toggle('is-active', hasEndorsed);
                endorseBtn.setAttribute('aria-pressed', hasEndorsed ? 'true' : 'false');
            }
        }
    }

    /* ----------------------------------------------------------
       Fetch page data & hydrate (via shared store — 0 extra requests)
    ---------------------------------------------------------- */

    function hydrate(header) {
        var pageId = header.getAttribute('data-page-id');
        if (!pageId) return;

        var store = window.bccPageStore;
        if (store) {
            store.get(parseInt(pageId, 10))
                .then(function (data) { updateHeader(header, data); })
                .catch(function (err) {
                    if (window.bccTrust && window.bccTrust.debug) {
                        console.error('BCC Trust Header: store fetch error', err);
                    }
                });
        }
    }

    /* ----------------------------------------------------------
       Vote handler (optimistic UI)
    ---------------------------------------------------------- */

    /**
     * Build a page-store-shaped object from a write response.
     * Delegates to the shared builder on bccPageStore to avoid duplication.
     */
    function buildStorePayload(d) {
        if (window.bccPageStore && window.bccPageStore.buildStorePayload) {
            return window.bccPageStore.buildStorePayload(d);
        }
        // Inline fallback (should never fire — page-store.js loads first)
        var s = d.score || {};
        return {
            trust: {
                score:         s.total_score       !== undefined ? s.total_score       : 50,
                tier:          s.reputation_tier    || 'neutral',
                confidence:    s.confidence_score   !== undefined ? s.confidence_score  : 0,
                votes_up:      d.votes_up           !== undefined ? d.votes_up          : 0,
                votes_down:    d.votes_down         !== undefined ? d.votes_down        : 0,
                unique_voters: s.unique_voters      !== undefined ? s.unique_voters     : 0,
                endorsements:  d.endorsement_count  !== undefined ? d.endorsement_count : 0,
            },
            viewer: null,
        };
    }

    /**
     * Apply the server response to the header DOM.  Single source of truth
     * for both vote and endorse handlers — eliminates duplication.
     */
    function applyResponseToHeader(header, d) {
        var s = d.score || {};

        var posCount = qs(header, '.bcc-trust-header__count--positive');
        if (posCount && d.votes_up !== undefined) posCount.textContent = d.votes_up;

        var riskCount = qs(header, '.bcc-trust-header__count--risk');
        if (riskCount && d.votes_down !== undefined) riskCount.textContent = d.votes_down;

        var endorseCount = qs(header, '.bcc-trust-header__count--endorsements');
        if (endorseCount && d.endorsement_count !== undefined) endorseCount.textContent = d.endorsement_count;

        if (s.total_score !== undefined || s.grade) {
            var gradeEl = qs(header, '.bcc-trust-header__grade');
            if (gradeEl) gradeEl.textContent = s.grade || scoreToGrade(s.total_score);
        }
        if (s.confidence_score !== undefined) {
            var confEl = qs(header, '.bcc-trust-header__confidence-val');
            if (confEl) confEl.textContent = Math.round(s.confidence_score * 100) + '%';
        }
    }

    /**
     * Capture current DOM state so we can restore it on error.
     * Avoids calling hydrate() which re-fetches and can itself
     * return stale data.
     */
    function captureState(header) {
        return {
            posCount:     (qs(header, '.bcc-trust-header__count--positive') || {}).textContent,
            riskCount:    (qs(header, '.bcc-trust-header__count--risk') || {}).textContent,
            endorseCount: (qs(header, '.bcc-trust-header__count--endorsements') || {}).textContent,
            grade:        (qs(header, '.bcc-trust-header__grade') || {}).textContent,
            confidence:   (qs(header, '.bcc-trust-header__confidence-val') || {}).textContent,
            activeVoteBtns: Array.from(qsa(header, '.bcc-trust-header__vote-btn.is-active')),
            endorseActive: qs(header, '.bcc-trust-header__endorse-btn.is-active') ? true : false,
        };
    }

    function restoreState(header, snap) {
        var el;
        el = qs(header, '.bcc-trust-header__count--positive');
        if (el && snap.posCount !== undefined) el.textContent = snap.posCount;
        el = qs(header, '.bcc-trust-header__count--risk');
        if (el && snap.riskCount !== undefined) el.textContent = snap.riskCount;
        el = qs(header, '.bcc-trust-header__count--endorsements');
        if (el && snap.endorseCount !== undefined) el.textContent = snap.endorseCount;
        el = qs(header, '.bcc-trust-header__grade');
        if (el && snap.grade !== undefined) el.textContent = snap.grade;
        el = qs(header, '.bcc-trust-header__confidence-val');
        if (el && snap.confidence !== undefined) el.textContent = snap.confidence;

        qsa(header, '.bcc-trust-header__vote-btn').forEach(function (b) {
            b.classList.remove('is-active');
            b.setAttribute('aria-pressed', 'false');
        });
        snap.activeVoteBtns.forEach(function (b) {
            b.classList.add('is-active');
            b.setAttribute('aria-pressed', 'true');
        });

        var endorseBtn = qs(header, '.bcc-trust-header__endorse-btn');
        if (endorseBtn) {
            endorseBtn.classList.toggle('is-active', snap.endorseActive);
            endorseBtn.setAttribute('aria-pressed', snap.endorseActive ? 'true' : 'false');
        }
    }

    function handleVote(header, btn) {
        if (btn.getAttribute('data-requires-login')) {
            var loginUrl = (window.bccTrust && window.bccTrust.login_url) || '/wp-login.php';
            window.location.href = loginUrl + '?redirect_to=' + encodeURIComponent(window.location.href);
            return;
        }

        // Double-click guard: ignore if a write is already in flight
        if (header._bccWriteInFlight) return;

        var pageId   = header.getAttribute('data-page-id');
        var pageIdInt = parseInt(pageId, 10);
        var voteType = parseInt(btn.getAttribute('data-vote'), 10);
        var isActive = btn.classList.contains('is-active');

        // Claim a version BEFORE the optimistic update so any stale
        // inject() from a prior request is silently discarded.
        var store = window.bccPageStore;
        var ver   = store && store.nextVersion ? store.nextVersion(pageIdInt) : undefined;

        // Snapshot DOM before optimistic update for clean rollback
        var snapshot = captureState(header);

        // Abort any previous in-flight request for this header
        if (header._bccAbort) header._bccAbort.abort();
        var abortCtrl = new AbortController();
        header._bccAbort = abortCtrl;

        // Lock: block subscriber-driven updateHeader() during flight.
        // Failsafe timer releases the lock after 15s if the request hangs.
        header._bccWriteInFlight = true;
        clearTimeout(header._bccWriteLockTimer);
        header._bccWriteLockTimer = setTimeout(function () {
            if (header._bccWriteInFlight) {
                abortCtrl.abort();
                header._bccWriteInFlight = false;
                restoreState(header, snapshot);
                showStatus(header, 'Request timed out', true);
                btn.disabled = false;
            }
        }, 15000);

        // Optimistic: toggle active state + aria-pressed
        if (isActive) {
            btn.classList.remove('is-active');
            btn.setAttribute('aria-pressed', 'false');
        } else {
            qsa(header, '.bcc-trust-header__vote-btn').forEach(function (b) {
                b.classList.remove('is-active');
                b.setAttribute('aria-pressed', 'false');
            });
            btn.classList.add('is-active');
            btn.setAttribute('aria-pressed', 'true');
        }

        // Only optimistically update count for vote removal (simple decrement).
        // For new votes / vote switches, let the server response set counts.
        if (isActive) {
            var countEl = btn.querySelector('.bcc-trust-header__count');
            if (countEl) {
                var cur = parseInt(countEl.textContent, 10) || 0;
                countEl.textContent = Math.max(0, cur - 1);
            }
        }

        btn.disabled = true;

        var endpoint = isActive ? 'remove-vote' : 'vote';
        var body     = isActive
            ? { page_id: pageIdInt, _wpnonce: getNonce() }
            : { page_id: pageIdInt, vote_type: voteType, _wpnonce: getNonce() };

        // Attach fingerprint if available
        if (!isActive && window.bccFingerprinter && window.bccFingerprinter.ready) {
            body.fingerprint = {
                hash: window.bccFingerprinter.fingerprint.hash,
                timestamp: Date.now(),
            };
        }

        fetch(getRestUrl() + endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: restHeaders(),
            body: JSON.stringify(body),
            signal: abortCtrl.signal,
        })
        .then(function (r) { return r.json(); })
        .then(function (result) {
            if (result.success && result.data && result.data.score) {
                applyResponseToHeader(header, result.data);
                var statusMsg = isActive ? 'Vote removed' : 'Vote recorded';
                if (!isActive && result.data.vesting && result.data.vesting.pct < 100) {
                    statusMsg += ' \u00B7 ' + result.data.vesting.pct + '% weight (grows over time)';
                }
                showStatus(header, statusMsg);
                header._bccWriteInFlight = false;
                if (store && store.inject) {
                    store.inject(pageIdInt, buildStorePayload(result.data), ver);
                }
            } else {
                header._bccWriteInFlight = false;
                restoreState(header, snapshot);
                showStatus(header, (result.message || 'Vote failed'), true);
            }
            if (window.jQuery) {
                jQuery(document).trigger(isActive ? 'bccTrustVoteRemoved' : 'bccTrustVoteCast', [pageIdInt, voteType, result]);
            }
        })
        .catch(function (err) {
            // Aborted requests: silently release lock, no rollback (teardown or superseded)
            if (err.name === 'AbortError') {
                header._bccWriteInFlight = false;
                return;
            }
            header._bccWriteInFlight = false;
            restoreState(header, snapshot);
            showStatus(header, err.message || 'Network error', true);
        })
        .finally(function () {
            clearTimeout(header._bccWriteLockTimer);
            header._bccAbort = null;
            btn.disabled = false;
        });
    }

    /* ----------------------------------------------------------
       Endorse handler (optimistic UI)
    ---------------------------------------------------------- */

    function handleEndorse(header, btn) {
        if (btn.getAttribute('data-requires-login')) {
            var loginUrl = (window.bccTrust && window.bccTrust.login_url) || '/wp-login.php';
            window.location.href = loginUrl + '?redirect_to=' + encodeURIComponent(window.location.href);
            return;
        }

        // Double-click guard
        if (header._bccWriteInFlight) return;

        var pageId    = header.getAttribute('data-page-id');
        var pageIdInt = parseInt(pageId, 10);
        var isActive  = btn.classList.contains('is-active');

        // Claim version before optimistic update
        var store = window.bccPageStore;
        var ver   = store && store.nextVersion ? store.nextVersion(pageIdInt) : undefined;

        // Snapshot DOM before optimistic update for clean rollback
        var snapshot = captureState(header);

        // Abort any previous in-flight request for this header
        if (header._bccAbort) header._bccAbort.abort();
        var abortCtrl = new AbortController();
        header._bccAbort = abortCtrl;

        // Lock with failsafe timeout
        header._bccWriteInFlight = true;
        clearTimeout(header._bccWriteLockTimer);
        header._bccWriteLockTimer = setTimeout(function () {
            if (header._bccWriteInFlight) {
                abortCtrl.abort();
                header._bccWriteInFlight = false;
                restoreState(header, snapshot);
                showStatus(header, 'Request timed out', true);
                btn.disabled = false;
            }
        }, 15000);

        // Optimistic toggle + aria-pressed
        btn.classList.toggle('is-active');
        btn.setAttribute('aria-pressed', btn.classList.contains('is-active') ? 'true' : 'false');
        var countEl = qs(header, '.bcc-trust-header__count--endorsements');
        if (countEl) {
            var cur = parseInt(countEl.textContent, 10) || 0;
            countEl.textContent = isActive ? Math.max(0, cur - 1) : cur + 1;
        }

        btn.disabled = true;

        var endpoint = isActive ? 'revoke-endorsement' : 'endorse';
        var body     = { page_id: pageIdInt, context: 'general', _wpnonce: getNonce() };

        if (!isActive && window.bccFingerprinter && window.bccFingerprinter.ready) {
            body.fingerprint = {
                hash: window.bccFingerprinter.fingerprint.hash,
                timestamp: Date.now(),
            };
        }

        fetch(getRestUrl() + endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: restHeaders(),
            body: JSON.stringify(body),
            signal: abortCtrl.signal,
        })
        .then(function (r) { return r.json(); })
        .then(function (result) {
            if (result.success && result.data && result.data.score) {
                applyResponseToHeader(header, result.data);
                showStatus(header, endorseMessage(header, isActive));
                header._bccWriteInFlight = false;
                if (store && store.inject) {
                    store.inject(pageIdInt, buildStorePayload(result.data), ver);
                }
            } else {
                header._bccWriteInFlight = false;
                restoreState(header, snapshot);
                showStatus(header, (result.message || 'Action failed'), true);
            }
            if (window.jQuery) {
                jQuery(document).trigger(isActive ? 'bccTrustEndorsementRevoked' : 'bccTrustEndorsed', [pageIdInt, result]);
            }
        })
        .catch(function (err) {
            if (err.name === 'AbortError') {
                header._bccWriteInFlight = false;
                return;
            }
            header._bccWriteInFlight = false;
            restoreState(header, snapshot);
            showStatus(header, err.message || 'Network error', true);
        })
        .finally(function () {
            clearTimeout(header._bccWriteLockTimer);
            header._bccAbort = null;
            btn.disabled = false;
        });
    }

    /* ----------------------------------------------------------
       Tooltip popover
    ---------------------------------------------------------- */

    function initPopover(header) {
        var infoBtn  = qs(header, '.bcc-trust-header__info-btn');
        var popover  = qs(header, '.bcc-trust-header__popover');
        var closeBtn = popover ? qs(popover, '.bcc-trust-header__popover-close') : null;

        if (!infoBtn || !popover) return;

        // Portal: move popover to document.body so it escapes all
        // parent stacking contexts (navbar, modal overlays, etc.).
        document.body.appendChild(popover);
        header._bccPopover = popover;

        function positionPopover() {
            var rect = infoBtn.getBoundingClientRect();
            var popW = 300; // matches CSS width
            var gap  = 8;

            // Prefer right-aligned below the button; flip left if it overflows viewport
            var top  = rect.bottom + gap;
            var left = rect.right - popW;

            if (left < 8) {
                left = rect.left;
            }

            // If it would overflow the bottom of the viewport, show above instead
            if (top + 250 > window.innerHeight) {
                top = rect.top - gap - 250;
                if (top < 8) top = 8;
            }

            popover.style.top  = top + 'px';
            popover.style.left = Math.max(8, left) + 'px';
        }

        function setPopoverOpen(open) {
            if (open) positionPopover();
            popover.hidden = !open;
            infoBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        infoBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            setPopoverOpen(popover.hidden);
        });

        if (closeBtn) {
            closeBtn.addEventListener('click', function () {
                setPopoverOpen(false);
            });
        }

        // Close on outside click
        document.addEventListener('click', function (e) {
            if (!popover.hidden && !popover.contains(e.target) && e.target !== infoBtn) {
                setPopoverOpen(false);
            }
        });

        // Close on Escape
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !popover.hidden) {
                setPopoverOpen(false);
                infoBtn.focus();
            }
        });

        // Reposition on scroll/resize while open
        var reposition = function () {
            if (!popover.hidden) positionPopover();
        };
        window.addEventListener('scroll', reposition, { passive: true });
        window.addEventListener('resize', reposition, { passive: true });
    }

    /* ----------------------------------------------------------
       Status message
    ---------------------------------------------------------- */

    function showStatus(header, msg, isError) {
        var el = qs(header, '.bcc-trust-header__status');
        if (!el) return;

        el.textContent = msg;
        el.style.color = isError ? '#f44336' : '#4caf50';
        el.style.opacity = '1';

        clearTimeout(el._tid);
        el._tid = setTimeout(function () {
            el.style.opacity = '0';
            setTimeout(function () { el.textContent = ''; }, 300);
        }, 3000);
    }

    /* ----------------------------------------------------------
       Init
    ---------------------------------------------------------- */

    function initHeader(header) {
        // Guard against double-init (PeepSo AJAX re-render)
        if (header._bccInitialized) return;
        header._bccInitialized = true;

        // Popover
        initPopover(header);

        var pageId = parseInt(header.getAttribute('data-page-id'), 10);
        var mode   = header.getAttribute('data-mode') || 'public';
        var store  = window.bccPageStore;

        // Hydrate with fresh data via the shared store (1 request, N blocks)
        if (store && pageId) {
            hydrate(header);

            // Subscribe and store the unsubscribe handle for cleanup
            var unsub = store.subscribe(pageId, function (data) {
                updateHeader(header, data);
            });

            // Stash cleanup handle so external code or future teardown can call it
            header._bccUnsub = unsub;
        }

        // Event delegation — bind interactive handlers for all interactive headers
        header.addEventListener('click', function (e) {
            var voteBtn    = e.target.closest('.bcc-trust-header__vote-btn');
            var endorseBtn = e.target.closest('.bcc-trust-header__endorse-btn');
            var flagBtn    = e.target.closest('.bcc-trust-header__flag-btn');
            var reportBtn  = e.target.closest('.bcc-trust-header__report-btn');

            if (voteBtn) {
                e.preventDefault();
                handleVote(header, voteBtn);
            } else if (endorseBtn) {
                e.preventDefault();
                handleEndorse(header, endorseBtn);
            } else if (flagBtn) {
                e.preventDefault();
                handleFlag(header, flagBtn, pageId);
            } else if (reportBtn) {
                e.preventDefault();
                handleReport(header, reportBtn);
            }
        });
    }

    /* ----------------------------------------------------------
       Flag handler
    ---------------------------------------------------------- */
    function handleFlag(header, btn, pageId) {
        if (btn.disabled) return;

        var action = btn.getAttribute('data-action') || 'flag';
        btn.disabled = true;

        var labelEl = qs(btn, '.bcc-trust-header__flag-label');
        var origLabel = labelEl ? labelEl.textContent : '';
        if (labelEl) labelEl.textContent = '...';

        wp.apiFetch({
            path: '/bcc/v1/flag',
            method: 'POST',
            data: { page_id: pageId, action: action }
        }).then(function (res) {
            btn.disabled = false;

            if (res.success) {
                var newAction = res.user_flagged ? 'unflag' : 'flag';
                btn.setAttribute('data-action', newAction);
                btn.setAttribute('aria-pressed', res.user_flagged ? 'true' : 'false');
                btn.classList.toggle('is-active', res.user_flagged);
                btn.title = res.user_flagged ? 'Remove public flag' : 'Publicly flag this page';

                if (labelEl) labelEl.textContent = res.user_flagged ? 'Flagged' : 'Flag Page';

                var countEl = qs(btn, '.bcc-trust-header__flag-count');
                if (res.flag_count > 0) {
                    if (!countEl) {
                        countEl = document.createElement('span');
                        countEl.className = 'bcc-trust-header__flag-count';
                        btn.appendChild(countEl);
                    }
                    countEl.textContent = res.flag_count;
                } else if (countEl) {
                    countEl.remove();
                }

                showStatus(header, res.user_flagged ? 'Page flagged' : 'Flag removed');
            } else {
                if (labelEl) labelEl.textContent = origLabel;
                showStatus(header, res.message || 'Error');
            }
        }).catch(function () {
            btn.disabled = false;
            if (labelEl) labelEl.textContent = origLabel;
            showStatus(header, 'Request failed');
        });
    }

    /* ----------------------------------------------------------
       Report handler
    ---------------------------------------------------------- */
    function handleReport(header, btn) {
        if (btn.disabled) return;

        var userId = btn.getAttribute('data-reported-user-id');
        if (!userId) return;

        // Simple inline prompt for reason
        var reasons = [
            { key: 'fraud', label: 'Fraud / Scam' },
            { key: 'spam', label: 'Spam' },
            { key: 'misinformation', label: 'Misinformation' },
            { key: 'impersonation', label: 'Impersonation' },
            { key: 'other', label: 'Other' },
        ];

        var reasonKey = null;
        var msg = 'Report this user for:\n';
        for (var i = 0; i < reasons.length; i++) {
            msg += (i + 1) + '. ' + reasons[i].label + '\n';
        }
        var choice = prompt(msg + '\nEnter number (1-' + reasons.length + '):');

        if (!choice) return;
        var idx = parseInt(choice, 10) - 1;
        if (idx < 0 || idx >= reasons.length) return;
        reasonKey = reasons[idx].key;

        // Always prompt for details. Required for 'other', optional for the
        // rest — but if provided, must meet the minimum length so the admin
        // review queue isn't flooded with one-word "spam" entries.
        var MIN_FLAG_DETAIL = 10; // UX-only gate; backend does not enforce a minimum
        var isOther = reasonKey === 'other';
        var detailPrompt = isOther
            ? 'Please describe the issue (required, at least ' + MIN_FLAG_DETAIL + ' characters):'
            : 'Add context for the moderator (optional, at least ' + MIN_FLAG_DETAIL + ' characters if provided):';

        var detail = prompt(detailPrompt);

        // User clicked Cancel → abort the whole report.
        if (detail === null) return;

        detail = detail.trim();

        if (isOther && detail.length < MIN_FLAG_DETAIL) {
            showStatus(header, 'Description must be at least ' + MIN_FLAG_DETAIL + ' characters');
            return;
        }
        if (!isOther && detail.length > 0 && detail.length < MIN_FLAG_DETAIL) {
            showStatus(header, 'If provided, details must be at least ' + MIN_FLAG_DETAIL + ' characters');
            return;
        }

        btn.disabled = true;

        wp.apiFetch({
            path: '/bcc/v1/report-user',
            method: 'POST',
            data: {
                reported_user_id: parseInt(userId, 10),
                reason_key: reasonKey,
                reason_detail: detail
            }
        }).then(function (res) {
            btn.disabled = false;
            showStatus(header, res.message || 'Report submitted');
        }).catch(function (err) {
            btn.disabled = false;
            showStatus(header, (err && err.message) || 'Report failed');
        });
    }

    /* ----------------------------------------------------------
       Boot
    ---------------------------------------------------------- */

    /**
     * IntersectionObserver: pause ring animation when panel scrolls offscreen.
     * Reduces GPU compositing cost with many panels on screen.
     */
    /**
     * IntersectionObserver: pause ring animation when panel scrolls offscreen.
     * Reduces GPU compositing cost with many panels on screen.
     *
     * Fallback: if IntersectionObserver is unavailable (old browsers),
     * the animation runs continuously — harmless, just slightly less efficient.
     *
     * Cleanup: call header._bccVisObserver.disconnect() on teardown
     * to prevent memory leaks on AJAX/SPA navigation.
     */
    function observeVisibility(header) {
        if (!('IntersectionObserver' in window)) return;

        var ring = qs(header, '.bcc-trust-header__ring-fill');
        if (!ring) return;

        var observer = new IntersectionObserver(function (entries) {
            for (var i = 0; i < entries.length; i++) {
                ring.style.animationPlayState = entries[i].isIntersecting ? 'running' : 'paused';
            }
        }, { threshold: 0 });

        observer.observe(header);
        header._bccVisObserver = observer;
    }

    /**
     * Teardown a header: unsubscribe from store, disconnect observer,
     * clear timers.  Call when the header DOM is about to be removed
     * (e.g., PeepSo AJAX navigation).
     */
    function teardownHeader(header) {
        // Abort any in-flight request
        if (header._bccAbort) {
            header._bccAbort.abort();
            header._bccAbort = null;
        }
        if (header._bccUnsub) {
            header._bccUnsub();
            header._bccUnsub = null;
        }
        if (header._bccVisObserver) {
            header._bccVisObserver.disconnect();
            header._bccVisObserver = null;
        }
        // Remove portaled popover from document.body
        if (header._bccPopover && header._bccPopover.parentNode) {
            header._bccPopover.parentNode.removeChild(header._bccPopover);
            header._bccPopover = null;
        }
        clearTimeout(header._bccWriteLockTimer);
        header._bccWriteInFlight = false;
        header._bccInitialized = false;
    }

    function boot() {
        var headers = document.querySelectorAll('.bcc-trust-header');
        headers.forEach(function (h) {
            initHeader(h);
            observeVisibility(h);
        });
    }

    // Expose teardown for AJAX/SPA navigation cleanup.
    // Call bccTrustHeader.teardown(el) before removing a header from the DOM.
    window.bccTrustHeader = {
        teardown: teardownHeader,
        boot: boot,
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();