/**
 * BCC Page Data Store
 *
 * Single source of truth for page data on the frontend.
 * Fetches once from /bcc/v1/page/{id}, caches the result,
 * and lets every block on the page share the same response.
 *
 * Key guarantees:
 *   1. Multiple .get(id) calls resolve from cache (0 extra requests).
 *   2. Concurrent .get(id) calls while a fetch is in-flight share
 *      the same Promise (request deduplication).
 *   3. .bust(id) clears the cache, auto-refetches, and pushes
 *      fresh data to every subscriber via the callback + a DOM event.
 *
 * Usage (any block):
 *   // Read (fires at most 1 request per page-id per page-load)
 *   var data = await bccPageStore.get(123);
 *
 *   // Subscribe to changes (vote/endorse from any block)
 *   bccPageStore.subscribe(123, function (freshData) {
 *       updateMyUI(freshData);
 *   });
 *
 *   // After a mutation (vote, endorse) — bust + auto-notify
 *   bccPageStore.bust(123);
 */
(function () {
    'use strict';

    var config  = window.bccPageData || {};
    var baseUrl = config.endpoint || '/wp-json/bcc/v1/page/';
    var nonce   = config.nonce    || '';

    var cache       = {};   // pageId → resolved data
    var pending     = {};   // pageId → Promise (deduplicates in-flight)
    var subscribers = {};   // pageId → [ callback, … ]
    var version     = {};   // pageId → monotonic counter (prevents stale overwrites)

    /* ----------------------------------------------------------
       Core: fetch (or return cached)
    ---------------------------------------------------------- */

    function fetchPage(pageId) {
        if (cache[pageId]) {
            return Promise.resolve(cache[pageId]);
        }

        if (pending[pageId]) {
            return pending[pageId];
        }

        var headers = {};
        if (nonce) {
            headers['X-WP-Nonce'] = nonce;
        }

        pending[pageId] = fetch(baseUrl + pageId, {
            credentials: 'same-origin',
            headers: headers,
        })
        .then(function (res) {
            if (!res.ok) {
                return res.json().then(function (body) {
                    throw new Error(body.message || 'HTTP ' + res.status);
                });
            }
            return res.json();
        })
        .then(function (data) {
            cache[pageId] = data;
            delete pending[pageId];
            return data;
        })
        .catch(function (err) {
            delete pending[pageId];
            throw err;
        });

        return pending[pageId];
    }

    /* ----------------------------------------------------------
       Bust: clear cache, re-fetch, notify all subscribers
    ---------------------------------------------------------- */

    function bust(pageId) {
        delete cache[pageId];
        delete pending[pageId];

        // Capture the version at bust-time.  If a write (inject with
        // a higher version) lands while the re-fetch is in-flight,
        // discard the stale fetch result to avoid overwriting it.
        var bustVer = version[pageId] || 0;

        fetchPage(pageId)
            .then(function (data) {
                // If a versioned inject landed while we were fetching,
                // skip notification — the inject already pushed fresh data.
                if (version[pageId] && version[pageId] > bustVer) {
                    return;
                }
                notify(pageId, data);
            })
            .catch(function () { /* silent — subscribers won't fire */ });
    }

    /* ----------------------------------------------------------
       Inject: merge authoritative data into the cache without
       re-fetching.  Used after a write (vote/endorse) when the
       API response contains updated trust state.

       CRITICAL: Must MERGE into the existing cache entry, not
       replace it.  The write response only contains {trust}
       fields — a full replacement would destroy {verification},
       {profiles}, and other fields cached from the initial GET.
    ---------------------------------------------------------- */

    /**
     * Bump and return the next version for a page.
     * Call this BEFORE starting an optimistic write so you can tag
     * the request.  Pass the returned version to inject() — any
     * inject() with a lower version is silently discarded.
     *
     * @param {number} pageId
     * @returns {number} The new version number
     */
    function nextVersion(pageId) {
        version[pageId] = (version[pageId] || 0) + 1;
        return version[pageId];
    }

    /**
     * Inject authoritative data into the cache without re-fetching.
     *
     * @param {number} pageId
     * @param {Object} data     - Partial store-shaped object to merge
     * @param {number} [ver]    - Version tag from nextVersion().
     *                            If supplied and older than the current
     *                            version, the inject is silently skipped
     *                            (stale response protection).
     */
    function inject(pageId, data, ver) {
        // Staleness guard: discard if a newer version has been issued.
        if (ver !== undefined && version[pageId] && ver < version[pageId]) {
            return; // stale response — skip
        }

        var existing = cache[pageId] || {};
        var merged   = {};
        var key;

        // Shallow-copy existing top-level keys
        for (key in existing) {
            if (existing.hasOwnProperty(key)) {
                merged[key] = existing[key];
            }
        }

        // Overwrite only the keys present in data
        for (key in data) {
            if (data.hasOwnProperty(key)) {
                // Skip null/undefined — don't overwrite existing data with nothing.
                // This prevents viewer:null from erasing a previously-fetched viewer.
                if (data[key] != null) {
                    merged[key] = data[key];
                }
            }
        }

        cache[pageId] = merged;
        delete pending[pageId];
        notify(pageId, merged);

        // Broadcast to other tabs if this was a local versioned write
        if (ver !== undefined) {
            broadcast(pageId, data);
        }
    }

    /* ----------------------------------------------------------
       Subscribe / Notify
    ---------------------------------------------------------- */

    /**
     * Subscribe to changes for a page.
     * Returns an unsubscribe function — call it to remove this callback
     * and prevent memory leaks on AJAX/SPA navigation.
     *
     * @param {number}   pageId
     * @param {Function} callback
     * @returns {Function} unsubscribe
     */
    function subscribe(pageId, callback) {
        if (typeof callback !== 'function') return function () {};
        if (!subscribers[pageId]) {
            subscribers[pageId] = [];
        }
        subscribers[pageId].push(callback);

        // Return unsubscribe handle
        var removed = false;
        return function unsubscribe() {
            if (removed) return;
            removed = true;
            var cbs = subscribers[pageId];
            if (!cbs) return;
            var idx = cbs.indexOf(callback);
            if (idx !== -1) cbs.splice(idx, 1);
        };
    }

    function notify(pageId, data) {
        // 1. Fire registered callbacks.
        var cbs = subscribers[pageId] || [];
        for (var i = 0; i < cbs.length; i++) {
            try { cbs[i](data); } catch (e) { /* don't break the loop */ }
        }

        // 2. Dispatch a DOM event so blocks that prefer event listeners
        //    (or blocks loaded after the store) can also react.
        document.dispatchEvent(new CustomEvent('bcc:trust-data-changed', {
            detail: { pageId: pageId, data: data },
        }));
    }

    /* ----------------------------------------------------------
       Public API
    ---------------------------------------------------------- */

    /**
     * Build a page-store-shaped payload from a write API response.
     * Shared by trust-header.js and trust-frontend.js so the mapping
     * is defined once.
     *
     * @param {Object} d - result.data from a vote/endorse write endpoint
     * @returns {Object} Store-shaped object suitable for inject()
     */
    function buildStorePayload(d) {
        var s = d.score || {};
        return {
            trust: {
                score:         s.total_score       !== undefined ? s.total_score       : 50,
                grade:         s.grade              || null,
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

    /* ----------------------------------------------------------
       Cross-tab sync via BroadcastChannel (optional enhancement).
       When a write lands in one tab, other tabs receive the update
       and merge it through inject() with version awareness.
    ---------------------------------------------------------- */

    var channel = null;
    try {
        if (typeof BroadcastChannel !== 'undefined') {
            channel = new BroadcastChannel('bcc_trust_store');
            channel.onmessage = function (e) {
                var msg = e.data;
                if (msg && msg.type === 'inject' && msg.pageId && msg.payload) {
                    // Accept external updates — they bypass versioning since
                    // the originating tab already validated. Force-apply to
                    // keep this tab's cache current.
                    var existing = cache[msg.pageId] || {};
                    var merged   = {};
                    var key;
                    for (key in existing) {
                        if (existing.hasOwnProperty(key)) merged[key] = existing[key];
                    }
                    for (key in msg.payload) {
                        if (msg.payload.hasOwnProperty(key) && msg.payload[key] != null) {
                            merged[key] = msg.payload[key];
                        }
                    }
                    cache[msg.pageId] = merged;
                    notify(msg.pageId, merged);
                }
            };
        }
    } catch (e) { /* BroadcastChannel not available — single-tab mode */ }

    /**
     * Broadcast a payload to other tabs after a successful inject.
     * Only called for local writes (not for received broadcasts).
     */
    function broadcast(pageId, payload) {
        if (channel) {
            try {
                channel.postMessage({ type: 'inject', pageId: pageId, payload: payload });
            } catch (e) { /* quota exceeded or channel closed — ignore */ }
        }
    }

    window.bccPageStore = {
        get:               fetchPage,
        bust:              bust,
        inject:            inject,
        subscribe:         subscribe,
        buildStorePayload: buildStorePayload,
        nextVersion:       nextVersion,
    };

    // Backward-compat aliases (remove after one release)
    window.bccProjectStore = window.bccPageStore;
})();
