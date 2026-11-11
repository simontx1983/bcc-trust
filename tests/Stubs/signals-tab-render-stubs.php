<?php

declare(strict_types=1);

/**
 * Doubles for rendering the four REAL Signals tabs.
 *
 * ── WHAT IS REAL ────────────────────────────────────────────────────────
 * The tabs themselves. tab-fraud.php, tab-devices.php, tab-rings.php and
 * tab-ml.php are required and executed, so the branch under test — which
 * sentence the operator is shown — is production code.
 *
 * ── WHAT IS FAKED ───────────────────────────────────────────────────────
 * Only the repository behind them, through the Plugin singleton the tabs
 * reach for. The repository's own behaviour (which verdict it produces) is
 * pinned against a real database in SignalsTabReadFailureIntegrationTest.
 *
 * ⚠ The consuming test class MUST run in separate processes. This file
 * declares `BCC\Trust\Core\Plugin`, and in the shared suite any of ~3,700
 * tests touching a production class that calls `Plugin::instance()`
 * autoloads the REAL Plugin first — after which this double is skipped and
 * every case dies on an undefined cache function. That is not theoretical:
 * it cost PR #300 thirteen CI errors from a file that passed alone.
 */

namespace {
    foreach (['esc_html', 'esc_attr', 'esc_url', 'esc_html__'] as $fn) {
        if (!function_exists($fn)) {
            eval("function {$fn}(\$t, \$d = null): string { return htmlspecialchars((string) \$t, ENT_QUOTES, 'UTF-8'); }");
        }
    }
    if (!function_exists('esc_html_e')) {
        function esc_html_e($t, $d = null): void
        {
            echo htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
        }
    }
    if (!function_exists('admin_url')) {
        function admin_url(string $path = ''): string
        {
            return 'https://example.test/wp-admin/' . $path;
        }
    }
    if (!function_exists('human_time_diff')) {
        function human_time_diff(int $from, int $to = 0): string
        {
            return max(1, (int) round(abs(($to ?: time()) - $from) / 60)) . ' mins';
        }
    }
    if (!function_exists('get_avatar')) {
        function get_avatar($id, $size = 32, $d = '', $alt = '')
        {
            return '';
        }
    }
    if (!function_exists('wp_kses_post')) {
        function wp_kses_post($s)
        {
            return $s;
        }
    }
}

namespace BCC\Trust\Core {

    final class SignalsRenderWorld
    {
        /** @var array<string, mixed> */ public static array $fraud = [];
        /** @var array<string, mixed> */ public static array $devices = [];
        /** @var array<string, mixed> */ public static array $rings = [];
        /** @var array<string, mixed> */ public static array $ml = [];

        public static function reset(): void
        {
            self::$fraud = [
                'ok'                  => true,
                'fraud_users'         => [self::user()],
                'critical_count'      => 1,
                'high_risk_threshold' => 60,
                'critical_threshold'  => 90,
                'total'               => 1,
                'limit'               => 100,
            ];
            self::$devices = [
                'ok'         => true,
                'devices'    => [self::device()],
                'total'      => 1,
                'limit'      => 100,
                'thresholds' => ['high' => 80, 'medium' => 50],
            ];
            self::$rings = [
                'available'  => true,
                'rings'      => [],
                'thresholds' => ['min_size' => 3, 'min_mutual' => 3],
            ];
            self::$ml = [
                'ok'             => true,
                'patterns'       => [self::pattern()],
                'pattern_counts' => [(object) ['pattern_type' => 'burst_voting', 'count' => 1]],
                'total'          => 1,
                'limit'          => 100,
            ];
        }

        private static function user(): object
        {
            return (object) [
                'user_id' => 951, 'fraud_score' => 90, 'risk_level' => 'high',
                'automation_score' => 10, 'behavior_score' => 10,
                'recent_analyses' => 0, 'total_analyses' => 0,
                'fraud_triggers' => '', 'signals' => [],
                'risk_label' => 'High', 'risk_color' => '#f44336',
                'display_name' => 'Signals Fixture', 'user_email' => 'sig@example.test',
                'is_suspended' => 0, 'is_verified' => 0, 'updated_at' => '2026-10-11 00:00:00',
            ];
        }

        /**
         * ⚠ Every property the view reads, because a double that is missing
         * one emits "Undefined property" — and a fixture that triggers PHP
         * warnings is not a faithful stand-in for a real row. It also quietly
         * weakens testEveryDegradedRenderIsFreeOfPhpDiagnostics, which would
         * then be passing next to warnings it simply cannot see.
         */
        private static function device(): object
        {
            return (object) [
                'id' => 1, 'user_id' => 951, 'fingerprint' => 'sig-fp-0001',
                'automation_score' => 95, 'automation_color' => '#f44336',
                'ip_address' => '203.0.113.7',
                'first_seen' => '2026-10-01 00:00:00', 'last_seen' => '2026-10-11 00:00:00',
                'display_name' => 'Signals Fixture', 'risk_level' => 'high',
                'user_count' => 1,
            ];
        }

        private static function pattern(): object
        {
            return (object) [
                'id' => 1, 'user_id' => 951, 'pattern_type' => 'burst_voting',
                'pattern_data' => '{"n":12}', 'confidence' => 0.9,
                'detected_at' => '2026-10-11 00:00:00', 'expires_at' => null,
                'display_name' => 'Signals Fixture',
                'details' => 'burst of 12 votes in 40s', 'severity' => 'high',
            ];
        }
    }

    final class StubSignalsRepository
    {
        /** @return array<string, mixed> */
        public function getFraudData(): array
        {
            return SignalsRenderWorld::$fraud;
        }

        /** @return array<string, mixed> */
        public function getDevicesData(): array
        {
            return SignalsRenderWorld::$devices;
        }

        /** @return array<string, mixed> */
        public function getRingsData(): array
        {
            return SignalsRenderWorld::$rings;
        }

        /** @return array<string, mixed> */
        public function getMlData(): array
        {
            return SignalsRenderWorld::$ml;
        }

        public function moderationService(): self
        {
            return $this;
        }

        /** Real signature: ModerationService::isFingerprintWhitelisted(string): bool */
        public function isFingerprintWhitelisted(string $fp): bool
        {
            return false;
        }
    }

    if (!class_exists('BCC\Trust\Core\Plugin', false)) {
        final class Plugin
        {
            private static ?self $instance = null;

            public static function instance(): self
            {
                return self::$instance ??= new self();
            }

            public function adminDashboardRepository(): StubSignalsRepository
            {
                return new StubSignalsRepository();
            }

            public function moderationService(): StubSignalsRepository
            {
                return new StubSignalsRepository();
            }
        }
    }
}
