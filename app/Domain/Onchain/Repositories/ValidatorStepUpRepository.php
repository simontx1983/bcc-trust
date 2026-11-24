<?php

namespace BCC\Trust\Onchain\Repositories;

use BCC\Trust\Core\Database\TableRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Storage for staff step-ups (see schema-validator-step-ups.php). Every
 * state change is ONE conditional UPDATE, so it is atomic without a lock:
 *
 *  - {@see countAttempt()} adds an attempt only while the step-up is live
 *    and under the cap. It runs BEFORE a code is compared, and on its own
 *    (it commits even when the action later rolls back), so wrong codes are
 *    always counted.
 *  - {@see consume()} sets used_at only when EVERY binding column matches
 *    and the step-up is verified, unused and unexpired. It runs inside the
 *    action's transaction, so the step-up is spent exactly when the change
 *    commits.
 *
 * Times are UTC strings computed by the caller in PHP, never MySQL NOW().
 *
 * @phpstan-type StepUpRow object{id: string, user_id: numeric-string, action: string, page_id: numeric-string, target_user_id: numeric-string, role: string, code_hash: string|null, credential_verified: numeric-string, attempts: numeric-string, created_at: string, expires_at: string, resend_after: string|null, used_at: string|null}
 *
 * @package BCC\Trust\Onchain\Repositories
 */
final class ValidatorStepUpRepository
{
    use GuardsReadFailures;

    /** Wrong codes (or code checks) a step-up survives. */
    public const MAX_ATTEMPTS = 5;

    /** Most expired rows one purge deletes. */
    public const PURGE_BATCH = 500;

    private const COLUMNS = 'id, user_id, action, page_id, target_user_id, role, code_hash, credential_verified,
                             attempts, created_at, expires_at, resend_after, used_at';

    public static function table(): string
    {
        return TableRegistry::validatorStepUps();
    }

    /**
     * @param array{id: string, user_id: int, action: string, page_id: int, target_user_id: int, role: string, code_hash: string|null, created_at: string, expires_at: string, resend_after: string|null} $row
     * @throws \RuntimeException when the insert fails
     */
    public static function create(array $row): void
    {
        global $wpdb;
        $ok = $wpdb->insert(self::table(), [
            'id'                  => $row['id'],
            'user_id'             => $row['user_id'],
            'action'              => $row['action'],
            'page_id'             => $row['page_id'],
            'target_user_id'      => $row['target_user_id'],
            'role'                => $row['role'],
            'code_hash'           => $row['code_hash'],
            'credential_verified' => 1,
            'attempts'            => 0,
            'created_at'          => $row['created_at'],
            'expires_at'          => $row['expires_at'],
            'resend_after'        => $row['resend_after'],
        ], ['%s', '%d', '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s']);

        if ($ok !== 1) {
            throw new \RuntimeException('Step-up could not be stored: ' . (string) $wpdb->last_error);
        }
    }

    /**
     * The step-up $id if it belongs to $userId, else null. Read fail-closed.
     *
     * @return StepUpRow|null
     * @throws RepositoryReadFailure
     */
    public static function find(string $id, int $userId): ?object
    {
        global $wpdb;
        $table = self::table();

        /** @var list<StepUpRow> $rows */
        $rows = self::runRead($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$table} WHERE id = %s AND user_id = %d LIMIT 1",
            $id,
            $userId
        ), __FUNCTION__, true);

        return $rows[0] ?? null;
    }

    /**
     * Count one attempt, atomically, while the step-up is live and under the
     * cap. False: it is dead (expired, used, capped or not this user's).
     *
     * @throws \RuntimeException when the update itself fails
     */
    public static function countAttempt(string $id, int $userId, string $nowUtc): bool
    {
        global $wpdb;
        $table = self::table();

        return self::changedOne($wpdb->prepare(
            "UPDATE {$table} SET attempts = attempts + 1
              WHERE id = %s AND user_id = %d AND used_at IS NULL AND expires_at > %s AND attempts < %d",
            $id,
            $userId,
            $nowUtc,
            self::MAX_ATTEMPTS
        ));
    }

    /**
     * Spend the step-up, atomically, only for its EXACT binding. Must run
     * inside the action's transaction.
     *
     * @param array{id: string, user_id: int, action: string, page_id: int, target_user_id: int, role: string} $binding
     * @throws \RuntimeException when the update itself fails
     */
    public static function consume(array $binding, string $nowUtc): bool
    {
        global $wpdb;
        $table = self::table();

        return self::changedOne($wpdb->prepare(
            "UPDATE {$table} SET used_at = %s
              WHERE id = %s AND user_id = %d AND action = %s AND page_id = %d AND target_user_id = %d AND role = %s
                AND credential_verified = 1 AND used_at IS NULL AND expires_at > %s AND attempts <= %d",
            $nowUtc,
            $binding['id'],
            $binding['user_id'],
            $binding['action'],
            $binding['page_id'],
            $binding['target_user_id'],
            $binding['role'],
            $nowUtc,
            self::MAX_ATTEMPTS
        ));
    }

    /**
     * Replace the code of a live, code-bearing step-up once its resend
     * window has passed. Attempts are NOT reset.
     *
     * @throws \RuntimeException when the update itself fails
     */
    public static function replaceCode(string $id, int $userId, string $codeHash, string $expiresAt, string $resendAfter, string $nowUtc): bool
    {
        global $wpdb;
        $table = self::table();

        return self::changedOne($wpdb->prepare(
            "UPDATE {$table} SET code_hash = %s, expires_at = %s, resend_after = %s
              WHERE id = %s AND user_id = %d AND code_hash IS NOT NULL AND used_at IS NULL
                AND expires_at > %s AND attempts < %d AND (resend_after IS NULL OR resend_after <= %s)",
            $codeHash,
            $expiresAt,
            $resendAfter,
            $id,
            $userId,
            $nowUtc,
            self::MAX_ATTEMPTS,
            $nowUtc
        ));
    }

    /**
     * Delete up to PURGE_BATCH rows that expired before $beforeUtc.
     *
     * @return int rows deleted
     * @throws \RuntimeException when the delete fails
     */
    public static function purgeExpired(string $beforeUtc): int
    {
        global $wpdb;
        $table = self::table();

        $wpdb->last_error = '';
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE expires_at < %s ORDER BY expires_at ASC LIMIT %d",
            $beforeUtc,
            self::PURGE_BATCH
        ));
        if ($deleted === false || self::lastReadError() !== '') {
            throw new \RuntimeException('Step-up purge failed: ' . (string) $wpdb->last_error);
        }

        return (int) $deleted;
    }

    /**
     * Run a conditional UPDATE; true when exactly one row changed.
     *
     * @throws \RuntimeException when the update fails
     */
    private static function changedOne(string $sql): bool
    {
        global $wpdb;
        $wpdb->last_error = '';
        $affected = $wpdb->query($sql);
        if ($affected === false || self::lastReadError() !== '') {
            throw new \RuntimeException('Step-up update failed: ' . (string) $wpdb->last_error);
        }

        return (int) $affected === 1;
    }
}
