<?php
/**
 * Pull Meta Repository
 *
 * CRUD for the bcc_pull_meta table — sidecar metadata for PeepSo
 * follows that represent BCC card pulls (per §C2 of the V1 plan).
 *
 * Scope: this repository owns the bcc_pull_meta table only. Queries
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
 * @phpstan-type PullMetaRow object{
 *     follow_id: numeric-string,
 *     tier_at_pull: string|null,
 *     batch_id: string|null,
 *     visibility: string,
 *     pulled_at: string
 * }
 */
class PullMetaRepository
{
    /** Explicit column list — must match schema-pull-meta.php. */
    private const COLUMNS = 'follow_id, tier_at_pull, batch_id, visibility, pulled_at';

    private string $table;

    public function __construct()
    {
        $this->table = TableRegistry::pullMeta();
    }

    /**
     * Find a single pull-meta row by its parent follow_id.
     *
     * Used by the watch endpoint for the §already_watching idempotency
     * check before deciding whether to insert.
     *
     * @phpstan-return PullMetaRow|null
     */
    public function find(int $followId): ?object
    {
        global $wpdb;

        /** @var PullMetaRow|null */
        return $wpdb->get_row($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$this->table} WHERE follow_id = %d LIMIT 1",
            $followId
        ));
    }

    /**
     * Insert a pull-meta row. Returns true on success, false if the row
     * already exists (PK collision) or on DB error.
     *
     * tier_at_pull is the card_tier at the moment of pull (preserves
     * historical narrative even when the entity's current tier changes).
     * batch_id is null on first insert and assigned later by the
     * BatchAggregatorService when the batch closes (per §C3).
     */
    public function insert(int $followId, ?string $tierAtPull, ?string $batchId, string $visibility = 'public'): bool
    {
        global $wpdb;

        $result = $wpdb->insert($this->table, [
            'follow_id'    => $followId,
            'tier_at_pull' => $tierAtPull,
            'batch_id'     => $batchId,
            'visibility'   => $visibility,
            'pulled_at'    => current_time('mysql', true),
        ], ['%d', '%s', '%s', '%s', '%s']);

        return $result !== false;
    }

    /**
     * Delete a pull-meta row by follow_id. Called from the PeepSo
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
     * Bulk-fetch pull-meta for a set of follow_ids. Used by the watchlist
     * list endpoint after PeepSo returns the user's follows — we then
     * enrich with our metadata in a single query (no N+1).
     *
     * @param int[] $followIds
     * @phpstan-return array<int, PullMetaRow> follow_id => row
     */
    public function findManyByFollowIds(array $followIds): array
    {
        if (empty($followIds)) {
            return [];
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($followIds), '%d'));

        /** @var list<PullMetaRow>|null */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$this->table}
             WHERE follow_id IN ({$placeholders})
             LIMIT 500",
            ...$followIds
        ));

        $map = [];
        foreach ($rows ?: [] as $row) {
            $map[(int) $row->follow_id] = $row;
        }
        return $map;
    }

    /**
     * Bulk variant of findByBatchId — fetch members for many batches
     * in one round-trip. Used by the §A3 feed-body hydrator to
     * compose pull_batch top_cards across a feed page.
     *
     * Rows are returned grouped by batch_id, ordered within each
     * group by (pulled_at ASC, follow_id ASC) — the same order the
     * §C3 aggregator used to pick the top 3, so the rendered feed
     * top_cards matches the at-emit-time top_cards exactly.
     *
     * Bounded by LIMIT 1000 — covers a feed page of ~50 batches with
     * ~20 members each.
     *
     * @param list<string> $batchIds
     * @phpstan-return array<string, list<PullMetaRow>>
     */
    public function findManyByBatchIds(array $batchIds): array
    {
        if ($batchIds === []) {
            return [];
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($batchIds), '%s'));

        /** @var list<PullMetaRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$this->table}
              WHERE batch_id IN ({$placeholders})
              ORDER BY batch_id ASC, pulled_at ASC, follow_id ASC
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
     * Find all pull-meta rows belonging to a single batch. Used by the
     * BatchAggregatorService when emitting the §C3 pull_batch feed item
     * to compose card_count + top_cards + more_count from the actual
     * pulls in the batch.
     *
     * @phpstan-return list<PullMetaRow>
     */
    public function findByBatchId(string $batchId): array
    {
        global $wpdb;

        /** @var list<PullMetaRow>|null */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$this->table}
             WHERE batch_id = %s
             ORDER BY pulled_at ASC
             LIMIT 500",
            $batchId
        ));

        return $rows ?: [];
    }

    /**
     * Assign a follow_id to a batch_id. Called as the
     * BatchAggregatorService closes a batch and stamps every member.
     */
    public function assignToBatch(int $followId, string $batchId): bool
    {
        global $wpdb;

        $result = $wpdb->update(
            $this->table,
            ['batch_id' => $batchId],
            ['follow_id' => $followId],
            ['%s'],
            ['%d']
        );

        return $result !== false;
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
