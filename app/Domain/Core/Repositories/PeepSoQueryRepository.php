<?php

namespace BCC\Trust\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Centralises all raw queries against PeepSo-owned tables.
 *
 * PeepSo does not expose a PHP API for page ownership or existence checks,
 * so direct $wpdb access is required. This repository isolates that access
 * so no service class touches $wpdb directly.
 */
final class PeepSoQueryRepository
{
    /**
     * Check whether a PeepSo table exists (request memo + object cache
     * via the shared TableRegistry::exists seam).
     */
    public static function tableExists(string $tableName): bool
    {
        return \BCC\Trust\Core\Database\TableRegistry::exists($tableName);
    }

    /**
     * Check if a PeepSo page exists by ID.
     *
     * @return bool True if found in peepso_pages table.
     */
    public static function pageExists(int $pageId): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'peepso_pages';

        if (!self::tableExists($table)) {
            return false;
        }

        return (bool) $wpdb->get_var(
            $wpdb->prepare("SELECT ID FROM {$table} WHERE ID = %d LIMIT 1", $pageId)
        );
    }

    /**
     * Get page owner from peepso_page_members (single page).
     *
     * @return int|null Owner user ID or null if not found.
     */
    public static function getPageOwner(int $pageId): ?int
    {
        global $wpdb;

        // Try peepso_page_members first (most common PeepSo schema).
        $table = $wpdb->prefix . 'peepso_page_members';
        if (self::tableExists($table)) {
            $owner = $wpdb->get_var($wpdb->prepare(
                "SELECT pm_user_id FROM {$table}
                 WHERE pm_page_id = %d AND pm_user_status = 'member_owner'
                 LIMIT 1",
                $pageId
            ));
            if ($owner) {
                return (int) $owner;
            }
        }

        // Try peepso_page_users (alternative schema).
        $table = $wpdb->prefix . 'peepso_page_users';
        if (self::tableExists($table)) {
            $owner = $wpdb->get_var($wpdb->prepare(
                "SELECT user_id FROM {$table}
                 WHERE page_id = %d AND (role = 'owner' OR role = 'admin')
                 LIMIT 1",
                $pageId
            ));
            if ($owner) {
                return (int) $owner;
            }
        }

        // Try peepso_pages_users (another schema variant).
        $table = $wpdb->prefix . 'peepso_pages_users';
        if (self::tableExists($table)) {
            $owner = $wpdb->get_var($wpdb->prepare(
                "SELECT user_id FROM {$table}
                 WHERE page_id = %d AND (role = 'owner' OR role = 'admin')
                 LIMIT 1",
                $pageId
            ));
            if ($owner) {
                return (int) $owner;
            }
        }

        return null;
    }

    /**
     * Batch-resolve page owners from PeepSo tables.
     *
     * @param int[] $pageIds
     * @return array<int, int> page_id => owner_user_id (only resolved pages)
     */
    public static function batchGetPageOwners(array $pageIds): array
    {
        global $wpdb;
        $resolved = [];

        $tables = [
            $wpdb->prefix . 'peepso_page_members' => ['pm_page_id', 'pm_user_id', "pm_user_status = 'member_owner'"],
            $wpdb->prefix . 'peepso_page_users'    => ['page_id', 'user_id', "(role = 'owner' OR role = 'admin')"],
            $wpdb->prefix . 'peepso_pages_users'   => ['page_id', 'user_id', "(role = 'owner' OR role = 'admin')"],
        ];

        foreach ($tables as $table => [$pageCol, $userCol, $roleFilter]) {
            $remaining = array_diff($pageIds, array_keys($resolved));
            if (empty($remaining) || !self::tableExists($table)) {
                continue;
            }
            $ph   = implode(',', array_fill(0, count($remaining), '%d'));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT {$pageCol} AS pid, {$userCol} AS uid FROM {$table}
                 WHERE {$pageCol} IN ({$ph}) AND {$roleFilter}",
                array_values($remaining)
            ));
            foreach ($rows as $row) {
                $resolved[(int) $row->pid] = (int) $row->uid;
            }
        }

        return $resolved;
    }

    /**
     * F-299 backfill: claimed pages whose claimer has no PeepSo
     * `member_owner` row — the rows a claim made before F-299 never wrote.
     *
     * A candidate is a VERIFIED operator/creator PAGE claim (the page-mirror
     * row) whose claimer IS the page's author (published `peepso-page`), with
     * no `member_owner` row for that pair. Pages whose author is not the
     * claimer are excluded on purpose: ownership is never assigned by a
     * repair (onPageClaimed's no-automatic-transfer rule), and an author-0
     * page heals when its claim is resubmitted.
     *
     * Keyset by page id, bounded by $limit (capped at 500). NULL = the read
     * did not run (e.g. PeepSo Pages' table is missing) — never an empty
     * list, so a caller cannot report "nothing to do" on a failure.
     *
     * @return list<array{page_id: int, user_id: int}>|null
     */
    public static function claimedPagesMissingOwnerRow(int $afterPageId, int $limit): ?array
    {
        global $wpdb;
        $limit   = max(1, min(500, $limit));
        $claims  = \BCC\Core\DB\DB::table('onchain_claims');
        $members = $wpdb->prefix . 'peepso_page_members';

        self::clearLastError();
        $ok = $wpdb->query($wpdb->prepare(
            "SELECT c.entity_id AS page_id, c.user_id
               FROM {$claims} c
               JOIN {$wpdb->posts} p
                 ON p.ID = c.entity_id
                AND p.post_type = 'peepso-page'
                AND p.post_status = 'publish'
                AND p.post_author = c.user_id
              WHERE c.entity_type = 'page'
                AND c.status = 'verified'
                AND c.claim_role IN ('operator', 'creator')
                AND c.entity_id > %d
                AND NOT EXISTS (
                    SELECT 1 FROM {$members} pm
                     WHERE pm.pm_page_id = c.entity_id
                       AND pm.pm_user_id = c.user_id
                       AND pm.pm_user_status = 'member_owner'
                )
              ORDER BY c.entity_id ASC
              LIMIT %d",
            max(0, $afterPageId),
            $limit
        ));
        if ($ok === false || self::lastError() !== '') {
            return null;
        }

        $out = [];
        foreach (is_array($wpdb->last_result) ? $wpdb->last_result : [] as $row) {
            if (is_object($row) && isset($row->page_id, $row->user_id)) {
                $out[] = ['page_id' => (int) $row->page_id, 'user_id' => (int) $row->user_id];
            }
        }

        return $out;
    }

    // Separate helpers (bcc-core's shape) so static analysis does not narrow
    // last_error to '' across the query() call that sets it.
    private static function clearLastError(): void
    {
        global $wpdb;
        $wpdb->last_error = '';
    }

    private static function lastError(): string
    {
        global $wpdb;
        return (string) $wpdb->last_error;
    }

    /**
     * Resolve a PeepSo page by ID from peepso_pages table.
     *
     * @return object|null Object with ID, name, owner_id, or null.
     * @phpstan-return object{ID: int|numeric-string, name: string, owner_id: int|numeric-string}|null
     */
    public static function resolvePage(int $pageId): ?object
    {
        global $wpdb;
        $table = $wpdb->prefix . 'peepso_pages';

        if (!self::tableExists($table)) {
            return null;
        }

        return $wpdb->get_row($wpdb->prepare(
            "SELECT ID, name, owner_id FROM {$table} WHERE ID = %d LIMIT 1",
            $pageId
        ));
    }

    /**
     * Count filled PeepSo user profile fields for a user.
     *
     * Queries wp_usermeta for peepso_user_field_* keys with non-empty
     * values, excluding the _acc (access control) meta entries.
     *
     * Used by QuestValidator to check profile completion.
     */
    public static function countFilledProfileFields(int $userId): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->usermeta}
             WHERE user_id = %d
               AND meta_key LIKE 'peepso_user_field_%%'
               AND meta_key NOT LIKE '%%_acc'
               AND meta_value != ''
               AND meta_value IS NOT NULL",
            $userId
        ));
    }
}
