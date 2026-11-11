<?php

declare(strict_types=1);

/**
 * Doubles for rendering the REAL `bcc_trust_render_overview_tab()`.
 *
 * ── WHAT IS REAL ────────────────────────────────────────────────────────
 * The tab itself. `includes/admin/tabs/tab-overview.php` is required and
 * executed, so every branch under test is production code: the dash
 * substitution, the colour choice, the two notices, the percentage guards
 * and the device-reconciliation warning.
 *
 * ── WHAT IS FAKED, AND WHY ──────────────────────────────────────────────
 * Only the repository behind it, through the Plugin singleton the tab
 * reaches for. The point of these tests is what the tab DISPLAYS for a
 * given repository answer, so the answer is the input and everything that
 * turns it into markup is real. The repository's own behaviour — which
 * answers it actually produces, and whether a failed one is cached — is
 * pinned against a real database in
 * {@see \BCC\Trust\Tests\Integration\AdminDashboardReadFailureIntegrationTest}.
 *
 * ⚠ The escaping helpers are the real WordPress ones in spirit but local
 * here, because this suite has no WordPress. They are deliberately NOT
 * no-ops: `esc_html()` escapes, so a test asserting on rendered text is
 * asserting on escaped text exactly as a browser receives it.
 */

namespace {
    if (!function_exists('esc_html')) {
        function esc_html($t): string
        {
            return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
        }
    }
    if (!function_exists('esc_attr')) {
        function esc_attr($t): string
        {
            return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
        }
    }
    if (!function_exists('esc_url')) {
        function esc_url($t): string
        {
            return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
        }
    }
    if (!function_exists('esc_html__')) {
        function esc_html__($t, $d = null): string
        {
            return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
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
            $to   = $to ?: time();
            $mins = max(1, (int) round(abs($to - $from) / 60));

            return $mins . ' mins';
        }
    }
}

namespace BCC\Trust\Core {

    /**
     * The repository answer under test, plus a counter so a test can prove the
     * tab asked for it rather than inferring the render from a default.
     */
    final class OverviewRenderWorld
    {
        /** @var array<string, mixed> */
        public static array $overview = [];

        /** @var array<string, mixed> */
        public static array $today = [];

        public static int $overviewCalls = 0;
        public static int $todayCalls    = 0;

        public static function reset(): void
        {
            self::$overviewCalls = 0;
            self::$todayCalls    = 0;
            self::$overview      = self::healthyOverview();
            self::$today         = self::healthyToday();
        }

        /** @return array<string, mixed> */
        public static function healthyOverview(): array
        {
            return [
                'ok'     => true,
                'counts' => [
                    'total_votes'           => 12,
                    'total_endorsements'    => 7,
                    'total_pages'           => 40,
                    'total_users'           => 3,
                    'total_devices'         => 10,
                    'total_fraud_analyses'  => 5,
                    'recent_fraud_analyses' => 2,
                ],
                'risk_distribution' => [
                    'critical_risk' => 1,
                    'high_risk'     => 2,
                    'medium_risk'   => 3,
                    'low_risk'      => 4,
                    'minimal_risk'  => 5,
                ],
                'device_risk' => [
                    'high_automation' => 2,
                    'suspicious'      => 3,
                    'normal'          => 5,
                ],
                'tier_distribution' => [
                    'elite'   => 1,
                    'trusted' => 2,
                    'neutral' => 3,
                    'caution' => 4,
                    'risky'   => 5,
                ],
                'thresholds' => [],
            ];
        }

        /** @return array<string, mixed> */
        public static function healthyToday(): array
        {
            return [
                'ok'                  => true,
                'today_fraud'         => 0,
                'today_suspensions'   => 0,
                'today_verifications' => 4,
                'today_votes'         => 6,
                'today_admin_actions' => 1,
            ];
        }
    }

    final class StubAdminDashboardRepository
    {
        /** @return array<string, mixed> */
        public function getOverviewData(): array
        {
            OverviewRenderWorld::$overviewCalls++;

            return OverviewRenderWorld::$overview;
        }

        /** @return array<string, mixed> */
        public function getTodaySummary(): array
        {
            OverviewRenderWorld::$todayCalls++;

            return OverviewRenderWorld::$today;
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

            public function adminDashboardRepository(): StubAdminDashboardRepository
            {
                return new StubAdminDashboardRepository();
            }
        }
    }
}
