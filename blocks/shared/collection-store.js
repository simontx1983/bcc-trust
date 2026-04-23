/**
 * BCC Collection Store
 *
 * Centralized data store for NFT collection data, following the same pattern
 * as bccPageStore: request deduplication, per-chain caching, subscribe/update.
 *
 * Usage:
 *   var data = await bccCollectionStore.get(chain, page, perPage);
 *   bccCollectionStore.subscribe(chain, callback);
 *   bccCollectionStore.bust(chain);
 */
(function () {
    'use strict';

    var ENDPOINT = (window.bccCollectionData && window.bccCollectionData.restUrl)
        || (window.bccNftLb && window.bccNftLb.restUrl)
        || '/wp-json/bcc/v1/nft/collections';
    var NONCE = (window.bccCollectionData && window.bccCollectionData.nonce)
        || (window.bccNftLb && window.bccNftLb.nonce)
        || '';

    var cache = {};
    var pending = {};
    var subscribers = {};
    var version = 0;

    function cacheKey(chain, page, perPage) {
        return chain + ':' + page + ':' + perPage;
    }

    function get(chain, page, perPage) {
        page = page || 1;
        perPage = perPage || 20;
        var key = cacheKey(chain, page, perPage);

        if (cache[key]) {
            return Promise.resolve(cache[key]);
        }

        if (pending[key]) {
            return pending[key];
        }

        var url = ENDPOINT + '?chain=' + encodeURIComponent(chain)
            + '&per_page=' + perPage
            + '&page=' + page;

        var headers = {};
        if (NONCE) headers['X-WP-Nonce'] = NONCE;

        var promise = fetch(url, { credentials: 'same-origin', headers: headers })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                cache[key] = data;
                delete pending[key];
                notify(chain, data);
                return data;
            })
            .catch(function (err) {
                delete pending[key];
                throw err;
            });

        pending[key] = promise;
        return promise;
    }

    function bust(chain) {
        Object.keys(cache).forEach(function (key) {
            if (key.indexOf(chain + ':') === 0) {
                delete cache[key];
            }
        });
        Object.keys(pending).forEach(function (key) {
            if (key.indexOf(chain + ':') === 0) {
                delete pending[key];
            }
        });
        version++;
    }

    function bustAll() {
        cache = {};
        pending = {};
        version++;
    }

    function subscribe(chain, callback) {
        if (!subscribers[chain]) subscribers[chain] = [];
        subscribers[chain].push(callback);
        return function unsubscribe() {
            subscribers[chain] = (subscribers[chain] || []).filter(function (cb) { return cb !== callback; });
        };
    }

    function notify(chain, data) {
        (subscribers[chain] || []).forEach(function (cb) {
            try { cb(data); } catch (e) { /* silent */ }
        });
    }

    window.bccCollectionStore = {
        get: get,
        bust: bust,
        bustAll: bustAll,
        subscribe: subscribe,
        nextVersion: function () { return ++version; }
    };
})();
