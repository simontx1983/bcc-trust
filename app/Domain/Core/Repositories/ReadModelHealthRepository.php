<?php
/**
 * Read Model Health Repository
 *
 * Handles all health-monitoring queries for the page read model:
 * coverage, drift detection, staleness, and gap analysis. Used by
 * ReadModelHealthEndpoint and the bcc_system_health filter.
 *
 * @package BCC\Trust\Core\Repositories
 */

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\Database\TableRegistry;

if (!defined('ABSPATH')) {
    exit;
}

final class ReadModelHealthRepository
{
    private string $rmTable;
    private string $scoresTable;

    public function __construct()
    {
        $this->rmTable     = TableRegistry::pageReadModel();
        $this->scoresTable = TableRegistry::scores();
    }

    /**
     * Count published peepso-page posts.
     */
    public function countPublishedPages(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'peepso-page' AND post_status = 'publish'"
        );
    }

    /**
     * Count rows in the read model table.
     */
    public function countReadModelRows(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->rmTable}"
        );
    }

    /**
     * Count pages pending recalculation.
     */
    public function countPendingRecalculations(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->scoresTable} WHERE recalculate_required = 1"
        );
    }

    /**
     * Count pages where read model trust_score diverges from live score by > 1 point.
     *
     * @param int $limit Upper bound to keep the query fast.
     */
    public function countDriftedPages(int $limit = 100): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$this->rmTable} rm
                 JOIN {$this->scoresTable} s ON s.page_id = rm.page_id AND s.category_id = 0
                 WHERE ABS(rm.trust_score - s.total_score) > 1.0
                 LIMIT %d",
                $limit
            )
        );
    }

    /**
     * Count pages that have scores but no read model row.
     *
     * @param int $limit Upper bound for the subquery.
     */
    public function countGapPages(int $limit = 1000): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT s.page_id)
             FROM {$this->scoresTable} s
             LEFT JOIN {$this->rmTable} rm ON rm.page_id = s.page_id
             WHERE s.category_id = 0
               AND rm.page_id IS NULL
             LIMIT %d",
            $limit
        ));
    }

    /**
     * Get the oldest updated_at timestamp in the read model.
     */
    public function getOldestUpdate(): ?string
    {
        global $wpdb;

        return $wpdb->get_var(
            "SELECT MIN(updated_at) FROM {$this->rmTable} WHERE updated_at IS NOT NULL"
        );
    }

    /**
     * Get the newest updated_at timestamp in the read model.
     */
    public function getNewestUpdate(): ?string
    {
        global $wpdb;

        return $wpdb->get_var(
            "SELECT MAX(updated_at) FROM {$this->rmTable} WHERE updated_at IS NOT NULL"
        );
    }

    /**
     * Oldest pending dirty-queue entry in seconds.
     *
     * Returns the number of seconds between NOW() and the oldest pending
     * dirty-queue row. Answers "how stale could any read-model value be
     * right now?" — drives the freshness indicator on the health endpoint
     * and the `read_model_lag_seconds` field shipped with page responses.
     *
     * Returns 0 when the dirty queue is empty (read model fully in sync).
     */
    public function getDirtyQueueLagSeconds(): int
    {
        global $wpdb;

        $dirtyTable = TableRegistry::dirtyQueue();
        $lag = $wpdb->get_var(
            "SELECT TIMESTAMPDIFF(SECOND, MIN(created_at), UTC_TIMESTAMP())
             FROM {$dirtyTable}"
        );

        return $lag === null ? 0 : max(0, (int) $lag);
    }

    /**
     * Number of pending entries in the dirty queue.
     */
    public function getDirtyQueueSize(): int
    {
        global $wpdb;

        $dirtyTable = TableRegistry::dirtyQueue();
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$dirtyTable}");
    }

    /**
     * Get sample pages where read model diverges from live score.
     *
     * @param int $limit Max samples to return.
     * @return object[]
     */
    public function getDriftSamples(int $limit = 5): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT rm.page_id,
                    rm.trust_score AS rm_score,
                    s.total_score AS live_score,
                    ROUND(ABS(rm.trust_score - s.total_score), 2) AS drift,
                    rm.updated_at AS rm_updated
             FROM {$this->rmTable} rm
             JOIN {$this->scoresTable} s ON s.page_id = rm.page_id AND s.category_id = 0
             WHERE ABS(rm.trust_score - s.total_score) > 1.0
             ORDER BY ABS(rm.trust_score - s.total_score) DESC
             LIMIT %d",
            $limit
        ));
    }

    /**
     * Get page IDs where read model diverges from live score by > 1 point.
     *
     * @param int $limit Max page IDs to return.
     * @return int[]
     */
    public function getDriftedPageIds(int $limit = 10): array
    {
        global $wpdb;

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT rm.page_id
             FROM {$this->rmTable} rm
             JOIN {$this->scoresTable} s ON s.page_id = rm.page_id AND s.category_id = 0
             WHERE ABS(rm.trust_score - s.total_score) > 1.0
             LIMIT %d",
            $limit
        )));
    }

    /**
     * Get trending pages ordered by trust_score descending.
     *
     * Only returns published peepso-page posts.
     *
     * @param int $limit Max pages to return.
     * @return object[]
     */
    public function getTrendingPages(int $limit): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT rm.page_id AS ID, rm.trust_score AS total_score, rm.reputation_tier
             FROM {$this->rmTable} rm
             INNER JOIN {$wpdb->posts} p ON p.ID = rm.page_id
                                          AND p.post_type   = 'peepso-page'
                                          AND p.post_status = 'publish'
             LEFT JOIN {$wpdb->postmeta} pm_priv ON pm_priv.post_id = p.ID
                                                 AND pm_priv.meta_key = 'peepso_page_privacy'
             WHERE (pm_priv.meta_value IS NULL
                    OR CAST(pm_priv.meta_value AS UNSIGNED) <> 2)
             ORDER BY rm.trust_score DESC, rm.page_id ASC
             LIMIT %d",
            $limit
        ));
    }
}
