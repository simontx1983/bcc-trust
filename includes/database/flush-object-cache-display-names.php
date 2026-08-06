<?php
/**
 * One-shot object-cache flush — display-name backfill companion
 * (2026-08-06).
 *
 * The v2 display-name backfill wrote wp_users directly WITHOUT
 * clean_user_cache(), so on hosts with a persistent object cache
 * (LSMCD) already-cached WP_User objects kept serving the old leaky
 * display_name from get_userdata() after the DB was rewritten. The
 * callback now cleans per-user for environments where it hasn't run
 * yet (production); this migration heals environments where v2
 * ALREADY completed against a warm cache (staging) by flushing the
 * object cache once. Deliberately blunt for the same reason the
 * edge-cache purge was: a one-time rebuild beats indefinitely stale
 * identity data. Silent no-op where no persistent backend exists.
 *
 * Status contract: wp_cache_flush() is fire-and-forget → completes
 * unconditionally.
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since 2026-08-06 (display-name hygiene follow-up)
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_flush_object_cache_for_display_names')) {

    function bcc_trust_flush_object_cache_for_display_names(): string
    {
        wp_cache_flush();
        return BCC_TRUST_MIGRATION_COMPLETE;
    }
}
