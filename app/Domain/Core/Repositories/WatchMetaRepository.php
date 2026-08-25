<?php
/**
 * Watch Meta Repository
 *
 * CRUD for the bcc_watch_meta table — sidecar metadata for PeepSo
 * follows that represent BCC card watches (per §C2 of the V1 plan).
 *
 * Scope: this repository owns the bcc_watch_meta table only. Queries
 * that JOIN with peepso_follower (e.g., the watchlist list endpoint)
 * belong in a Service that composes this Repository with the PeepSo
 * follow store — never inline here.
 *
 * Uniqueness: follow_id is PK, so 1:1 with peepso_follower rows.
 *
 * @package BCC\Trust\Core\Repositories
 * @since V1 (2026-04)
 */

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\Database\TableRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type WatchMetaRow object{
 *     follow_id: numeric-string,
 *     tier_at_watch: string|null,
 *     batch_id: string|null,
 *     watched_at: string
 * }
 */
class WatchMetaRepository
{
    /** Explicit column list — must match schema-watch-meta.php. */
    private const COLUMNS = 'follow_id, tier_at_watch, batch_id, watched_at';

    private string $table;

    public function __construct()
    {
        $this->table = TableRegistry::watchMeta();
    }

    /**
     * Find a single watch-meta row by its parent follow_id.
     *
     * Used by the watch endpoint for the §already_watching idempotency
     * check before deciding whether to insert.
     *
     * @phpstan-return WatchMetaRow|null
     */
    public function find(int $followId): ?object
    {
        global $wpdb;

        /** @var WatchMetaRow|null */
        return $wpdb->get_row($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$this->table} WHERE follow_id = %d LIMIT 1",
            $followId
        ));
    }

    /**
     * Insert a watch-meta row. Returns true on success, false if the row
     * already exists (PK collision) or on DB error.
     *
     * tier_at_watch is the reputation_tier at the moment of watch (preserves
     * historical narrative even when the entity's current tier changes).
     * batch_id is null on first insert and assigned later by the
     * BatchAggregatorService when the batch closes (per §C3).
     */
    public function insert(int $followId, ?string $tierAtWatch, ?string $batchId): bool
    {
        global $wpdb;

        $result = $wpdb->insert($this->table, [
            'follow_id'     => $followId,
            'tier_at_watch' => $tierAtWatch,
            'batch_id'      => $batchId,
            'watched_at'    => current_time('mysql', true),
        ], ['%d', '%s', '%s', '%s']);

        return $result !== false;
    }

    /**
     * Delete a watch-meta row by follow_id. Called from the PeepSo
     * unfollow handler so the meta doesn't outlive its parent follow.
     *
     * Note (§C3): unfollowing does NOT modify any prior batch feed
     * post — that's a frozen historical record, not a live mirror.
     *
     * @return int|false Rows deleted (0 or 1), or false on DB error.
     */
    public function delete(int $followId): int|false
    {
        global $wpdb;

        return $wpdb->delete($this->table, [
            'follow_id' => $followId,
        ], ['%d']);
    }

    /**
     * Fetch members for many batches in one round-trip. Used by the §A3
     * feed-body hydrator to compose watch_batch top_cards across a feed
     * page.
     *
     * Rows are returned grouped by batch_id, ordered within each
     * group by (watched_at ASC, follow_id ASC) — the same order the
     * §C3 aggregator used to pick the top 3, so the rendered feed
     * top_cards matches the at-emit-time top_cards exactly.
     *
     * Bounded by LIMIT 1000 — covers a feed page of ~50 batches with
     * ~20 members each.
     *
     * @param list<string> $batchIds
     * @phpstan-return array<string, list<WatchMetaRow>>
     */
    public function findManyByBatchIds(array $batchIds): array
    {
        if ($batchIds === []) {
            return [];
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($batchIds), '%s'));

        /** @var list<WatchMetaRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$this->table}
              WHERE batch_id IN ({$placeholders})
              ORDER BY batch_id ASC, watched_at ASC, follow_id ASC
              LIMIT 1000",
            ...$batchIds
        ));

        $map = [];
        foreach ($rows ?: [] as $row) {
            $bid = (string) $row->batch_id;
            if (!isset($map[$bid])) {
                $map[$bid] = [];
            }
            $map[$bid][] = $row;
        }
        return $map;
    }

    /**
     * Bulk-stamp a set of follow_ids with a fresh batch_id. Used by
     * the §C3 close path to atomically close a batch in one query.
     *
     * Idempotency rule: the WHERE clause includes `batch_id IS NULL`
     * so a concurrent close attempt for the same user sees zero rows
     * to stamp and exits. Callers MUST treat a return of 0 as "race
     * lost — another worker already closed this batch" and skip the
     * subsequent feed/event emission.
     *
     * @param list<int> $followIds
     * @return int Rows actually stamped (0 when the race was lost).
     */
    public function stampBatch(array $followIds, string $batchId): int
    {
        if ($followIds === [] || $batchId === '') {
            return 0;
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($followIds), '%d'));

        $args = $followIds;
        array_unshift($args, $batchId);

        return (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
                SET batch_id = %s
              WHERE follow_id IN ({$placeholders})
                AND batch_id IS NULL",
            ...$args
        ));
    }
}
