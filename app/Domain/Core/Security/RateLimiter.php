<?php
/**
 * Enterprise Rate Limiter
 *
 * - Per-user limits
 * - Per-IP limits
 * - Burst control
 * - Sliding window
 * - Scales to 1M users
 * - Trust-based adjustments using config thresholds
 *
 * @package BCC\Trust\Core\Security
 * @version 2.0.0
 */

namespace BCC\Trust\Core\Security;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Trust\Core\Repositories\RateLimitRepository;
use BCC\Trust\Core\Repositories\UserInfoRepository;

class RateLimiter {

    // Default limits from config
    const DEFAULT_LIMIT = 20;      // max actions
    const DEFAULT_WINDOW = 60;     // seconds

    /**
     * @var array<string, array{limit: int, window: int}>|null Cached limits array, built at runtime.
     */
    private static $limits;

    /**
     * Get action-specific rate limits.
     *
     * Built at runtime (not as a class constant) so that the config constants
     * from includes/config/limits.php are guaranteed to be defined before
     * they are referenced. Class constants are evaluated at compile time by
     * the PHP engine — if Composer autoloads this class before the plugin's
     * config files have been require'd, a fatal "undefined constant" error
     * results. A lazy-loaded static method avoids this entirely.
     *
     * @return array<string, array{limit: int, window: int}>
     */
    public static function getLimits(): array {
        if (self::$limits === null) {
            self::$limits = [
                'vote'    => ['limit' => BCC_TRUST_RATE_LIMIT_VOTE,    'window' => BCC_TRUST_RATE_WINDOW_VOTE],
                'endorse' => ['limit' => BCC_TRUST_RATE_LIMIT_ENDORSE, 'window' => BCC_TRUST_RATE_WINDOW_ENDORSE],
                'flag'    => ['limit' => BCC_TRUST_RATE_LIMIT_FLAG,    'window' => BCC_TRUST_RATE_WINDOW_FLAG],
                'comment' => ['limit' => BCC_TRUST_RATE_LIMIT_COMMENT, 'window' => BCC_TRUST_RATE_WINDOW_COMMENT],
                'message' => ['limit' => BCC_TRUST_RATE_LIMIT_MESSAGE, 'window' => BCC_TRUST_RATE_WINDOW_MESSAGE],
                'login'   => ['limit' => BCC_TRUST_RATE_LIMIT_LOGIN,   'window' => BCC_TRUST_RATE_WINDOW_LOGIN],
                'api'     => ['limit' => BCC_TRUST_RATE_LIMIT_API,     'window' => BCC_TRUST_RATE_WINDOW_API],
            ];
        }
        return self::$limits;
    }

    // Trust adjustment multipliers
    private const TRUST_MULTIPLIERS = [
        'critical_risk' => 0.2,   // 80% reduction
        'high_risk' => 0.4,        // 60% reduction
        'medium_risk' => 0.6,      // 40% reduction
        'low_risk' => 0.8,         // 20% reduction
        'minimal_risk' => 1.0,     // No reduction
        'verified' => 1.2,         // 20% increase
        'trusted' => 1.3,          // 30% increase
        'elite' => 1.5,            // 50% increase
    ];

    /**
     * @var UserInfoRepository
     */
    private static $userInfoRepo;

    /**
     * Initialize repositories
     */
    private static function initRepositories(): void {
        if (self::$userInfoRepo === null) {
            self::$userInfoRepo = new UserInfoRepository();
        }
    }

    /**
     * Check if action allowed using a caller-supplied key.
     *
     * Use this for unauthenticated endpoints (e.g. OAuth callbacks) where
     * get_current_user_id() returns 0 for all callers. The key should
     * include an identifier like the client IP or OAuth state.
     *
     * @param string $key     Unique rate-limit key (will be hashed).
     * @param int    $limit   Max requests per window.
     * @param int    $window  Window size in seconds.
     * @return bool
     */
    public static function allowByKey(string $key, int $limit, int $window): bool {
        return self::slidingWindowCheck($key, $limit, $window);
    }

    /**
     * Bucketed sliding-window rate limit check (shared by all paths).
     *
     * Keys are bucketed by time: hash(key + floor(now/window)).
     * Effective count = current_count + prev_count * overlap_weight.
     * This prevents boundary-edge spikes (2x limit in 2 seconds).
     *
     * @return bool True if allowed, false if over limit.
     */
    private static function slidingWindowCheck(string $key, int $limit, int $window): bool {
        $now    = time();
        $window = max(1, $window);
        $bucket = (int) floor($now / $window);

        $curKey  = '_transient_bcc_rl_' . md5($key . '_b' . $bucket);
        $prevKey = '_transient_bcc_rl_' . md5($key . '_b' . ($bucket - 1));
        $bucketExpires = ($bucket + 2) * $window;
        $freshVal      = "1|{$bucketExpires}";

        // Atomic increment-and-fetch. Returns the post-increment count,
        // or null on DB failure. Increment-first closes the classic
        // TOCTOU race: the count is always pessimistic.
        $curCount = RateLimitRepository::incrementBucket($curKey, $freshVal);

        // FAIL CLOSED: a DB error must deny the request. The previous
        // implementation discarded the return value and relied on a
        // separate read that could itself return zero on error, which
        // turned the limiter into a no-op when the DB hiccuped.
        if ($curCount === null) {
            return false;
        }

        // Previous-bucket read is load-bearing for boundary correctness.
        // getBucketCount() now returns null on DB error (distinguishable
        // from "row missing" = 0).  FAIL CLOSED on null — previously a
        // DB hiccup at bucket-boundary time collapsed prevCount to 0 and
        // let attackers deliver up to 2× the configured limit (audit
        // CRIT-1).
        $prevCount = RateLimitRepository::getBucketCount($prevKey);
        if ($prevCount === null) {
            return false;
        }

        // Sliding window approximation using integer arithmetic.
        // weight_pct = 100 - (elapsed * 100 / window), clamped to [0, 100].
        // effective = current + prev * weight_pct / 100, clamped to [0, 2*limit].
        $elapsed   = $now - ($bucket * $window);
        $weightPct = max(0, min(100, 100 - (int) (($elapsed * 100) / $window)));
        $effective = $curCount + (int) (($prevCount * $weightPct) / 100);
        $effective = max(0, min($limit * 2, $effective));

        // Denied requests still consume a slot. Decrement-on-deny was
        // removed because a flooder spamming past the limit would otherwise
        // see every Nth request succeed (the previous denial freed capacity
        // back into the same window). Pessimistic accounting is the whole
        // point of the increment-first pattern.
        return $effective <= $limit;
    }

    /**
     * Check if action allowed
     *
     * @param string $action
     * @param int|null $limit
     * @param int|null $window
     * @return bool
     */
    public static function allow(string $action, ?int $limit = null, ?int $window = null): bool {
        self::initRepositories();
        
        $userId = get_current_user_id();
        $ip = self::getIp();

        // Get action-specific limits if not provided
        if ($limit === null || $window === null) {
            $config = self::getLimits()[$action] ?? [
                'limit' => self::DEFAULT_LIMIT, 
                'window' => self::DEFAULT_WINDOW
            ];
            $limit = $limit ?? $config['limit'];
            $window = $window ?? $config['window'];
        }

        // Adjust limits based on user trust level
        $adjustedLimit = self::getAdjustedLimit($userId, $limit, $action);

        // Anonymous users have stricter limits
        if (!$userId) {
            $adjustedLimit = min($adjustedLimit, 5);
        }

        // Delegate to unified sliding-window implementation.
        // Authenticated users: key by user ID PLUS a secondary bucket
        // keyed on the subnet. Per-user alone let an attacker spread
        // requests across N sock-puppet accounts on a single /24 and
        // deliver N × limit synchronously (audit CRIT-2). We now require
        // both buckets to pass — stricter of the two wins.
        // Anonymous users: key by IP instead (capped to 5 above).
        // The key is derived from $optionName which already encodes
        // user/IP + action. We strip the prefix since slidingWindowCheck
        // adds its own prefix.
        if ($userId) {
            $userKey   = "{$action}_u_{$userId}";
            $subnet    = self::normalizeIpToSubnet($ip);
            $ipKey     = "{$action}_ip_{$subnet}";
            // Per-IP cap: 4× the adjusted per-user limit, floored at 10.
            // This absorbs legitimate shared-network traffic (households,
            // offices, CG-NAT) while still cutting off multi-account
            // bursts from one subnet.
            $ipLimit   = max(10, $adjustedLimit * 4);
            $allowedByUser = self::slidingWindowCheck($userKey, $adjustedLimit, $window);
            $allowedByIp   = self::slidingWindowCheck($ipKey, $ipLimit, $window);
            $allowed = $allowedByUser && $allowedByIp;
            $slidingKey = $allowedByUser ? $ipKey : $userKey;
        } else {
            // Normalize IP to /24 (IPv4) or /64 (IPv6) subnet to bound
            // key cardinality and prevent DB growth from rotating IPs.
            $subnet = self::normalizeIpToSubnet($ip);
            $slidingKey = "{$action}_anon_{$subnet}";
            $allowed = self::slidingWindowCheck($slidingKey, $adjustedLimit, $window);
        }

        // Log excessive abuse when denied.
        if (!$allowed) {
            // Read current count for logging.
            $now    = time();
            $bucket = (int) floor($now / max(1, $window));
            $curKey = '_transient_bcc_rl_' . md5($slidingKey . '_b' . $bucket);

            $count = (int) (RateLimitRepository::getBucketCount($curKey) ?? 0);

            if ($count > $adjustedLimit * 2) {
                AuditLogger::log('rate_limit_exceeded', null, [
                    'action'  => $action,
                    'user_id' => $userId,
                    'ip'      => $ip,
                    'count'   => $count,
                    'limit'   => $adjustedLimit,
                ], 'system');
            }
        }

        return $allowed;
    }

    /**
     * Get adjusted limit based on user trust level using config thresholds.
     * User info is cached in the object cache for 5 minutes to avoid a DB
     * query on every single rate-limit check.
     */
    private static function getAdjustedLimit(int $userId, int $baseLimit, string $action): int {
        if (!$userId) {
            return $baseLimit;
        }

        $cacheKey = 'rl_userinfo_' . $userId;
        $userInfo = wp_cache_get($cacheKey, 'bcc_trust_rl');
        if ($userInfo === false) {
            $userInfo = self::$userInfoRepo->getByUserId($userId);
            wp_cache_set($cacheKey, $userInfo, 'bcc_trust_rl', 300);
        }

        if (!$userInfo) {
            return $baseLimit;
        }

        // Compute risk penalty and trust bonus SEPARATELY so that
        // trust boosts can never offset a fraud-score penalty.
        // Final multiplier = min(riskMultiplier, trustMultiplier).
        $riskMultiplier  = 1.0;
        $trustMultiplier = 1.0;

        // Risk penalties (fraud score) — lower = stricter
        if ($userInfo->fraud_score >= BCC_TRUST_FRAUD_CRITICAL) {
            $riskMultiplier = self::TRUST_MULTIPLIERS['critical_risk'];
        } elseif ($userInfo->fraud_score >= BCC_TRUST_FRAUD_HIGH) {
            $riskMultiplier = self::TRUST_MULTIPLIERS['high_risk'];
        } elseif ($userInfo->fraud_score >= BCC_TRUST_FRAUD_MEDIUM) {
            $riskMultiplier = self::TRUST_MULTIPLIERS['medium_risk'];
        } elseif ($userInfo->fraud_score >= BCC_TRUST_FRAUD_LOW) {
            $riskMultiplier = self::TRUST_MULTIPLIERS['low_risk'];
        }

        // Trust boosts (only applied when no risk penalty is active)
        $tier = $userInfo->reputation_tier ?? 'neutral';

        if ($userInfo->is_verified) {
            $trustMultiplier *= self::TRUST_MULTIPLIERS['verified'];
        }

        if ($tier === 'elite') {
            $trustMultiplier *= self::TRUST_MULTIPLIERS['elite'];
        } elseif ($tier === 'trusted') {
            $trustMultiplier *= self::TRUST_MULTIPLIERS['trusted'];
        }

        if ($userInfo->trust_rank > 0.8) {
            $trustMultiplier *= 1.2;
        }

        // Risk penalty always wins: a flagged user cannot be boosted
        // above their risk-capped limit regardless of tier or verification.
        $multiplier = min($riskMultiplier, $trustMultiplier);

        $adjusted = (int) round($baseLimit * $multiplier);

        return max(1, $adjusted);
    }

    /**
     * Invalidate the cached user_info used for trust-based rate-limit adjustments.
     *
     * Must be called whenever fraud_score or reputation data changes so that
     * getAdjustedLimit() picks up the new values instead of serving a stale
     * 300-second cache entry.
     *
     * @param int $userId WordPress user ID.
     */
    public static function invalidateUserCache(int $userId): void {
        wp_cache_delete('rl_userinfo_' . $userId, 'bcc_trust_rl');
    }

    /**
     * Enforce or throw
     *
     * @throws \Exception
     */
    public static function enforce(string $action, ?int $limit = null, ?int $window = null): void {
        if (!self::allow($action, $limit, $window)) {
            $resetIn = self::resetIn($action);
            throw new \Exception(
                sprintf('Too many requests. Please wait %d seconds.', $resetIn),
                429
            );
        }
    }

    /**
     * Get reset time in seconds.
     *
     * MUST reconstruct the exact key format that allow() writes (the bucketed
     * sliding-window key with `_b{bucket}` suffix) — the earlier form hashed
     * an un-bucketed key, so the lookup ALWAYS missed and resetIn() always
     * returned 0. That silently broke every Retry-After / "wait N seconds"
     * message and hid the `count > adjustedLimit * 2` abuse-detection branch
     * in allow() which reads the same bucketed key.
     */
    public static function resetIn(string $action): int {
        $userId = get_current_user_id();

        // Resolve the action's window so the bucket index aligns with the
        // writer. Fall back to DEFAULT_WINDOW for unknown actions, mirroring
        // allow()'s lookup.
        $config = self::getLimits()[$action] ?? [
            'limit'  => self::DEFAULT_LIMIT,
            'window' => self::DEFAULT_WINDOW,
        ];
        $window = max(1, (int) $config['window']);

        // Build the same sliding key allow() passes to slidingWindowCheck.
        // Authenticated users check the per-user bucket (the IP bucket is
        // coarser and typically wait-time-equivalent). Anonymous callers
        // MUST go through normalizeIpToSubnet so this matches the
        // writer's subnet key rather than the raw IP.
        $slidingKey = $userId
            ? "{$action}_u_{$userId}"
            : "{$action}_anon_" . self::normalizeIpToSubnet(self::getIp());

        // Check current bucket first; fall back to previous on the boundary.
        // The stored "count|expires" value's expires is the bucket's own
        // expiry (end of the CURRENT window + 1 window of slide overlap).
        $now    = time();
        $bucket = (int) floor($now / $window);

        $curKey = '_transient_bcc_rl_' . md5($slidingKey . '_b' . $bucket);
        $stored = RateLimitRepository::getBucketRaw($curKey);

        if ($stored === null) {
            $prevKey = '_transient_bcc_rl_' . md5($slidingKey . '_b' . ($bucket - 1));
            $stored  = RateLimitRepository::getBucketRaw($prevKey);
        }

        if ($stored === null) {
            return 0;
        }

        $parts     = explode('|', $stored, 2);
        $expiresAt = (int) ($parts[1] ?? 0);

        return max(0, $expiresAt - $now);
    }

    /**
     * Spoof-proof IP detection via IpResolver.
     * CF-Connecting-IP is only trusted when REMOTE_ADDR is a verified Cloudflare IP.
     */
    private static function getIp(): string {
        return IpResolver::getClientIp();
    }

    /**
     * Normalize an IP to its subnet to bound key cardinality.
     * IPv4 → /24, IPv6 → /48. Invalid → returned as-is.
     *
     * IPv6 uses /48 (not /64) because a /48 is the standard end-site
     * allocation (RFC 6177). Normalizing to /64 lets an attacker on a
     * single ISP customer allocation hop across /64 subnets and get a
     * fresh rate-limit bucket for each one.
     */
    private static function normalizeIpToSubnet(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[3] = '0';
            return implode('.', $parts);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);
            if ($packed === false) {
                return $ip;
            }
            // Zero out bytes 7-16 (the host + subnet-ID part of a /48).
            $masked = substr($packed, 0, 6) . str_repeat("\0", 10);
            return (string) inet_ntop($masked);
        }

        return $ip;
    }

}