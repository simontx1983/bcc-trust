<?php
/**
 * Reads and the single guarded write for the Hall ownership repair.
 *
 * ── WHY A REPAIR-SPECIFIC REPOSITORY ────────────────────────────────────
 * §1 puts every `$wpdb` touch in a Repository, and the rows this repair
 * needs are not the rows any existing repository exposes: it reads PeepSo's
 * membership ledger with row locks, and it performs a single UPDATE that no
 * production code path should ever be able to make. Keeping that UPDATE
 * here — behind a name that says exactly what it does and a WHERE clause
 * that cannot match anything else — is what makes it reviewable.
 *
 * ── THE LOCK ASSERTION ──────────────────────────────────────────────────
 * `SELECT … FOR UPDATE` outside a transaction takes NO lock and silently
 * succeeds, so a caller that forgot the transaction would get a repair that
 * races. Every locking read asserts it is inside one, mirroring
 * {@see GateIdentityRepairRepository}.
 *
 * ── SINGLE-GRAPH RULE (§E3) ─────────────────────────────────────────────
 * BCC never writes `peepso_group_members`; `PeepSoGroupWriter` owns it.
 * This repair is the documented exception and it is narrow on purpose: the
 * rows it fixes name **user 0, a user that does not exist**, so there is no
 * `PeepSoGroupUser` to construct and no writer method that models it —
 * `transferOwnership()` refuses (post_author and the owner row disagree),
 * `join()` would add a second row rather than fix the orphan, and `leave()`
 * refuses to remove an owner. The orphan can only be re-pointed in place.
 *
 * @package BCC\Trust\Onchain\Repositories
 */

namespace BCC\Trust\Onchain\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

final class HallOwnershipRepairRepository
{
    /** The orphan status this repair is allowed to touch. */
    public const OWNER_STATUS = 'member_owner';

    /** The orphan user id this repair is allowed to touch. */
    public const ORPHAN_USER_ID = 0;

    private static function membersTable(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'peepso_group_members';
    }

    /**
     * A locking read is meaningless outside a transaction — fail loudly
     * rather than return unlocked rows a caller will treat as locked.
     */
    private static function assertInTransaction(string $method): void
    {
        if (!\BCC\Trust\Core\Security\TransactionManager::isInRunTransaction()) {
            throw new \RuntimeException(
                $method . ' must run inside TransactionManager::run(); '
                . 'SELECT … FOR UPDATE outside a transaction takes no lock and silently succeeds.'
            );
        }
    }

    /**
     * Every membership row for one group, LOCKED.
     *
     * Deliberately returns EVERY row, not just the orphan: the repair has to
     * prove there is exactly one orphan owner row AND that the incoming
     * owner holds no row already, and both facts must be read under the same
     * lock as the write.
     *
     * @return list<object{gm_id: string, gm_user_id: string, gm_user_status: string}>
     */
    public static function lockMembershipRows(int $groupId): array
    {
        self::assertInTransaction(__METHOD__);

        if ($groupId <= 0) {
            return [];
        }

        global $wpdb;
        $table = self::membersTable();

        /** @var list<object{gm_id: string, gm_user_id: string, gm_user_status: string}> $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT gm_id, gm_user_id, gm_user_status
               FROM {$table}
              WHERE gm_group_id = %d
              ORDER BY gm_id ASC
              LIMIT 500
                FOR UPDATE",
            $groupId
        ));

        return is_array($rows) ? $rows : [];
    }

    /**
     * Unlocked read for planning and for the backup artifact.
     *
     * @return list<object{gm_id: string, gm_group_id: string, gm_user_id: string, gm_user_status: string}>
     */
    public static function readMembershipRows(int $groupId): array
    {
        if ($groupId <= 0) {
            return [];
        }

        global $wpdb;
        $table = self::membersTable();

        /** @var list<object{gm_id: string, gm_group_id: string, gm_user_id: string, gm_user_status: string}> $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT gm_id, gm_group_id, gm_user_id, gm_user_status
               FROM {$table}
              WHERE gm_group_id = %d
              ORDER BY gm_id ASC
              LIMIT 500",
            $groupId
        ));

        return is_array($rows) ? $rows : [];
    }

    /**
     * Re-point ONE orphan owner row, by primary key, to a real user.
     *
     * ── EVERY CLAUSE IS LOad-BEARING ────────────────────────────────────
     * `gm_id` alone would be enough to hit one row, so the other three are
     * not for selectivity — they are for SAFETY. If anything changed since
     * the row was read (a concurrent writer, a stale plan, a wrong id in a
     * hand-edited rollback file) the WHERE stops matching and the update
     * affects 0 rows, which the caller treats as a failure and rolls back.
     * A repair that can only fire on the exact state it verified cannot
     * corrupt a state it did not.
     *
     * `$fromUserId` is a parameter rather than a hard-coded 0 so the
     * rollback artifact can drive the same guarded method in reverse.
     *
     * @return int rows affected (expected: exactly 1)
     */
    public static function repointOwnerRow(
        int $rowId,
        int $groupId,
        int $fromUserId,
        int $toUserId,
        string $status
    ): int {
        self::assertInTransaction(__METHOD__);

        if ($rowId <= 0 || $groupId <= 0 || $toUserId < 0 || $fromUserId < 0) {
            return 0;
        }

        global $wpdb;
        $table = self::membersTable();

        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET gm_user_id = %d
              WHERE gm_id = %d
                AND gm_group_id = %d
                AND gm_user_id = %d
                AND gm_user_status = %s",
            $toUserId,
            $rowId,
            $groupId,
            $fromUserId,
            $status
        ));

        return is_int($affected) ? $affected : 0;
    }

    /**
     * PeepSo's own member count, recomputed exactly as PeepSo computes it.
     *
     * ── WHY THIS MIRRORS PEEPSO RATHER THAN COUNTING ROWS ───────────────
     * `PeepSoGroupUsers::update_members_count()` LEFT JOINs `peepso_users`
     * and filters `usr_role NOT IN ('register','ban','verified')`. A user
     * with no `peepso_users` row yields NULL, and `NULL NOT IN (…)` is NULL,
     * so the row is EXCLUDED. That is precisely why every broken Hall reads
     * `members_count = 0` today: user 0 has no such row. A naive
     * `COUNT(*) … LIKE 'member%'` would write a different number than PeepSo
     * itself would, and the next PeepSo write would silently correct it —
     * making this repair look wrong. Same shape, same answer.
     */
    public static function computePeepSoMemberCount(int $groupId): int
    {
        if ($groupId <= 0) {
            return 0;
        }

        global $wpdb;
        $table = self::membersTable();
        $users = $wpdb->prefix . 'peepso_users';

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(m.gm_user_id)
               FROM {$table} m
          LEFT JOIN {$users} f ON m.gm_user_id = f.usr_id
              WHERE m.gm_group_id = %d
                AND m.gm_user_status LIKE 'member%'
                AND f.usr_role NOT IN ('register', 'ban', 'verified')",
            $groupId
        ));

        return (int) $count;
    }

    /**
     * The member count PeepSo would compute IF one row were re-pointed.
     *
     * ── WHY THIS IS NOT `current + 1` ───────────────────────────────────
     * The arithmetic shortcut is correct today — the eligibility guards
     * already prove the incoming owner holds no row in this group and is
     * PeepSo-countable, so exactly one row joins the countable set. But it
     * encodes PeepSo's rule a SECOND time, in a different form, in a place
     * nobody would think to update. Re-running the real query with the one
     * row substituted keeps a single expression of that rule: if PeepSo's
     * exclusion list ever changes, the dry run's prediction and the apply's
     * measurement move together instead of silently diverging.
     *
     * The substitution is in the JOIN KEY, not a WHERE clause: the count
     * hinges on which `peepso_users` row is reachable, so pretending the
     * membership row already names `$newUserId` is exactly the right shape.
     *
     * ⚠ `$rowId` need not exist and `$newUserId` need not be countable — a
     * caller asking "what would happen" is entitled to an honest answer of
     * "no change".
     */
    public static function computePeepSoMemberCountAsIf(int $groupId, int $rowId, int $newUserId): int
    {
        if ($groupId <= 0) {
            return 0;
        }

        global $wpdb;
        $table = self::membersTable();
        $users = $wpdb->prefix . 'peepso_users';

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(m.gm_user_id)
               FROM {$table} m
          LEFT JOIN {$users} f
                 ON f.usr_id = CASE WHEN m.gm_id = %d THEN %d ELSE m.gm_user_id END
              WHERE m.gm_group_id = %d
                AND m.gm_user_status LIKE 'member%'
                AND f.usr_role NOT IN ('register', 'ban', 'verified')",
            $rowId,
            $newUserId,
            $groupId
        ));

        return (int) $count;
    }

    /**
     * Does this user id exist in wp_users? Asked directly, because the whole
     * finding is that the recorded owner does not.
     */
    public static function userExists(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        global $wpdb;

        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->users} WHERE ID = %d LIMIT 1",
            $userId
        ));

        return $found !== null;
    }

    /**
     * Is this user countable by PeepSo's own member-count rule?
     *
     * A repair that hands ownership to a user PeepSo will not count leaves
     * the group reading `members_count = 0` and PeepSo logging
     * "Group member count should never be 0 (zero)" — the exact symptom
     * being repaired, with a different cause.
     */
    public static function isPeepSoCountableUser(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        global $wpdb;
        $users = $wpdb->prefix . 'peepso_users';

        $role = $wpdb->get_var($wpdb->prepare(
            "SELECT usr_role FROM {$users} WHERE usr_id = %d LIMIT 1",
            $userId
        ));

        if ($role === null) {
            return false;
        }

        return !in_array((string) $role, ['register', 'ban', 'verified'], true);
    }

    /**
     * Post row for a candidate Hall (type + status + author), LOCKED.
     *
     * @return object{ID: string, post_type: string, post_status: string, post_author: string}|null
     */
    public static function lockPost(int $postId): ?object
    {
        self::assertInTransaction(__METHOD__);

        if ($postId <= 0) {
            return null;
        }

        global $wpdb;

        /** @var object{ID: string, post_type: string, post_status: string, post_author: string}|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT ID, post_type, post_status, post_author
               FROM {$wpdb->posts}
              WHERE ID = %d
              LIMIT 1
                FOR UPDATE",
            $postId
        ));

        return $row;
    }

    /**
     * Every marker meta value for a group, as key => list of values.
     *
     * Returns a LIST per key on purpose: a duplicated `_bcc_group_kind` row
     * is itself a disqualifying condition, and a method that returned one
     * value could not express it.
     *
     * @return array<string, list<string>>
     */
    public static function readMarkerMeta(int $postId): array
    {
        if ($postId <= 0) {
            return [];
        }

        global $wpdb;

        $keys = [
            HallRepository::META_KIND,
            HallRepository::META_CHAIN_TAG,
            HallRepository::META_OWNER_INCOMPLETE,
            GatedGroupRepository::META_KIND,
            'peepso_group_privacy',
            'peepso_group_members_count',
            '_bcc_gate_collection_id',
            '_bcc_gate_validator_id',
        ];
        $placeholders = implode(',', array_fill(0, count($keys), '%s'));

        /** @var list<object{meta_key: string, meta_value: string|null}> $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value
               FROM {$wpdb->postmeta}
              WHERE post_id = %d
                AND meta_key IN ({$placeholders})
              ORDER BY meta_id ASC
              LIMIT 200",
            $postId,
            ...$keys
        ));

        $out = [];
        foreach (($rows ?: []) as $row) {
            $out[(string) $row->meta_key][] = (string) $row->meta_value;
        }

        return $out;
    }

    /**
     * Read one audit row back, to prove it says what the repair meant.
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
