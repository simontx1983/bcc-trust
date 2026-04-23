<?php
/**
 * Plugin Name: Blue Collar Crypto – Trust
 * Description: Unified reputation, dispute, and on-chain signal plugin. Merges bcc-trust-engine, bcc-disputes, and bcc-onchain-signals into a single bounded-context codebase.
 * Version: 1.1.0
 * Author: Blue Collar Labs LLC
 * Text Domain: bcc-trust
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 8.1
 * Requires Plugins: bcc-core
 */

if (!defined('ABSPATH')) {
    exit;
}

/*
|--------------------------------------------------------------------------
| CONSTANTS
|--------------------------------------------------------------------------
*/

define('BCC_TRUST_VERSION', '1.1.0');
define('BCC_TRUST_PATH', plugin_dir_path(__FILE__));
define('BCC_TRUST_URL', plugin_dir_url(__FILE__));
define('BCC_TRUST_FILE', __FILE__);

/**
 * Schema version — derived from the content hash of every
 * includes/database/schema-*.php file. Any edit to a schema definition
 * automatically triggers dbDelta on the next request.
 */
define('BCC_TRUST_SCHEMA_VERSION', (static function (): string {
    $files = glob(__DIR__ . '/includes/database/schema-*.php') ?: [];
    if (!$files) {
        return '0000000000';
    }
    sort($files);
    $input = '';
    foreach ($files as $file) {
        $input .= basename($file) . ':' . md5_file($file) . "\n";
    }
    return substr(md5($input), 0, 10);
})());

/*
|--------------------------------------------------------------------------
| AUTOLOADER
|--------------------------------------------------------------------------
*/

if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>BCC Trust:</strong> Run <code>composer install</code> in the plugin directory to generate the autoloader.</p></div>';
    });
    return;
}

require_once __DIR__ . '/vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| DEPENDENCY CHECK — bcc-core must be active
|--------------------------------------------------------------------------
*/

if (!defined('BCC_CORE_VERSION')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>'
           . '<strong>BCC Trust:</strong> '
           . 'The <strong>BCC Core</strong> plugin must be activated first. '
           . 'Please activate BCC Core, then re-activate Trust.'
           . '</p></div>';
    });
    return;
}

/*
|--------------------------------------------------------------------------
| INERT-MODE GUARD — BCC_ENCRYPTION_KEY required
|--------------------------------------------------------------------------
| Without this constant, every non-admin user receives 403 on trust,
| dispute, and onchain REST calls (NullTrustReadService::isSuspended()
| returns true across the board). Notices are aggressive and cron is
| cleared so scheduled events do not fire into the void.
*/

if (!defined('BCC_ENCRYPTION_KEY')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error" style="border-left-color:#dc3232;border-left-width:6px;background:#fff5f5;">'
           . '<p style="font-size:14px;"><strong>⛔ CRITICAL: BCC Trust is DISABLED — ALL USERS ARE LOCKED OUT.</strong></p>'
           . '<p>Define <code>BCC_ENCRYPTION_KEY</code> in <code>wp-config.php</code> to activate. '
           . 'Without this key, every non-admin user receives 403 on all trust, dispute, and onchain API calls. '
           . 'All trust scoring, fraud detection, and vote processing are currently offline.</p>'
           . '<p><code>define(\'BCC_ENCRYPTION_KEY\', \'your-secret-key-here\');</code></p>'
           . '</div>';
    });
    add_action('admin_bar_menu', function ($bar) {
        if (!is_object($bar) || !current_user_can('manage_options')) {
            return;
        }
        $bar->add_node([
            'id'    => 'bcc-trust-inert',
            'title' => '⛔ Trust DISABLED (BCC_ENCRYPTION_KEY missing)',
            'href'  => admin_url('plugins.php'),
            'meta'  => [
                'class' => 'bcc-trust-inert-warning',
                'title' => 'BCC Trust is inert. All non-admin users receive 403 on trust, dispute, and onchain API calls. Define BCC_ENCRYPTION_KEY in wp-config.php.',
            ],
        ]);
    }, 100);
    add_action('wp_head', function () {
        if (current_user_can('manage_options')) {
            echo '<style>#wpadminbar #wp-admin-bar-bcc-trust-inert > .ab-item{background:#dc3232!important;color:#fff!important;font-weight:bold;}</style>';
        }
    });
    add_action('admin_head', function () {
        if (current_user_can('manage_options')) {
            echo '<style>#wpadminbar #wp-admin-bar-bcc-trust-inert > .ab-item{background:#dc3232!important;color:#fff!important;font-weight:bold;}</style>';
        }
    });
    if (function_exists('error_log')) {
        error_log('[BCC CRITICAL] BCC_ENCRYPTION_KEY is not defined. Trust is inert. All non-admin users are locked out.');
    }
    if (!get_option('bcc_trust_inert_since')) {
        update_option('bcc_trust_inert_since', time(), false);
    }
    $hooks_to_clear = [
        'bcc_trust_daily_cleanup', 'bcc_trust_hourly_recalc',
        'bcc_trust_daily_ml_update', 'bcc_trust_daily_graph_update',
        'bcc_trust_daily_vesting', 'bcc_trust_process_recalculations',
        'bcc_trust_daily_maintenance', 'bcc_trust_deferred_rm_sync',
    ];
    foreach ($hooks_to_clear as $hook) {
        wp_clear_scheduled_hook($hook);
    }
    return;
}

if (get_option('bcc_trust_inert_since')) {
    delete_option('bcc_trust_inert_since');
}

/*
|--------------------------------------------------------------------------
| CONFIGURATION + DATABASE
|--------------------------------------------------------------------------
*/

require_once BCC_TRUST_PATH . 'includes/config.php';
require_once BCC_TRUST_PATH . 'includes/database/tables.php';

// Schema migration: re-run dbDelta when any schema file changes.
add_action('plugins_loaded', function (): void {
    $stored = get_option('bcc_trust_schema_version', '');
    if ($stored !== BCC_TRUST_SCHEMA_VERSION) {
        if (function_exists('bcc_trust_create_tables')) {
            bcc_trust_create_tables();
        }
        update_option('bcc_trust_schema_version', BCC_TRUST_SCHEMA_VERSION, false);
    }
}, 5);

/*
|--------------------------------------------------------------------------
| DATABASE HOOKS (user lifecycle, read model sync, vote cache)
|--------------------------------------------------------------------------
*/

add_action('plugins_loaded', function () {
    \BCC\Trust\Core\Plugin::instance()->userLifecycleService()->register();
    \BCC\Trust\Core\Services\PageReadModelSync::register();
    \BCC\Trust\Core\Services\ScoreMutationLogger::register();
}, 5);

add_action('bcc_trust_vote_cast', function (int $voterId): void {
    if ($voterId > 0) {
        \BCC\Trust\Core\Services\Vote\VoteEligibilityChecker::invalidateForUser($voterId);
    }
});
add_action('bcc_trust_vote_removed', function (int $voterId): void {
    if ($voterId > 0) {
        \BCC\Trust\Core\Services\Vote\VoteEligibilityChecker::invalidateForUser($voterId);

        $voteRepo = \BCC\Trust\Core\Plugin::instance()->voteRepository();
        $remaining = $voteRepo->countByVoter($voterId);
        if ($remaining === 0) {
            do_action('bcc_trust_quest_signal_revoke', $voterId, 'first_vote');
        }
    }
});

/*
|--------------------------------------------------------------------------
| CRON
|--------------------------------------------------------------------------
*/

// Cron interval registration — registered at plugin-load time so custom
// intervals are available during activation AND at cron run time.
add_filter('cron_schedules', [\BCC\Trust\Core\Services\CronService::class, 'addCronIntervals']);
add_filter('cron_schedules', [\BCC\Trust\Core\Services\PageReadModelSync::class, 'registerIntervals']);

add_action('bcc_trust_daily_cleanup', function () {
    \BCC\Trust\Core\Plugin::instance()->cronService()->dailyCleanup();
});
add_action('bcc_trust_hourly_recalc', function () {
    \BCC\Trust\Core\Plugin::instance()->cronService()->hourlyRecalc();
});
add_action('bcc_trust_daily_ml_update', function () {
    \BCC\Trust\Core\Plugin::instance()->cronService()->dailyFraudRefresh();
});
add_action('bcc_trust_daily_graph_update', function () {
    \BCC\Trust\Core\Plugin::instance()->cronService()->dailyGraphUpdate();
});
add_action('bcc_trust_daily_vesting', function () {
    \BCC\Trust\Core\Plugin::instance()->cronService()->dailyVesting();
});
add_action('bcc_trust_process_recalculations', function () {
    \BCC\Trust\Core\Plugin::instance()->cronService()->processRecalculations();
});

function bcc_trust_schedule_cron_jobs() {
    \BCC\Trust\Core\Services\CronService::scheduleAll();
}

add_action('admin_init', function () {
    \BCC\Trust\Core\Services\CronService::maybeReschedule();
});

if (is_admin()) {
    add_action('admin_notices', [\BCC\Trust\Core\Services\CronService::class, 'systemRequirementsNotice']);
}

\BCC\Trust\Core\Services\CronService::registerCacheInvalidation();

/*
|--------------------------------------------------------------------------
| SERVICELOCATOR FILTERS (bcc-core cross-plugin contracts)
|--------------------------------------------------------------------------
*/

add_filter('bcc.resolve.dispute_adjudication', function ($service = null) {
    if ($service instanceof \BCC\Core\Contracts\DisputeAdjudicationInterface) {
        return $service;
    }
    return \BCC\Trust\Core\Plugin::instance()->disputeAdjudicationService();
});

add_filter('bcc.resolve.trust_read_service', function ($service = null) {
    if ($service instanceof \BCC\Core\Contracts\TrustReadServiceInterface) {
        return $service;
    }
    return \BCC\Trust\Core\Plugin::instance()->trustReadService();
});

add_filter('bcc.resolve.score_contributor', function ($service = null) {
    if ($service instanceof \BCC\Core\Contracts\ScoreContributorInterface) {
        return $service;
    }
    return \BCC\Trust\Core\Plugin::instance()->scoreContributorService();
});

add_filter('bcc.resolve.score_read_service', function ($service = null) {
    if ($service instanceof \BCC\Core\Contracts\ScoreReadServiceInterface) {
        return $service;
    }
    return \BCC\Trust\Core\Plugin::instance()->scoreReadService();
});

add_filter('bcc.resolve.trust_header_data', function ($service = null) {
    if ($service instanceof \BCC\Core\Contracts\TrustHeaderDataInterface) {
        return $service;
    }
    return \BCC\Trust\Core\Plugin::instance()->peepSoIntegration();
});

add_filter('bcc.resolve.page_owner_resolver', function ($service = null) {
    if ($service instanceof \BCC\Core\Contracts\PageOwnerResolverInterface) {
        return $service;
    }
    return \BCC\Trust\Core\Plugin::instance()->pageOwnerResolver();
});

add_filter('bcc.resolve.wallet_verification_read', function ($service = null) {
    if ($service instanceof \BCC\Core\Contracts\WalletVerificationReadInterface) {
        return $service;
    }
    return new \BCC\Trust\Core\Application\WalletVerificationReadService();
});

add_filter('bcc.resolve.trending_data', function ($service = null) {
    if ($service instanceof \BCC\Core\Contracts\TrendingDataInterface) {
        return $service;
    }
    return new \BCC\Trust\Core\Application\TrendingDataService();
});

/*
|--------------------------------------------------------------------------
| PRE-WARM SERVICELOCATOR CACHE (before ServiceLocator::freeze)
|--------------------------------------------------------------------------
*/

\BCC\Core\ServiceLocator::resolveDisputeAdjudication();
\BCC\Core\ServiceLocator::resolveTrustReadService();
\BCC\Core\ServiceLocator::resolveScoreContributor();
\BCC\Core\ServiceLocator::resolveScoreReadService();
\BCC\Core\ServiceLocator::resolveTrustHeaderData();
\BCC\Core\ServiceLocator::resolvePageOwnerResolver();
\BCC\Core\ServiceLocator::resolveWalletVerificationRead();
\BCC\Core\ServiceLocator::resolveTrendingData();

/*
|--------------------------------------------------------------------------
| QUEST SYSTEM HOOKS
|--------------------------------------------------------------------------
*/

add_action('init', function () {
    $questService = \BCC\Trust\Core\Plugin::instance()->questProgressService();

    add_action('bcc_trust_quest_signal', [$questService, 'onQuestSignal'], 10, 2);

    add_action('bcc_trust_quest_signal_revoke', function (int $userId, string $slug) use ($questService) {
        if ($userId > 0) {
            $questService->revoke($userId, $slug);
        }
    }, 10, 2);

    add_action('bcc_trust_vote_cast', [$questService, 'onVoteCast'], 20, 3);
}, 20);

/*
|--------------------------------------------------------------------------
| DOMAIN EVENTS (cross-plugin write operations via actions)
|--------------------------------------------------------------------------
*/

add_action('bcc.trust.recalculate_score', function (int $pageId) {
    try {
        \BCC\Trust\Core\Plugin::instance()->voteService()->recalculateScore($pageId);
    } catch (\Exception $e) {
        \BCC\Core\Log\Logger::info('[bcc-trust] Score recalculation failed', [
            'page_id' => $pageId,
            'error'   => $e->getMessage(),
        ]);
    }
});

// Admin report penalty — fired by the disputes admin panel.
add_action('bcc.trust.admin_report_penalty', function (int $userId, int $points, string $reason): void {
    try {
        \BCC\Trust\Core\Plugin::instance()->reputationRepository()->adjustScore(
            $userId,
            -1 * abs($points),
            'admin_report_penalty'
        );
    } catch (\Throwable $e) {
        \BCC\Core\Log\Logger::error('[bcc-trust] admin_report_penalty_failed', [
            'user_id' => $userId,
            'points'  => $points,
            'reason'  => $reason,
            'error'   => $e->getMessage(),
        ]);
    }
}, 10, 3);

/*
|--------------------------------------------------------------------------
| ROUTE REGISTRATION (consolidated via Plugin container)
|--------------------------------------------------------------------------
*/

add_action('rest_api_init', function () {
    \BCC\Trust\Core\Plugin::instance()->registerRoutes();
});

/*
|--------------------------------------------------------------------------
| EMAIL VERIFICATION LINK HANDLER
|--------------------------------------------------------------------------
*/

add_action('template_redirect', function () {
    if (
        !isset($_GET['bcc_action'])
        || $_GET['bcc_action'] !== 'verify'
        || !isset($_GET['token'])
    ) {
        return;
    }

    $token  = sanitize_text_field(wp_unslash($_GET['token']));
    $userId = get_current_user_id();

    if (!$userId) {
        wp_safe_redirect(wp_login_url(esc_url_raw($_SERVER['REQUEST_URI'] ?? '')));
        exit;
    }

    try {
        $verificationService = \BCC\Trust\Core\Plugin::instance()->verificationService();
        $verificationService->verifyEmail($userId, $token);
        wp_safe_redirect(add_query_arg('bcc_verified', '1', home_url('/')));
    } catch (\Exception $e) {
        wp_safe_redirect(add_query_arg('bcc_verify_error', '1', home_url('/')));
    }
    exit;
});

/*
|--------------------------------------------------------------------------
| ASYNC JOB WIRING (vote fraud, trust graph, reputation, stats, edges,
| endorsement fraud, wallet verification)
|--------------------------------------------------------------------------
*/

add_action('plugins_loaded', function () {
    \BCC\Trust\Core\Plugin::instance()->registerAsyncJobs();
}, 5);

/*
|--------------------------------------------------------------------------
| FRONTEND
|--------------------------------------------------------------------------
*/

require_once BCC_TRUST_PATH . 'includes/enqueue.php';

\BCC\Trust\Core\Plugin::instance()->peepSoIntegration()->register();

add_shortcode('bcc_landing_page', function () {
    ob_start();
    include BCC_TRUST_PATH . 'templates/landing-page.php';
    return ob_get_clean();
});

/*
|--------------------------------------------------------------------------
| GUTENBERG BLOCKS
|--------------------------------------------------------------------------
*/

require_once BCC_TRUST_PATH . 'includes/blocks.php';

/*
|--------------------------------------------------------------------------
| ACTIVATION / DEACTIVATION
|--------------------------------------------------------------------------
*/

register_activation_hook(__FILE__, 'bcc_trust_activate');

function bcc_trust_activate() {
    if (!defined('BCC_ENCRYPTION_KEY') || !BCC_ENCRYPTION_KEY) {
        wp_die(
            '<h1>BCC Trust — activation blocked</h1>'
            . '<p><strong>BCC_ENCRYPTION_KEY is not defined in wp-config.php.</strong></p>'
            . '<p>This plugin will not activate without an encryption key. Without it, the trust engine boots in a disabled state and every non-admin user receives 403 on vote, endorse, dispute, and onchain API calls — with no user-facing error.</p>'
            . '<p>Add this line to <code>wp-config.php</code> above the <code>/* That\'s all, stop editing! */</code> comment, then re-activate:</p>'
            . '<pre style="background:#f5f5f5;padding:12px;border-left:4px solid #dc3232;">define(\'BCC_ENCRYPTION_KEY\', \'' . wp_generate_password(64, true, true) . '\');</pre>'
            . '<p><a href="' . esc_url(admin_url('plugins.php')) . '">&larr; Back to plugins</a></p>',
            'BCC Trust — BCC_ENCRYPTION_KEY required',
            ['response' => 500, 'back_link' => false]
        );
    }

    if (function_exists('bcc_trust_create_tables')) {
        bcc_trust_create_tables();

        if (function_exists('bcc_trust_verify_all_tables')) {
            $missing = bcc_trust_verify_all_tables();
            update_option('bcc_trust_activation_issues', $missing, false);
        }
    }

    if (function_exists('bcc_trust_schedule_cron_jobs')) {
        bcc_trust_schedule_cron_jobs();
    }

    if (!wp_next_scheduled('bcc_trust_initial_user_sync')) {
        wp_schedule_single_event(time() + 60, 'bcc_trust_initial_user_sync');
    }

    if (!wp_next_scheduled('bcc_trust_initial_read_model_sync')) {
        wp_schedule_single_event(time() + 120, 'bcc_trust_initial_read_model_sync');
    }

    flush_rewrite_rules();

    update_option('bcc_trust_activated', time(), false);
}

register_deactivation_hook(__FILE__, 'bcc_trust_deactivate');

function bcc_trust_deactivate() {
    $cron_hooks = [
        'bcc_trust_daily_cleanup',
        'bcc_trust_hourly_recalc',
        'bcc_trust_daily_ml_update',
        'bcc_trust_daily_graph_update',
        'bcc_trust_daily_vesting',
        'bcc_trust_process_recalculations',
        'bcc_trust_initial_user_sync',
        'bcc_trust_initial_read_model_sync',
        'bcc_trust_daily_maintenance',
        'bcc_trust_deferred_rm_sync',
    ];

    foreach ($cron_hooks as $hook) {
        wp_clear_scheduled_hook($hook);
    }

    flush_rewrite_rules();
}

add_action('bcc_trust_initial_user_sync', function (): void {
    \BCC\Trust\Core\Plugin::instance()->userSyncService()->sync();
});

/*
|--------------------------------------------------------------------------
| INITIALIZATION / ADMIN NOTICES / PLUGIN ACTION LINKS
|--------------------------------------------------------------------------
*/

add_action('init', function () {
    load_plugin_textdomain(
        'bcc-trust',
        false,
        dirname(plugin_basename(BCC_TRUST_FILE)) . '/languages'
    );
}, 1);

add_action('admin_notices', 'bcc_trust_admin_notices');

function bcc_trust_admin_notices() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (!class_exists('PeepSo')) {
        echo '<div class="notice notice-warning is-dismissible">
        <p><strong>⚠️ BCC Trust:</strong> PeepSo is not active. Some features will be limited.</p>
        </div>';
    }

    $missing_tables = get_option('bcc_trust_activation_issues', []);

    if (!empty($missing_tables)) {
        echo '<div class="notice notice-error">
        <p><strong>⚠️ BCC Trust: Missing database tables</strong></p>
        <p>' . esc_html(implode(', ', $missing_tables)) . '</p>
        </div>';
    }
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'bcc_trust_action_links');

function bcc_trust_action_links($links) {
    $plugin_links = [
        '<a href="' . admin_url('admin.php?page=bcc-trust-dashboard') . '">Dashboard</a>',
        '<a href="' . admin_url('admin.php?page=bcc-trust-moderation') . '">Moderation</a>',
    ];

    return array_merge($plugin_links, $links);
}

/*
|--------------------------------------------------------------------------
| ADMIN — only load in admin context
|--------------------------------------------------------------------------
*/

if (is_admin()) {
    $admin_files = [
        'dashboard.php',
        'dashboard-action.php',
        'moderation.php',
        'debug.php',
    ];

    foreach ($admin_files as $file) {
        $path = BCC_TRUST_PATH . 'includes/admin/' . $file;
        if (file_exists($path)) {
            require_once $path;
        }
    }
}