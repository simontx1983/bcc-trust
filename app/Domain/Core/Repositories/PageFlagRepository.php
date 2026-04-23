<?php
/**
 * Page Flag Repository
 *
 * Handles database operations for the page_flags table (page-level flags).
 *
 * @package BCC\Trust\Core\Repositories
 * @version 1.0.0
 */

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\Database\TableRegistry;

if (!defined('ABSPATH')) {
    exit;
}

class PageFlagRepository
{
    private string $table;

    public function __construct()
    {
        $this->table = TableRegistry::pageFlags();
    }

    /**
     * Insert a page flag.
     *
     * @return bool True on success.
     */
    public function create(int $pageId, int $userId, ?string $reason): bool
    {
        global $wpdb;

        $result = $wpdb->insert($this->table, [
            'page_id'    => $pageId,
            'user_id'    => $userId,
            'reason'     => $reason ? substr(sanitize_text_field($reason), 0, 255) : null,
            'created_at' => current_time('mysql'),
        ], ['%d', '%d', '%s', '%s']);

        return $result !== false;
    }

    /**
     * Delete a flag by page_id and user_id.
     *
     * @return int|false Number of rows deleted, or false on DB error.
     */
    public function deleteByUser(int $pageId, int $userId)
    {
        global $wpdb;

        return $wpdb->delete($this->table, [
            'page_id' => $pageId,
            'user_id' => $userId,
        ], ['%d', '%d']);
    }

    /**
     * Check if a user has flagged a specific page.
     */
    public function hasUserFlagged(int $pageId, int $userId): bool
    {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$this->table} WHERE page_id = %d AND user_id = %d LIMIT 1",
            $pageId, $userId
        ));
    }

    /**
     * Count flags for a page.
     */
    public function countForPage(int $pageId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE page_id = %d",
            $pageId
        ));
    }

    /**
     * Get recent flags for a page with user display names.
     *
     * @return object[]
     * @phpstan-return list<object{
     *   user_id: int|numeric-string,
     *   reason: string|null,
     *   created_at: string,
     *   display_name: string|null
     * }>
     */
    public function getRecentForPage(int $pageId, int $limit = 20): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT f.user_id, f.reason, f.created_at, u.display_name
             FROM {$this->table} f
             LEFT JOIN {$wpdb->users} u ON f.user_id = u.ID
             WHERE f.page_id = %d
             ORDER BY f.created_at DESC
             LIMIT %d",
            $pageId, $limit
        )) ?: [];
    }
}
