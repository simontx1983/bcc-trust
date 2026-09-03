<?php

declare(strict_types=1);

/**
 * The durable run ledger.
 *
 * Mirrors {@see ValidatorMsgQueueRepository}, which already solves this
 * problem in production: an atomic compare-and-swap claim whose WHERE
 * clause IS the concurrency story, every later write guarded by
 * `AND lease_token = %s` so a revived zombie cannot overwrite a re-leased
 * row, and a reaper that returns an expired lease WITHOUT bumping the
 * attempt counter.
 *
 * ⚠ NO MARKET DATA. No price, volume, listing or sale figure may ever be
 * written here. The counts are counts of WORK.
 *
 * @package BCC\Trust\Onchain\Repositories
 */

namespace BCC\Trust\Onchain\Repositories;

use BCC\Trust\Onchain\ValueObjects\DiscoveryJobKind;
use BCC\Trust\Onchain\ValueObjects\DiscoveryRunError;
use BCC\Trust\Onchain\ValueObjects\DiscoveryRunStatus;
use BCC\Trust\Onchain\ValueObjects\DiscoveryScanMode;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type DiscoveryRunRow object{
 *     id: string,
 *     run_uuid: string,
 *     job_kind: string,
 *     scan_mode: string,
 *     chain_id: string,
 *     status: string,
 *     active_marker: string|null,
 *     requested_by: string,
 *     requested_at: string,
 *     started_at: string|null,
 *     finished_at: string|null,
 *     lease_expires_at: string|null,
 *     heartbeat_at: string|null,
 *     attempt_count: string,
 *     next_retry_at: string|null,
 *     retry_of_run_id: string|null,
 *     stop_reason: string|null,
 *     error_code: string|null,
 *     partial: string,
 *     audit_degraded: string,
 *     requests_used: string,
 *     pages_fetched: string,
 *     families_seen: string,
 *     contracts_seen: string,
 *     collections_emitted: string,
 *     collections_denied: string,
 *     updated_at: string
 * }
 */
final class DiscoveryRunRepository
{
    /**
     * Seconds a claimant may hold a run before the reaper may reclaim it.
     * Matches ValidatorMsgQueueRepository::LEASE_SECONDS — the same class of
     * work, the same failure mode, so deliberately the same number.
     */
    public const LEASE_SECONDS = 120;

    /** Claims allowed before a run is terminally failed. */
    public const MAX_ATTEMPTS = 3;

    /**
     * Backoff after a lease expiry, indexed by attempts already consumed.
     * Bounded and short: this is recovery from a dead worker, not a retry
     * against a hostile provider.
     */
    public const RETRY_BACKOFF_SECONDS = [60, 300, 900];

    /** Read-time only: how long a queued run may wait before it is flagged. */
    public const PICKUP_GRACE_SECONDS = 900;

    /** Terminal rows older than this may be pruned. */
    public const RETENTION_DAYS = 90;

    /**
     * ⚠ EXPLICIT COLUMN LIST — §2 forbids `SELECT *`.
     * `lease_token` is deliberately ABSENT: it is a capability, not a fact
     * about the run, and it must never reach a read model or a response.
     */
    private const COLUMNS = 'id, run_uuid, job_kind, scan_mode, chain_id, status, active_marker,
        requested_by, requested_at, started_at, finished_at, lease_expires_at, heartbeat_at,
        attempt_count, next_retry_at, retry_of_run_id, stop_reason, error_code, partial,
        audit_degraded, requests_used, pages_fetched, families_seen, contracts_seen,
        collections_emitted, collections_denied, updated_at';

    public static function table(): string
    {
        return \BCC\Core\DB\DB::table('discovery_runs');
    }

    /**
     * Build a literal `NULL` or a prepared integer.
     *
     * ⚠ `wpdb::prepare()` cannot express SQL NULL: `%d` renders null as `0`
     * and `%s` renders it as `''`. Both are VALUES, and both would be
     * indistinguishable from a real one. The same helper exists on
     * CollectionRepository for the provisioning columns; this is the second
     * caller of one rule, not a second copy of it — if a third appears it
     * belongs in a shared trait.
     *
     * @param int|null $value
     */
    private static function sqlIntOrNull(?int $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        global $wpdb;

        return $wpdb->prepare('%d', $value);
    }

    // ── Creation ────────────────────────────────────────────────────────

    /**
     * Insert a queued run. Returns null when the active-run unique index
     * refuses it — the caller decides what that means.
     *
     * The insert is the ONLY place `active_marker` is set to 1. Every
     * terminal transition clears it to NULL, which is what frees the
     * (job_kind, chain_id) slot for the next request.
     *
     * @return array{id: int, run_uuid: string}|null null on refusal or failure
     */
    public static function insertQueued(
        string $jobKind,
        string $scanMode,
        int $chainId,
        int $requestedBy,
        ?int $retryOfRunId = null
    ): ?array {
        global $wpdb;

        if (!DiscoveryJobKind::isValid($jobKind)
            || !DiscoveryScanMode::isValid($scanMode)
            || $chainId <= 0
            || $requestedBy <= 0
        ) {
            return null;
        }

        $table = self::table();
        $uuid  = wp_generate_uuid4();

        // ⚠ `prepare()` CANNOT EXPRESS SQL NULL — `%d` renders null as 0,
        // which would make every non-retry run claim to be a retry of run 0.
        // The house helper builds the literal instead, exactly as
        // CollectionRepository does for the provisioning columns.
        $sqlRetryOf = self::sqlIntOrNull(
            ($retryOfRunId !== null && $retryOfRunId > 0) ? $retryOfRunId : null
        );

        // suppress_errors: a duplicate-key violation on uq_active is an
        // EXPECTED outcome of the two-administrator race, not an incident.
        // Letting wpdb print it would fill the log with noise on a path
        // that is behaving exactly as designed.
        $previous = $wpdb->suppress_errors(true);

        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table}
                (run_uuid, job_kind, scan_mode, chain_id, status, active_marker,
                 requested_by, requested_at, attempt_count, retry_of_run_id, updated_at)
             VALUES (%s, %s, %s, %d, %s, 1, %d, UTC_TIMESTAMP(), 0, {$sqlRetryOf}, UTC_TIMESTAMP())",
            $uuid,
            $jobKind,
            $scanMode,
            $chainId,
            DiscoveryRunStatus::QUEUED,
            $requestedBy
        ));

        $wpdb->suppress_errors($previous);

        if ($inserted === false || (int) $wpdb->insert_id <= 0) {
            return null;
        }

        return ['id' => (int) $wpdb->insert_id, 'run_uuid' => $uuid];
    }

    /**
     * The one active run for a target, if any.
     *
     * Reads on `active_marker IS NOT NULL` rather than a status list, so it
     * can never disagree with the unique index that enforces the rule.
     *
     * @return DiscoveryRunRow|null
     */
    public static function findActive(string $jobKind, int $chainId): ?object
    {
        global $wpdb;

        if (!DiscoveryJobKind::isValid($jobKind) || $chainId <= 0) {
            return null;
        }

        $table   = self::table();
        $columns = self::COLUMNS;

        /** @var DiscoveryRunRow|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT {$columns} FROM {$table}
              WHERE job_kind = %s AND chain_id = %d AND active_marker IS NOT NULL
              LIMIT 1",
            $jobKind,
            $chainId
        ));

        return $row;
    }

    /** @return DiscoveryRunRow|null */
    public static function findById(int $runId): ?object
    {
        global $wpdb;

        if ($runId <= 0) {
            return null;
        }

        $table   = self::table();
        $columns = self::COLUMNS;

        /** @var DiscoveryRunRow|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT {$columns} FROM {$table} WHERE id = %d LIMIT 1",
            $runId
        ));

        return $row;
    }

    // ── Claiming ────────────────────────────────────────────────────────

    /**
     * Atomically claim a QUEUED, DUE run. Returns the lease token, or null
     * when another worker won.
     *
     * ⚠ THE EXECUTOR NEVER RECLAIMS AN EXPIRED LEASE.
     * The WHERE clause admits `status = 'queued'` only. Reclaiming an
     * expired `running` row is the REAPER's job
     * ({@see reapExpiredLeases()}), and keeping the two separate is what
     * makes attempt accounting honest: a claim always means "this run began
     * an execution", and a reap always means "a worker vanished". Folding
     * them together, as the message queue does for its own reasons, would
     * make `attempt_count` mean two different things.
     *
     * The claim bumps `attempt_count`, because an attempt IS a successful
     * database claim — not proof a provider was contacted. A run claimed and
     * killed before its first request has still consumed an attempt, which
     * is correct: the alternative is an infinite claim loop.
     */
    public static function claim(int $runId): ?string
    {
        global $wpdb;

        if ($runId <= 0) {
            return null;
        }

        $table = self::table();
        $token = wp_generate_uuid4();

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET status = %s,
                    lease_token = %s,
                    lease_expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND),
                    heartbeat_at = UTC_TIMESTAMP(),
                    started_at = COALESCE(started_at, UTC_TIMESTAMP()),
                    attempt_count = attempt_count + 1,
                    next_retry_at = NULL,
                    updated_at = UTC_TIMESTAMP()
              WHERE id = %d
                AND status = %s
                AND active_marker IS NOT NULL
                AND (next_retry_at IS NULL OR next_retry_at <= UTC_TIMESTAMP())
                AND attempt_count < %d",
            DiscoveryRunStatus::RUNNING,
            $token,
            self::LEASE_SECONDS,
            $runId,
            DiscoveryRunStatus::QUEUED,
            self::MAX_ATTEMPTS
        ));

        return $wpdb->rows_affected === 1 ? $token : null;
    }

    /**
     * Extend a lease we still own. False means we lost it — the caller must
     * stop working, because another claimant may already be running.
     */
    public static function heartbeat(int $runId, string $leaseToken): bool
    {
        global $wpdb;

        if ($runId <= 0 || $leaseToken === '') {
            return false;
        }

        $table = self::table();

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET lease_expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND),
                    heartbeat_at = UTC_TIMESTAMP(),
                    updated_at = UTC_TIMESTAMP()
              WHERE id = %d AND lease_token = %s AND status = %s",
            self::LEASE_SECONDS,
            $runId,
            $leaseToken,
            DiscoveryRunStatus::RUNNING
        ));

        return $wpdb->rows_affected === 1;
    }

    // ── Terminal writes ─────────────────────────────────────────────────

    /**
     * running -> succeeded, with the bounded outcome of the pass.
     *
     * ⚠ THE RETURN VALUE IS NOT DECORATION.
     * False means the terminal write was NOT confirmed — the row was not
     * ours, or the update matched nothing. The caller must never report
     * success on a false: leaving the run leased lets the lease expire and
     * the reaper return it, which is the only safe response to "we do not
     * know whether the result landed".
     *
     * @param array{requests_used?: int, pages_fetched?: int, families_seen?: int,
     *              contracts_seen?: int, collections_emitted?: int,
     *              collections_denied?: int} $counts
     */
    public static function markSucceeded(
        int $runId,
        string $leaseToken,
        string $stopReason,
        bool $partial,
        array $counts = []
    ): bool {
        global $wpdb;

        if ($runId <= 0 || $leaseToken === '' || $stopReason === '') {
            return false;
        }

        $table = self::table();

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET status = %s,
                    active_marker = NULL,
                    lease_token = NULL,
                    lease_expires_at = NULL,
                    next_retry_at = NULL,
                    finished_at = UTC_TIMESTAMP(),
                    stop_reason = %s,
                    error_code = NULL,
                    partial = %d,
                    requests_used = %d,
                    pages_fetched = %d,
                    families_seen = %d,
                    contracts_seen = %d,
                    collections_emitted = %d,
                    collections_denied = %d,
                    updated_at = UTC_TIMESTAMP()
              WHERE id = %d AND lease_token = %s AND status = %s",
            DiscoveryRunStatus::SUCCEEDED,
            substr($stopReason, 0, 40),
            $partial ? 1 : 0,
            max(0, (int) ($counts['requests_used'] ?? 0)),
            max(0, (int) ($counts['pages_fetched'] ?? 0)),
            max(0, (int) ($counts['families_seen'] ?? 0)),
            max(0, (int) ($counts['contracts_seen'] ?? 0)),
            max(0, (int) ($counts['collections_emitted'] ?? 0)),
            max(0, (int) ($counts['collections_denied'] ?? 0)),
            $runId,
            $leaseToken,
            DiscoveryRunStatus::RUNNING
        ));

        return $wpdb->rows_affected === 1;
    }

    /**
     * running -> failed with a bounded code. Same confirmation contract as
     * {@see markSucceeded()}.
     */
    /**
     * @param string|null $stopReason preserved when the PASS produced one —
     *        a locked or refused pass is a failure of the RUN, but the
     *        reason it stopped is still the pass's own vocabulary, and
     *        collapsing the two would lose the distinction between "another
     *        worker holds the chain" and "the pass threw".
     */
    public static function markFailed(
        int $runId,
        string $leaseToken,
        string $errorCode,
        ?string $stopReason = null
    ): bool {
        global $wpdb;

        if ($runId <= 0 || $leaseToken === '' || !DiscoveryRunError::isValid($errorCode)) {
            return false;
        }

        $table      = self::table();
        $sqlStop    = $stopReason === null || $stopReason === ''
            ? 'NULL'
            : $wpdb->prepare('%s', substr($stopReason, 0, 40));

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET status = %s,
                    active_marker = NULL,
                    lease_token = NULL,
                    lease_expires_at = NULL,
                    next_retry_at = NULL,
                    finished_at = UTC_TIMESTAMP(),
                    error_code = %s,
                    stop_reason = {$sqlStop},
                    updated_at = UTC_TIMESTAMP()
              WHERE id = %d AND lease_token = %s AND status = %s",
            DiscoveryRunStatus::FAILED,
            $errorCode,
            $runId,
            $leaseToken,
            DiscoveryRunStatus::RUNNING
        ));

        return $wpdb->rows_affected === 1;
    }

    /**
     * queued -> cancelled. Administrator withdrawal of a run that never
     * started. There is deliberately no way to cancel a RUNNING run: it
     * holds a lease and may be mid-provider-call, and co-operative
     * cancellation is a separate piece of machinery.
     */
    public static function markCancelled(int $runId): bool
    {
        global $wpdb;

        if ($runId <= 0) {
            return false;
        }

        $table = self::table();

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET status = %s,
                    active_marker = NULL,
                    lease_token = NULL,
                    lease_expires_at = NULL,
                    next_retry_at = NULL,
                    finished_at = UTC_TIMESTAMP(),
                    updated_at = UTC_TIMESTAMP()
              WHERE id = %d AND status = %s",
            DiscoveryRunStatus::CANCELLED,
            $runId,
            DiscoveryRunStatus::QUEUED
        ));

        return $wpdb->rows_affected === 1;
    }

    /**
     * Record that a terminal run's secondary audit could not be written.
     *
     * Best-effort by design: the terminal result is already durable and
     * correct, and rolling it back would strand the run and repeat provider
     * work. This keeps the gap VISIBLE instead of silent.
     */
    public static function markAuditDegraded(int $runId): bool
    {
        global $wpdb;

        if ($runId <= 0) {
            return false;
        }

        $table = self::table();

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET audit_degraded = 1, updated_at = UTC_TIMESTAMP() WHERE id = %d",
            $runId
        ));

        return $wpdb->rows_affected === 1;
    }

    // ── Dispatch and recovery ───────────────────────────────────────────

    /**
     * Queued runs that are due to be dispatched.
     *
     * Used by the maintenance sweep to RE-dispatch work an administrator
     * already asked for. It selects nothing else: the sweep has no
     * chain-selection logic anywhere, which is the structural reason it can
     * never become an automatic scanner.
     *
     * @return list<DiscoveryRunRow>
     */
    public static function findDispatchable(int $limit = 20): array
    {
        global $wpdb;

        $table   = self::table();
        $columns = self::COLUMNS;
        $limit   = max(1, min($limit, 100));

        /** @var list<DiscoveryRunRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT {$columns} FROM {$table}
              WHERE status = %s
                AND active_marker IS NOT NULL
                AND (next_retry_at IS NULL OR next_retry_at <= UTC_TIMESTAMP())
                AND attempt_count < %d
              ORDER BY requested_at ASC, id ASC
              LIMIT %d",
            DiscoveryRunStatus::QUEUED,
            self::MAX_ATTEMPTS,
            $limit
        ));

        return $rows ?: [];
    }

    /**
     * Return one expired lease to `queued` with backoff — WITHOUT bumping
     * the attempt counter.
     *
     * ⚠ A LEASE EXPIRY IS NOT AN ATTEMPT.
     * The claim already counted it. Counting it twice would let a flapping
     * worker burn all three attempts on a perfectly healthy run inside
     * fifteen minutes, and the operator would see a terminal failure caused
     * entirely by infrastructure.
     */
    public static function requeueExpiredLease(int $runId): bool
    {
        global $wpdb;

        if ($runId <= 0) {
            return false;
        }

        $table = self::table();

        // Backoff is chosen from attempts ALREADY consumed, clamped to the
        // last entry so a longer table never produces an undefined index.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET status = %s,
                    lease_token = NULL,
                    lease_expires_at = NULL,
                    next_retry_at = DATE_ADD(
                        UTC_TIMESTAMP(),
                        INTERVAL ELT(LEAST(GREATEST(attempt_count, 1), %d), %d, %d, %d) SECOND
                    ),
                    updated_at = UTC_TIMESTAMP()
              WHERE id = %d
                AND status = %s
                AND lease_expires_at IS NOT NULL
                AND lease_expires_at < UTC_TIMESTAMP()
                AND attempt_count < %d",
            DiscoveryRunStatus::QUEUED,
            count(self::RETRY_BACKOFF_SECONDS),
            self::RETRY_BACKOFF_SECONDS[0],
            self::RETRY_BACKOFF_SECONDS[1],
            self::RETRY_BACKOFF_SECONDS[2],
            $runId,
            DiscoveryRunStatus::RUNNING,
            self::MAX_ATTEMPTS
        ));

        return $wpdb->rows_affected === 1;
    }

    /**
     * An expired lease on a run that has already consumed every attempt is
     * terminal. Nothing will pick it up again, so leaving it `running`
     * forever would be a lie told by omission.
     */
    public static function terminalizeExhausted(int $runId): bool
    {
        global $wpdb;

        if ($runId <= 0) {
            return false;
        }

        $table = self::table();

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET status = %s,
                    active_marker = NULL,
                    lease_token = NULL,
                    lease_expires_at = NULL,
                    next_retry_at = NULL,
                    finished_at = UTC_TIMESTAMP(),
                    error_code = %s,
                    updated_at = UTC_TIMESTAMP()
              WHERE id = %d
                AND status = %s
                AND lease_expires_at IS NOT NULL
                AND lease_expires_at < UTC_TIMESTAMP()
                AND attempt_count >= %d",
            DiscoveryRunStatus::FAILED,
            DiscoveryRunError::MAX_ATTEMPTS_EXHAUSTED,
            $runId,
            DiscoveryRunStatus::RUNNING,
            self::MAX_ATTEMPTS
        ));

        return $wpdb->rows_affected === 1;
    }

    /**
     * Runs whose lease has expired, for the reaper to triage.
     *
     * @return list<DiscoveryRunRow>
     */
    public static function findExpiredLeases(int $limit = 20): array
    {
        global $wpdb;

        $table   = self::table();
        $columns = self::COLUMNS;
        $limit   = max(1, min($limit, 100));

        /** @var list<DiscoveryRunRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT {$columns} FROM {$table}
              WHERE status = %s
                AND lease_expires_at IS NOT NULL
                AND lease_expires_at < UTC_TIMESTAMP()
              ORDER BY lease_expires_at ASC, id ASC
              LIMIT %d",
            DiscoveryRunStatus::RUNNING,
            $limit
        ));

        return $rows ?: [];
    }

    // ── Retention ───────────────────────────────────────────────────────

    /**
     * Prune old terminal history in one bounded batch.
     *
     * Three guarantees, each of which a mutation control proves:
     *   - never touches a row with `active_marker IS NOT NULL`;
     *   - never deletes the newest succeeded or newest failed run for a
     *     (job_kind, chain_id), so the status read model's last-success and
     *     last-failure can never go blank;
     *   - bounded batch, so a large history cannot stall a cron tick.
     *
     * @return int rows deleted
     */
    public static function pruneTerminal(int $batchSize = 200): int
    {
        global $wpdb;

        $table     = self::table();
        $batchSize = max(1, min($batchSize, 1000));

        // ── STEP 1: the keepers ─────────────────────────────────────────
        // Resolved in PHP, in two steps, for two reasons.
        //
        //   1. MySQL's MULTI-TABLE `DELETE … JOIN` does NOT support LIMIT,
        //      so a join-based prune cannot be bounded — it would either
        //      delete everything matching or nothing at all.
        //   2. The keeper must be the SAME row `findLatestByStatus()`
        //      returns, or pruning can delete exactly the run the status
        //      read model is about to display. That ordering is
        //      `finished_at DESC, id DESC`, and expressing it per group
        //      without window functions (MySQL 8 / MariaDB 10.2+, which this
        //      schema does not otherwise require) is not worth the SQL.
        $keepers = [];

        /** @var list<object{job_kind: string, chain_id: string, status: string}>|null $groups */
        $groups = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT job_kind, chain_id, status FROM {$table} WHERE status IN (%s, %s)",
            DiscoveryRunStatus::SUCCEEDED,
            DiscoveryRunStatus::FAILED
        ));

        foreach ((array) $groups as $group) {
            $latest = self::findLatestByStatus(
                (string) $group->job_kind,
                (int) $group->chain_id,
                (string) $group->status
            );

            if ($latest !== null) {
                $keepers[] = (int) $latest->id;
            }
        }

        // ── STEP 2: bounded single-table DELETE ─────────────────────────
        $keepClause = '';
        if ($keepers !== []) {
            // Integers only, cast above — safe to inline, and `NOT IN` with
            // a placeholder list would need a dynamic format string.
            $keepClause = ' AND id NOT IN (' . implode(',', $keepers) . ')';
        }

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table}
              WHERE active_marker IS NULL
                AND finished_at IS NOT NULL
                AND finished_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
                {$keepClause}
              LIMIT %d",
            self::RETENTION_DAYS,
            $batchSize
        ));

        return $deleted === false ? 0 : (int) $deleted;
    }

    // ── Read model ──────────────────────────────────────────────────────

    /**
     * The newest terminal run of one status for a target.
     *
     * ⚠ Ordered by `finished_at`, then `id` as the tiebreak. Ordering by
     * `id` alone would be wrong the moment a run that started earlier
     * finishes later — which is exactly what happens when one run is
     * retried while a newer one completes quickly.
     *
     * @return DiscoveryRunRow|null
     */
    public static function findLatestByStatus(string $jobKind, int $chainId, string $status): ?object
    {
        global $wpdb;

        if (!DiscoveryJobKind::isValid($jobKind)
            || $chainId <= 0
            || !DiscoveryRunStatus::isValid($status)
        ) {
            return null;
        }

        $table   = self::table();
        $columns = self::COLUMNS;

        /** @var DiscoveryRunRow|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT {$columns} FROM {$table}
              WHERE job_kind = %s AND chain_id = %d AND status = %s
              ORDER BY finished_at DESC, id DESC
              LIMIT 1",
            $jobKind,
            $chainId,
            $status
        ));

        return $row;
    }

    /**
     * The newest run of any status for a target — the one an operator is
     * looking at when no run is active.
     *
     * @return DiscoveryRunRow|null
     */
    public static function findLatest(string $jobKind, int $chainId): ?object
    {
        global $wpdb;

        if (!DiscoveryJobKind::isValid($jobKind) || $chainId <= 0) {
            return null;
        }

        $table   = self::table();
        $columns = self::COLUMNS;

        /** @var DiscoveryRunRow|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT {$columns} FROM {$table}
              WHERE job_kind = %s AND chain_id = %d
              ORDER BY requested_at DESC, id DESC
              LIMIT 1",
            $jobKind,
            $chainId
        ));

        return $row;
    }
}
