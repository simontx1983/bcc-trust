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
     * Get page categories from peepso_page_categories.
     *
     * @return object[] Array of objects with term_id property.
     * @phpstan-return list<object{term_id: int|numeric-string}>
     */
    public static function getPageCategories(int $pageId): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'peepso_page_categories';

        if (!self::tableExists($table)) {
            return [];
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pm_cat_id AS term_id FROM {$table} WHERE pm_page_id = %d",
                $pageId
            )
        ) ?: [];
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
