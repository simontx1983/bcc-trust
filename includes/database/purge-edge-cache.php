<?php
/**
 * One-shot edge-cache purge — rollout companion to the BCC REST
 * edge-cache exclusion (EdgeCache::init, owner-directed 2026-08-06).
 *
 * The exclusion only affects responses generated AFTER it deploys;
 * entries cached before it (REST bodies stored without CORS headers
 * when primed Origin-less, and 404s captured during the 2026-08-05
 * deactivation incident) would otherwise persist for the remainder of
 * `cache-ttl_rest` — up to a WEEK on production. Purging everything
 * once at rollout is deliberate bluntness: LSCWP rebuilds page cache
 * organically, and the REST tier is no longer cached at all.
 *
 * Status contract (migration-runner callback): LSCWP's purge action is
 * fire-and-forget (silent no-op where LSCWP is absent — Local, CI), so
 * this completes unconditionally.
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since 2026-08-06 (edge-cache REST exclusion rollout)
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_purge_edge_cache_for_rest_exclusion')) {

    function bcc_trust_purge_edge_cache_for_rest_exclusion(): string
    {
        \BCC\Trust\Infrastructure\EdgeCache::purgeAll();
        return BCC_TRUST_MIGRATION_COMPLETE;
    }
}
