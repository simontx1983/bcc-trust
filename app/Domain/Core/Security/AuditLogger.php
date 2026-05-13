<?php
/**
 * Audit Logger
 *
 * Handles logging of all trust-related events for audit trail and fraud detection
 *
 * @package BCC\Trust\Core\Security
 * @version 2.0.0
 */

namespace BCC\Trust\Core\Security;

if (!defined('ABSPATH')) {
    exit;
}

// IpResolver provides spoof-proof IP detection: validates CF-Connecting-IP
// against Cloudflare's published CIDRs and ignores X-Forwarded-For entirely.
use BCC\Trust\Core\Security\IpResolver;
use BCC\Trust\Core\Repositories\AuditLogRepository;

class AuditLogger {

    private static ?AuditLogRepository $repo = null;

    /**
     * Whether the activity table is confirmed to exist.
     * Populated once per request via a single SHOW TABLES query, then reused.
     */
    private static ?bool $tableExists = null;

    /**
     * Get or create the repository instance.
     */
    private static function getRepo(): AuditLogRepository {
        if (self::$repo === null) {
            self::$repo = new AuditLogRepository();
        }
        return self::$repo;
    }

    /**
     * Return true if the activity table exists.
     *
     * Result is stored in a static property so the SHOW TABLES query runs at
     * most once per PHP process (once per request under PHP-FPM), regardless
     * of how many times log() is called.
     */
    private static function tableExists(): bool {
        if (self::$tableExists === null) {
            self::$tableExists = self::getRepo()->tableExists();
        }

        return self::$tableExists;
    }

    /**
     * Log event
     *
     * @param string                $action
     * @param int|null              $targetId
     * @param array<string, mixed>  $meta
     * @param string|null           $targetType
     * @param int|null              $userId
     */
    public static function log(string $action, ?int $targetId = null, array $meta = [], ?string $targetType = null, ?int $userId = null): void {
        if (!self::tableExists()) {
            return;
        }

        $currentUserId = $userId ?? get_current_user_id();
        $ip = self::getIp();

        // Convert IP to binary for storage
        $ipBinary = null;
        if ($ip && $ip !== 'unknown') {
            $ipBinary = inet_pton($ip);
        }

        $data = [
            'user_id'       => $currentUserId ?: 0, // Default to 0 instead of null
            'action'        => sanitize_text_field($action),
            'target_type'   => $targetType ? sanitize_text_field($targetType) : '',
            'target_id'     => $targetId ?: 0,
            'ip_address'    => $ipBinary,
            'created_at'    => current_time('mysql', true)
        ];

        $result = self::getRepo()->insertLog(
            $data,
            ['%d', '%s', '%s', '%d', '%s', '%s']
        );

        if ( $result === false ) {
            // §VIII.30: audit-log write failures MUST NOT propagate (the
            // mutation has already committed). But silently dropping rows
            // hides accountability gaps from operators. Record a
            // DegradationMetric so /system/health surfaces the failure
            // before forensic queries on bcc_trust_activity discover the
            // missing rows after an incident. Distinct source name from
            // the read-path swallows so dashboards can segment by which
            // half of the audit subsystem is unhealthy.
            \BCC\Core\Observability\DegradationMetrics::record('audit_log_swallow', 'log_write_failed');

            if ( defined('WP_DEBUG') && WP_DEBUG ) {
                \BCC\Core\Log\Logger::error('[bcc-trust] ' . 'BCC Trust: Audit log write failed - ', ['detail' => self::getRepo()->getLastError()]);
            }
        }

        $alertActions = ['fraud', 'suspicious', 'flag', 'block', 'suspend'];
        foreach ($alertActions as $alert) {
            if (strpos($action, $alert) === 0) {
                $logLevel = 'INFO';
                if (strpos($action, 'critical') !== false) {
                    $logLevel = 'CRITICAL';
                } elseif (strpos($action, 'high') !== false) {
                    $logLevel = 'HIGH';
                }

                \BCC\Core\Log\Logger::error(sprintf(
                    '[BCC Trust %s] %s - User: %d, Target: %d (%s), IP: %s, Meta: %s',
                    $logLevel,
                    $action,
                    $currentUserId,
                    $targetId ?? 0,
                    $targetType ?? 'unknown',
                    $ip,
                    json_encode($meta)
                ));
                break;
            }
        }
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function removeVote(int $pageId, array $meta = []): void {
        self::log('vote_removed', $pageId, $meta, 'page');
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function endorse(int $pageId, string $context = 'general', array $meta = []): void {
        $action = 'endorse_' . sanitize_key($context);

        if (isset($meta['weight']) && $meta['weight'] > BCC_TRUST_MAX_ENDORSE_WEIGHT) {
            $meta['exceeds_max'] = true;
        }

        self::log($action, $pageId, $meta, 'page');
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function revokeEndorsement(int $pageId, string $context = 'general', array $meta = []): void {
        self::log('endorse_revoked_' . sanitize_key($context), $pageId, $meta, 'page');
    }

    public static function verificationComplete(int $userId): void {
        self::log('email_verified', $userId, [], 'user');
    }

    public static function flagCreated(int $voteId, int $flaggerId, string $reason): void {
        self::log('flag_created', $voteId, [
            'flagger_id' => $flaggerId,
            'reason' => $reason
        ], 'vote');
    }

    /**
     * Get suspicious activity
     *
     * @return object[]
     * @phpstan-return list<object{
     *   id: int|numeric-string,
     *   user_id: int|numeric-string,
     *   action: string,
     *   target_type: string,
     *   target_id: int|numeric-string,
     *   ip_address: string|null,
     *   created_at: string
     * }>
     */
    public static function getSuspiciousActivity(int $hours = 24, int $limit = 100): array {
        return self::getRepo()->getSuspiciousActivity($hours, $limit);
    }

    /**
     * Spoof-proof IP detection.
     *
     * Delegates to IpResolver which:
     *  - Trusts CF-Connecting-IP ONLY when REMOTE_ADDR is a verified Cloudflare CIDR.
     *  - Ignores X-Forwarded-For and X-Real-IP (any client can forge these headers).
     *  - Falls back to REMOTE_ADDR as the authoritative source.
     *
     * Previously this method read CF/XFF headers unconditionally, letting any
     * client inject an arbitrary IP into the audit trail.
     */
    private static function getIp(): string {
        $ip = IpResolver::getClientIp();

        // IpResolver returns '0.0.0.0' as its safe fallback; map that to 'unknown'
        // to preserve the existing schema expectation.
        return ($ip && $ip !== '0.0.0.0') ? $ip : 'unknown';
    }

    /**
     * Clean old logs using config retention period
     */
    public static function cleanOldLogs(): int {
        if (!self::getRepo()->tableExists()) {
            return 0;
        }

        $retentionDays = BCC_TRUST_CLEANUP_ACTIVITY;

        return self::getRepo()->deleteOlderThan($retentionDays);
    }

}
