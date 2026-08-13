<?php

namespace BCC\Trust\Onchain\Repositories;

use BCC\Core\DB\DB;
use BCC\Core\Log\Logger;

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
 * CosmWasm discovery extension (2026-08): the `cw_*` columns carry
 * OVERALL PER-CHAIN discovery/backfill state — where the historical walk
 * of `/cosmwasm/wasm/v1/code` is, the highest code id seen, whether the
 * chain has a wasm module at all, and when each periodic pass last ran.
 * Per-code-family and per-contract state deliberately live in
 * `wp_bcc_cosmwasm_code_families` / `wp_bcc_cosmwasm_contracts`; this
 * table stays the shared "where is each chain-walking worker up to"
 * primitive rather than growing a parallel twin.
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
 *     block_progression_history: string|null,
 *     cw_discovery_state: string,
 *     cw_code_cursor: string|null,
 *     cw_max_code_id: string,
 *     cw_backfill_completed_at: string|null,
 *     cw_last_discovery_at: string|null,
 *     cw_metadata_refreshed_at: string|null,
 *     cw_last_error: string|null
 * }
 *
 * @phpstan-type ProgressionEntry array{block: int, head: int, at: string}
 */
final class ChainCheckpointRepository
{
    use GuardsReadFailures;

    public const STATE_HEALTHY      = 'healthy';
    public const STATE_DEGRADED     = 'degraded';
    public const STATE_BREAKER_OPEN = 'breaker_open';
    public const STATE_DISABLED     = 'disabled';

    // ── CosmWasm discovery per-chain state ──────────────────────────────

    /** Never started, or between passes. */
    public const CW_STATE_IDLE = 'idle';
    /** Historical backfill in progress (resumable via cw_code_cursor). */
    public const CW_STATE_BACKFILLING = 'backfilling';
    /** Historical backfill drained; incremental-only from here. */
    public const CW_STATE_BACKFILLED = 'backfilled';
    /**
     * The chain has no wasm module. DURABLE AND TERMINAL for the routine
     * sweeps — cryptoorgchain answers the wasmd endpoints with HTTP 501
     * (measured), and retrying it forever would burn budget on a
     * guaranteed failure. Distinct from an LCD that is merely DOWN
     * (kujira, 502 — measured), which keeps its state and is retried.
     */
    public const CW_STATE_UNSUPPORTED = 'unsupported';
    /** Operator pause. Nothing runs for this chain until resumed. */
    public const CW_STATE_PAUSED = 'paused';

    /** @return list<string> */
    public static function cwStates(): array
    {
        return [
            self::CW_STATE_IDLE,
            self::CW_STATE_BACKFILLING,
            self::CW_STATE_BACKFILLED,
            self::CW_STATE_UNSUPPORTED,
            self::CW_STATE_PAUSED,
        ];
    }

    /**
     * Hard cap on stored progression entries. Bounded write amplification:
     * the column is rewritten on every `recordSuccess` call, so keeping
     * the array tiny matters. Five entries is enough to detect monotonic
     * lag drift (4 deltas) while staying under ~300 bytes encoded.
     */
    public const MAX_PROGRESSION_ENTRIES = 5;

    private const COLUMNS = 'chain_id, last_processed_block, head_block, state, cu_used_today, cu_budget_reset_at, last_run_at, last_error, block_progression_history,'
        . ' cw_discovery_state, cw_code_cursor, cw_max_code_id, cw_backfill_completed_at, cw_last_discovery_at, cw_metadata_refreshed_at, cw_last_error';

    public static function table(): string
    {
        return DB::table('chain_checkpoints');
    }

    /**
     * One chain's checkpoint row.
     *
     * FAIL-SAFE on a read failure (logs, returns null). Every caller but
     * one is a WORKER deciding whether it may do work this tick —
     * {@see \BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker},
     * {@see \BCC\Trust\Onchain\Services\HoldingsService},
     * {@see cuRemainingForToday()} — and "no checkpoint, so do nothing and
     * come back next tick" is the safe reading there. Throwing would turn
     * a logged degradation into a fatal inside cron.
     *
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
        self::guardRead(__FUNCTION__);

        return $row;
    }

    /**
     * Every chain's checkpoint row. FAIL-SAFE — see {@see getAllOrFail()}
     * for the operator-facing half.
     *
     * @return list<CheckpointRow>
     */
    public static function getAll(): array
    {
        return self::readAll(false);
    }

    /**
     * Every chain's checkpoint row, FAIL-CLOSED.
     *
     * Same one query as {@see getAll()} — deliberately NOT a second query
     * — under the opposite policy, because the two callers want opposite
     * things from a failed read. The worker-side dashboards degrade to
     * "no rows yet"; the CosmWasm scanner panel
     * ({@see \BCC\Trust\Onchain\Services\CosmwasmDiscoveryHealthSnapshot::buildSummary()})
     * must not, because an empty checkpoint set there renders as every
     * chain sitting `idle` and never scanned — a specific, wrong,
     * reassuring claim.
     *
     * @return list<CheckpointRow>
     * @throws RepositoryReadFailure when the read did not run
     */
    public static function getAllOrFail(): array
    {
        return self::readAll(true);
    }

    /**
     * The single checkpoint-listing read behind {@see getAll()} and
     * {@see getAllOrFail()}.
     *
     * @param  bool $failClosed throw instead of returning an empty list
     * @return list<CheckpointRow>
     * @throws RepositoryReadFailure when $failClosed and the read did not run
     */
    private static function readAll(bool $failClosed): array
    {
        global $wpdb;
        $table = self::table();
        $cols  = self::COLUMNS;

        // No LIMIT here is bounded by the chains table (~10 rows in
        // practice; new chains are admin-curated). This is the one
        // intentional unbounded read in the repo.
        /** @var list<CheckpointRow>|null $rows */
        $rows = $wpdb->get_results("SELECT {$cols} FROM {$table} ORDER BY chain_id ASC");
        if ($failClosed) {
            self::guardReadOrThrow('getAllOrFail');
        } else {
            self::guardRead('getAll');
        }

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
     *
     * ── WHEN THE HISTORY READ FAILS ─────────────────────────────────────
     * This is a READ INSIDE A WRITE PATH, so a failed read is not just a
     * missing answer — it would silently CORRUPT the write. `get_var()`
     * answers a SQL error with null, `decodeProgressionHistory(null)`
     * answers null with `[]`, and the UPDATE would then overwrite a real
     * five-entry history with a fresh one-entry array that looks perfectly
     * legitimate. The progression evidence the dashboard uses to spot
     * stagnation, lag drift and checkpoint regression would be gone, with
     * no error anywhere.
     *
     * So the tick's REAL progress (block, head, state, last_run_at) is
     * still written — losing that would re-walk blocks for no reason —
     * and the history column is left untouched instead of rewritten from
     * a read we know did not run. The failure is logged by the guard.
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
        $currentJson    = $wpdb->get_var($wpdb->prepare(
            "SELECT block_progression_history FROM {$table} WHERE chain_id = %d LIMIT 1",
            $chainId
        ));
        $historyUnknown = self::readFailed(__FUNCTION__);

        $data = [
            'last_processed_block' => $lastProcessedBlock,
            'head_block'           => $headBlock,
            'state'                => self::STATE_HEALTHY,
            'last_run_at'          => $now,
            'last_error'           => null,
        ];
        $formats = ['%d', '%d', '%s', '%s', '%s'];

        if (!$historyUnknown) {
            $history    = self::decodeProgressionHistory(is_string($currentJson) ? $currentJson : null);
            $newHistory = self::appendProgressionEntry($history, $lastProcessedBlock, $headBlock);

            $data['block_progression_history'] = (string) wp_json_encode($newHistory);
            $formats[]                         = '%s';
        }

        $wpdb->update(
            $table,
            $data,
            ['chain_id' => $chainId],
            $formats,
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
     *
     * ── WHEN THE LOCKED READ FAILS ──────────────────────────────────────
     * The other READ INSIDE A WRITE PATH. A failed `SELECT … FOR UPDATE`
     * returns null, which the null branch below reads as "this chain has
     * no checkpoint row" — and then returns 0 having written nothing and
     * said nothing. So the guard THROWS here; the throw is caught by this
     * method's own `catch`, which rolls the transaction back and logs the
     * lost CU. The worker still gets its 0 and keeps running (an escaping
     * exception inside cron would be worse than an under-count), but the
     * two cases are no longer the same silence.
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
            // Caught below: rolls back and logs, so a failed read can never
            // be mistaken for the "no such chain" case on the next line.
            self::guardReadOrThrow(__FUNCTION__);

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
            // Log the rollback — returning 0 silently corrupts the daily CU
            // budget breaker. Callers charge CU unconditionally (the provider
            // call already happened), so a lost write under-counts
            // cu_used_today and can overrun real provider spend with no signal.
            Logger::error('[ChainCheckpointRepository] addCuUsage rollback — CU usage not recorded', [
                'chain_id' => $chainId,
                'cu'       => $cu,
                'db_error' => $e->getMessage(),
            ]);
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

    // ── CosmWasm discovery (per-chain worker state + progress) ──────────

    /**
     * Set the durable per-chain discovery state, optionally recording a
     * sanitized error excerpt.
     *
     * `$error` is capped at 255 chars — the same convention
     * {@see recordFailure()} uses. Raw LCD bodies are never stored.
     */
    public static function setCwDiscoveryState(int $chainId, string $state, ?string $error = null): bool
    {
        if ($chainId <= 0 || !in_array($state, self::cwStates(), true)) {
            return false;
        }

        global $wpdb;
        $table = self::table();

        $updated = $wpdb->update(
            $table,
            [
                'cw_discovery_state' => $state,
                'cw_last_error'      => $error !== null && $error !== '' ? mb_substr($error, 0, 255) : null,
            ],
            ['chain_id' => $chainId],
            ['%s', '%s'],
            ['%d']
        );

        return is_int($updated) && $updated >= 0;
    }

    /**
     * Persist SAFE progress for the resumable code-listing walk.
     *
     * Written BEFORE the tick's wall-clock deadline can bite, so a tick
     * that is cut short resumes from here rather than restarting. The
     * cursor is opaque `pagination.key`; the watermark is the highest
     * code id observed. There is deliberately NO percentage: only one of
     * the nine cosmos chains honours `pagination.count_total` (measured),
     * so a reported total is not a number we may derive progress from.
     *
     * `$complete` flips the chain to `backfilled` and stamps
     * `cw_backfill_completed_at`; the cursor is cleared so a stale
     * opaque key can never be replayed against a re-indexed node.
     */
    public static function recordCwCodeProgress(
        int $chainId,
        ?string $cursor,
        int $maxCodeId,
        bool $complete
    ): bool {
        if ($chainId <= 0) {
            return false;
        }

        global $wpdb;
        $table = self::table();
        $now   = current_time('mysql', true);

        // A completed walk clears the cursor: an opaque `pagination.key`
        // is only meaningful against the node state that produced it, and
        // replaying a stale one against a re-indexed node is worse than
        // starting the tail read from an offset.
        $storedCursor = null;
        if (!$complete && $cursor !== null && $cursor !== '') {
            $storedCursor = mb_substr($cursor, 0, 255);
        }

        $data = [
            'cw_code_cursor'       => $storedCursor,
            'cw_max_code_id'       => max(0, $maxCodeId),
            'cw_discovery_state'   => $complete ? self::CW_STATE_BACKFILLED : self::CW_STATE_BACKFILLING,
            'cw_last_discovery_at' => $now,
            'cw_last_error'        => null,
        ];
        $formats = ['%s', '%d', '%s', '%s', '%s'];

        if ($complete) {
            $data['cw_backfill_completed_at'] = $now;
            $formats[]                        = '%s';
        }

        // `$wpdb->update()` rather than a hand-rolled UPDATE because the
        // cursor is legitimately NULL and `prepare()` would coerce a null
        // `%s` to an empty string. The max-code-id watermark is already
        // monotonic at the caller (it starts from the stored value), so no
        // SQL-side GREATEST() is needed.
        $updated = $wpdb->update(
            $table,
            $data,
            ['chain_id' => $chainId],
            $formats,
            ['%d']
        );

        return is_int($updated) && $updated >= 0;
    }

    /**
     * Advance ONLY the code-id watermark, after an incremental reverse
     * tail walk proved it reached the previous watermark.
     *
     * Deliberately separate from {@see recordCwCodeProgress()}: that one
     * owns the historical backfill's state machine (idle → backfilling →
     * backfilled) and its opaque cursor, and an incremental pass must not
     * touch either. This one moves the contiguous high-water mark and
     * nothing else.
     *
     * The watermark is CONTIGUOUS by contract — "every code id at or below
     * this is inventoried" — which is why the caller may only advance it
     * when the reverse walk actually met it. Advancing on a partial walk
     * would strand the un-ingested middle forever.
     */
    public static function advanceCwCodeWatermark(int $chainId, int $maxCodeId): bool
    {
        if ($chainId <= 0 || $maxCodeId <= 0) {
            return false;
        }

        global $wpdb;
        $table = self::table();

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET cw_max_code_id = GREATEST(cw_max_code_id, %d),
                    cw_last_discovery_at = %s,
                    cw_last_error = NULL
              WHERE chain_id = %d
              LIMIT 1",
            $maxCodeId,
            current_time('mysql', true),
            $chainId
        ));

        return $result !== false;
    }

    /**
     * Hand a chain's catch-up back to the HISTORICAL BACKFILL and clear
     * its opaque cursor.
     *
     * Two callers, one shape:
     *
     *  1. The incremental reverse walk exhausted its page budget WITHOUT
     *     reaching the watermark. It must NOT advance the watermark (that
     *     would strand the middle) and it must NOT report success (that
     *     would be a fake-healthy chain). The forward walk is the
     *     mechanism built for volume, so the work goes there.
     *
     *  2. A stored `pagination.key` failed or came back empty on
     *     resumption. Opaque keys are minted BY A NODE, and
     *     `rest.cosmos.directory` round-robins across nodes — a key from
     *     one node may be rejected or misread by the next. Silently
     *     accepting an empty page as "walk complete" would truncate the
     *     inventory and look healthy. Clearing the cursor restarts that
     *     chain's walk from the beginning, which is cheap and safe: every
     *     write is idempotent under `uk_chain_code` / `uk_chain_contract`.
     *
     * The reason is recorded in `cw_last_error` so the chain is visibly
     * degraded rather than quietly wrong.
     */
    public static function requestCwBackfillRestart(int $chainId, string $reason): bool
    {
        if ($chainId <= 0) {
            return false;
        }

        global $wpdb;
        $table = self::table();

        $updated = $wpdb->update(
            $table,
            [
                'cw_code_cursor'     => null,
                'cw_discovery_state' => self::CW_STATE_BACKFILLING,
                'cw_last_error'      => mb_substr($reason, 0, 255),
            ],
            ['chain_id' => $chainId],
            ['%s', '%s', '%s'],
            ['%d']
        );

        return is_int($updated) && $updated >= 0;
    }

    /** Stamp "an incremental discovery pass ran for this chain". */
    public static function touchCwDiscovery(int $chainId): bool
    {
        if ($chainId <= 0) {
            return false;
        }

        global $wpdb;
        $table = self::table();

        $updated = $wpdb->update(
            $table,
            ['cw_last_discovery_at' => current_time('mysql', true)],
            ['chain_id' => $chainId],
            ['%s'],
            ['%d']
        );

        return is_int($updated) && $updated >= 0;
    }

    /**
     * Stamp the "monthly" migration-check / metadata-refresh pass.
     *
     * wp-cron has NO monthly interval and we do not invent one: the pass
     * rides a DAILY hook and this durable timestamp is the ≥30-day
     * elapsed guard that makes it monthly.
     */
    public static function touchCwMetadataRefresh(int $chainId): bool
    {
        if ($chainId <= 0) {
            return false;
        }

        global $wpdb;
        $table = self::table();

        $updated = $wpdb->update(
            $table,
            ['cw_metadata_refreshed_at' => current_time('mysql', true)],
            ['chain_id' => $chainId],
            ['%s'],
            ['%d']
        );

        return is_int($updated) && $updated >= 0;
    }

    /**
     * OPERATOR PAUSE for one chain's CosmWasm discovery.
     *
     * `cw_discovery_state = paused` is DURABLE STATE THE WORKER ALREADY
     * HONOURS — {@see \BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker}
     * refuses to prepare a paused chain, and {@see nextCwDiscoveryChain()}
     * excludes it from the backfill rotation. There is deliberately NO
     * second pause flag (no option, no transient): a parallel switch is
     * how "paused in the UI, still hammering the LCD" happens.
     *
     * `unsupported` is NOT pausable. It is already terminal for the
     * routine sweeps, and overwriting it would lose the durable "this
     * chain has no wasm module" fact and put the chain back into the
     * rotation on resume.
     */
    public static function pauseCwDiscovery(int $chainId): bool
    {
        if ($chainId <= 0) {
            return false;
        }

        $row = self::get($chainId);
        if ($row === null) {
            return false;
        }

        $state = (string) $row->cw_discovery_state;
        if ($state === self::CW_STATE_UNSUPPORTED || $state === self::CW_STATE_PAUSED) {
            return false;
        }

        return self::setCwDiscoveryState($chainId, self::CW_STATE_PAUSED, null);
    }

    /**
     * OPERATOR RESUME. Puts the chain back into the state its own durable
     * progress says it is in.
     *
     * Pausing overwrites `cw_discovery_state`, so resume cannot simply
     * "restore the previous value" — there is nowhere it was kept. Rather
     * than add a `cw_paused_from` column (a second piece of state that can
     * disagree with the first), the resume target is DERIVED from the
     * progress columns that were never touched by the pause. That is
     * load-bearing: resuming a drained chain to `idle` would make
     * {@see \BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker::runBackfillForChain()}
     * re-walk its entire code listing, because its Phase A runs for every
     * state except `backfilled`.
     */
    public static function resumeCwDiscovery(int $chainId): bool
    {
        if ($chainId <= 0) {
            return false;
        }

        $row = self::get($chainId);
        if ($row === null || (string) $row->cw_discovery_state !== self::CW_STATE_PAUSED) {
            return false;
        }

        return self::setCwDiscoveryState($chainId, self::cwResumeState($row), null);
    }

    /**
     * PURE. Which state a paused chain returns to, read off its own
     * durable progress.
     *
     *   backfill completed        → `backfilled` (incremental-only)
     *   a cursor or a watermark   → `backfilling` (mid-walk, resumable)
     *   nothing learned yet       → `idle`
     *
     * The watermark check matters as much as the cursor: a completed
     * backfill clears the cursor, and an incremental pass that advanced
     * the watermark never sets one, so cursor-presence alone would send a
     * partially-walked chain back to `idle` and lose the fact that work
     * had started.
     *
     * @param CheckpointRow $row
     */
    public static function cwResumeState(object $row): string
    {
        $completed = $row->cw_backfill_completed_at ?? null;
        if (is_string($completed) && $completed !== '') {
            return self::CW_STATE_BACKFILLED;
        }

        $cursor = $row->cw_code_cursor ?? null;
        if (is_string($cursor) && $cursor !== '') {
            return self::CW_STATE_BACKFILLING;
        }
        if ((int) $row->cw_max_code_id > 0) {
            return self::CW_STATE_BACKFILLING;
        }

        return self::CW_STATE_IDLE;
    }

    /**
     * The next chain due a discovery slice — least-recently-worked first.
     *
     * This is what makes "one chain slice per tick" fair AND isolating: a
     * chain that fails still gets its `cw_last_discovery_at` stamped, so
     * the next tick moves on to a different chain instead of wedging on
     * the broken one. Bounded: capped IN() + ORDER BY + LIMIT 1.
     *
     * Chains in `unsupported` or `paused` are excluded.
     *
     * @param list<int> $chainIds
     */
    public static function nextCwDiscoveryChain(array $chainIds): ?int
    {
        $ids = array_slice(array_values(array_unique(array_map('intval', $chainIds))), 0, 50);
        $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            return null;
        }

        global $wpdb;
        $table = self::table();

        $inPlaceholders = implode(', ', array_fill(0, count($ids), '%d'));

        /** @var array<int, string|int> $args */
        $args = array_merge($ids, [self::CW_STATE_UNSUPPORTED, self::CW_STATE_PAUSED]);

        $chainId = $wpdb->get_var($wpdb->prepare(
            "SELECT chain_id
               FROM {$table}
              WHERE chain_id IN ({$inPlaceholders})
                AND cw_discovery_state NOT IN (%s, %s)
              ORDER BY cw_last_discovery_at IS NOT NULL ASC, cw_last_discovery_at ASC, chain_id ASC
              LIMIT 1",
            ...$args
        ));
        self::guardRead(__FUNCTION__);

        return $chainId !== null ? (int) $chainId : null;
    }
}
