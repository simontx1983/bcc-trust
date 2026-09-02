<?php
/**
 * The schema-version completion contract.
 *
 * ── THE DEFECT THIS FILE EXISTS FOR ─────────────────────────────────────
 * `bcc_trust_schema_version` is the ONLY thing that decides whether the
 * installer runs again. The gate is:
 *
 *     stored === computed  ->  do nothing
 *     otherwise            ->  run every installer, then stamp
 *
 * and the stamp used to be unconditional. `bcc_onchain_ensure_schema()`
 * returned void, so a migration that bailed out — an unreadable probe, a
 * refused ALTER, a backfill that could not verify its postcondition — was
 * followed by a stamp saying the schema was current. The next request found
 * `stored === computed` and never tried again.
 *
 * That made the PR 6 migration's "resumable" claim false in the one case
 * resumability is for: it was resumable only if something happened to bump
 * the version again, and nothing would, because the version is a content
 * hash of files that had not changed.
 *
 * ── WHY THE STAMP LIVES HERE AND NOT IN A CLOSURE ───────────────────────
 * It was inside an anonymous `plugins_loaded` callback in bcc-trust.php,
 * where no test can reach it. A rule that decides whether a failed migration
 * is retried is worth being able to test, so it is a named function in a
 * file the integration bootstrap can require on its own.
 *
 * ── SCOPE ───────────────────────────────────────────────────────────────
 * Narrow, deliberately. Only the PR 6 provisioning migration reports
 * completion; every historical installer keeps its existing void semantics
 * and is unchanged. Redesigning all of them is a separate piece of work with
 * its own risk, and doing it here would bury this fix inside it.
 *
 * @package BCC\Trust
 * @subpackage Database
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_stamp_schema_version')) {
    /**
     * Record the schema version — but ONLY if the migrations that report
     * completion actually completed.
     *
     * ── WHY A REFUSAL IS NOT AN ERROR ───────────────────────────────────
     * Declining to stamp costs one extra installer pass per request until
     * the underlying problem clears. Every step is probe-guarded and
     * idempotent, so that pass is cheap and safe — and it is the entire
     * mechanism by which a partial migration heals itself. Stamping a
     * version the schema has not reached is the expensive mistake: it is
     * silent, permanent, and only visible later as a column that should
     * exist and does not.
     *
     * @param bool   $migrationsComplete did every reporting migration finish
     *                                   AND verify its postconditions?
     * @param string $computed           the version to stamp
     * @return bool true when the version was advanced
     */
    function bcc_trust_stamp_schema_version(bool $migrationsComplete, string $computed): bool
    {
        if (!$migrationsComplete) {
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error(
                    '[bcc-trust] schema version NOT advanced — a reporting migration did not complete. '
                    . 'The installer will run again on the next request; every step is probe-guarded and idempotent.',
                    ['computed' => $computed]
                );
            }

            return false;
        }

        update_option('bcc_trust_schema_version', $computed, false);

        // Verify the write actually landed where the next request will read
        // it. A poisoned persistent object cache makes update_option succeed
        // while get_option keeps returning the old value — the exact failure
        // mode that turns this gate into a per-request tax.
        wp_cache_delete('bcc_trust_schema_version', 'options');
        wp_cache_delete('alloptions', 'options');

        $reread = get_option('bcc_trust_schema_version', '');
        if ($reread !== $computed) {
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error(
                    '[bcc-trust] schema version write did not stick — object cache likely stale',
                    ['reread' => $reread, 'computed' => $computed]
                );
            }

            return false;
        }

        return true;
    }
}
