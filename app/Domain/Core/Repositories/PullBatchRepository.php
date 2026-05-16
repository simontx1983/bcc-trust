<?php
/**
 * Pull Batch Repository — owns the bcc_pull_batches summary table.
 *
 * Each row is a §C3 frozen-history snapshot of an emitted pull batch.
 * Created by ActivityStreamWriter when bcc_pull_batch_emitted fires;
 * referenced by peepso_activities.act_external_id so feed renderers
 * have a typed FK back to the batch summary.
 *
 * Writes are idempotent via UNIQUE KEY uk_batch_id (the deterministic
 * batch_id from WatchBatchAggregator collides on duplicate event
 * delivery; the writer detects that and reuses the existing row).
 *
 * @package BCC\Trust\Core\Repositories
 * @since V1 (2026-04, watch-batch Phase 4)
 */

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\Database\TableRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type PullBatchRow object{
 *     id: int|numeric-string,
 *     user_id: int|numeric-string,
 *     batch_id: string,
 *     card_count: int|numeric-string,
 *     more_count: int|numeric-string,
 *     emitted_at: string
 * }
 */
final class PullBatchRepository
{
    /** Explicit column list — must match schema-pull-batches.php. */
    private const COLUMNS = 'id, user_id, batch_id, card_count, more_count, emitted_at';

    private string $table;

    public function __construct()
    {
        $this->table = TableRegistry::pullBatches();
    }

    /**
     * Insert a frozen-snapshot row for an emitted batch. Returns the
     * new id, or — on UNIQUE KEY collision — the id of the existing
     * row for the same batch_id (idempotent under duplicate events).
     *
     * Returns 0 on hard DB error.
     */
    public function record(int $userId, string $batchId, int $cardCount, int $moreCount): int
    {
        if ($userId <= 0 || $batchId === '') {
            return 0;
        }

        global $wpdb;
        $result = $wpdb->insert(
            $this->table,
            [
                'user_id'    => $userId,
                'batch_id'   => $batchId,
                'card_count' => $cardCount,
                'more_count' => $moreCount,
                'emitted_at' => current_time('mysql', true),
            ],
            ['%d', '%s', '%d', '%d', '%s']
        );

        if ($result === false) {
            // UNIQUE KEY collision on batch_id — the row already
            // exists. Look it up so we can return a valid id.
            return $this->findIdByBatchId($batchId);
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Look up the id for a given batch_id (used by the
     * idempotency-fallback path in record() and by future read
     * surfaces that arrive with a batch_id only).
     */
    public function findIdByBatchId(string $batchId): int
    {
        if ($batchId === '') {
            return 0;
        }

        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table} WHERE batch_id = %s LIMIT 1",
            $batchId
        ));
    }

    /**
     * Bulk lookup for the feed-body hydrator. Returns a map of
     * id → row so the caller can index in O(1) after the single
     * round-trip. Bounded by LIMIT 100 — feed pages cap at 50, so
     * 100 is a safety margin.
     *
     * @param list<int> $ids
     * @return array<int, PullBatchRow>
     */
    public function findManyByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        /** @var list<PullBatchRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$this->table}
              WHERE id IN ({$placeholders})
              LIMIT 100",
            ...$ids
        ));

        $map = [];
        foreach ($rows ?: [] as $row) {
            $map[(int) $row->id] = $row;
        }
        return $map;
    }

    /**
     * Read-side: get the snapshot for a given internal id (the
     * peepso_activities.act_external_id pointer). Used by future
     * feed-renderer hydration paths.
     *
     * @phpstan-return PullBatchRow|null
     */
    public function findById(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        global $wpdb;
        /** @var PullBatchRow|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$this->table} WHERE id = %d LIMIT 1",
            $id
        ));
        return $row ?: null;
    }
}
