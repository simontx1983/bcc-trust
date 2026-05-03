<?php
/**
 * Device Fingerprinter
 * 
 * Handles browser fingerprinting, bot detection, and multi-account detection
 * Uses config constants for thresholds and detection sensitivity
 * 
 * @package BCC\Trust
 * @subpackage Security
 * @version 2.1.0
 */

namespace BCC\Trust\Core\Security;

if (!defined('ABSPATH')) exit;

use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Repositories\FraudAnalysisRepository;
use BCC\Trust\Core\Repositories\DeviceFingerprintRepository;

class DeviceFingerprinter {

    /**
     * Repositories
     */
    private UserInfoRepository $userInfoRepo;
    private DeviceFingerprintRepository $fpRepo;
    private FraudAnalysisRepository $fraudAnalysisRepo;

    /**
     * Config constants
     */
    private int $automationHigh;
    private int $automationMedium;
    private int $ringMinSize;
    private int $cleanupDays;

    /**
     * Hash an IP address for privacy-safe storage (GDPR).
     * Uses a keyed HMAC so the same IP produces a consistent hash within
     * this installation, but the raw IP cannot be recovered.
     */
    private static function hashIp(string $ip): ?string {
        if (!$ip || $ip === '0.0.0.0') {
            return null;
        }
        // Plugin activation is gated on BCC_ENCRYPTION_KEY (see
        // bcc-trust.php and bootstrap.php). If we reach
        // here without it, state is unsafe — fail closed rather than
        // silently degrade to AUTH_SALT (which rotates under key
        // rotation) or, worst, a hardcoded literal.
        if (defined('BCC_ENCRYPTION_KEY') && \BCC_ENCRYPTION_KEY) {
            return hash_hmac('sha256', $ip, \BCC_ENCRYPTION_KEY);
        }
        if (class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::error('[bcc-trust] hashIp called without BCC_ENCRYPTION_KEY — returning null');
        }
        return null;
    }

    /**
     * Known bot user agents
     */
    private const BOT_USER_AGENTS = [
        'bot', 'crawler', 'spider', 'scraper', 'curl', 'wget',
        'python', 'java', 'perl', 'ruby', 'php', 'node',
        'headless', 'phantomjs', 'puppeteer', 'playwright',
        'selenium', 'webdriver', 'chrome-headless', 'headlesschrome'
    ];

    /**
     * Constructor
     */
    public function __construct() {
        $this->userInfoRepo = new UserInfoRepository();
        $this->fpRepo = new DeviceFingerprintRepository();

        $this->automationHigh   = BCC_TRUST_AUTOMATION_HIGH;
        $this->automationMedium = BCC_TRUST_AUTOMATION_MEDIUM;
        $this->ringMinSize      = BCC_TRUST_RING_MIN_SIZE;
        $this->cleanupDays      = BCC_TRUST_CLEANUP_FINGERPRINTS;

        $this->fraudAnalysisRepo = new FraudAnalysisRepository();
    }
    
    /**
     * Check whether the user has granted fingerprinting consent.
     *
     * Mirrors the client-side hasConsent() check in fingerprint.js so that
     * direct REST API calls cannot bypass the consent gate.
     *
     * @param int $userId WordPress user ID.
     * @return bool
     */
    public function hasConsent(int $userId): bool {
        // Server-side admin setting (localized as window.bccTrust.fingerprint_consent).
        if (\get_option('bcc_trust_fingerprint_consent', false)) {
            return true;
        }

        // Per-user consent stored as user meta (set when consent banner accepted).
        if (\get_user_meta($userId, '_bcc_fingerprint_consent', true)) {
            return true;
        }

        // Client-side cookie set by the consent banner.
        if (isset($_COOKIE['bcc_fp_consent']) && $_COOKIE['bcc_fp_consent'] === '1') {
            return true;
        }

        return false;
    }

    /**
     * Device fingerprint identifier used for sybil / shared-device detection.
     *
     * CRITICAL SECURITY INVARIANT: The identifier returned here MUST be
     * derived ONLY from server-readable signals. Client cookies (bcc_canvas,
     * bcc_webgl_*, bcc_fonts, …) are attacker-controlled — an attacker who
     * rotates cookies per sock-puppet account produces a unique fingerprint
     * per alt and bypasses shared-device detection entirely.
     *
     * Any entropy from client-supplied cookies belongs in auxiliary fraud
     * analysis (risk_level, automation signals) — NOT in the identity hash.
     *
     * Historical versions of this method blended 22 client cookies with a
     * single server-side entry; that was exploitable. See audit 2026-04-23.
     *
     * @return string SHA-256 hex string (same shape as generateServerSideFingerprint).
     */
    public function generateFingerprint(): string {
        return $this->generateServerSideFingerprint();
    }

    /**
     * Generate a server-side-only fingerprint using signals the client cannot forge.
     *
     * Suitable for multi-account detection and fraud scoring even when the
     * browser-side cookie signals have been spoofed or cleared.
     *
     * @return string SHA-256 hex string
     */
    public function generateServerSideFingerprint(): string {
        $headers = function_exists('getallheaders') ? getallheaders() : [];

        $components = [
            // True remote address — cannot be set by the client.
            'remote_addr'     => $this->getClientIp(),

            // Header arrival order differs between browser engines and automation tools.
            'header_order'    => implode(',', array_keys($headers)),

            // Content-negotiation headers — vary per browser engine / OS locale.
            'accept'          => $_SERVER['HTTP_ACCEPT']          ?? '',
            'accept_language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            'accept_encoding' => $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',

            // User-Agent — spoofable but adds entropy.
            'user_agent'      => $_SERVER['HTTP_USER_AGENT']      ?? '',

            // TLS cipher suite set by the server during the TLS handshake.
            'ssl_cipher'      => $_SERVER['SSL_CIPHER'] ?? ($_SERVER['HTTPS'] ?? ''),
        ];

        return hash('sha256', json_encode($components) . wp_salt('secure_auth'));
    }

    /**
     * Return a security-grade fingerprint backed only by server-readable signals.
     *
     * Use this (instead of generateFingerprint) when making access-control
     * or fraud-scoring decisions, so a client cannot pre-compute or replay
     * a known-good fingerprint.
     *
     * @return string  'srv_' prefixed SHA-256 hex string
     */
    public function getSecurityFingerprint(): string {
        return 'srv_' . $this->generateServerSideFingerprint();
    }
    
    /**
     * Detect headless browsers and automation tools using config thresholds
     *
     * @return array<string, mixed> Automation detection results
     */
    public function detectAutomation(): array {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $headers = $this->getAllHeaders();
        
        $signals = [];
        $confidence = 0;
        
        // Check user agent for bot signatures
        foreach (self::BOT_USER_AGENTS as $bot) {
            if (stripos($userAgent, $bot) !== false) {
                $signals[] = "ua_contains_{$bot}";
                $confidence += 15;
            }
        }
        
        // Check for headless Chrome specific
        if (stripos($userAgent, 'HeadlessChrome') !== false) {
            $signals[] = 'headless_chrome';
            $confidence += 25;
        }
        
        // Check for PhantomJS
        if ((isset($headers['Phantom-Version']) && $headers['Phantom-Version']) ||
            (isset($headers['X-Phantom-Viewport']) && $headers['X-Phantom-Viewport']) ||
            stripos($userAgent, 'phantomjs') !== false) {
            $signals[] = 'phantomjs';
            $confidence += 30;
        }

        // Check for Selenium
        if ((isset($headers['X-Selenium']) && $headers['X-Selenium']) ||
            (isset($headers['X-Webdriver']) && $headers['X-Webdriver']) ||
            isset($_SERVER['HTTP_WEBDRIVER'])) {
            $signals[] = 'selenium';
            $confidence += 30;
        }

        // Check for Puppeteer
        if ((isset($headers['X-Puppeteer']) && $headers['X-Puppeteer']) ||
            (isset($headers['X-Puppeteer-Version']) && $headers['X-Puppeteer-Version'])) {
            $signals[] = 'puppeteer';
            $confidence += 30;
        }
        
        // Check for missing headers that real browsers have
        if (empty($_SERVER['HTTP_ACCEPT'])) {
            $signals[] = 'missing_accept';
            $confidence += 10;
        }
        
        if (empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $signals[] = 'missing_language';
            $confidence += 10;
        }
        
        if (empty($_SERVER['HTTP_ACCEPT_ENCODING'])) {
            $signals[] = 'missing_encoding';
            $confidence += 5;
        }
        
        // Check for consistent request timing (from cookie)
        $timingData = $this->getCookie('bcc_request_timing');
        if ($timingData) {
            $timings = json_decode($timingData, true);
            if (is_array($timings) && count($timings) > 5) {
                $variance = $this->calculateTimingVariance($timings);
                if ($variance < 0.1) { // Very consistent timing
                    $signals[] = 'consistent_timing';
                    $confidence += 20;
                }
            }
        }
        
        // Check for WebDriver property (from client-side detection)
        if ($this->getCookie('bcc_webdriver') === 'true') {
            $signals[] = 'webdriver_detected';
            $confidence += 25;
        }
        
        // Check for headless property
        if ($this->getCookie('bcc_headless') === 'true') {
            $signals[] = 'headless_detected';
            $confidence += 25;
        }
        
        // Check for no plugins
        $plugins = $this->getCookie('bcc_plugins');
        if ($plugins === '[]' || $plugins === '') {
            $signals[] = 'no_plugins';
            $confidence += 10;
        }
        
        // Check for missing fonts
        $fonts = $this->getCookie('bcc_fonts');
        if (empty($fonts) || $fonts === '[]') {
            $signals[] = 'no_fonts';
            $confidence += 15;
        }
        
        // Check for headless WebGL
        if ($this->getCookie('bcc_webgl_headless') === 'true') {
            $signals[] = 'headless_webgl';
            $confidence += 20;
        }
        
        // Check for no mouse movement
        if ($this->getCookie('bcc_mouse_moved') === 'false') {
            $signals[] = 'no_mouse_movement';
            $confidence += 15;
        }
        
        // Check for no scroll
        if ($this->getCookie('bcc_scrolled') === 'false') {
            $signals[] = 'no_scroll';
            $confidence += 10;
        }
        
        // Cloud/VPS IP detection (simplified)
        $ip = $this->getClientIp();
        if ($this->isDatacenterIp($ip)) {
            $signals[] = 'datacenter_ip';
            $confidence += 15;
        }
        
        // Determine if automated based on config threshold
        $isAutomated = $confidence >= $this->automationMedium;
        
        // Cap confidence at 100
        $confidence = min(100, $confidence);
        
        return [
            'is_automated' => $isAutomated,
            'confidence' => $confidence,
            'signals' => array_unique($signals)
        ];
    }
    
    /**
     * Store fingerprint for user
     * 
     * @param int $userId
     * @param string $fingerprint
     * @param array<string, mixed> $automationData
     * @return int|false Insert ID or false on failure
     */
    public function storeFingerprint(int $userId, string $fingerprint, array $automationData = []) {
        // Server-side consent gate: refuse to store fingerprint without consent.
        if (!$this->hasConsent($userId)) {
            return false;
        }

        // Check if this fingerprint exists for other users
        $existingUsers = $this->fpRepo->getOtherUsersForFingerprint($fingerprint, $userId);

        $multipleAccounts = !empty($existingUsers);
        $sharedCount = count($existingUsers) + 1; // Include current user

        // Determine risk level using config thresholds
        $riskLevel = 'low';
        if (isset($automationData['confidence'])) {
            if ($automationData['confidence'] >= $this->automationHigh) {
                $riskLevel = 'high';
            } elseif ($automationData['confidence'] >= $this->automationMedium) {
                $riskLevel = 'medium';
            }
        }

        // Upgrade risk level if device is shared beyond config threshold
        if ($sharedCount >= $this->ringMinSize) {
            $riskLevel = ($riskLevel === 'high') ? 'high' : 'medium';
        }

        // Check if fingerprint already exists for this user
        $existing = $this->fpRepo->findByUserAndFingerprint($userId, $fingerprint);

        $ip = $this->getClientIp();
        $ipHash = self::hashIp($ip);

        // Capture screen resolution from the cookie set by fingerprint.js
        $screenRes = $this->getCookie('bcc_screen');
        if ($screenRes && preg_match('/^\d{1,5}x\d{1,5}$/', $screenRes)) {
            $screenResClean = $screenRes; // e.g. "1920x1080"
        } else {
            $screenResClean = null;
        }

        $data = [
            'user_id' => $userId,
            'fingerprint' => $fingerprint,
            'automation_score' => $automationData['confidence'] ?? 0,
            'automation_signals' => !empty($automationData['signals']) ? json_encode($automationData['signals']) : null,
            'screen_resolution' => $screenResClean,
            'last_seen' => current_time('mysql'),
            'ip_address' => $ipHash,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'risk_level' => $riskLevel
        ];

        // Always record the server-side fingerprint alongside the client one.
        $this->storeServerSideFingerprint($userId);

        if ($existing) {
            // Update existing
            $this->fpRepo->updateById(
                (int) $existing->id,
                $data,
                $this->getFormatSpecifiers($data)
            );

            // If multiple accounts detected, update user_info
            if ($multipleAccounts) {
                $fraudIncrement = min(20, $sharedCount * 5);
                $this->userInfoRepo->incrementFraudScore($userId, $fraudIncrement, 'device_sharing');

                AuditLogger::log('device_sharing_detected', $userId, [
                    'fingerprint' => $fingerprint,
                    'shared_count' => $sharedCount,
                    'other_users' => wp_list_pluck($existingUsers, 'user_id'),
                    'automation_score' => $automationData['confidence'] ?? 0,
                    'risk_level' => $riskLevel
                ], 'user');
            }

            // Update automation score in user_info if above config thresholds
            if (($automationData['confidence'] ?? 0) > $this->automationMedium) {
                $this->userInfoRepo->updateAutomationScore($userId, $automationData['confidence']);
            }

            return (int) $existing->id;
        } else {
            // Insert new
            $data['first_seen'] = current_time('mysql');

            $insertId = $this->fpRepo->insert($data, $this->getFormatSpecifiers($data));

            if ($insertId === false) {
                \BCC\Core\Log\Logger::info('[bcc-trust] ' . 'BCC Trust: Fingerprint insert failed');
                return false;
            }

            // If multiple accounts detected, update user_info
            if ($multipleAccounts) {
                $fraudIncrement = min(20, $sharedCount * 5);
                $this->userInfoRepo->incrementFraudScore($userId, $fraudIncrement, 'device_sharing');

                AuditLogger::log('device_sharing_detected', $userId, [
                    'fingerprint' => $fingerprint,
                    'shared_count' => $sharedCount,
                    'other_users' => wp_list_pluck($existingUsers, 'user_id'),
                    'automation_score' => $automationData['confidence'] ?? 0,
                    'risk_level' => $riskLevel
                ], 'user');
            }

            // Update automation score in user_info if above config thresholds
            if (($automationData['confidence'] ?? 0) > $this->automationMedium) {
                $this->userInfoRepo->updateAutomationScore($userId, $automationData['confidence']);
            }

            // Store detailed fraud analysis if high risk
            if ($multipleAccounts || ($automationData['confidence'] ?? 0) > $this->automationMedium) {
                $triggers = [];
                if ($multipleAccounts) {
                    $triggers[] = 'device_sharing_' . $sharedCount . '_users';
                }
                if (($automationData['confidence'] ?? 0) > $this->automationHigh) {
                    $triggers[] = 'high_automation';
                } elseif (($automationData['confidence'] ?? 0) > $this->automationMedium) {
                    $triggers[] = 'medium_automation';
                }

                $fraudScore = (int) round(
                    ($automationData['confidence'] ?? 0) * 0.5 +
                    ($multipleAccounts ? min(50, $sharedCount * 10) : 0)
                );

                $expiresAt = date('Y-m-d H:i:s', strtotime('+' . BCC_TRUST_CLEANUP_FRAUD_ANALYSIS . ' days'));

                $this->fraudAnalysisRepo->storeAnalysis(
                    $userId,
                    $fraudScore,
                    $riskLevel,
                    $automationData['confidence'] / 100,
                    $triggers,
                    [
                        'fingerprint' => substr($fingerprint, 0, 16) . '...',
                        'automation_signals' => $automationData['signals'] ?? [],
                        'multiple_accounts' => $multipleAccounts,
                        'shared_count' => $sharedCount,
                        'other_users' => wp_list_pluck($existingUsers, 'user_id')
                    ],
                    $expiresAt
                );
            }

            return $insertId;
        }
    }
    
    /**
     * Get format specifiers for database operations
     *
     * @param array<string, mixed> $data
     * @return string[]
     */
    private function getFormatSpecifiers(array $data): array {
        $formats = [];
        
        foreach (array_keys($data) as $field) {
            switch ($field) {
                case 'user_id':
                case 'automation_score':
                    $formats[] = '%d';
                    break;
                case 'fingerprint':
                case 'automation_signals':
                case 'screen_resolution':
                case 'first_seen':
                case 'last_seen':
                case 'user_agent':
                case 'risk_level':
                    $formats[] = '%s';
                    break;
                case 'ip_address':
                    $formats[] = '%s'; // VARBINARY as string
                    break;
                default:
                    $formats[] = '%s';
            }
        }
        
        return $formats;
    }
    
    /**
     * Store fingerprint for user, also recording the server-side fingerprint.
     *
     * The server-side fingerprint is stored with a 'srv_' prefix so it can
     * be queried independently for multi-account detection even when the
     * browser-side cookies have been cleared or spoofed.
     */
    private function storeServerSideFingerprint(int $userId): void {
        $serverFp = $this->getSecurityFingerprint();

        // Reuse getFingerprintUserCount to check shared server fingerprints
        $serverSharedCount = $this->getFingerprintUserCount($serverFp);
        if ($serverSharedCount > 1) {
            // Different accounts, same server-side fingerprint -> strong signal
            $fraudIncrement = min(30, $serverSharedCount * 8);
            $this->userInfoRepo->incrementFraudScore($userId, $fraudIncrement, 'server_fingerprint_sharing');

            AuditLogger::log('server_fingerprint_shared', $userId, [
                'server_fingerprint' => substr($serverFp, 0, 20) . '...',
                'shared_count'       => $serverSharedCount,
            ], 'user');
        }

        $ip     = $this->getClientIp();
        // Empty string on unknown/zero IP so the upsert stays bound to a non-null column.
        $ipHash = self::hashIp($ip) ?? '';

        $this->fpRepo->upsertServerFingerprint(
            $userId,
            $serverFp,
            $ipHash,
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        );
    }

    /**
     * Get number of users associated with a fingerprint
     *
     * @param string $fingerprint
     * @return int
     */
    public function getFingerprintUserCount(string $fingerprint): int {
        return $this->fpRepo->getFingerprintUserCount($fingerprint);
    }

    /**
     * Batch-fetch user counts for multiple fingerprints in a single query.
     *
     * @param  string[] $fingerprints Fingerprint hashes.
     * @return array<string, int>     Map of fingerprint => distinct user count.
     */
    public function getFingerprintUserCounts(array $fingerprints): array {
        return $this->fpRepo->getFingerprintUserCounts($fingerprints);
    }

    /**
     * Compute all fingerprint-related fraud data for a user in minimal queries.
     *
     * Returns a pre-computed bundle that both calculateDeviceFraudProbability()
     * and the shared-device check in FraudDetector can consume without any
     * additional DB calls.
     *
     * @param  int $userId
     * @return array{fingerprints: object[], user_counts: array<string, int>, fraud_probability: float, shared_device: array{user_count: int, fingerprint: string}|null}
     *
     * @phpstan-return array{
     *   fingerprints: list<object{
     *     id: int|numeric-string,
     *     user_id: int|numeric-string,
     *     fingerprint: string,
     *     automation_score: int|numeric-string,
     *     risk_level: string|null,
     *     last_seen: string
     *   }>,
     *   user_counts: array<string, int>,
     *   fraud_probability: float,
     *   shared_device: array{user_count: int, fingerprint: string}|null
     * }
     */
    public function getAggregatedFingerprintData(int $userId): array {
        $fingerprints = $this->getUserFingerprints($userId);

        $fpHashes   = array_map(fn($fp) => $fp->fingerprint, $fingerprints);
        $userCounts = $this->getFingerprintUserCounts($fpHashes);

        $fraudProbability = $this->calculateDeviceFraudProbability($userId, $fingerprints, $userCounts);

        // Determine the worst shared-device match.
        $sharedDevice = null;
        foreach ($fingerprints as $fp) {
            $count = $userCounts[$fp->fingerprint] ?? 0;
            if ($count > $this->ringMinSize) {
                $sharedDevice = [
                    'user_count'  => $count,
                    'fingerprint' => $fp->fingerprint,
                ];
                break; // same break-on-first logic as the original FraudDetector loop
            }
        }

        return [
            'fingerprints'      => $fingerprints,
            'user_counts'       => $userCounts,
            'fraud_probability' => $fraudProbability,
            'shared_device'     => $sharedDevice,
        ];
    }
    
    /**
     * Get all fingerprints for a user
     *
     * @param int $userId
     * @return object[]
     * @phpstan-return list<object{
     *   id: int|numeric-string,
     *   user_id: int|numeric-string,
     *   fingerprint: string,
     *   automation_score: int|numeric-string,
     *   risk_level: string|null,
     *   last_seen: string
     * }>
     */
    public function getUserFingerprints(int $userId): array {
        return $this->fpRepo->getUserFingerprints($userId);
    }
    
    /**
     * Calculate fraud probability based on device fingerprints using config thresholds.
     *
     * Accepts optional pre-fetched data so callers (e.g. getAggregatedFingerprintData)
     * can avoid redundant queries.
     *
     * @param int        $userId
     * @param array|null $fingerprints  Pre-fetched getUserFingerprints() result.
     * @param object[]|null            $fingerprints  Pre-fetched getUserFingerprints() result.
     * @param array<string, int>|null  $userCounts    Pre-fetched getFingerprintUserCounts() map.
     * @return float 0-1 probability
     *
     * @phpstan-param list<object{
     *   id: int|numeric-string,
     *   user_id: int|numeric-string,
     *   fingerprint: string,
     *   automation_score: int|numeric-string,
     *   risk_level: string|null,
     *   last_seen: string
     * }>|null $fingerprints
     */
    public function calculateDeviceFraudProbability(int $userId, ?array $fingerprints = null, ?array $userCounts = null): float {
        $fingerprints ??= $this->getUserFingerprints($userId);

        if (empty($fingerprints)) {
            return 0.1; // Low risk - no fingerprint data yet
        }

        // Batch-fetch user counts if not provided.
        if ($userCounts === null) {
            $fpHashes   = array_map(fn($fp) => $fp->fingerprint, $fingerprints);
            $userCounts = $this->getFingerprintUserCounts($fpHashes);
        }

        $scores = [];

        foreach ($fingerprints as $fp) {
            $score = 0;

            // Automation score contributes
            $score += $fp->automation_score / 100 * 0.4;

            // Risk level contributes based on config thresholds
            if ($fp->risk_level === 'high') {
                $score += 0.3;
            } elseif ($fp->risk_level === 'medium') {
                $score += 0.15;
            }

            // Check if this fingerprint is shared
            $userCount = $userCounts[$fp->fingerprint] ?? 0;
            if ($userCount > 1) {
                $score += min(0.3, ($userCount - 1) * 0.1);
            }

            $scores[] = $score;
        }

        // Take the highest score (most suspicious fingerprint)
        $maxScore = max($scores);

        // Weight by recency (newer fingerprints matter more)
        $recentFps = array_slice($fingerprints, 0, 3);
        $recentScores = [];
        foreach ($recentFps as $fp) {
            $recentScores[] = $fp->automation_score / 100;
        }
        $avgRecent = count($recentScores) > 0 ? array_sum($recentScores) / count($recentScores) : 0;

        // Combine: 70% max score, 30% recent average
        $finalScore = ($maxScore * 0.7) + ($avgRecent * 0.3);

        return min(1, max(0, $finalScore));
    }
    
    /**
     * Check if IP belongs to a known datacenter CIDR range.
     *
     * Uses actual published CIDR blocks instead of first-octet prefixes,
     * which were matching millions of residential IPs (e.g. '5.' matched
     * all of 5.0.0.0/8, mostly European residential).
     *
     * @param string $ip IPv4 address.
     * @return bool
     */
    private function isDatacenterIp(string $ip): bool {
        if ($ip === '0.0.0.0') return false;

        $ipLong = ip2long($ip);
        if ($ipLong === false) return false;

        // Published CIDR ranges for major cloud providers.
        // Source: provider IP range documentation. Subset of the largest blocks.
        static $datacenterCidrs = [
            // AWS — https://ip-ranges.amazonaws.com/ip-ranges.json (major blocks)
            '3.0.0.0/15',       '3.2.0.0/24',      '3.4.0.0/24',
            '3.5.0.0/19',       '3.8.0.0/14',      '3.16.0.0/14',
            '3.32.0.0/14',      '3.48.0.0/12',     '3.64.0.0/12',
            '3.80.0.0/12',      '3.96.0.0/15',     '3.104.0.0/14',
            '3.112.0.0/14',     '3.120.0.0/14',    '3.128.0.0/15',
            '3.130.0.0/16',     '3.132.0.0/14',    '3.136.0.0/13',
            '3.208.0.0/12',     '3.224.0.0/12',
            '13.32.0.0/15',     '13.34.0.0/15',    '13.48.0.0/15',
            '13.52.0.0/16',     '13.56.0.0/16',    '13.57.0.0/16',
            '13.112.0.0/14',    '13.208.0.0/16',   '13.210.0.0/15',
            '13.212.0.0/15',    '13.228.0.0/15',   '13.230.0.0/15',
            '13.232.0.0/14',    '13.236.0.0/14',   '13.244.0.0/15',
            '13.246.0.0/16',    '13.248.0.0/16',   '13.250.0.0/15',
            '15.152.0.0/16',    '15.160.0.0/16',   '15.164.0.0/15',
            '15.168.0.0/16',    '15.177.0.0/18',   '15.188.0.0/16',
            '15.197.0.0/18',    '15.200.0.0/14',
            '16.16.0.0/16',     '16.24.0.0/16',    '16.170.0.0/15',
            '18.60.0.0/15',     '18.64.0.0/10',    '18.128.0.0/16',
            '18.130.0.0/16',    '18.132.0.0/14',   '18.136.0.0/16',
            '18.138.0.0/15',    '18.140.0.0/15',   '18.142.0.0/15',
            '18.144.0.0/15',    '18.153.0.0/16',   '18.156.0.0/14',
            '18.162.0.0/16',    '18.163.0.0/16',   '18.175.0.0/16',
            '18.176.0.0/15',    '18.180.0.0/15',   '18.182.0.0/16',
            '18.183.0.0/16',    '18.184.0.0/15',   '18.188.0.0/16',
            '18.190.0.0/16',    '18.191.0.0/16',   '18.192.0.0/14',
            '18.196.0.0/15',    '18.200.0.0/14',   '18.204.0.0/14',
            '18.208.0.0/13',    '18.216.0.0/14',   '18.220.0.0/14',
            '18.224.0.0/14',    '18.228.0.0/16',   '18.229.0.0/16',
            '18.230.0.0/16',    '18.231.0.0/16',   '18.232.0.0/14',
            '18.236.0.0/15',    '18.246.0.0/16',   '18.252.0.0/16',
            '18.253.0.0/16',
            '35.71.0.0/16',     '35.72.0.0/13',    '35.80.0.0/12',
            '44.192.0.0/11',
            '52.0.0.0/15',      '52.4.0.0/14',     '52.8.0.0/16',
            '52.9.0.0/16',      '52.10.0.0/15',    '52.12.0.0/15',
            '52.14.0.0/16',     '52.15.0.0/16',    '52.16.0.0/15',
            '52.18.0.0/15',     '52.20.0.0/14',    '52.24.0.0/14',
            '52.28.0.0/16',     '52.29.0.0/16',    '52.30.0.0/15',
            '52.32.0.0/14',     '52.36.0.0/14',    '52.40.0.0/14',
            '52.44.0.0/15',     '52.46.0.0/18',    '52.47.0.0/16',
            '52.48.0.0/14',     '52.52.0.0/15',    '52.54.0.0/15',
            '52.56.0.0/16',     '52.57.0.0/16',    '52.58.0.0/15',
            '52.60.0.0/16',     '52.62.0.0/15',    '52.64.0.0/17',
            '52.66.0.0/16',     '52.68.0.0/15',    '52.70.0.0/15',
            '52.72.0.0/15',     '52.74.0.0/16',    '52.76.0.0/17',
            '52.77.0.0/16',     '52.78.0.0/16',    '52.79.0.0/16',
            '52.80.0.0/16',     '52.82.0.0/17',    '52.83.0.0/16',
            '54.64.0.0/15',     '54.66.0.0/16',    '54.67.0.0/16',
            '54.68.0.0/14',     '54.72.0.0/15',    '54.74.0.0/15',
            '54.76.0.0/15',     '54.78.0.0/16',    '54.79.0.0/16',
            '54.80.0.0/13',     '54.88.0.0/14',    '54.92.0.0/16',
            '54.93.0.0/16',     '54.94.0.0/16',    '54.144.0.0/14',
            '54.148.0.0/15',    '54.150.0.0/16',   '54.151.0.0/17',
            '54.152.0.0/16',    '54.153.0.0/17',   '54.154.0.0/16',
            '54.155.0.0/16',    '54.156.0.0/14',   '54.160.0.0/13',
            '54.168.0.0/16',    '54.169.0.0/16',   '54.170.0.0/15',
            '54.172.0.0/15',    '54.174.0.0/15',   '54.176.0.0/15',
            '54.178.0.0/16',    '54.179.0.0/16',   '54.180.0.0/15',
            '54.182.0.0/16',    '54.183.0.0/16',   '54.184.0.0/13',
            '54.192.0.0/16',    '54.193.0.0/16',   '54.194.0.0/15',
            '54.196.0.0/15',    '54.198.0.0/16',   '54.199.0.0/16',
            '54.200.0.0/15',    '54.202.0.0/15',   '54.204.0.0/15',
            '54.206.0.0/16',    '54.207.0.0/16',   '54.208.0.0/15',
            '54.210.0.0/15',    '54.212.0.0/15',   '54.214.0.0/16',
            '54.215.0.0/16',    '54.216.0.0/15',   '54.218.0.0/16',
            '54.219.0.0/16',    '54.220.0.0/16',   '54.221.0.0/16',
            '54.222.0.0/17',    '54.223.0.0/16',   '54.224.0.0/15',
            '54.226.0.0/15',    '54.228.0.0/16',   '54.229.0.0/16',
            '54.230.0.0/16',    '54.231.0.0/16',   '54.232.0.0/16',
            '54.233.0.0/17',    '54.234.0.0/15',   '54.236.0.0/15',
            '54.238.0.0/16',    '54.239.0.0/17',   '54.240.0.0/18',
            '54.241.0.0/16',    '54.242.0.0/15',   '54.244.0.0/16',
            '54.245.0.0/16',    '54.246.0.0/16',   '54.247.0.0/16',
            '54.248.0.0/15',    '54.250.0.0/16',   '54.251.0.0/16',
            '54.252.0.0/16',    '54.253.0.0/16',   '54.254.0.0/16',
            '54.255.0.0/16',

            // Google Cloud — https://www.gstatic.com/ipranges/cloud.json
            '34.0.0.0/15',      '34.2.0.0/16',     '34.3.0.0/16',
            '34.4.0.0/14',      '34.8.0.0/14',     '34.16.0.0/12',
            '34.32.0.0/11',     '34.64.0.0/11',    '34.96.0.0/12',
            '34.112.0.0/14',    '34.116.0.0/14',   '34.120.0.0/13',
            '34.128.0.0/10',    '34.192.0.0/10',
            '35.184.0.0/13',    '35.192.0.0/14',   '35.196.0.0/15',
            '35.198.0.0/16',    '35.199.0.0/17',   '35.200.0.0/13',
            '35.208.0.0/12',    '35.224.0.0/12',   '35.240.0.0/13',

            // Azure — https://www.microsoft.com/en-us/download/details.aspx?id=56519
            '4.149.0.0/16',     '4.150.0.0/16',    '4.151.0.0/16',
            '4.152.0.0/14',     '4.156.0.0/14',    '4.175.0.0/16',
            '4.176.0.0/13',     '4.184.0.0/14',    '4.188.0.0/14',
            '4.192.0.0/13',     '4.200.0.0/14',    '4.204.0.0/14',
            '4.208.0.0/12',     '4.224.0.0/12',    '4.240.0.0/13',
            '4.248.0.0/14',
            '13.64.0.0/11',     '13.96.0.0/13',    '13.104.0.0/14',
            '20.0.0.0/11',      '20.33.0.0/16',    '20.34.0.0/15',
            '20.36.0.0/14',     '20.40.0.0/13',    '20.48.0.0/12',
            '20.64.0.0/10',     '20.128.0.0/16',   '20.135.0.0/16',
            '20.136.0.0/16',    '20.143.0.0/16',   '20.144.0.0/14',
            '20.150.0.0/15',    '20.157.0.0/16',   '20.160.0.0/12',
            '20.176.0.0/14',    '20.180.0.0/14',   '20.184.0.0/13',
            '20.192.0.0/10',
            '40.64.0.0/10',     '40.128.0.0/12',
            '51.104.0.0/15',    '51.107.0.0/16',   '51.116.0.0/16',
            '51.120.0.0/16',    '51.124.0.0/16',   '51.132.0.0/16',
            '51.136.0.0/15',    '51.138.0.0/16',   '51.140.0.0/14',
            '51.144.0.0/15',    '52.96.0.0/12',    '52.112.0.0/14',
            '52.120.0.0/14',    '52.125.0.0/16',   '52.126.0.0/15',
            '52.130.0.0/15',    '52.132.0.0/14',   '52.136.0.0/13',
            '52.145.0.0/16',    '52.146.0.0/15',   '52.148.0.0/14',
            '52.152.0.0/13',    '52.160.0.0/11',   '52.224.0.0/11',

            // Cloudflare — https://www.cloudflare.com/ips/
            '103.21.244.0/22',  '103.22.200.0/22', '103.31.4.0/22',
            '104.16.0.0/13',    '104.24.0.0/14',
            '108.162.192.0/18', '131.0.72.0/22',
            '141.101.64.0/18',  '162.158.0.0/15',
            '172.64.0.0/13',    '173.245.48.0/20',
            '188.114.96.0/20',  '190.93.240.0/20',
            '197.234.240.0/22', '198.41.128.0/17',

            // DigitalOcean — major blocks
            '64.225.0.0/16',    '68.183.0.0/16',   '134.122.0.0/16',
            '134.209.0.0/16',   '137.184.0.0/16',  '138.68.0.0/16',
            '138.197.0.0/16',   '139.59.0.0/16',   '142.93.0.0/16',
            '143.110.0.0/16',   '143.198.0.0/16',  '144.126.0.0/16',
            '146.190.0.0/16',   '157.230.0.0/16',  '157.245.0.0/16',
            '158.247.0.0/16',   '159.65.0.0/16',   '159.89.0.0/16',
            '159.203.0.0/16',   '161.35.0.0/16',   '164.90.0.0/16',
            '164.92.0.0/16',    '165.22.0.0/16',   '165.227.0.0/16',
            '167.71.0.0/16',    '167.172.0.0/16',  '170.64.0.0/16',
            '174.138.0.0/16',   '178.128.0.0/16',  '178.62.0.0/16',

            // Hetzner — major blocks
            '5.75.0.0/16',      '5.161.0.0/16',
            '49.12.0.0/16',     '49.13.0.0/16',
            '65.108.0.0/16',    '65.109.0.0/16',
            '78.46.0.0/15',     '85.10.192.0/18',
            '88.198.0.0/16',    '88.99.0.0/16',
            '91.107.128.0/17',  '95.216.0.0/16',
            '116.202.0.0/16',   '116.203.0.0/16',
            '128.140.0.0/17',   '135.181.0.0/16',
            '136.243.0.0/16',   '138.201.0.0/16',
            '142.132.0.0/16',   '148.251.0.0/16',
            '157.90.0.0/16',    '159.69.0.0/16',
            '162.55.0.0/16',    '167.233.0.0/16',
            '168.119.0.0/16',   '176.9.0.0/16',
            '178.63.0.0/16',    '188.40.0.0/16',
            '195.201.0.0/16',   '213.133.96.0/19',
            '213.239.192.0/18',

            // OVH — major blocks
            '51.38.0.0/16',     '51.68.0.0/16',    '51.75.0.0/16',
            '51.77.0.0/16',     '51.79.0.0/16',    '51.81.0.0/16',
            '51.83.0.0/16',     '51.89.0.0/16',    '51.91.0.0/16',
            '51.161.0.0/16',    '51.178.0.0/16',   '51.195.0.0/16',
            '51.210.0.0/16',    '51.222.0.0/16',   '51.254.0.0/16',
            '54.36.0.0/16',     '54.37.0.0/16',    '54.38.0.0/16',
            '54.39.0.0/16',
            '139.99.0.0/16',    '145.239.0.0/16',  '147.135.0.0/16',
            '149.56.0.0/16',    '149.202.0.0/16',  '151.80.0.0/16',
            '158.69.0.0/16',    '167.114.0.0/16',  '176.31.0.0/16',
            '178.32.0.0/16',    '185.12.32.0/22',  '188.165.0.0/16',
            '192.95.0.0/18',    '192.99.0.0/16',   '198.27.64.0/18',
            '198.50.128.0/17',  '198.100.144.0/20',

            // Vultr — major blocks
            '45.32.0.0/16',     '45.63.0.0/16',    '45.76.0.0/16',
            '45.77.0.0/16',     '64.176.0.0/16',   '64.237.32.0/19',
            '66.42.0.0/16',     '78.141.192.0/18', '95.179.128.0/17',
            '104.156.224.0/19', '104.238.128.0/18','108.61.0.0/16',
            '136.244.64.0/18',  '140.82.0.0/17',   '149.28.0.0/16',
            '155.138.128.0/17', '207.148.0.0/18',  '209.250.224.0/19',
            '216.128.128.0/17', '217.69.0.0/16',

            // Linode/Akamai Cloud — major blocks
            '45.33.0.0/17',     '45.56.64.0/18',   '45.79.0.0/16',
            '50.116.0.0/18',    '66.175.208.0/20', '69.164.192.0/19',
            '72.14.176.0/20',   '74.207.224.0/19', '85.159.208.0/21',
            '96.126.96.0/19',   '97.107.128.0/17', '103.3.60.0/22',
            '109.74.192.0/20',  '139.144.0.0/16',  '139.162.0.0/16',
            '143.42.0.0/16',    '170.187.128.0/17','172.104.0.0/15',
            '172.232.0.0/14',   '176.58.96.0/19',  '178.79.128.0/17',
            '192.46.208.0/21',  '194.195.208.0/21',
        ];

        // Parse and cache the bitmask table on first call.
        static $parsed = null;
        if ($parsed === null) {
            $parsed = [];
            foreach ($datacenterCidrs as $cidr) {
                [$subnet, $bits] = explode('/', $cidr, 2);
                $bits  = (int) $bits;
                $mask  = $bits === 0 ? 0 : (~0 << (32 - $bits));
                $start = ip2long($subnet) & $mask;
                $parsed[] = [$start, $mask];
            }
        }

        foreach ($parsed as [$start, $mask]) {
            if (($ipLong & $mask) === $start) {
                return true;
            }
        }

        return false;
    }
    
    /**
     * Calculate variance in request timings
     * 
     * @param float[] $timings
     * @return float
     */
    private function calculateTimingVariance(array $timings): float {
        if (count($timings) < 2) return 1;
        
        $intervals = [];
        for ($i = 1; $i < count($timings); $i++) {
            $intervals[] = $timings[$i] - $timings[$i - 1];
        }
        
        $mean = array_sum($intervals) / count($intervals);
        if ($mean == 0) return 0;
        
        $variance = 0;
        foreach ($intervals as $interval) {
            $variance += pow($interval - $mean, 2);
        }
        $variance /= count($intervals);
        
        $stdDev = sqrt($variance);
        
        // Coefficient of variation (normalized variance)
        return $stdDev / $mean;
    }
    
    /**
     * Get cookie value
     * 
     * @param string $name
     * @return string|null
     */
    private function getCookie(string $name): ?string {
        return isset($_COOKIE[$name]) ? sanitize_text_field($_COOKIE[$name]) : null;
    }
    
    /**
     * Get all HTTP headers
     *
     * @return array<string, string>
     */
    private function getAllHeaders(): array {
        $headers = [];
        
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
        } else {
            // Fallback for nginx
            foreach ($_SERVER as $name => $value) {
                if (substr($name, 0, 5) === 'HTTP_') {
                    $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                    $headers[$name] = $value;
                }
            }
        }
        
        return $headers;
    }
    
    /**
     * Get the real client IP address.
     *
     * Delegates to IpResolver which validates CF-Connecting-IP against
     * Cloudflare's published CIDR ranges and ignores spoofable headers
     * such as X-Forwarded-For and X-Real-IP.
     *
     * @return string Canonical IP string, or '0.0.0.0' as a safe fallback.
     */
    private function getClientIp(): string {
        return IpResolver::getClientIp();
    }
    
    /**
     * Clean up old fingerprint records using config retention period
     * 
     * @return int Number of deleted records
     */
    public function cleanOldRecords(): int {
        $ts     = strtotime("-{$this->cleanupDays} days");
        $cutoff = date('Y-m-d H:i:s', $ts !== false ? $ts : time());

        return $this->fpRepo->deleteOlderThan($cutoff);
    }
    
    /**
     * Get statistics about fingerprints
     *
     * @return array<string, mixed>
     */
    public static function getStats(): array {
        $repo = new DeviceFingerprintRepository();

        $total              = $repo->getTotalCount();
        $uniqueUsers        = $repo->getDistinctUserCount();
        $uniqueFingerprints = $repo->getDistinctFingerprintCount();
        $automated          = $repo->countAboveAutomationScore(BCC_TRUST_AUTOMATION_MEDIUM);
        $highRisk           = $repo->countByRiskLevel('high');
        $sharedDevices      = $repo->countSharedFingerprints(BCC_TRUST_RING_MIN_SIZE);
        $multiAccountDevices = $repo->countSharedFingerprints(2);

        return [
            'total_records'         => $total,
            'unique_users'          => $uniqueUsers,
            'unique_fingerprints'   => $uniqueFingerprints,
            'automated_detected'    => $automated,
            'high_risk'             => $highRisk,
            'shared_devices'        => $sharedDevices,
            'multi_account_devices' => $multiAccountDevices,
            'sharing_ratio'         => $uniqueUsers > 0 ? round($sharedDevices / $uniqueUsers * 100, 2) . '%' : '0%',
            'thresholds'            => [
                'automation_high'   => BCC_TRUST_AUTOMATION_HIGH,
                'automation_medium' => BCC_TRUST_AUTOMATION_MEDIUM,
                'ring_min_size'     => BCC_TRUST_RING_MIN_SIZE,
            ],
        ];
    }
}