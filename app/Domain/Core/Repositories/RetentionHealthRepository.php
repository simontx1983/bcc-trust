<?php
/**
 * RetentionHealthRepository — row counts for the retention-bounded tables,
 * for the Phase 4 overgrown-table alarm.
 *
 * These tables are pruned by `bcc_trust_daily_cleanup` (append-only audit
 * log, TTL caches, expiring fraud/pattern rows). Their total row count IS
 * the retention signal: if cleanup stalls or fails to prune, the count
 * climbs unbounded. The 4a cron heartbeat catches "the cleanup cron stopped
 * firing"; this catches "the table is overgrown anyway" (prune erroring,
 * misconfigured horizon, or genuine over-capacity) — the same belt-and-
 * suspenders the Helius dedup table already has.
 *
 * `votes` is intentionally excluded: its total grows legitimately, and the
 * soft-deleted prune is already covered by the daily_cleanup heartbeat.
 *
 * @package BCC\Trust\Core\Repositories
 * @since Phase 4 ops-visibility (2026-06-25)
 */

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\Database\TableRegistry;

if (!defined('ABSPATH')) {
    exit;
}

final class RetentionHealthRepository
{
    /**
     * Total rows per retention-bounded table, keyed by a stable logical name.
     * Table names come from TableRegistry (trusted identifiers — never user
     * input), so the inlined COUNT(*) is injection-safe.
     *
     * @return array<string, int>
     */
    public function rowCounts(): array
    {
        return [
            'score_events'    => $this->countTable(TableRegistry::scoreEvents()),
            'fraud_analysis'  => $this->countTable(TableRegistry::fraudAnalysis()),
            'fingerprints'    => $this->countTable(TableRegistry::fingerprints()),
            'patterns'        => $this->countTable(TableRegistry::patterns()),
            'content_reports' => $this->countTable(TableRegistry::contentReports()),
        ];
    }

    private function countTable(string $table): int
    {
        global $wpdb;

        // $table is a TableRegistry-derived identifier, not user input.
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }
}
