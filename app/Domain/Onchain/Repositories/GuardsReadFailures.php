<?php

namespace BCC\Trust\Onchain\Repositories;

use BCC\Core\Log\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Makes a FAILED repository read loud instead of silent.
 *
 * ── THE FAILURE CLASS THIS EXISTS FOR ───────────────────────────────────
 * `$wpdb->get_results()` returns null on a SQL error, and the house idiom
 * `return $rows ?: [];` turns that null into an empty array. A caller then
 * cannot tell "the query ran and matched nothing" from "the query was
 * malformed and never ran". For a worker whose loops are driven by
 * "is there anything to do?", those two answers produce identical
 * behaviour — do nothing — and identical health signals — success.
 *
 * That is not hypothetical. A `ORDER BY 0` in
 * {@see CosmwasmCodeFamilyRepository::findPendingClassification()} (MySQL
 * reads a bare integer in ORDER BY as a 1-based column ORDINAL, so `0` is
 * "Unknown column '0' in 'order clause'") made classification return zero
 * rows in the DEFAULT configuration. The worker enumerated code families
 * correctly, reported success, and then did nothing at all — forever,
 * with a full request budget and no error anywhere.
 *
 * ── WHAT THIS DOES ──────────────────────────────────────────────────────
 * Call ONE of these immediately after any `get_results` / `get_row` /
 * `get_col` / `get_var`. All three detect the failure and log it at ERROR
 * with the class and method; they differ only in what the caller is then
 * forced to do about it.
 *
 *   {@see guardRead()}         FAIL-SAFE. Logs, returns, the read still
 *                              yields its empty shape. For WORKER paths:
 *                              an exception there would turn a logged
 *                              degradation into a fatal, and "nothing to
 *                              do this tick, retry next" is a safe
 *                              reading of an empty queue.
 *
 *   {@see guardReadOrThrow()}  FAIL-CLOSED. Logs, then throws
 *                              {@see RepositoryReadFailure}. For
 *                              OPERATOR-FACING paths, where `[]` / `0` /
 *                              `idle` / `green` are ANSWERS and returning
 *                              one after a failed query reports a healthy
 *                              empty system that nobody has looked at.
 *
 *   {@see readFailed()}        Logs and RETURNS the verdict, for the two
 *                              reads that sit inside a write path and
 *                              have to decide what to do with the write.
 *
 * Neither variant changes a SUCCESSFUL return shape, which is why
 * adopting the throwing one required no caller rewrites.
 *
 * ── PICKING ONE ─────────────────────────────────────────────────────────
 * The test is who reads the answer, not which table it came from. The
 * same table is read fail-safe by the discovery worker and fail-closed by
 * the admin panel, and both are correct:
 * {@see ChainCheckpointRepository::getAll()} vs
 * {@see ChainCheckpointRepository::getAllOrFail()} are one query behind
 * two policies, not two queries.
 *
 * Portability note: it checks `last_error` rather than a null return
 * because the integration harness's mysqli-backed `$wpdb` shim returns
 * `[]` (not null) on error while still setting `last_error`. Checking the
 * error string works identically under real WordPress and under the test
 * shim.
 *
 * PII: `Logger` scrubs context values for secret-bearing and wallet-shaped
 * KEYS. `method` and `db_error` match neither, so the diagnostic survives
 * redaction intact — and a MySQL error string carries SQL structure, not
 * member data.
 */
trait GuardsReadFailures
{
    /**
     * FAIL-SAFE. Log a SQL error from the read that just ran, if there was
     * one, and carry on with the empty result.
     *
     * @param string $method usually `__FUNCTION__` at the call site
     */
    private static function guardRead(string $method): void
    {
        self::readFailed($method);
    }

    /**
     * FAIL-CLOSED. Log a SQL error from the read that just ran and refuse
     * to let the caller mistake the empty result for an answer.
     *
     * @param  string $method usually `__FUNCTION__` at the call site
     * @throws RepositoryReadFailure when the read did not run
     */
    private static function guardReadOrThrow(string $method): void
    {
        if (!self::readFailed($method)) {
            return;
        }

        throw new RepositoryReadFailure(static::class, $method, self::lastReadError());
    }

    /**
     * Detect + log, and hand the verdict back.
     *
     * The variant for a read that sits INSIDE a write path, where neither
     * "carry on" nor "throw" is automatically right: the caller has to
     * decide what the pending write should now do. See
     * {@see ChainCheckpointRepository::recordSuccess()}.
     *
     * @param  string $method usually `__FUNCTION__` at the call site
     * @return bool   true when the read that just ran FAILED
     */
    private static function readFailed(string $method): bool
    {
        $error = self::lastReadError();
        if ($error === '') {
            return false;
        }

        Logger::error('[' . static::class . '] read failed — empty result is NOT "no rows"', [
            'method'   => $method,
            'db_error' => $error,
        ]);

        return true;
    }

    /**
     * Run a SELECT and return its rows ONLY if they are this query's.
     *
     * The list-read form of bcc-core's PeepSoBlockRepository::runProbe().
     *
     * ⚠⚠ WHY query() IS CALLED EXPLICITLY. `get_results($sql)` calls query()
     * and DISCARDS its return value, and `wpdb::query()` has failure paths
     * that return false WITHOUT setting `last_error` — the `! $this->ready`
     * guard (a failed reconnect) and an empty result from the `query` filter.
     * Both return BEFORE `flush()`, so `last_result` still holds the PREVIOUS
     * query's rows: a `get_results()` + `last_error` check can hand a caller
     * an unrelated query's rows as an answer. For a caller that PUTs its
     * result as a whole external list (the Helius resync, F-64) that is a
     * corrupted write, not merely a wrong read.
     *
     * ⚠ THE COMPARISON IS `=== false`, NOT FALSY. For a SELECT, query()
     * returns the ROW COUNT, so a genuine empty result returns 0.
     *
     * @param  string $method usually `__FUNCTION__` at the call site
     * @return list<object>
     * @throws RepositoryReadFailure when $failClosed and the read did not run
     */
    private static function runRead(string $sql, string $method, bool $failClosed): array
    {
        global $wpdb;

        $wpdb->last_error = '';
        $ok    = $wpdb->query($sql);
        $error = self::lastReadError();

        if ($ok === false || $error !== '') {
            $reason = $error !== '' ? $error : 'query() returned false with no error (wpdb early return)';
            Logger::error('[' . static::class . '] read failed — empty result is NOT "no rows"', [
                'method'   => $method,
                'db_error' => $reason,
            ]);
            if ($failClosed) {
                throw new RepositoryReadFailure(static::class, $method, $reason);
            }
            return [];
        }

        $rows = $wpdb->last_result;

        return is_array($rows) ? array_values($rows) : [];
    }

    /** The error string left behind by the most recent query, if any. */
    private static function lastReadError(): string
    {
        global $wpdb;

        return isset($wpdb->last_error) ? (string) $wpdb->last_error : '';
    }
}
