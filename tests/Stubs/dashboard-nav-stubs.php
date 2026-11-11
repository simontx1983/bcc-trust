<?php

declare(strict_types=1);

/**
 * Doubles for including the REAL `includes/dashboard-view.php`.
 *
 * ── WHAT IS REAL ────────────────────────────────────────────────────────
 * The whole view: the cluster map, the legacy aliases, the resolution of
 * ?tab= and ?sub=, both navs, and the dispatch — including the branch taken
 * when a renderer is missing. That dispatch is the subject.
 *
 * ── WHAT IS FAKED, AND WHY IT MATTERS HERE ──────────────────────────────
 * The tab renderers, each emitting a unique marker instead of its real
 * panel. The markers are the mechanism: asserting that OVERVIEW'S marker is
 * ABSENT from a failed Fraud tab is what proves the old silent fallback is
 * gone, and reading the dispatch code could not prove it as directly.
 *
 * ⚠⚠ BCC_TRUST_PATH IS REDIRECTED AT A TEMP ROOT, DELIBERATELY.
 * The view opens with `require_once BCC_TRUST_PATH . 'includes/admin/tabs/'
 * . 'tab-*.php'`. Pointed at the real plugin root it would load the real
 * renderers — which would (a) defeat defineOverviewRendererOnly(), the one
 * state the third defect lives in, and (b) drag the whole repository and
 * WordPress in behind them. The temp root holds stand-ins that define
 * nothing, so this file alone decides which renderers exist.
 */

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
if (!function_exists('sanitize_key')) {
    /** Mirrors WP: lowercase, and only a-z0-9_- survive. */
    function sanitize_key($k): string
    {
        return strtolower((string) preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $k));
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
        return $url . '?' . http_build_query((array) $args);
    }
}

final class BccNavStubs
{
    public const OVERVIEW_MARKER = '[[STUB-OVERVIEW-PANEL]]';
    public const FRAUD_MARKER    = '[[STUB-FRAUD-PANEL]]';

    /** Every renderer the cluster map can dispatch to. */
    private const RENDERERS = [
        'bcc_trust_render_overview_tab'  => self::OVERVIEW_MARKER,
        'bcc_trust_render_ecosystem_tab' => '[[STUB-ECOSYSTEM-PANEL]]',
        'bcc_trust_render_pages_tab'     => '[[STUB-PAGES-PANEL]]',
        'bcc_trust_render_all_pages_tab' => '[[STUB-ALLPAGES-PANEL]]',
        'bcc_trust_render_users_tab'     => '[[STUB-USERS-PANEL]]',
        'bcc_trust_render_verified_tab'  => '[[STUB-VERIFIED-PANEL]]',
        'bcc_trust_render_fraud_tab'     => self::FRAUD_MARKER,
        'bcc_trust_render_devices_tab'   => '[[STUB-DEVICES-PANEL]]',
        'bcc_trust_render_rings_tab'     => '[[STUB-RINGS-PANEL]]',
        'bcc_trust_render_ml_tab'        => '[[STUB-ML-PANEL]]',
        'bcc_trust_render_activity_tab'  => '[[STUB-ACTIVITY-PANEL]]',
        'bcc_trust_render_push_tab'      => '[[STUB-PUSH-PANEL]]',
    ];

    /** The temp root BCC_TRUST_PATH points at; holds tab stand-ins only. */
    public static function prepareTabRoot(): string
    {
        $root = sys_get_temp_dir() . '/bcc-nav-stub-' . getmypid();
        $tabs = $root . '/includes/admin/tabs';
        if (!is_dir($tabs)) {
            mkdir($tabs, 0777, true);
        }

        // ⚠ EVERY file the view requires, not just the ones under tabs/.
        // dashboard-verified.php sits a directory up, and a missing stand-in
        // there fails the require rather than the assertion — 17 errors that
        // say nothing about the navigation under test.
        $standIns = [
            'includes/admin/tabs/tab-overview.php',
            'includes/admin/tabs/tab-ecosystem.php',
            'includes/admin/tabs/tab-pages.php',
            'includes/admin/tabs/tab-users.php',
            'includes/admin/tabs/tab-activity.php',
            'includes/admin/tabs/tab-fraud.php',
            'includes/admin/tabs/tab-devices.php',
            'includes/admin/tabs/tab-rings.php',
            'includes/admin/tabs/tab-ml.php',
            'includes/admin/tabs/tab-push.php',
            'includes/admin/dashboard-verified.php',
        ];
        foreach ($standIns as $rel) {
            $path = $root . '/' . $rel;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            if (!file_exists($path)) {
                file_put_contents($path, "<?php\n// nav-test stand-in: defines no renderer\n");
            }
        }

        return $root . '/';
    }

    public static function defineRenderers(): void
    {
        foreach (self::RENDERERS as $fn => $marker) {
            self::define($fn, $marker);
        }
    }

    /**
     * Only Overview exists — the state a partially deployed or truncated tab
     * file leaves, and the one in which the old dispatch reached for
     * Overview's content and showed it under the selected tab.
     */
    public static function defineOverviewRendererOnly(): void
    {
        self::define('bcc_trust_render_overview_tab', self::OVERVIEW_MARKER);
    }

    private static function define(string $fn, string $marker): void
    {
        if (function_exists($fn)) {
            return;
        }
        eval("function {$fn}(): void { echo '" . $marker . "'; }");
    }
}

if (!defined('BCC_TRUST_PATH')) {
    define('BCC_TRUST_PATH', BccNavStubs::prepareTabRoot());
}
