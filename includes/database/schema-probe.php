<?php
/**
 * Shared schema-introspection probe.
 *
 * ── WHY THIS IS ONE FUNCTION IN ONE FILE ────────────────────────────────
 * Every idempotent migration in this directory asks INFORMATION_SCHEMA the
 * same question — "does this column / table / index already exist?" — and
 * every one of them has to distinguish TWO answers that `wpdb::get_var()`
 * renders identically:
 *
 *     0     the object genuinely is not there  -> create it
 *     null  the probe itself failed            -> DO NOT create it
 *
 * Collapsing those is the bug this file exists to prevent. A dropped
 * connection, a permissions change, or a `prepare()` whose placeholder count
 * does not match its arguments all yield `null`; read as `0`, a migration
 * would try to re-create objects that already exist, error, and — depending
 * on the caller — report success anyway.
 *
 * Treating an inspection error as NOT absence is the fail-closed direction:
 * the migration does nothing this request and picks the work up on the next
 * one, when the database is readable again.
 *
 * @package BCC\Trust\Onchain
 * @subpackage Database
 * @since PR 2 — per-chain NFT capability model
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_onchain_probe_count')) {
    /**
     * Run a COUNT(*) INFORMATION_SCHEMA probe and distinguish "zero" from
     * "could not tell".
     *
     * A COUNT(*) that executes always yields a row containing a number, so a
     * `null` from `get_var()` means the QUERY failed rather than that the
     * count was zero.
     *
     * @param string           $sql  a prepared-statement template
     * @param list<string|int> $args placeholder arguments
     * @return int|null count, or null when the probe itself failed
     */
    function bcc_onchain_probe_count(string $sql, array $args): ?int {
        global $wpdb;

        $prepared = $wpdb->prepare($sql, ...$args);

        // wpdb::prepare() returns an empty string when the placeholder count
        // does not match the argument count. Handing that to get_var() would
        // query nothing and read as "absent" — the precise failure this
        // function exists to make impossible.
        if (!is_string($prepared) || $prepared === '') {
            return null;
        }

        $value = $wpdb->get_var($prepared);

        return $value === null ? null : (int) $value;
    }
}
