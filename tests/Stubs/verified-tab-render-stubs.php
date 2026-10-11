<?php

declare(strict_types=1);

/**
 * Doubles for rendering the REAL Users ▸ Verified tab.
 *
 * The tab is real; only the repository behind it is faked, so the branch
 * under test — which sentence and which figures the operator is shown — is
 * production code.
 *
 * ⚠ The consuming test class MUST run in separate processes with
 * PreserveGlobalState(false). This file declares `BCC\Trust\Core\Plugin`, and
 * in the shared suite any of ~3,700 tests touching a production class that
 * calls `Plugin::instance()` autoloads the REAL Plugin first, after which
 * this double is skipped and every case dies on an undefined function. That
 * cost PR #300 thirteen CI errors from a file that passed alone, and a
 * `class_exists(..., false)` guard cannot win the race — it only decides
 * which of the two loses.
 *
 * ⚠ The stub list was ENUMERATED from the tab file with a token walk (every
 * non-builtin function call that the file does not define itself), not
 * discovered one CI error at a time. Fourteen names, all present below.
 */

namespace {
    // ABSPATH gates the tab file's own early exit.
    if (!defined('ABSPATH')) {
        define('ABSPATH', '/var/www/html/');
    }

    // ⚠ Config constants the tab reads at the top of the function. Without
    // them the file fatals before reaching anything this suite is about.
    // BCC_TRUST_WEIGHT_* drive the "Trust Boost Tiers" card, which is the one
    // card that must NOT be dashed on a read failure — it is config, not a
    // read, and a test that dashed it would be enforcing the wrong thing.
    //
    // ⚠⚠ THE GUARD DEFERS TO THE REAL CONFIG, AND THAT IS THE POINT — but it
    // also means these numbers are NOT what the test sees. The unit bootstrap
    // loads includes/config/*.php, so in the suite the real values win and
    // these are used only if that ever stops being true. A first version of
    // this file guessed BCC_TRUST_WEIGHT_TRUSTED = 0.20 while the real config
    // says 0.25, and the config-card case failed on the real value — which is
    // the stub being wrong, not the page. The values below now MATCH
    // includes/config/trust-weights.php, and the test derives its expected
    // string from the constants rather than hardcoding either.
    foreach ([
        'BCC_TRUST_ADMIN_VERIFIED_PER_PAGE' => 50,
        'BCC_TRUST_WEIGHT_ELITE'            => 0.30,
        'BCC_TRUST_WEIGHT_TRUSTED'          => 0.25,
        'BCC_TRUST_FRAUD_MEDIUM'            => 40,
    ] as $name => $value) {
        if (!defined($name)) {
            define($name, $value);
        }
    }

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
    if (!function_exists('add_query_arg')) {
        function add_query_arg($args, $url = '')
        {
            return (string) $url . '?' . http_build_query((array) $args);
        }
    }
    if (!function_exists('paginate_links')) {
        function paginate_links($args = '')
        {
            // Marked, so a test can assert pagination did NOT render rather
            // than inferring it from an absent number.
            return '<!--PAGINATE-->';
        }
    }
    if (!function_exists('selected')) {
        function selected($a, $b = true, $echo = true): string
        {
            $out = (string) $a === (string) $b ? " selected='selected'" : '';
            if ($echo) {
                echo $out;
            }
            return $out;
        }
    }
    if (!function_exists('sanitize_text_field')) {
        function sanitize_text_field($s): string
        {
            return trim(strip_tags((string) $s));
        }
    }
    if (!function_exists('sanitize_key')) {
        function sanitize_key($k): string
        {
            return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k)) ?? '';
        }
    }
    if (!function_exists('get_option')) {
        function get_option($name, $default = false)
        {
            return $name === 'date_format' ? 'Y-m-d' : $default;
        }
    }
    if (!function_exists('date_i18n')) {
        function date_i18n($format, $timestamp = null, $gmt = false): string
        {
            return gmdate((string) $format, $timestamp === null ? time() : (int) $timestamp);
        }
    }
    if (!function_exists('current_time')) {
        function current_time($type, $gmt = 0)
        {
            return $type === 'timestamp' ? time() : gmdate('Y-m-d H:i:s');
        }
    }
    if (!function_exists('human_time_diff')) {
        function human_time_diff(int $from, int $to = 0): string
        {
            return max(1, (int) round(abs(($to ?: time()) - $from) / 60)) . ' mins';
        }
    }
}

namespace BCC\Trust\Core {

    final class VerifiedRenderWorld
    {
        /** @var array<string, mixed> */
        public static array $panel = [];

        public static function reset(): void
        {
            self::$panel = [
                'ok'    => true,
                'total' => 1,
                'users' => [self::row()],
                'summary' => [
                    'ok'                   => true,
                    'total_verified'       => 1234,
                    'avg_net_impact'       => 7.25,
                    'high_quality_count'   => 56,
                    'recent_verifications' => 78,
                ],
            ];
        }

        /**
         * ⚠ One row, carrying every field the table body dereferences. The
         * field list was taken from the tab file rather than guessed, because
         * a missing property is a PHP 8 warning and this suite runs under
         * failOnWarning="true" — it would fail as a stub defect while looking
         * like a render defect.
         */
        private static function row(): object
        {
            return (object) [
                'user_id'              => 961,
                'display_name'         => 'Verified Fixture',
                'user_email'           => 'verified@example.test',
                'fraud_score'          => 10,
                'trust_boost'          => 12.5,
                'fraud_reduction'      => 3.5,
                'net_impact'           => 9.0,
                'quality_score'        => 82,
                'account_age_days'     => 400,
                'github_username'      => 'fixture',
                'github_avatar'        => '',
                'github_followers'     => 150,
                'github_public_repos'  => 20,
                'github_org_count'     => 2,
                'github_verified_at'   => '2026-10-01 00:00:00',
                'has_verified_email'   => 1,
                'admin'                => 0,
                'meta'                 => '',
                'get'                  => '',
            ];
        }
    }

    final class StubVerifiedRepository
    {
        /** @return array<string, mixed> */
        public function getVerifiedPanelData(
            string $whereClause,
            array $params,
            string $orderbyCol,
            string $order,
            int $perPage,
            int $offset
        ): array {
            return VerifiedRenderWorld::$panel;
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

            public function adminDashboardRepository(): StubVerifiedRepository
            {
                return new StubVerifiedRepository();
            }
        }
    }
}
