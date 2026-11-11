<?php

declare(strict_types=1);

/**
 * Doubles for rendering the REAL Pages, Users and Activity tabs.
 *
 * The tabs are real; only the repository behind them is faked, so the branch
 * under test — which sentence the operator is shown — is production code.
 *
 * ⚠ The consuming test class MUST run in separate processes. This file
 * declares `BCC\Trust\Core\Plugin`, and in the shared suite any of ~3,700
 * tests touching a production class that calls `Plugin::instance()` autoloads
 * the REAL Plugin first, after which this double is skipped and every case
 * dies on an undefined function. That cost PR #300 thirteen CI errors from a
 * file that passed alone.
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
    if (!function_exists('get_edit_post_link')) {
        function get_edit_post_link($id = 0, $ctx = 'display')
        {
            return 'https://example.test/edit?post=' . (int) $id;
        }
    }
    if (!function_exists('get_permalink')) {
        function get_permalink($p = 0)
        {
            return 'https://example.test/?p=' . (int) $p;
        }
    }
    if (!function_exists('get_avatar')) {
        function get_avatar($id, $size = 32, $d = '', $alt = '')
        {
            return '';
        }
    }
    if (!function_exists('mysql2date')) {
        function mysql2date($format, $date, $translate = true)
        {
            return (string) $date;
        }
    }
    if (!function_exists('human_time_diff')) {
        function human_time_diff(int $from, int $to = 0): string
        {
            return max(1, (int) round(abs(($to ?: time()) - $from) / 60)) . ' mins';
        }
    }
    if (!function_exists('number_format_i18n')) {
        function number_format_i18n($n, $d = 0)
        {
            return number_format((float) $n, (int) $d);
        }
    }
    if (!function_exists('add_query_arg')) {
        function add_query_arg($args, $url = '')
        {
            return $url . '?' . http_build_query((array) $args);
        }
    }
    if (!function_exists('trailingslashit')) {
        function trailingslashit($s): string
        {
            return rtrim((string) $s, '/') . '/';
        }
    }
    if (!function_exists('get_the_title')) {
        function get_the_title($p = 0)
        {
            return 'Fixture Page';
        }
    }
    // ⚠ Plugin helpers the tabs call directly. Enumerated from the three tab
    // files rather than discovered one CI error at a time: every lowercase
    // call was listed, builtins and already-stubbed names removed, and what
    // remained is exactly this set.
    if (!function_exists('bcc_trust_get_tier_info_from_score')) {
        function bcc_trust_get_tier_info_from_score($score)
        {
            return ['label' => 'Trusted', 'color' => '#8bc34a', 'tier' => 'trusted'];
        }
    }
    // ⚠ bcc_trust_get_peepso_page_url() and ...settings_url() are NOT
    // stubbed here: tab-pages.php DEFINES them itself. A
    // function_exists() guard cannot save it, because this stub loads
    // FIRST — so the result is a redeclare FATAL, not a skipped stub.
    // bcc_trust_get_tier_info_from_score() does need a stub: it lives in
    // includes/admin/moderation.php, which this suite does not load.
}

namespace BCC\Trust\Core {

    final class ListRenderWorld
    {
        /** @var array<string, mixed> */ public static array $pages = [];
        /** @var array<string, mixed> */ public static array $allPages = [];
        /** @var array<string, mixed> */ public static array $users = [];
        /** @var array<string, mixed> */ public static array $activity = [];

        public static function reset(): void
        {
            self::$pages = [
                'ok' => true, 'cpt_map' => [], 'total' => 1, 'limit' => 50,
                'pages' => [(object) [
                    'page_id' => 9101, 'page_owner_id' => 1, 'post_title' => 'Fixture Page',
                    'post_name' => 'fixture-page', 'post_status' => 'publish',
                    'post_type' => 'peepso-page', 'post_date' => '2026-10-11 00:00:00',
                    'total_score' => 70.0, 'reputation_tier' => 'trusted',
                    'vote_count' => 3, 'confidence_score' => 0.8,
                    'owner_display_name' => 'List Fixture',
                ]],
            ];
            self::$allPages = self::$pages;
            self::$allPages['pages'][0]->ID = 9101;

            self::$users = [
                'ok' => true, 'total' => 1, 'limit' => 50,
                'users' => [(object) [
                    'user_id' => 961, 'display_name' => 'List Fixture',
                    'user_email' => 'list@example.test', 'fraud_score' => 10,
                    'trust_rank' => 1.0, 'risk_level' => 'minimal', 'user_tier' => 'member',
                    'is_suspended' => 0, 'is_verified' => 1, 'votes_cast' => 2,
                    'endorsements_given' => 0, 'updated_at' => '2026-10-11 00:00:00',
                    'page_count' => 1, 'avg_page_score' => 70.0,
                ]],
            ];

            self::$activity = [
                'ok' => true, 'total' => 1, 'limit' => 50,
                'activity' => [(object) [
                    'id' => 1, 'user_id' => 961, 'action' => 'admin_list_fixture',
                    'target_type' => 'page', 'target_id' => 9101,
                    'ip_address' => '203.0.113.9', 'created_at' => '2026-10-11 00:00:00',
                    'user_name' => 'List Fixture',
                ]],
            ];
        }
    }

    final class StubListRepository
    {
        /** @return array<string, mixed> */
        public function getPagesData(): array
        {
            return ListRenderWorld::$pages;
        }

        /** @return array<string, mixed> */
        public function getAllPagesData(): array
        {
            return ListRenderWorld::$allPages;
        }

        /** @return array<string, mixed> */
        public function getUsersData(): array
        {
            return ListRenderWorld::$users;
        }

        /** @return array<string, mixed> */
        public function getActivityData(): array
        {
            return ListRenderWorld::$activity;
        }

        /** @return list<object> */
        public function getAdminOnlyActivity(int $limit): array
        {
            return ListRenderWorld::$activity['activity'];
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

            public function adminDashboardRepository(): StubListRepository
            {
                return new StubListRepository();
            }
        }
    }
}
