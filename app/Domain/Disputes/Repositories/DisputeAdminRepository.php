<?php

namespace BCC\Trust\Disputes\Repositories;

use BCC\Core\DTO\RowAssert;
use BCC\Trust\Disputes\Domain\DisputeStatus;
use BCC\Trust\Disputes\DTO\AdminDisputeRowDTO;
use BCC\Trust\Disputes\DTO\DisputeDetailDTO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin-dashboard query shapes for disputes: the wp-admin list table,
 * the detail view, and the status-count rollup (also consumed by the
 * Next.js admin dashboard via AdminDashboardRepository). Read-only.
 *
 * Extracted verbatim from DisputeRepository (Phase 3.1 split). The
 * report-side admin list shapes live in UserReportRepository (table
 * cohesion), and dispute lifecycle mutations stay in DisputeRepository.
 */
class DisputeAdminRepository
{
    /** Cache group for all dispute-related keys. */
    private const CACHE_GROUP = DisputeRepositorySupport::CACHE_GROUP;

    /** TTL for data that changes frequently (counts, active queues). */
    private const TTL_HOT = DisputeRepositorySupport::TTL_HOT;

    /**
     * Get a dispute with joined page title and user display names for admin detail view.
     */
    public static function getDisputeDetailForAdmin(int $disputeId): ?DisputeDetailDTO
    {
        global $wpdb;
        $table = DisputeRepository::disputes_table();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT d.id, d.vote_id, d.page_id, d.reporter_id, d.voter_id,
                    d.reason, d.evidence_url, d.status,
                    d.created_at, d.resolved_at,
                    p.post_title   AS page_title,
                    reporter.display_name AS reporter_name,
                    voter.display_name    AS voter_name
             FROM {$table} d
             LEFT JOIN {$wpdb->posts} p         ON d.page_id     = p.ID
             LEFT JOIN {$wpdb->users} reporter  ON d.reporter_id = reporter.ID
             LEFT JOIN {$wpdb->users} voter     ON d.voter_id    = voter.ID
             WHERE d.id = %d
             LIMIT 1",
            $disputeId
        ), ARRAY_A);

        if (!is_array($row)) {
            return null;
        }
        return DisputeRepositorySupport::hydrateDisputeDetail($row);
    }

    /**
     * Get dispute counts grouped by status.
     *
     * @return array<string, int>
     */
    public static function getDisputeStatusCounts(): array
    {
        $cacheKey = 'dispute_status_counts';
        $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached;
        }

        global $wpdb;
        $table = DisputeRepository::disputes_table();

        $rows = $wpdb->get_results(
            "SELECT status, COUNT(*) AS cnt FROM {$table} GROUP BY status LIMIT 10"
        );

        $counts = [];
        foreach ($rows as $r) {
            $counts[$r->status] = (int) $r->cnt;
        }

        wp_cache_set($cacheKey, $counts, self::CACHE_GROUP, self::TTL_HOT);
        return $counts;
    }

    /**
     * Count disputes for admin list, optionally filtered by status.
     */
    public static function countDisputesForAdminList(?string $status): int
    {
        global $wpdb;
        $table = DisputeRepository::disputes_table();

        if ($status) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE status = %s",
                $status
            ));
        }

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    /**
     * Paginated dispute list for admin, with joined page title and user names.
     *
     * @return list<AdminDisputeRowDTO>
     */
    public static function getDisputesForAdminList(?string $status, string $orderBy, string $order, int $limit, int $offset): array
    {
        global $wpdb;
        $table = DisputeRepository::disputes_table();

        $allowed = ['id', 'status', 'created_at'];
        if (!in_array($orderBy, $allowed, true)) {
            $orderBy = 'id';
        }
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

        $where  = '1=1';
        $params = [];

        if ($status) {
            $where   .= ' AND d.status = %s';
            $params[] = $status;
        }

        $sql = "SELECT d.id, d.vote_id, d.page_id, d.reporter_id, d.voter_id,
                       d.reason, d.status,
                       d.created_at, d.resolved_at,
                       p.post_title   AS page_title,
                       reporter.display_name AS reporter_name,
                       voter.display_name    AS voter_name
                FROM {$table} d
                LEFT JOIN {$wpdb->posts} p         ON d.page_id     = p.ID
                LEFT JOIN {$wpdb->users} reporter  ON d.reporter_id = reporter.ID
                LEFT JOIN {$wpdb->users} voter     ON d.voter_id    = voter.ID
                WHERE {$where}
                ORDER BY d.{$orderBy} {$order}
                LIMIT %d OFFSET %d";

        $params[] = $limit;
        $params[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        $dtos = [];
        foreach ($rows as $row) {
            // Fail-soft at the admin-list edge: one corrupt legacy row must
            // not 500 the whole admin screen. Per-entity admin reads
            // (getDisputeDetailForAdmin) remain fail-fast.
            try {
                $dtos[] = new AdminDisputeRowDTO(
                    id:            RowAssert::requireDigitInt($row, 'id'),
                    vote_id:       RowAssert::requireDigitInt($row, 'vote_id'),
                    page_id:       RowAssert::requireDigitInt($row, 'page_id'),
                    reporter_id:   RowAssert::requireDigitInt($row, 'reporter_id'),
                    voter_id:      RowAssert::requireDigitInt($row, 'voter_id'),
                    reason:        RowAssert::requireString($row, 'reason'),
                    status:        DisputeStatus::assert(RowAssert::requireString($row, 'status')),
                    created_at:    RowAssert::requireString($row, 'created_at'),
                    resolved_at:   RowAssert::optString($row, 'resolved_at'),
                    page_title:    RowAssert::optString($row, 'page_title'),
                    reporter_name: RowAssert::optString($row, 'reporter_name'),
                    voter_name:    RowAssert::optString($row, 'voter_name'),
                );
            } catch (\LogicException $e) {
                \BCC\Core\Log\Logger::error('[bcc-disputes] invalid_dispute_row_skipped', [
                    'source'     => 'getDisputesForAdminList',
                    'dispute_id' => $row['id'] ?? null,
                    'error'      => $e->getMessage(),
                ]);
            }
        }
        return $dtos;
    }
}
