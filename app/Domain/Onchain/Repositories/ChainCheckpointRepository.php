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
 *     last_error: string|null
 * }
 */
final class ChainCheckpointRepository
{
    public const STATE_HEALTHY      = 'healthy';
    public const STATE_DEGRADED     = 'degraded';
    public const STATE_BREAKER_OPEN = 'breaker_open';
    public const STATE_DISABLED     = 'disabled';

    private const COLUMNS = 'chain_id, last_processed_block, head_block, state, cu_used_today, cu_budget_reset_at, last_run_at, last_error';

    public static function table(): string
    {
        return DB::table('chain_checkpoints');
    }

    public static function get(int $chainId): ?object
    {
        if ($chainId <= 0) {
            return null;
        }

        global $wpdb;
        $table = self::table();
        $cols  = self::COLUMNS;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT {$cols}
               FROM {$table}
              WHERE chain_id = %d
              LIMIT 1",
            $chainId
        ));

        return is_object($row) ? $row : null;
    }

    /**
     * @return list<object>
     */
    public static function getAll(): array
    {
        global $wpdb;
        $table = self::table();
        $cols  = self::COLUMNS;

        // No LIMIT here is bounded by the chains table (~10 rows in
        // practice; new chains are admin-curated). This is the one
        // intentional unbounded read in the repo.
        $rows = $wpdb->get_results("SELECT {$cols} FROM {$table} ORDER BY chain_id ASC");
        return is_array($rows) ? $rows : [];
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
     */
    public static function recordSuccess(int $chainId, int $lastProcessedBlock, int $headBlock): void
    {
        if ($chainId <= 0) {
            return;
        }

        global $wpdb;
        $table = self::table();
        $now   = current_time('mysql', true);

        $wpdb->update(
            $table,
            [
                'last_processed_block' => $lastProcessedBlock,
                'head_block'           => $headBlock,
                'state'                => self::STATE_HEALTHY,
                'last_run_at'          => $now,
                'last_error'           => null,
            ],
            ['chain_id' => $chainId],
            ['%d', '%d', '%s', '%s', '%s'],
            ['%d']
        );
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
        $wpdb->query('START TRANSACTION');
        try {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT cu_used_today, cu_budget_reset_at
                   FROM {$table}
                  WHERE chain_id = %d
                  LIMIT 1
                    FOR UPDATE",
                $chainId
            ));

            if (!is_object($row)) {
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
