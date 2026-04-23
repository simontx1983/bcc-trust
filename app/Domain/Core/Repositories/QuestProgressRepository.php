<?php
/**
 * Quest Progress Repository
 *
 * Database operations for the bcc_trust_quest_log table.
 * Tracks which onboarding quests each user has completed.
 *
 * @package BCC\Trust\Core\Repositories
 */

namespace BCC\Trust\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

class QuestProgressRepository {

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'bcc_trust_quest_log';
    }

    /**
     * Record a quest completion. Idempotent via UNIQUE(user_id, quest_slug).
     *
     * @return bool True if this was a NEW completion, false if already done.
     */
    public function insertCompletion(int $userId, string $slug): bool {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$this->table} (user_id, quest_slug, completed_at)
             VALUES (%d, %s, %s)",
            $userId,
            $slug,
            current_time('mysql', true)
        ));

        return ($wpdb->rows_affected === 1);
    }

    /**
     * Revoke a quest completion. Returns true if a row was actually deleted.
     * Used when the underlying action is reversed (wallet disconnect,
     * GitHub unlink, fraud revocation).
     */
    public function deleteCompletion(int $userId, string $slug): bool {
        global $wpdb;

        $result = $wpdb->delete(
            $this->table,
            ['user_id' => $userId, 'quest_slug' => $slug],
            ['%d', '%s']
        );

        return ($result === 1);
    }

    /**
     * Get all completed quests for a user as slug => completed_at map.
     *
     * @return array<string, string>
     */
    public function getCompletedMap(int $userId): array {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT quest_slug, completed_at FROM {$this->table} WHERE user_id = %d LIMIT 200",
            $userId
        ));

        $map = [];
        foreach ($rows as $row) {
            $map[$row->quest_slug] = $row->completed_at;
        }

        return $map;
    }
}
