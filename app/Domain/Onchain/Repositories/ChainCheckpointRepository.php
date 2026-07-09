<?php

namespace BCC\Trust\Onchain\Repositories;

use BCC\Core\DB\DB;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-chain indexer-progress repository (V2 Phase 1a).
 *
 * Owns `wp_bcc_chain_checkpoints`. Intentionally a shared primitive —
 * future chain-walking workers extend it with new state columns
 * rather than create parallel tables.
 *
 * Daily CU-budget accounting (per spike 1):
 *   - cu_used_today increments on every Alchemy call (120 CU flat)
 *   - cu_budget_reset_at rolls forward when the worker's tick crosses
 *     a UTC date boundary; cu_used_today is then zeroed
 *   - BCC_ETH_DAILY_RPC_BUDGET (default 50 000 CU/day) gates calls
 *
 * @phpstan-type CheckpointRow object{
 *     chain_id: string,
 *     last_processed_block: string,
 *     head_block: string,
 *     state: string,
 *     cu_used_today: string,
 *     cu_budget_reset_at: string,
 *     last_run_at: string|null,
 *     last_error: string|null,
 *     block_progression_history: string|null
 * }
 *
 * @phpstan-type ProgressionEntry array{block: int, head: int, at: string}
 */
final class ChainCheckpointRepository
{
    public const STATE_HEALTHY      = 'healthy';
    public const STATE_DEGRADED     = 'degraded';
    public const STATE_BREAKER_OPEN = 'breaker_open';
    public const STATE_DISABLED     = 'disabled';

    /**
     * Hard cap on stored progression entries. Bounded write amplification:
     * the column is rewritten on every `recordSuccess` call, so keeping
     * the array tiny matters. Five entries is enough to detect monotonic
     * lag drift (4 deltas) while staying under ~300 bytes encoded.
     */
    public const MAX_PROGRESSION_ENTRIES = 5;

    private const COLUMNS = 'chain_id, last_processed_block, head_block, state, cu_used_today, cu_budget_reset_at, last_run_at, last_error, block_progression_history';

    public static function table(): string
    {
        return DB::table('chain_checkpoints');
    }

    /**
     * @return CheckpointRow|null
     */
    public static function get(int $chainId): ?object
    {
        if ($chainId <= 0) {
            return null;
        }

        global $wpdb;
        $table = self::table();
        $cols  = self::COLUMNS;

        /** @var CheckpointRow|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT {$cols}
               FROM {$table}
              WHERE chain_id = %d
              LIMIT 1",
            $chainId
        ));

        return $row;
    }

    /**
     * @return list<CheckpointRow>
     */
    public static function getAll(): array
    {
        global $wpdb;
        $table = self::table();
        $cols  = self::COLUMNS;

        // No LIMIT here is bounded by the chains table (~10 rows in
        // practice; new chains are admin-curated). This is the one
        // intentional unbounded read in the repo.
        /** @var list<CheckpointRow>|null $rows */
        $rows = $wpdb->get_results("SELECT {$cols} FROM {$table} ORDER BY chain_id ASC");
        return $rows ?: [];
    }

    /**
     * Initialise a checkpoint row in 'disabled' state. Idempotent —
     * does nothing if the row already exists. Called from the indexer
     * worker's first run on a chain.
     */
    public static function ensureExists(int $chainId): void
    {
        if ($chainId <= 0) {
            return;
        }

        global $wpdb;
        $table = self::table();
        $today = current_time('Y-m-d', true);

        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$table}
                (chain_id, last_processed_block, head_block, state, cu_used_today, cu_budget_reset_at)
             VALUES (%d, 0, 0, %s, 0, %s)",
            $chainId,
            self::STATE_DISABLED,
            $today
        ));
    }

    /**
     * Advance the checkpoint after a successful tick.
     *
     * Also appends a {block, head, at} entry to `block_progression_history`,
     * capped at MAX_PROGRESSION_ENTRIES. The history powers progression
     * detection in the dashboard: stagnant-but-healthy (worker alive but
     * checkpoint not advancing), monotonic lag drift (chain throughput
     * exceeds BLOCKS_PER_TICK), and backward progression (checkpoint
     * regression — a correctness anomaly).
     *
     * Read-modify-write of the JSON column happens inside the same UPDATE
     * statement (one round trip). Bounded by MAX_PROGRESSION_ENTRIES = 5
     * → ~50-byte entry × 5 ≈ ~300 bytes max payload.
     */
    public static function recordSuccess(int $chainId, int $lastProcessedBlock, int $headBlock): void
    {
        if ($chainId <= 0) {
            return;
        }

        global $wpdb;
        $table = self::table();
        $now   = current_time('mysql', true);

        // Read current history (one tiny SELECT — bounded by primary key).
        $currentJson = $wpdb->get_var($wpdb->prepare(
            "SELECT block_progression_history FROM {$table} WHERE chain_id = %d LIMIT 1",
            $chainId
        ));
        $history    = self::decodeProgressionHistory(is_string($currentJson) ? $currentJson : null);
        $newHistory = self::appendProgressionEntry($history, $lastProcessedBlock, $headBlock);
        $encoded    = (string) wp_json_encode($newHistory);

        $wpdb->update(
            $table,
            [
                'last_processed_block'      => $lastProcessedBlock,
                'head_block'                => $headBlock,
                'state'                     => self::STATE_HEALTHY,
                'last_run_at'               => $now,
                'last_error'                => null,
                'block_progression_history' => $encoded,
            ],
            ['chain_id' => $chainId],
            ['%d', '%d', '%s', '%s', '%s', '%s'],
            ['%d']
        );
    }

    /**
     * Decode the `block_progression_history` column into a typed list.
     * Returns `[]` on null, malformed JSON, or any shape violation —
     * fail-quiet because the column is bounded-best-effort, not contract.
     *
     * @return list<ProgressionEntry>
     */
    public static function decodeProgressionHistory(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $block = isset($entry['block']) && is_numeric($entry['block']) ? (int) $entry['block'] : null;
            $head  = isset($entry['head']) && is_numeric($entry['head']) ? (int) $entry['head'] : null;
            $at    = isset($entry['at']) && is_string($entry['at']) ? $entry['at'] : null;
            if ($block === null || $head === null || $at === null) {
                continue;
            }
            $result[] = ['block' => $block, 'head' => $head, 'at' => $at];
        }
        return $result;
    }

    /**
     * Append a new entry and trim to MAX_PROGRESSION_ENTRIES (oldest first).
     *
     * @param  list<ProgressionEntry> $history
     * @return list<ProgressionEntry>
     */
    private static function appendProgressionEntry(array $history, int $block, int $head): array
    {
        $history[] = [
            'block' => $block,
            'head'  => $head,
            'at'    => gmdate('c'),
        ];

        $overflow = count($history) - self::MAX_PROGRESSION_ENTRIES;
        if ($overflow > 0) {
            $history = array_slice($history, $overflow);
        }
        return array_values($history);
    }

    /**
     * Mark a chain as degraded after a failed tick. Caller decides the
     * state ('degraded' for transient errors, 'breaker_open' when the
     * CircuitBreaker trips).
     */
    public static function recordFailure(int $chainId, string $state, string $lastError): void
    {
        if ($chainId <= 0) {
            return;
        }

        $allowed = [self::STATE_DEGRADED, self::STATE_BREAKER_OPEN, self::STATE_DISABLED];
        if (!in_array($state, $allowed, true)) {
            $state = self::STATE_DEGRADED;
        }

        global $wpdb;
        $table = self::table();
        $now   = current_time('mysql', true);

        $wpdb->update(
            $table,
            [
                'state'       => $state,
                'last_run_at' => $now,
                'last_error'  => mb_substr($lastError, 0, 255),
            ],
            ['chain_id' => $chainId],
            ['%s', '%s', '%s'],
            ['%d']
        );
    }

    public static function setState(int $chainId, string $state): bool
    {
        if ($chainId <= 0) {
            return false;
        }

        $allowed = [
            self::STATE_HEALTHY,
            self::STATE_DEGRADED,
            self::STATE_BREAKER_OPEN,
            self::STATE_DISABLED,
        ];
        if (!in_array($state, $allowed, true)) {
            return false;
        }

        global $wpdb;
        $table = self::table();

        $updated = $wpdb->update(
            $table,
            ['state' => $state],
            ['chain_id' => $chainId],
            ['%s'],
            ['%d']
        );

        return is_int($updated) && $updated >= 0;
    }

    /**
     * Add to today's CU usage. Auto-resets the counter when the date
     * has rolled over since cu_budget_reset_at.
     *
     * Returns the post-increment cu_used_today value (used by the
     * worker to decide whether to circuit-break before the next call).
     */
    public static function addCuUsage(int $chainId, int $cu): int
    {
        if ($chainId <= 0 || $cu <= 0) {
            return 0;
        }

        global $wpdb;
        $table = self::table();
        $today = current_time('Y-m-d', true);

        // Single statement: reset to %d if the date has changed, else
        // increment. We can't use a CASE without a SELECT so we do this
        // in two steps inside one transaction.
        try {
            // Fail closed if the transaction never opened (DB failover): a
            // no-op START leaves the FOR UPDATE below as a plain read, losing
            // the row lock that serialises concurrent CU increments.
            if ($wpdb->query('START TRANSACTION') === false) {
                throw new \RuntimeException('START TRANSACTION failed');
            }
            /** @var object{cu_used_today: int|string, cu_budget_reset_at: string}|null $row */
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT cu_used_today, cu_budget_reset_at
                   FROM {$table}
                  WHERE chain_id = %d
                  LIMIT 1
                    FOR UPDATE",
                $chainId
            ));

            if ($row === null) {
                $wpdb->query('ROLLBACK');
                return 0;
            }

            $resetDate = (string) $row->cu_budget_reset_at;
            $current   = (int) $row->cu_used_today;

            if ($resetDate !== $today) {
                $newTotal = $cu;
                $wpdb->update(
                    $table,
                    [
                        'cu_used_today'      => $newTotal,
                        'cu_budget_reset_at' => $today,
                    ],
                    ['chain_id' => $chainId],
                    ['%d', '%s'],
                    ['%d']
                );
            } else {
                $newTotal = $current + $cu;
                $wpdb->update(
                    $table,
                    ['cu_used_today' => $newTotal],
                    ['chain_id' => $chainId],
                    ['%d'],
                    ['%d']
                );
            }

            $wpdb->query('COMMIT');
            return $newTotal;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            return 0;
        }
    }

    /**
     * Read-only CU-budget consult — does not mutate the row. Used by
     * the worker to decide whether to skip a tick before doing real work.
     */
    public static function cuRemainingForToday(int $chainId, int $dailyBudget): int
    {
        if ($chainId <= 0 || $dailyBudget <= 0) {
            return 0;
        }

        $row = self::get($chainId);
        if ($row === null) {
            return $dailyBudget;
        }

        $today = current_time('Y-m-d', true);
        if ((string) $row->cu_budget_reset_at !== $today) {
            return $dailyBudget;
        }

        $remaining = $dailyBudget - (int) $row->cu_used_today;
        return $remaining > 0 ? $remaining : 0;
    }
}
