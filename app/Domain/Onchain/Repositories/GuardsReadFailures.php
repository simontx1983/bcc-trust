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
 * Call {@see guardRead()} immediately after any `get_results` /
 * `get_row` / `get_col` / `get_var`. If `$wpdb->last_error` is non-empty,
 * it logs at ERROR with the class and method. The read still returns its
 * empty result — this is a diagnostic, not a control-flow change — but
 * the failure now leaves a trace.
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
     * Log a SQL error from the read that just ran, if there was one.
     *
     * @param string $method usually `__FUNCTION__` at the call site
     */
    private static function guardRead(string $method): void
    {
        global $wpdb;

        $error = isset($wpdb->last_error) ? (string) $wpdb->last_error : '';
        if ($error === '') {
            return;
        }

        Logger::error('[' . static::class . '] read failed — empty result is NOT "no rows"', [
            'method'   => $method,
            'db_error' => $error,
        ]);
    }
}
