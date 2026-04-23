/**
 * BCC Discovery Store
 *
 * Centralized data store for the discovery feed, following the same pattern
 * as bccPageStore: request deduplication, client-side caching, subscribe/update.
 *
 * Usage:
 *   var data = await bccDiscoveryStore.get(params);
 *   bccDiscoveryStore.subscribe(paramsKey, callback);
 *   bccDiscoveryStore.bust(paramsKey);
 */
(function () {
    'use strict';

    var ENDPOINT = (window.bccDiscoveryData && window.bccDiscoveryData.endpoint)
        || '/wp-json/bcc/v1/discover';
    var NONCE = (window.bccDiscoveryData && window.bccDiscoveryData.nonce) || '';

    var cache = {};
    var pending = {};
    var subscribers = {};
    var version = 0;

    function paramsKey(params) {
        return [
            params.types || '',
            params.sort || 'trust',
            params.verified_only ? '1' : '0',
            params.tier || '',
            params.min_confidence || 0,
            params.new_only ? '1' : '0',
            params.max_votes || 0,
            params.limit || 12,
            params.page || 1,
            params.search || ''
        ].join('|');
    }

    function buildUrl(params) {
        var url = ENDPOINT + '?limit=' + (params.limit || 12) + '&page=' + (params.page || 1);
        if (params.types)          url += '&types=' + encodeURIComponent(params.types);
        if (params.sort)           url += '&sort=' + encodeURIComponent(params.sort);
        if (params.verified_only)  url += '&verified_only=1';
        if (params.tier)           url += '&tier=' + encodeURIComponent(params.tier);
        if (params.min_confidence) url += '&min_confidence=' + params.min_confidence;
        if (params.new_only)       url += '&new_only=1';
        if (params.max_votes)      url += '&max_votes=' + params.max_votes;
        if (params.search)         url += '&search=' + encodeURIComponent(params.search);
        return url;
    }

    function get(params) {
        var key = paramsKey(params);

        if (cache[key]) {
            return Promise.resolve(cache[key]);
        }

        if (pending[key]) {
            return pending[key];
        }

        var headers = {};
        if (NONCE) headers['X-WP-Nonce'] = NONCE;

        var promise = fetch(buildUrl(params), { credentials: 'same-origin', headers: headers })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                cache[key] = data;
                delete pending[key];
                notify(key, data);
                return data;
            })
            .catch(function (err) {
                delete pending[key];
                throw err;
            });

        pending[key] = promise;
        return promise;
    }

    function bust(params) {
        var key = typeof params === 'string' ? params : paramsKey(params);
        delete cache[key];
        delete pending[key];
        version++;
    }

    function bustAll() {
        cache = {};
        pending = {};
        version++;
    }

    function subscribe(params, callback) {
        var key = typeof params === 'string' ? params : paramsKey(params);
        if (!subscribers[key]) subscribers[key] = [];
        subscribers[key].push(callback);
        return function unsubscribe() {
            subscribers[key] = (subscribers[key] || []).filter(function (cb) { return cb !== callback; });
        };
    }

    function notify(key, data) {
        (subscribers[key] || []).forEach(function (cb) {
            try { cb(data); } catch (e) { /* silent */ }
        });
    }

    window.bccDiscoveryStore = {
        get: get,
        bust: bust,
        bustAll: bustAll,
        subscribe: subscribe,
        paramsKey: paramsKey,
        nextVersion: function () { return ++version; }
    };
})();
