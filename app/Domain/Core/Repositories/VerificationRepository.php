<?php
/**
 * Verification Repository
 *
 * Handles secure email verification tokens.
 * Uses config constants for expiration and cleanup settings.
 *
 * @package BCC\Trust\Core\Repositories
 * @version 2.0.0
 */

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\Exceptions\RepositoryException;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Row shape returned by bcc_trust_verifications reads.
 *
 * @phpstan-type VerificationTokenRow object{
 *   id: int|numeric-string,
 *   user_id: int|numeric-string,
 *   verification_code: string,
 *   verified_at: string|null,
 *   expires_at: string,
 *   created_at: string
 * }
 */
class VerificationRepository {

    /** Explicit column list for bcc_trust_verifications table. */
    private const COLUMNS = 'id, user_id, verification_code, verified_at, expires_at, created_at';

    private const CACHE_GROUP = 'bcc_trust_verifications';
    private const CACHE_TTL   = 60; // seconds

    private string $table;
    private UserInfoRepository $userInfoRepo;
    
    /**
     * Config constants
     */
    private int $verificationExpiryHours;
    private int $cleanupVerifiedDays;
    private int $rateLimitHours;
    private int $rateLimitMax;

    public function __construct() {
        $this->table = \BCC\Trust\Core\Database\TableRegistry::verifications();
        $this->userInfoRepo = new UserInfoRepository();
        
        $this->verificationExpiryHours = BCC_TRUST_VERIFICATION_EXPIRY_HOURS;
        $this->cleanupVerifiedDays     = BCC_TRUST_CLEANUP_VERIFIED;
        $this->rateLimitHours          = BCC_TRUST_VERIFICATION_RATE_HOURS;
        $this->rateLimitMax            = BCC_TRUST_VERIFICATION_RATE_MAX;
    }

    /**
     * Create verification token with config-based expiry
     */
    public function create(int $userId, string $tokenHash): bool {
        global $wpdb;

        // Check rate limit using config
        $resendCount = $this->getResendCount($userId, $this->rateLimitHours);
        if ($resendCount >= $this->rateLimitMax) {
            throw new RepositoryException(
                "Verification rate limit exceeded for user {$userId}: {$resendCount} attempts in {$this->rateLimitHours} hours"
            );
        }

        // Delete any existing unused tokens for this user (verified_at IS NULL — wpdb->delete can't match NULL)
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table} WHERE user_id = %d AND verified_at IS NULL",
            $userId
        ));

        // Set expiry using config
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$this->verificationExpiryHours} hours") ?: 0);

        // Omit verified_at so the column receives SQL NULL from its
        // DEFAULT NULL definition.  Passing null with %s would store an
        // empty string, breaking all `verified_at IS NULL` queries.
        return (bool) $wpdb->insert(
            $this->table,
            [
                'user_id'           => $userId,
                'verification_code' => $tokenHash,
                'expires_at'        => $expiresAt,
                'created_at'        => current_time('mysql', true)
            ],
            ['%d', '%s', '%s', '%s']
        );
    }

    /**
     * Get valid token
     *
     * @phpstan-return VerificationTokenRow|null
     */
    public function getValid(int $userId, string $tokenHash): ?object {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT " . self::COLUMNS . " FROM {$this->table}
                 WHERE user_id = %d
                 AND verification_code = %s
                 AND verified_at IS NULL
                 AND expires_at > UTC_TIMESTAMP()
                 ORDER BY created_at DESC
                 LIMIT 1",
                $userId,
                $tokenHash
            )
        );
    }

    /**
     * Get valid token with an exclusive row lock (FOR UPDATE).
     *
     * MUST be called inside a TransactionManager::run() closure.
     * Prevents token replay: two concurrent requests with the same
     * token will serialize on the row lock, and the second will see
     * verified_at IS NOT NULL after the first commits.
     *
     * @phpstan-return VerificationTokenRow|null
     */
    public function getValidForUpdate(int $userId, string $tokenHash): ?object {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT " . self::COLUMNS . " FROM {$this->table}
                 WHERE user_id = %d
                 AND verification_code = %s
                 AND verified_at IS NULL
                 AND expires_at > UTC_TIMESTAMP()
                 ORDER BY created_at DESC
                 LIMIT 1
                 FOR UPDATE",
                $userId,
                $tokenHash
            )
        );
    }

    /**
     * Get token by code (for verification links)
     *
     * @phpstan-return VerificationTokenRow|null
     */
    public function getByCode(string $tokenHash): ?object {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT " . self::COLUMNS . " FROM {$this->table}
                 WHERE verification_code = %s
                 AND verified_at IS NULL
                 AND expires_at > UTC_TIMESTAMP()
                 ORDER BY created_at DESC
                 LIMIT 1",
                $tokenHash
            )
        );
    }

    /**
     * Get latest token for user
     *
     * @phpstan-return VerificationTokenRow|null
     */
    public function getLatest(int $userId): ?object {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT " . self::COLUMNS . " FROM {$this->table}
                 WHERE user_id = %d
                 ORDER BY created_at DESC
                 LIMIT 1",
                $userId
            )
        );
    }

    /**
     * Check if user is verified
     */
    public function isVerified(int $userId): bool {
        global $wpdb;

        $verified = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT verified_at FROM {$this->table}
                 WHERE user_id = %d
                 AND verified_at IS NOT NULL
                 ORDER BY verified_at DESC
                 LIMIT 1",
                $userId
            )
        );

        return !is_null($verified);
    }

    /**
     * Get verification record for user
     *
     * @phpstan-return VerificationTokenRow|null
     */
    public function getForUser(int $userId): ?object {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT " . self::COLUMNS . " FROM {$this->table}
                 WHERE user_id = %d
                 ORDER BY created_at DESC
                 LIMIT 1",
                $userId
            )
        );
    }

    /**
     * Mark token as verified and update user_info table
     */
    public function markVerified(int $id): void {
        global $wpdb;

        // Get the user_id before marking verified
        $record = $wpdb->get_row($wpdb->prepare(
            "SELECT user_id FROM {$this->table} WHERE id = %d",
            $id
        ));

        if ($record) {
            // Wrap both writes in a transaction so they succeed or fail together.
            \BCC\Trust\Core\Security\TransactionManager::run(function () use ($wpdb, $id, $record) {
                // Mark token as verified
                $result = $wpdb->update(
                    $this->table,
                    ['verified_at' => current_time('mysql', true)],
                    ['id' => $id],
                    ['%s'],
                    ['%d']
                );

                if ( $result === false ) {
                    throw new RepositoryException( 'VerificationRepository::markVerified failed: ' . $wpdb->last_error );
                }

                // Update user_info table
                $this->userInfoRepo->updateVerificationStatus($record->user_id, true);
            });

            // Log verification (outside transaction — non-critical)
            \BCC\Trust\Core\Security\AuditLogger::log('email_verified', $record->user_id, [
                'verification_id' => $id
            ], 'user');
        }
    }

    /**
     * Get users who haven't verified their email
     *
     * @return object[]
     */
    public function getUnverifiedUsers(int $limit = 100, int $offset = 0): array {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare("
            SELECT DISTINCT u.ID, u.user_email, u.display_name, u.user_registered,
                   v.created_at as last_verification_sent,
                   v.expires_at,
                   TIMESTAMPDIFF(HOUR, v.created_at, UTC_TIMESTAMP()) as hours_ago
            FROM {$wpdb->users} u
            LEFT JOIN {$this->table} v ON u.ID = v.user_id
            LEFT JOIN {$this->table} v2 ON u.ID = v2.user_id AND v2.verified_at IS NOT NULL
            WHERE v2.id IS NULL
            ORDER BY u.user_registered DESC
            LIMIT %d OFFSET %d
        ", $limit, $offset));
    }

    /**
     * Get verification history for a user
     *
     * @return object[]
     */
    public function getHistory(int $userId, int $limit = 10): array {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare("
            SELECT " . self::COLUMNS . ",
                   TIMESTAMPDIFF(HOUR, created_at, expires_at) as valid_hours,
                   CASE 
                       WHEN verified_at IS NOT NULL THEN 'verified'
                       WHEN expires_at < UTC_TIMESTAMP() THEN 'expired'
                       ELSE 'pending'
                   END as status
            FROM {$this->table}
            WHERE user_id = %d
            ORDER BY created_at DESC
            LIMIT %d
        ", $userId, $limit));
    }

    /**
     * Resend verification count (rate limiting helper)
     */
    public function getResendCount(int $userId, int $hours = null): int {
        global $wpdb;
        
        $hours = $hours ?? $this->rateLimitHours;

        return (int) $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$this->table}
            WHERE user_id = %d
            AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR)
        ", $userId, $hours));
    }

    /**
     * Check if user can request verification
     */
    public function canRequestVerification(int $userId): bool {
        $count = $this->getResendCount($userId, $this->rateLimitHours);
        return $count < $this->rateLimitMax;
    }

    /**
     * Get time until next allowed verification
     */
    public function getTimeUntilNextAllowed(int $userId): int {
        global $wpdb;

        $oldest = $wpdb->get_var($wpdb->prepare("
            SELECT created_at FROM {$this->table}
            WHERE user_id = %d
            ORDER BY created_at ASC
            LIMIT 1
        ", $userId));

        if (!$oldest) {
            return 0;
        }

        $oldestTime = strtotime($oldest);
        $now = time();
        $windowStart = $oldestTime + ($this->rateLimitHours * 3600);
        
        return max(0, $windowStart - $now);
    }

    /**
     * Delete expired tokens (cleanup)
     */
    public function deleteExpired(): int {
        global $wpdb;

        // No user-supplied parameters; table name from trusted \BCC\Trust\Core\Database\TableRegistry::verifications().
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->query(
            "DELETE FROM {$this->table}
             WHERE expires_at < UTC_TIMESTAMP()"
        );
    }

    /**
     * Delete old verified tokens using config
     */
    public function deleteOldVerified(): int {
        global $wpdb;

        return $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table}
                 WHERE verified_at IS NOT NULL
                 AND verified_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
                $this->cleanupVerifiedDays
            )
        );
    }

    /**
     * Delete orphaned tokens (for users that no longer exist)
     */
    public function deleteOrphaned(): int {
        global $wpdb;

        return $wpdb->query($wpdb->prepare("
            DELETE v FROM {$this->table} v
            LEFT JOIN {$wpdb->users} u ON v.user_id = u.ID
            WHERE u.ID IS NULL AND %d",
            1
        ));
    }

    /**
     * Get verification completion rate
     */
    public function getCompletionRate(): float {
        global $wpdb;

        $result = $wpdb->get_row($wpdb->prepare("
            SELECT
                COUNT(CASE WHEN verified_at IS NOT NULL THEN 1 END) as verified,
                COUNT(*) as total
            FROM {$this->table}
            WHERE %d",
            1
        ));

        if (!$result || $result->total == 0) {
            return 0;
        }

        return round(($result->verified / $result->total) * 100, 2);
    }

    /**
     * Bulk verify users (admin function)
     *
     * @param int[] $userIds
     */
    public function bulkVerify(array $userIds): int {
        global $wpdb;
        
        if (empty($userIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '%d'));
        
        // Mark tokens as verified
        $result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table} 
                 SET verified_at = %s
                 WHERE user_id IN ({$placeholders})
                 AND verified_at IS NULL",
                array_merge([current_time('mysql', true)], $userIds)
            )
        );

        // Batch-update user_info verification status (single query instead of N).
        $userInfoTable = \BCC\Trust\Core\Database\TableRegistry::userInfo();
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$userInfoTable}
                 SET is_verified = 1
                 WHERE user_id IN ({$placeholders})",
                $userIds
            )
        );

        // Invalidate caches for affected users via the repository method.
        foreach ($userIds as $userId) {
            $this->userInfoRepo->invalidateCache($userId);
        }

        // Batch audit log entry (single log with all user IDs).
        \BCC\Trust\Core\Security\AuditLogger::log('bulk_verified', get_current_user_id(), [
            'verified_user_ids' => $userIds,
            'count' => count($userIds),
        ], 'admin');

        return $result ?: 0;
    }

    /**
     * Get verification success rate by hour (for analytics)
     *
     * @return object[]
     */
    public function getSuccessRateByHour(int $days = 30): array {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare("
            SELECT 
                HOUR(created_at) as hour,
                COUNT(*) as total,
                SUM(CASE WHEN verified_at IS NOT NULL THEN 1 ELSE 0 END) as verified,
                ROUND(SUM(CASE WHEN verified_at IS NOT NULL THEN 1 ELSE 0 END) / COUNT(*) * 100, 2) as success_rate
            FROM {$this->table}
            WHERE created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
            GROUP BY HOUR(created_at)
            ORDER BY hour ASC
        ", $days));
    }

    /**
     * Clean up old data using config settings
     *
     * @return array{expired_deleted: int, verified_deleted: int, orphaned_deleted: int}
     */
    public function cleanup(): array {
        $results = [
            'expired_deleted' => 0,
            'verified_deleted' => 0,
            'orphaned_deleted' => 0
        ];

        // Delete expired tokens
        $results['expired_deleted'] = $this->deleteExpired();

        // Delete old verified tokens using config
        $results['verified_deleted'] = $this->deleteOldVerified();

        // Delete orphaned records
        $results['orphaned_deleted'] = $this->deleteOrphaned();

        return $results;
    }

    /**
     * Get active verification count
     */
    public function getActiveCount(): int {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$this->table}
            WHERE verified_at IS NULL
            AND expires_at > UTC_TIMESTAMP()
            AND %d",
            1
        ));
    }

    /**
     * Get verification statistics by day
     *
     * @return object[]
     */
    public function getDailyStats(int $days = 30): array {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare("
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as total,
                SUM(CASE WHEN verified_at IS NOT NULL THEN 1 ELSE 0 END) as verified,
                SUM(CASE WHEN verified_at IS NULL AND expires_at < UTC_TIMESTAMP() THEN 1 ELSE 0 END) as expired,
                ROUND(AVG(TIMESTAMPDIFF(MINUTE, created_at, verified_at)), 2) as avg_verification_minutes
            FROM {$this->table}
            WHERE created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
            GROUP BY DATE(created_at)
            ORDER BY date DESC
        ", $days));
    }

    // ── Verification data helpers (migrated from helpers.php) ────────────

    /**
     * @return array<string, mixed>
     */
    public function getGithubData(int $userId): array
    {
        $empty = [
            'connected'       => false,
            'username'        => null,
            'verified_at'     => null,
            'github_id'       => null,
            'followers'       => 0,
            'public_repos'    => 0,
            'org_count'       => 0,
            'trust_boost'     => 0.0,
            'fraud_reduction' => 0,
        ];

        if (!$userId) {
            return $empty;
        }

        $cache_key = 'github_' . $userId;
        $cached    = wp_cache_get($cache_key, self::CACHE_GROUP);
        if ($cached !== false) {
            return is_array($cached) ? $cached : $empty;
        }

        global $wpdb;
        $table = \BCC\Trust\Core\Database\TableRegistry::userVerifications();
        $row   = $wpdb->get_row($wpdb->prepare(
            "SELECT provider_id, provider_username, verified_at,
                    trust_boost, fraud_reduction, meta
             FROM {$table}
             WHERE user_id = %d AND type = 'github' AND status = 'active'",
            $userId
        ));

        if (!$row || !$row->provider_username) {
            wp_cache_set($cache_key, 0, self::CACHE_GROUP, self::CACHE_TTL);
            return $empty;
        }

        $meta   = $row->meta ? json_decode($row->meta, true) : [];
        $result = [
            'connected'       => true,
            'username'        => $row->provider_username,
            'verified_at'     => $row->verified_at,
            'github_id'       => $row->provider_id,
            'followers'       => (int) ($meta['followers'] ?? 0),
            'public_repos'    => (int) ($meta['public_repos'] ?? 0),
            'org_count'       => (int) ($meta['org_count'] ?? 0),
            'trust_boost'     => (float) $row->trust_boost,
            'fraud_reduction' => (int) $row->fraud_reduction,
        ];

        wp_cache_set($cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL);
        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function getXData(int $userId): array
    {
        $empty = [
            'connected'       => false,
            'username'        => null,
            'email'           => null,
            'verified_at'     => null,
            'trust_boost'     => 0.0,
            'fraud_reduction' => 0,
        ];

        if (!$userId) {
            return $empty;
        }

        $cache_key = 'x_' . $userId;
        $cached    = wp_cache_get($cache_key, self::CACHE_GROUP);
        if ($cached !== false) {
            return is_array($cached) ? $cached : $empty;
        }

        global $wpdb;
        $table = \BCC\Trust\Core\Database\TableRegistry::userVerifications();
        $row   = $wpdb->get_row($wpdb->prepare(
            "SELECT provider_username, verified_at,
                    trust_boost, fraud_reduction, meta
             FROM {$table}
             WHERE user_id = %d AND type = 'x' AND status = 'active'",
            $userId
        ));

        if (!$row || !$row->provider_username) {
            wp_cache_set($cache_key, 0, self::CACHE_GROUP, self::CACHE_TTL);
            return $empty;
        }

        $meta   = $row->meta ? json_decode($row->meta, true) : [];
        $result = [
            'connected'       => true,
            'username'        => $row->provider_username,
            'email'           => $meta['email'] ?? null,
            'verified_at'     => $row->verified_at,
            'trust_boost'     => (float) $row->trust_boost,
            'fraud_reduction' => (int) $row->fraud_reduction,
        ];

        wp_cache_set($cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL);
        return $result;
    }

    /**
     * Check if a user has an active verification of a specific type.
     *
     * Uses the user_verifications table (not the email verification table).
     */
    /**
     * Delete unverified (pending) tokens for a user.
     */
    public function deleteUnverifiedForUser(int $userId): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table} WHERE user_id = %d AND verified_at IS NULL",
            $userId
        ));
    }

    public function hasActiveVerificationByType(int $userId, string $type): bool
    {
        global $wpdb;
        $table = \BCC\Trust\Core\Database\TableRegistry::userVerifications();

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$table} WHERE user_id = %d AND type = %s AND status = 'active' LIMIT 1",
            $userId,
            $type
        ));
    }

    /**
     * Alias for hasActiveVerificationByType — used by QuestValidator.
     */
    public function hasVerification(int $userId, string $type): bool
    {
        return $this->hasActiveVerificationByType($userId, $type);
    }

    /**
     * @return array{count: int, types: string[]}
     */
    public function getVerifiedIdentityCount(int $userId): array
    {
        if (!$userId) {
            return ['count' => 0, 'types' => []];
        }

        global $wpdb;
        $table = \BCC\Trust\Core\Database\TableRegistry::userVerifications();
        $rows  = $wpdb->get_results($wpdb->prepare(
            "SELECT type FROM {$table} WHERE user_id = %d AND status = 'active'",
            $userId
        ));

        return [
            'count' => count($rows),
            'types' => array_map(fn($r) => $r->type, $rows),
        ];
    }
}