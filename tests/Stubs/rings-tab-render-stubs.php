<?php

declare(strict_types=1);

/**
 * Doubles for rendering the REAL Vote Rings tab.
 *
 * The tab is real; only the repository behind it is faked, so what the
 * operator is shown — and what the two destructive forms are armed with — is
 * production code.
 *
 * ⚠ The consuming test class MUST run in separate processes with
 * PreserveGlobalState(false). This file declares `BCC\Trust\Core\Plugin`, and
 * in the shared suite any of ~3,800 tests touching a production class that
 * calls `Plugin::instance()` autoloads the REAL Plugin first, after which
 * this double is skipped and every case dies on an undefined function. That
 * cost PR #300 thirteen CI errors from a file that passed alone.
 *
 * ⚠ The stub list was ENUMERATED from the tab with a token walk (every
 * non-builtin call the file does not define itself): eight names, all below.
 */

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', '/var/www/html/');
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
    if (!function_exists('wp_nonce_field')) {
        function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $display = true)
        {
            $html = '<input type="hidden" name="' . $name . '" value="nonce">';
            if ($display) {
                echo $html;
            }
            return $html;
        }
    }
    if (!function_exists('human_time_diff')) {
        function human_time_diff(int $from, int $to = 0): string
        {
            return max(1, (int) round(abs(($to ?: time()) - $from) / 60)) . ' mins';
        }
    }
    // ⚠ The four below are for the ring_msg notice block. Enumerated from
    // the tab with a token walk after the block was added, not discovered
    // one failing case at a time.
    if (!function_exists('__')) {
        function __($t, $d = null) { return $t; }
    }
    if (!function_exists('_n')) {
        function _n($single, $plural, $number, $d = null)
        {
            return (int) $number === 1 ? $single : $plural;
        }
    }
    if (!function_exists('sanitize_text_field')) {
        function sanitize_text_field($s): string
        {
            return trim(strip_tags((string) $s));
        }
    }
    if (!function_exists('wp_unslash')) {
        function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; }
    }
}

namespace BCC\Trust\Core {

    final class RingsRenderWorld
    {
        /** @var array<string, mixed> */
        public static array $data = [];

        public static function reset(): void
        {
            self::$data = [
                'available'   => true,
                'rings'       => [],
                'computed_at' => time() - 3600,
                'age_seconds' => 3600,
                'thresholds'  => ['min_size' => 3, 'min_mutual' => 3],
            ];
        }

        public static function absent(): void
        {
            self::reset();
            self::$data['available']   = false;
            self::$data['computed_at'] = null;
            self::$data['age_seconds'] = null;
        }

        /**
         * One ring of each producer shape, AFTER normalisation — which is the
         * only shape the tab is ever handed now.
         */
        public static function populated(): void
        {
            self::reset();
            self::$data['rings'] = [
                [
                    'users'       => [11, 12, 13],
                    'size'        => 3,
                    'kind'        => 'vote_ring',
                    'strength'    => 0.93,
                    'detected_at' => '2026-10-11 09:00:00',
                ],
                [
                    'users'       => [21, 22, 23, 24],
                    'size'        => 4,
                    'kind'        => 'endorsement_ring',
                    'strength'    => null,
                    'detected_at' => '2026-10-11 09:00:00',
                ],
            ];
        }
    }

    final class StubRingsRepository
    {
        /** @return array<string, mixed> */
        public function getRingsData(): array
        {
            return RingsRenderWorld::$data;
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

            public function adminDashboardRepository(): StubRingsRepository
            {
                return new StubRingsRepository();
            }
        }
    }
}
