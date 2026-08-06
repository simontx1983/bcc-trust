<?php
/**
 * Pending-data-migration runner.
 *
 * WHY THIS EXISTS
 * ---------------
 * The two one-shot data backfills — canonical-handle assignment and the
 * wallet placeholder-email rewrite — were only ever invoked from
 * `bcc_trust_create_tables()`, which the bootstrap fires *only when
 * `BCC_TRUST_SCHEMA_VERSION` changes* (an md5 of the `schema-*.php` files).
 * A pull request that adds a backfill but touches no schema file therefore
 * leaves it DORMANT: the version hash is unchanged, the schema gate
 * early-returns, and the migration never runs on a files-only deploy. That
 * is exactly what happened to the wallet backfill — it shipped in the merge
 * but never executed, because nothing bumped the schema hash.
 *
 * This runner decouples data migrations from the schema-version gate. It
 * runs from the ordinary `plugins_loaded` hook on every request, but is a
 * negligible-cost no-op once its work is done (a single autoloaded option
 * read short-circuits the whole thing).
 *
 * GUARANTEES
 * ----------
 *  - Runs independently of BCC_TRUST_SCHEMA_VERSION (files-only deploy is enough).
 *  - Explicit, deterministic order (the registry array order).
 *  - Each migration has its own durable completion option; completions are
 *    independent — one migration failing never marks another complete.
 *  - A migration is marked complete ONLY when it reports every eligible row
 *    processed successfully. Any failure (or a batch-cap hit with rows still
 *    remaining) leaves it INCOMPLETE and retryable on the next request.
 *  - A crash-safe, session-scoped advisory lock per migration
 *    ({@see \BCC\Core\DB\AdvisoryLock}, GET_LOCK) stops two concurrent
 *    requests from processing the same migration. MySQL releases the lock
 *    automatically if the worker dies, so a crash cannot wedge it forever.
 *  - Work is bounded per request by each migration's own deterministic
 *    batching; progress is persisted (rewritten rows / assigned meta), so a
 *    partial run resumes on the next request.
 *
 * SCHEMA-PATH COMPATIBILITY
 * -------------------------
 * `bcc_trust_create_tables()` also routes through this same runner (see
 * tables.php), so the schema-install path and the runtime path share one
 * idempotent implementation and one lock — they cannot race or double-mark.
 *
 * UPGRADE FROM THE DORMANT IMPLEMENTATION
 * ---------------------------------------
 * On the first request after this ships, the runner sees each migration's
 * completion option and acts: the wallet backfill (option unset, never ran)
 * executes and completes; the canonical-handle backfill (option already set
 * on most environments from a past schema deploy) is a no-op. Completion
 * option NAMES are unchanged, so already-completed migrations stay completed.
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since 2026-07-24 (dormant-backfill remediation)
 */

if (!defined('ABSPATH')) {
    exit;
}

/** A migration reports one of these from its callback. */
if (!defined('BCC_TRUST_MIGRATION_COMPLETE')) {
    define('BCC_TRUST_MIGRATION_COMPLETE', 'complete');
}
if (!defined('BCC_TRUST_MIGRATION_INCOMPLETE')) {
    define('BCC_TRUST_MIGRATION_INCOMPLETE', 'incomplete');
}

if (!function_exists('bcc_trust_pending_migrations')) {

    /**
     * The migration registry, in explicit execution order.
     *
     * Each entry:
     *   - id          — stable, version-carrying identifier. Bump the `_vN`
     *                   suffix to force a re-evaluation (it changes the
     *                   fast-path signature); a genuinely re-runnable
     *                   migration would also point at a fresh done_option.
     *   - done_option — durable wp_options key marking completion. NAMES ARE
     *                   PRESERVED from the original backfills for upgrade
     *                   compatibility.
     *   - callback    — a callable returning BCC_TRUST_MIGRATION_COMPLETE
     *                   when every eligible row is processed, or
     *                   BCC_TRUST_MIGRATION_INCOMPLETE otherwise.
     *
     * Order: canonical-handles first (matches the historical
     * create_tables() order), wallet placeholder-emails second. They are
     * independent, but the order is fixed and deterministic.
     *
     * @return list<array{id: string, done_option: string, callback: callable}>
     */
    function bcc_trust_pending_migrations(): array
    {
        return [
            [
                'id'          => 'canonical_handles_v1',
                'done_option' => 'bcc_trust_canonical_handles_backfilled',
                'callback'    => 'bcc_trust_backfill_canonical_handles',
            ],
            [
                'id'          => 'wallet_placeholder_emails_v1',
                'done_option' => 'bcc_trust_wallet_placeholder_emails_backfilled',
                'callback'    => 'bcc_trust_backfill_wallet_placeholder_emails',
            ],
            [
                'id'          => 'elite_eligibility_backfill_v1',
                'done_option' => 'bcc_trust_elite_eligibility_backfilled',
                'callback'    => 'bcc_trust_backfill_elite_eligibility',
            ],
            [
                'id'          => 'watch_tier_vocabulary_v1',
                'done_option' => 'bcc_trust_watch_tier_vocabulary_migrated',
                'callback'    => 'bcc_trust_backfill_watch_tier_vocabulary',
            ],
            [
                'id'          => 'cleanup_last_login_usermeta_v1',
                'done_option' => 'bcc_trust_last_login_usermeta_cleaned',
                'callback'    => 'bcc_trust_cleanup_last_login_usermeta',
            ],
            [
                'id'          => 'cleanup_feature_override_usermeta_v1',
                'done_option' => 'bcc_trust_feature_override_usermeta_cleaned',
                'callback'    => 'bcc_trust_cleanup_feature_override_usermeta',
            ],
            [
                'id'          => 'cleanup_last_seen_rank_usermeta_v1',
                'done_option' => 'bcc_trust_last_seen_rank_usermeta_cleaned',
                'callback'    => 'bcc_trust_cleanup_last_seen_rank_usermeta',
            ],
            [
                'id'          => 'cleanup_dispute_panel_v1',
                'done_option' => 'bcc_trust_dispute_panel_cleaned',
                'callback'    => 'bcc_trust_cleanup_dispute_panel',
            ],
            [
                'id'          => 'cleanup_dispute_participations_v1',
                'done_option' => 'bcc_trust_dispute_participations_cleaned',
                'callback'    => 'bcc_trust_cleanup_dispute_participations',
            ],
            [
                'id'          => 'purge_edge_cache_rest_exclusion_v1',
                'done_option' => 'bcc_trust_edge_cache_rest_exclusion_purged',
                'callback'    => 'bcc_trust_purge_edge_cache_for_rest_exclusion',
            ],
            [
                'id'          => 'display_name_placeholders_v1',
                'done_option' => 'bcc_trust_display_name_placeholders_backfilled',
                'callback'    => 'bcc_trust_backfill_display_name_placeholders',
            ],
        ];
    }

    /**
     * Run every pending migration in order. Cheap no-op once all are done.
     *
     * Signature is `mixed` on purpose: this is a `plugins_loaded` callback,
     * and WordPress invokes hook callbacks with the action's first argument
     * (an empty string for plugins_loaded). Anything that is not an
     * explicitly-injected registry array is ignored and the real registry is
     * used — so the hook call and the test-injection call both work, and a
     * mis-registered accepted_args cannot fatal.
     *
     * @param mixed $migrations Test-only registry override
     *        (list<array{id: string, done_option: string, callback: callable}>);
     *        any non-array (incl. WP's hook argument) falls back to the registry.
     */
    function bcc_trust_run_pending_migrations($migrations = null): void
    {
        /** @var list<array{id: string, done_option: string, callback: callable}> $migrations */
        $migrations = is_array($migrations) ? $migrations : bcc_trust_pending_migrations();

        // Fast path: one autoloaded read. When every registered migration is
        // complete AND the registry is unchanged, short-circuit entirely.
        // The signature includes the ids, so adding a migration invalidates
        // the marker and forces a re-evaluation.
        $signature = bcc_trust_migrations_signature($migrations);
        if (get_option('bcc_trust_migrations_all_done_signature') === $signature) {
            return;
        }

        $allDone = true;
        foreach ($migrations as $migration) {
            bcc_trust_execute_migration($migration);
            if (!get_option($migration['done_option'])) {
                $allDone = false;
            }
        }

        if ($allDone) {
            // Autoloaded so the next request's fast-path read is free even
            // when the individual completion options are autoload=no (as the
            // pre-existing ones were).
            update_option('bcc_trust_migrations_all_done_signature', $signature, true);
        }
    }

    /**
     * Execute a single migration: guard → lock → run → complete-on-success.
     *
     * @param array{id: string, done_option: string, callback: callable} $migration
     */
    function bcc_trust_execute_migration(array $migration): void
    {
        $doneOption = (string) $migration['done_option'];

        // 1. Already complete → nothing to do.
        if (get_option($doneOption)) {
            return;
        }

        // 2. Migration code not loaded (defensive) → skip, stay retryable.
        if (!is_callable($migration['callback'])) {
            return;
        }

        // 3. Crash-safe, session-scoped, non-blocking lock. If another
        //    request holds it, that request is processing this migration —
        //    skip this pass rather than double-process.
        $lockKey = 'bcc_trust_mig_' . (string) $migration['id'];
        if (!bcc_trust_migration_lock_acquire($lockKey)) {
            return;
        }

        try {
            // 4. Re-check under the lock: a peer may have completed it between
            //    step 1 and acquiring the lock.
            if (get_option($doneOption)) {
                return;
            }

            // 5. Run one bounded batch. The migration persists its own
            //    progress, so an INCOMPLETE result resumes next request.
            $status = (string) call_user_func($migration['callback']);

            // 6. Mark complete ONLY on a fully-successful drain. Autoload=yes
            //    keeps future no-op checks free.
            if ($status === BCC_TRUST_MIGRATION_COMPLETE) {
                update_option($doneOption, time(), true);
            }
        } finally {
            // Always release — including on a thrown error — so a caught
            // failure never wedges the lock for the rest of the request.
            bcc_trust_migration_lock_release($lockKey);
        }
    }

    /**
     * Deterministic fingerprint of the registry (its ids). Used only for the
     * autoloaded all-done fast-path marker.
     *
     * @param list<array{id: string, done_option: string, callback: callable}> $migrations
     */
    function bcc_trust_migrations_signature(array $migrations): string
    {
        $ids = array_map(
            static fn(array $m): string => (string) $m['id'],
            $migrations
        );
        sort($ids);
        return substr(md5(implode('|', $ids)), 0, 12);
    }

    /**
     * Acquire the per-migration advisory lock.
     *
     * Reuses {@see \BCC\Core\DB\AdvisoryLock} (MySQL GET_LOCK) — the same
     * crash-safe primitive the cron dedup and schema-migration guards use.
     * Session-scoped: MySQL frees it automatically if the PHP worker dies,
     * so a crash mid-migration cannot block execution permanently. Falls
     * back to "not acquired" (skip) if the lock class is unavailable, which
     * keeps the migration retryable rather than running unguarded.
     */
    function bcc_trust_migration_lock_acquire(string $key): bool
    {
        if (!class_exists('\\BCC\\Core\\DB\\AdvisoryLock')) {
            return false;
        }
        return \BCC\Core\DB\AdvisoryLock::acquire($key, 0);
    }

    function bcc_trust_migration_lock_release(string $key): void
    {
        if (class_exists('\\BCC\\Core\\DB\\AdvisoryLock')) {
            \BCC\Core\DB\AdvisoryLock::release($key);
        }
    }
}
