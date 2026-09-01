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
        // Metadata is BEST-EFFORT here: if it cannot be encoded the base row
        // is still written with meta = NULL. §VIII.30 — the mutation has
        // already committed and must never be broken by an audit problem.
        self::write($action, $targetId, $meta, $targetType, $userId, false);
    }

    /**
     * Log an event and CONFIRM it was recorded IN FULL, returning its row id.
     *
     * `log()` deliberately swallows failures (§VIII.30: an audit-log failure
     * must never break a mutation that has already committed). That is right
     * for the ~85 ordinary callers and wrong for a repair runner, where the
     * audit record IS the deliverable — an unwitnessed repair is not an
     * acceptable outcome, so its caller needs to be able to roll back.
     *
     * The contract is therefore STRICTER, not merely more talkative:
     *
     *   - metadata passed here is treated as REQUIRED, not decorative. If a
     *     non-empty `$meta` cannot be encoded, this reports FAILURE and writes
     *     NO ROW AT ALL.
     *   - a base-only row (action + actor + target, `meta` NULL) MUST NOT be
     *     able to pass for a confirmed repair audit. For an action named
     *     something like `nft_collection_identity_repaired`, such a row reads
     *     during forensics as "the repair happened, details unavailable" —
     *     indistinguishable from a successful one, which is worse than no row.
     *     So no row is written, and the caller is expected to roll back.
     *   - passing an empty `$meta` is still legitimate: that is "this action
     *     genuinely carries no context", not "the context was lost".
     *
     * @param  array<string, mixed> $meta Treated as REQUIRED when non-empty.
     * @return int|null Row id on a fully-recorded audit; null if the caller
     *                  must treat the audit as having failed and roll back.
     */
    public static function logChecked(string $action, ?int $targetId = null, array $meta = [], ?string $targetType = null, ?int $userId = null): ?int {
        return self::write($action, $targetId, $meta, $targetType, $userId, true);
    }

    /**
     * @param  array<string, mixed> $meta
     * @param  bool                 $requireMeta When true, non-empty metadata
     *                              that cannot be encoded fails the whole
     *                              write instead of degrading to meta = NULL.
     * @return int|null Inserted row id, or null on any failure.
     */
    private static function write(string $action, ?int $targetId, array $meta, ?string $targetType, ?int $userId, bool $requireMeta): ?int {
        if (!self::tableExists()) {
            return null;
        }

        $currentUserId = $userId ?? get_current_user_id();
        $ip = self::getIp();

        // Convert IP to binary for storage
        $ipBinary = null;
        if ($ip && $ip !== 'unknown') {
            $ipBinary = inet_pton($ip);
        }

        // Encode BEFORE the insert so an unserialisable payload costs the
        // metadata only, never the accountability row. AuditMeta distinguishes
        // "no metadata" from "could not encode" so the two do not collapse
        // into the same NULL and hide a bug.
        $encoded = AuditMeta::encode($meta);

        if ($encoded['failed']) {
            \BCC\Core\Observability\DegradationMetrics::record('audit_log_swallow', 'meta_encode_failed');

            if ($requireMeta) {
                // Required metadata was lost, so there is nothing honest to
                // write: a base-only row would be indistinguishable from a
                // complete audit. Refuse, and let the caller roll back.
                // The metric above is still recorded, and this is logged
                // unconditionally (not only under WP_DEBUG) because a
                // caller is about to abandon real work over it.
                \BCC\Core\Log\Logger::error(
                    '[bcc-trust] Required audit metadata could not be encoded; NO row written, caller must roll back',
                    ['action' => $action, 'target_type' => $targetType, 'target_id' => $targetId]
                );

                return null;
            }

            if ( defined('WP_DEBUG') && WP_DEBUG ) {
                \BCC\Core\Log\Logger::error('[bcc-trust] Audit meta could not be encoded; base row still written', ['action' => $action]);
            }
        }

        $data = [
            'user_id'       => $currentUserId ?: 0, // Default to 0 instead of null
            'action'        => sanitize_text_field($action),
            'target_type'   => $targetType ? sanitize_text_field($targetType) : '',
            'target_id'     => $targetId ?: 0,
            'ip_address'    => $ipBinary,
            'created_at'    => current_time('mysql', true),
            'meta'          => $encoded['json'],
        ];

        $insertId = self::getRepo()->insertLogReturningId(
            $data,
            ['%d', '%s', '%s', '%d', '%s', '%s', '%s']
        );

        self::emitAlert($action, $currentUserId, $targetId, $targetType, $ip, $meta);

        if ( $insertId === null ) {
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

        return $insertId;
    }

    /**
     * Mirror high-signal events into the file log.
     *
     * Meta goes through the SAME encoder as the durable row. Before this, the
     * alert path called `json_encode($meta)` directly, so the file log held an
     * unredacted copy of exactly the payload the database row is careful with.
     * One encoder means one policy; a redaction rule cannot be true in one
     * half of this method and false in the other.
     *
     * (The `IP: %s` field is the resolved request IP and is printed here as it
     * always has been — pre-existing behaviour, deliberately not changed by
     * this PR.)
     *
     * @param array<string, mixed> $meta
     */
    private static function emitAlert(string $action, int $currentUserId, ?int $targetId, ?string $targetType, string $ip, array $meta): void {
        $alertActions = ['fraud', 'suspicious', 'flag', 'block', 'suspend'];

        foreach ($alertActions as $alert) {
            if (strpos($action, $alert) === 0) {
                $logLevel = 'INFO';
                if (strpos($action, 'critical') !== false) {
                    $logLevel = 'CRITICAL';
                } elseif (strpos($action, 'high') !== false) {
                    $logLevel = 'HIGH';
                }

                $encoded = AuditMeta::encode($meta);

                \BCC\Core\Log\Logger::error(sprintf(
                    '[BCC Trust %s] %s - User: %d, Target: %d (%s), IP: %s, Meta: %s',
                    $logLevel,
                    $action,
                    $currentUserId,
                    $targetId ?? 0,
                    $targetType ?? 'unknown',
                    $ip,
                    $encoded['json'] ?? ($encoded['failed'] ? '[unencodable]' : '[]')
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
