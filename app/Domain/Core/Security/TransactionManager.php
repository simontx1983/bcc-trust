<?php
/**
 * Enterprise Transaction Manager
 *
 * - Ensures atomic DB operations
 * - Handles rollback on failure
 * - Safe for high concurrency
 * - Deadlock retry support with configurable backoff
 * 
 * @package BCC\Trust\Core\Security
 * @version 2.0.0
 */

namespace BCC\Trust\Core\Security;

use Exception;
use Throwable;

if (!defined('ABSPATH')) {
    exit;
}

class TransactionManager {

    /**
     * Config properties
     */
    private static int $retryAttempts;
    private static int $backoffMs;
    private static int $maxBackoffMs;
    private static bool $logDeadlocks;

    /**
     * Tracks nested run() calls so savepoint() can be used automatically.
     */
    private static int $transactionDepth = 0;

    /**
     * Initialize config values
     */
    private static function initConfig(): void {
        if (!isset(self::$retryAttempts)) {
            self::$retryAttempts = BCC_TRUST_TX_RETRY_ATTEMPTS;
            self::$backoffMs     = BCC_TRUST_TX_BACKOFF_MS;
            self::$maxBackoffMs  = BCC_TRUST_TX_MAX_BACKOFF_MS;
            self::$logDeadlocks  = BCC_TRUST_LOG_DEADLOCKS;
        }
    }

    /**
     * Execute callback inside DB transaction
     *
     * @param callable $callback
     * @param int|null $retryAttempts
     * @return mixed
     * @throws Exception
     */
    public static function run(callable $callback, ?int $retryAttempts = null) {
        self::initConfig();
        global $wpdb; // Exemption: transaction control statements (BEGIN/COMMIT/ROLLBACK/SAVEPOINT) cannot be abstracted behind a repository.

        // --- Stale-depth recovery (audit MED-1) ---------------------------
        // $transactionDepth is a static int.  On PHP-FPM worker reuse, if a
        // previous request died with an uncaught fatal (OOM, SIGKILL) while
        // the depth was > 0, the counter persists into the next request.
        // We'd then treat ourselves as nested, issue SAVEPOINT against a
        // non-existent outer tx, and corrupt the isInRunTransaction() gate
        // in repositories — opening a lost-update window on score rows.
        //
        // Detect the stale state: if the counter claims we're nested but
        // MySQL reports we're NOT in a transaction, reset.  @@in_transaction
        // returns 1 while a txn is open, 0 otherwise, and has no side
        // effects; using it as a liveness probe is cheap.
        if (self::$transactionDepth > 0) {
            $inTx = $wpdb->get_var('SELECT @@in_transaction');
            if ($inTx === null || (int) $inTx === 0) {
                \BCC\Core\Log\Logger::warning('[BCC Trust Transaction] Stale transactionDepth reset', [
                    'stale_depth' => self::$transactionDepth,
                ]);
                self::$transactionDepth = 0;
            }
        }

        // --- Nested call: use a SAVEPOINT instead of a new transaction. ---
        // Name uses cryptographic randomness (8 hex chars, 32 bits) instead of
        // mt_rand(1000, 9999).  Under pathological nesting a 4-digit space has
        // a meaningful collision probability — and a collision silently
        // re-defines the prior savepoint so ROLLBACK TO resolves to the wrong
        // target.  random_bytes is available since PHP 7.
        if (self::$transactionDepth > 0) {
            $savepointName = 'sp_' . self::$transactionDepth . '_' . bin2hex(random_bytes(4));
            return self::savepoint($savepointName, $callback);
        }

        $attempts = $retryAttempts ?? self::$retryAttempts;
        $attempt = 0;
        $lastException = null;

        self::$transactionDepth++;

        try {
            while ($attempt < $attempts) {
                try {
                    $attempt++;

                    // Start transaction.  $wpdb->query() returns false on driver
                    // error (connection reset, permission anomaly, implicit-commit
                    // in progress).  If we proceed past a false return, every
                    // write inside the callback executes under autocommit and the
                    // ROLLBACK in the catch becomes a no-op — i.e. a silent
                    // partial-commit.  Fail fast instead so the caller sees a
                    // clear error and the retry loop can re-attempt.
                    $txStarted = $wpdb->query('START TRANSACTION');
                    if ($txStarted === false) {
                        throw new \RuntimeException(
                            '[TransactionManager] START TRANSACTION failed: '
                            . (string) $wpdb->last_error
                        );
                    }

                    // Execute callback
                    $result = $callback();

                    // Legacy failure signal: a closure returning `false` is
                    // interpreted as "please roll back and retry".  NEW CALLERS
                    // SHOULD THROW INSTEAD — a thrown exception carries context
                    // that a bare `false` cannot.  Existing call sites (notably
                    // ScoreContributorService::applyBonus) still rely on this
                    // sentinel; do not remove without sweeping those callers.
                    if ($result === false) {
                        throw new Exception('Transaction callback returned false');
                    }

                    // Commit.  A false return from COMMIT means MySQL rolled the
                    // transaction back itself (deadlock detected at commit time,
                    // serialization failure, connection drop).  Surfacing this
                    // prevents callers from treating a rolled-back transaction
                    // as a successful write.
                    $committed = $wpdb->query('COMMIT');
                    if ($committed === false) {
                        \BCC\Core\Log\Logger::error('[BCC Trust Transaction] COMMIT failed', [
                            'attempt'  => $attempt,
                            'db_error' => (string) $wpdb->last_error,
                        ]);
                        throw new \RuntimeException(
                            '[TransactionManager] COMMIT failed: '
                            . (string) $wpdb->last_error
                        );
                    }

                    // Log success for debugging
                    if (self::$logDeadlocks && $attempt > 1) {
                        \BCC\Core\Log\Logger::info(sprintf(
                            '[BCC Trust Transaction] Success after %d attempt(s)',
                            $attempt
                        ));
                    }

                    return $result;

                } catch (Throwable $e) { // Catch both Exception and Error
                    // Rollback on error.  A false return here means the rollback
                    // itself failed — either because the connection is dead or
                    // because the callback ran DDL that triggered an implicit
                    // commit.  In that scenario writes may have landed silently;
                    // log loudly so the forensic trail survives.  The original
                    // exception is still rethrown below.
                    $rolledBack = $wpdb->query('ROLLBACK');
                    if ($rolledBack === false) {
                        \BCC\Core\Log\Logger::error('[BCC Trust Transaction] ROLLBACK failed after callback exception', [
                            'attempt'        => $attempt,
                            'original_error' => $e->getMessage(),
                            'db_error'       => (string) $wpdb->last_error,
                        ]);
                    }

                    $lastException = $e;

                    // Retry on deadlock (MySQL error 1213) or lock timeout (1205)
                    if (self::isDeadlock($e) && $attempt < $attempts) {
                        // Exponential backoff with jitter
                        $sleepMs = min(
                            self::$maxBackoffMs,
                            self::$backoffMs * pow(2, $attempt - 1)
                        );
                        $jitter = mt_rand(0, (int)($sleepMs * 0.1));

                        if (self::$logDeadlocks) {
                            \BCC\Core\Log\Logger::error(sprintf(
                                '[BCC Trust Transaction] Deadlock detected, retrying (%d/%d) after %dms',
                                $attempt,
                                $attempts,
                                $sleepMs + $jitter
                            ));
                        }

                        usleep(($sleepMs + $jitter) * 1000);

                        continue;
                    }

                    // Re-throw if not a deadlock or out of retries
                    throw $e;
                }
            }
        } finally {
            self::$transactionDepth--;
        }

        throw new Exception(
            'Transaction failed after ' . $attempts . ' attempts',
            0,
            $lastException
        );
    }

    /**
     * Detect MySQL deadlock or lock timeout.
     *
     * Preferred signal is the exception code — error 1213 (deadlock), 1205
     * (lock wait timeout), 40001 (serialization failure). The message-
     * pattern fallback exists because $wpdb wraps some driver errors
     * without propagating the numeric code.
     *
     * The message-pattern list is intentionally strict: the previous
     * loose `'deadlock'` substring match meant any business exception
     * whose text mentioned the word (e.g., "deadlock suspected in user
     * input") would trip the deadlock-retry loop. We now match full
     * phrases that only the MySQL driver emits.
     */
    private static function isDeadlock(Throwable $e): bool {
        $code = $e->getCode();

        // Preferred signal: numeric/string error codes emitted by the driver.
        if (in_array($code, [1213, 1205, '1213', '1205', 40001, '40001'], true)) {
            return true;
        }

        $message = $e->getMessage();

        // Strict MySQL-driver phrases only. Case-insensitive to tolerate
        // small wording changes across vendors, but the phrases are long
        // enough that application code cannot trip them by accident.
        $deadlockPatterns = [
            'Deadlock found when trying to get lock',
            'Lock wait timeout exceeded',
            'try restarting transaction',
        ];

        foreach ($deadlockPatterns as $pattern) {
            if (stripos($message, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Execute with savepoint (nested transactions)
     *
     * @return mixed
     */
    public static function savepoint(string $name, callable $callback) {
        // Guard: savepoints are only valid inside an active run() transaction.
        // Without this, $transactionDepth leaks upward permanently, causing
        // all subsequent run() calls to use SAVEPOINTs that never COMMIT.
        if (self::$transactionDepth === 0) {
            throw new \LogicException('savepoint() must be called inside run() — cannot create a savepoint without an active transaction.');
        }

        global $wpdb; // Exemption: transaction control statements (BEGIN/COMMIT/ROLLBACK/SAVEPOINT) cannot be abstracted behind a repository.

        // Sanitize savepoint name to prevent SQL injection.  A name that
        // sanitizes to the empty string (e.g. '!!!') would emit a malformed
        // SAVEPOINT statement and MySQL's error would only surface when the
        // RELEASE/ROLLBACK ran — reject up front.
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', $name);
        if ($name === '') {
            throw new \LogicException('savepoint(): name must contain at least one [a-zA-Z0-9_] character');
        }

        $savepointed = $wpdb->query("SAVEPOINT {$name}");
        if ($savepointed === false) {
            throw new \RuntimeException(
                '[TransactionManager] SAVEPOINT failed: ' . (string) $wpdb->last_error
            );
        }
        self::$transactionDepth++;

        try {
            $result = $callback();

            // Mirror run(): `return false` from the callback is the legacy
            // failure sentinel.  Without this, a savepointed nested call
            // returning false would be RELEASEd (committed into the outer
            // transaction) — silently promoting a failure signal to success.
            if ($result === false) {
                throw new Exception('Savepoint callback returned false');
            }

            $released = $wpdb->query("RELEASE SAVEPOINT {$name}");
            if ($released === false) {
                // MySQL drops the savepoint on outer COMMIT anyway, so a
                // failed RELEASE is surprising rather than fatal — but log it
                // so schema-drift symptoms do not get diagnosed blind.
                \BCC\Core\Log\Logger::warning('[BCC Trust Transaction] RELEASE SAVEPOINT failed', [
                    'savepoint' => $name,
                    'db_error'  => (string) $wpdb->last_error,
                ]);
            }
            return $result;
        } catch (Throwable $e) {
            $rolledBack = $wpdb->query("ROLLBACK TO SAVEPOINT {$name}");
            if ($rolledBack === false) {
                \BCC\Core\Log\Logger::error('[BCC Trust Transaction] ROLLBACK TO SAVEPOINT failed', [
                    'savepoint'      => $name,
                    'original_error' => $e->getMessage(),
                    'db_error'       => (string) $wpdb->last_error,
                ]);
            }
            throw $e;
        } finally {
            self::$transactionDepth--;
        }
    }

    /**
     * Returns true if the current call stack is inside a run() transaction.
     * Faster than inTransaction() — no DB round-trip required.
     */
    public static function isInRunTransaction(): bool {
        return self::$transactionDepth > 0;
    }

}