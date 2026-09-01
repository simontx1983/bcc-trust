<?php
/**
 * Locked reads and writes for the Solana gate-identity repair.
 *
 * ── WHY A REPOSITORY AND NOT SQL IN THE SERVICE ─────────────────────────
 * §1: raw `$wpdb` lives in repositories. Everything here is deliberately
 * dumb — it locks, reads and writes exactly what it is told. Every
 * decision (what is a precondition, what counts as already-applied, what
 * rolls back) belongs to {@see \BCC\Trust\Onchain\Repair\SolanaGateIdentityRepairService}.
 *
 * ── WHY NOT ITS OWN TRANSACTION CONTROL ─────────────────────────────────
 * `TransactionManager` already owns BEGIN/COMMIT/ROLLBACK/SAVEPOINT, with
 * stale-depth recovery and deadlock retry, and carries the documented
 * arch-guardrails exemption for it. Opening a second transaction here
 * would nest silently — MySQL has no nested transactions, so an inner
 * `START TRANSACTION` COMMITS the outer one, which is precisely how a
 * "rolled back" repair would quietly become permanent.
 *
 * So this class NEVER opens a transaction. Instead every locking read
 * ASSERTS it is already inside one: `SELECT … FOR UPDATE` under autocommit
 * is a silent no-op that returns the right rows while taking no lock, so
 * without this guard the code would look correct, pass tests, and lose
 * races in production.
 *
 * @package BCC\Trust\Onchain\Repositories
 * @since PR 5b — Solana holder-gate identity repair
 */

namespace BCC\Trust\Onchain\Repositories;

use BCC\Trust\Core\Security\TransactionManager;

if (!defined('ABSPATH')) {
    exit;
}

final class GateIdentityRepairRepository
{
    /**
     * Guard shared by every locking read here.
     *
     * @throws \RuntimeException when called outside TransactionManager::run().
     */
    private static function requireTransaction(string $method): void
    {
        if (!TransactionManager::isInRunTransaction()) {
            throw new \RuntimeException(
                'GateIdentityRepairRepository::' . $method . '() requires TransactionManager::run() — '
                . 'SELECT … FOR UPDATE outside a transaction takes no lock and silently succeeds.'
            );
        }
    }

    /**
     * Lock and read the collection row.
     *
     * Explicit column list (§2), single-row unique-key filter (§4).
     *
     * @return object{
     *     id: string,
     *     chain_id: string,
     *     contract_address: string,
     *     canonical_identifier: string|null,
     *     is_verified: string,
     *     source: string
     * }|null
     */
    public static function lockCollection(int $collectionId): ?object
    {
        self::requireTransaction('lockCollection');

        if ($collectionId <= 0) {
            return null;
        }

        global $wpdb;
        $table = CollectionRepository::table();

        /** @var object{id: string, chain_id: string, contract_address: string, canonical_identifier: string|null, is_verified: string, source: string}|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, chain_id, contract_address, canonical_identifier, is_verified, source
               FROM {$table}
              WHERE id = %d
              LIMIT 1
                FOR UPDATE",
            $collectionId
        ));

        return $row;
    }

    /**
     * Lock and read EVERY postmeta row for one post and one meta key.
     *
     * Returns all matching rows, not just the first, because "exactly one
     * value per key" is a precondition the caller must be able to CHECK.
     * `get_post_meta(..., true)` would hand back the first of two and hide
     * the contradiction — the caller would then repair a gate whose real
     * configuration is ambiguous.
     *
     * Bounded (§4): LIMIT 10 is far above the legitimate 1, and its purpose
     * is to cap a pathological read, not to sample.
     *
     * @return list<object{meta_id: string, meta_value: string}>
     */
    public static function lockPostMeta(int $postId, string $metaKey): array
    {
        self::requireTransaction('lockPostMeta');

        if ($postId <= 0 || $metaKey === '') {
            return [];
        }

        global $wpdb;

        /** @var list<object{meta_id: string, meta_value: string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, meta_value
               FROM {$wpdb->postmeta}
              WHERE post_id = %d
                AND meta_key = %s
              ORDER BY meta_id ASC
              LIMIT 10
                FOR UPDATE",
            $postId,
            $metaKey
        ));

        return $rows ?: [];
    }

    /**
     * Unlocked twin of {@see lockCollection()}, for the dry run.
     *
     * A dry run must not hold row locks on a live site — it is a report,
     * not a reservation — and the apply path re-reads everything under lock
     * anyway. Separate method rather than a `$forUpdate` flag so the
     * unlocked read cannot be requested by accident from inside the
     * transaction, where it would silently skip the lock.
     *
     * @return object{
     *     id: string,
     *     chain_id: string,
     *     contract_address: string,
     *     canonical_identifier: string|null,
     *     is_verified: string,
     *     source: string
     * }|null
     */
    public static function readCollection(int $collectionId): ?object
    {
        if ($collectionId <= 0) {
            return null;
        }

        global $wpdb;
        $table = CollectionRepository::table();

        /** @var object{id: string, chain_id: string, contract_address: string, canonical_identifier: string|null, is_verified: string, source: string}|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, chain_id, contract_address, canonical_identifier, is_verified, source
               FROM {$table}
              WHERE id = %d
              LIMIT 1",
            $collectionId
        ));

        return $row;
    }

    /**
     * Unlocked twin of {@see lockPostMeta()}, for the dry run. Returns ALL
     * matching rows for the same reason: the caller must be able to SEE a
     * duplicate, not be handed the first of two.
     *
     * @return list<object{meta_id: string, meta_value: string}>
     */
    public static function readPostMeta(int $postId, string $metaKey): array
    {
        if ($postId <= 0 || $metaKey === '') {
            return [];
        }

        global $wpdb;

        /** @var list<object{meta_id: string, meta_value: string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, meta_value
               FROM {$wpdb->postmeta}
              WHERE post_id = %d
                AND meta_key = %s
              ORDER BY meta_id ASC
              LIMIT 10",
            $postId,
            $metaKey
        ));

        return $rows ?: [];
    }

    /**
     * Read the post's type and status. Not locked: the repair asserts these
     * but never changes them, and locking the posts row would widen the
     * transaction's footprint for no benefit.
     *
     * @return object{ID: string, post_type: string, post_status: string}|null
     */
    public static function readPost(int $postId): ?object
    {
        if ($postId <= 0) {
            return null;
        }

        global $wpdb;

        /** @var object{ID: string, post_type: string, post_status: string}|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT ID, post_type, post_status
               FROM {$wpdb->posts}
              WHERE ID = %d
              LIMIT 1",
            $postId
        ));

        return $row;
    }

    /**
     * Set `canonical_identifier` on one collection row.
     *
     * The `AND canonical_identifier IS NULL` clause is a second line of
     * defence behind the locked precondition check: even if two runners
     * somehow reached here concurrently, only one UPDATE can match, so the
     * column can never be overwritten by the loser.
     *
     * `contract_address` is deliberately absent from the SET list — it
     * stays the legacy display alias, permanently.
     *
     * @return bool true when exactly one row was updated.
     */
    public static function setCanonicalIdentifier(int $collectionId, string $canonical): bool
    {
        self::requireTransaction('setCanonicalIdentifier');

        if ($collectionId <= 0 || $canonical === '') {
            return false;
        }

        global $wpdb;
        $table = CollectionRepository::table();

        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET canonical_identifier = %s
              WHERE id = %d
                AND canonical_identifier IS NULL",
            $canonical,
            $collectionId
        ));

        // `wpdb::query()` returns false on error and an int (possibly 0) on
        // success — 0 meaning "matched nothing". Both are failures here, but
        // only `=== false` is an ERROR; treat them distinctly from success.
        return $affected === 1;
    }

    /**
     * Rewrite one postmeta row by its primary key.
     *
     * By `meta_id`, not by (post_id, meta_key): the caller has already
     * locked and proven there is exactly ONE row, and addressing it by its
     * own id means a duplicate appearing between check and write cannot be
     * silently collapsed by an UPDATE that matches two rows.
     *
     * MySQL reports 0 affected rows when the new value equals the old, so
     * an unchanged write is not distinguishable from a missed one here.
     * The caller must not use this to detect "already applied" — it checks
     * the locked read for that, before writing.
     *
     * @return bool false only on a real database error.
     */
    public static function updatePostMetaById(int $metaId, string $value): bool
    {
        self::requireTransaction('updatePostMetaById');

        if ($metaId <= 0) {
            return false;
        }

        global $wpdb;

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta}
                SET meta_value = %s
              WHERE meta_id = %d",
            $value,
            $metaId
        ));

        return $result !== false;
    }

    /**
     * Read back one audit row, to verify what was actually stored.
     *
     * @return object{id: string, action: string, user_id: string, target_type: string, target_id: string, meta: string|null}|null
     */
    public static function readAuditRow(int $auditId): ?object
    {
        if ($auditId <= 0) {
            return null;
        }

        global $wpdb;
        $table = \BCC\Trust\Core\Database\TableRegistry::activity();

        /** @var object{id: string, action: string, user_id: string, target_type: string, target_id: string, meta: string|null}|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, action, user_id, target_type, target_id, meta
               FROM {$table}
              WHERE id = %d
              LIMIT 1",
            $auditId
        ));

        return $row;
    }
}
