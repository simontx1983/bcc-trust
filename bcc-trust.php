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

define('BCC_TRUST_VERSION', '1.2.0');
define('BCC_TRUST_PATH', plugin_dir_path(__FILE__));
define('BCC_TRUST_URL', plugin_dir_url(__FILE__));
define('BCC_TRUST_FILE', __FILE__);

// Onchain signal-score caps (ageScore max=8, depthScore max=7,
// contractScore max=8*0.6=4.8 → total ~20). Total on-chain bonus per
// page is capped across all wallets and chains.
define('BCC_ONCHAIN_MAX_AGE_SCORE',       8);
define('BCC_ONCHAIN_MAX_DEPTH_SCORE',     7);
define('BCC_ONCHAIN_MAX_CONTRACT_SCORE',  4.8);
define('BCC_ONCHAIN_CACHE_HOURS',        24);
define('BCC_ONCHAIN_MAX_TOTAL_BONUS',    20);

// Disputes domain limits.
define('BCC_DISPUTES_PANEL_SIZE',          5);      // panelists per dispute
define('BCC_DISPUTES_TTL_DAYS',            7);      // auto-resolve after N days
define('BCC_DISPUTES_MAX_PER_PAGE',        3);      // max disputes per page per 30 days
define('BCC_DISPUTES_REPORTER_MAX_ACTIVE', 5);      // max active disputes per reporter
define('BCC_DISPUTES_MIN_REASON_LENGTH',   20);     // min chars for dispute reason
define('BCC_DISPUTES_MAX_REASON_LENGTH',   1000);   // max chars for dispute reason
define('BCC_DISPUTES_MIN_DETAIL_LENGTH',   10);     // min chars for admin-report detail
// Reconciliation retry ceiling. A failed adjudication is re-run by the
// reconcile cron up to this many times before the dispute is
// quarantined for manual operator attention.
if (!defined('BCC_DISPUTES_MAX_REOPEN_ATTEMPTS')) {
    define('BCC_DISPUTES_MAX_REOPEN_ATTEMPTS', 10);
}

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

// Onchain schema definitions — table-creation functions used by the
// activation hook and by the content-hash-gated dbDelta re-run below.
require_once BCC_TRUST_PATH . 'includes/database/schema-chains.php';
require_once BCC_TRUST_PATH . 'includes/database/schema-wallets.php';
require_once BCC_TRUST_PATH . 'includes/database/schema-validators.php';
require_once BCC_TRUST_PATH . 'includes/database/schema-collections.php';
require_once BCC_TRUST_PATH . 'includes/database/schema-claims.php';
require_once BCC_TRUST_PATH . 'includes/renderers/onchain-template-functions.php';

/**
 * Onchain table installer — called from activation and from the
 * content-hash schema-version hook when any schema-*.php file changes.
 */
function bcc_onchain_ensure_schema(): void {
    bcc_onchain_create_chains_table();
    bcc_onchain_create_wallet_links_table();
    bcc_onchain_create_validators_table();
    bcc_onchain_create_collections_table();
    bcc_onchain_create_claims_table();

    // Signals table is owned by SignalRepository — included here so its
    // column-type migrations run on version bump, not just on fresh
    // activation.
    if (class_exists('\\BCC\\Trust\\Onchain\\Repositories\\SignalRepository')) {
        \BCC\Trust\Onchain\Repositories\SignalRepository::install_own_table();
    }
}

// Schema migration: re-run dbDelta when any schema file changes.
// Covers Core + Onchain + Disputes schemas (the hash is derived from
// every schema-*.php file, so all domains are re-installed on any edit).
add_action('plugins_loaded', function (): void {
    $stored = get_option('bcc_trust_schema_version', '');
    if ($stored !== BCC_TRUST_SCHEMA_VERSION) {
        if (function_exists('bcc_trust_create_tables')) {
            bcc_trust_create_tables();
        }
        bcc_onchain_ensure_schema();
        \BCC\Trust\Disputes\Repositories\DisputeRepository::install();
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
add_filter('cron_schedules', [\BCC\Trust\Onchain\Services\ChainRefreshService::class, 'add_cron_intervals']);
add_filter('cron_schedules', [\BCC\Trust\Disputes\Services\DisputeScheduler::class, 'registerIntervals']);

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
    return \BCC\Trust\Core\Plugin::instance()->disputeAdjudicator();
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

// Onchain service providers — registered at top level so the
// ServiceLocator pre-warm below picks them up before freeze.
add_filter('bcc.resolve.wallet_link_read', function ($service = null) {
    if ($service instanceof \BCC\Core\Contracts\WalletLinkReadInterface) {
        return $service;
    }
    return new \BCC\Trust\Onchain\Services\WalletLinkReadService();
});

add_filter('bcc.resolve.wallet_link_write', function ($service = null) {
    if ($service instanceof \BCC\Core\Contracts\WalletLinkWriteInterface) {
        return $service;
    }
    return new \BCC\Trust\Onchain\Services\WalletLinkWriteService();
});

add_filter('bcc.resolve.wallet_signal_write', function ($service = null) {
    if ($service instanceof \BCC\Core\Contracts\WalletSignalWriteInterface) {
        return $service;
    }
    return new \BCC\Trust\Onchain\Services\WalletSignalWriteService();
});

add_filter('bcc.resolve.onchain_data_read', function ($service = null) {
    if ($service instanceof \BCC\Core\Contracts\OnchainDataReadInterface) {
        return $service;
    }
    return new \BCC\Trust\Onchain\Services\OnchainDataReadService();
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
\BCC\Core\ServiceLocator::resolveWalletLinkRead();
\BCC\Core\ServiceLocator::resolveWalletLinkWrite();
\BCC\Core\ServiceLocator::resolveWalletSignalWrite();
\BCC\Core\ServiceLocator::resolveOnchainDataRead();

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
| ONCHAIN DOMAIN BOOT (plugins_loaded — bcc-core deps guaranteed)
|--------------------------------------------------------------------------
| Mirrors the original bcc-onchain-signals.php plugins_loaded hook: runs
| at priority 20 so bcc-core and Trust Core services (priority 5) have
| already registered. Wires chain-refresh + wallet controller init,
| cron handlers, domain events, REST routes, admin triggers/settings,
| Gutenberg block registration, and the onchain-signals shortcode.
*/

add_action('plugins_loaded', function (): void {
    if (!class_exists('BCC\\Core\\PeepSo\\PeepSo') || !class_exists('BCC\\Core\\DB\\DB')) {
        add_action('admin_notices', function () {
            printf(
                '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
                esc_html('BCC Trust (Onchain)'),
                esc_html('requires the BCC Core plugin to be installed and active.')
            );
        });
        return;
    }

    \BCC\Trust\Onchain\Services\ChainRefreshService::init();
    \BCC\Trust\Onchain\Controllers\WalletController::init();

    // Cron hooks.
    add_action('bcc_onchain_daily_refresh', [\BCC\Trust\Onchain\Services\SignalRefreshService::class, 'dailyRefresh']);
    add_action('bcc_onchain_refresh_batch', [\BCC\Trust\Onchain\Services\SignalRefreshService::class, 'processBatch']);
    add_action('bcc_onchain_refresh_page',  [\BCC\Trust\Onchain\Services\SignalRefreshService::class, 'refreshPage']);
    add_action('bcc_onchain_retry_bonus',   [\BCC\Trust\Onchain\Services\BonusRetryService::class,    'processAll']);

    // Prune quarantined bonus entries (>14 days or >100 rows) on daily refresh.
    add_action('bcc_onchain_daily_refresh', [\BCC\Trust\Onchain\Services\BonusRetryService::class, 'pruneQuarantine'], 20);

    // Domain event hooks.
    add_action('bcc_onchain_claim_verified', [\BCC\Trust\Onchain\Services\BonusService::class, 'applyClaimBonus'], 10, 4);

    // Schedule wallet seed as async cron event — external API calls must
    // not block the wallet-verify AJAX response (10s+ timeout per chain).
    add_action('bcc_wallet_verified', function (int $userId, string $chain, string $address): void {
        \BCC\Core\Cron\AsyncDispatcher::enqueueAsync(
            'bcc_onchain_seed_wallet',
            [$userId, $chain, $address],
            'bcc-onchain'
        );
    }, 10, 3);

    add_action('bcc_onchain_seed_wallet', function (int $userId, string $chain, string $address): void {
        try {
            \BCC\Trust\Onchain\Services\WalletSeedService::onWalletVerified($userId, $chain, $address);
        } catch (\Throwable $e) {
            if (class_exists('BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::warning('[bcc-trust] wallet seed failed, will retry on cron', [
                    'user_id' => $userId, 'chain' => $chain, 'error' => $e->getMessage(),
                ]);
            }
        }
    }, 10, 3);

    // Wallet disconnect: revoke claims + recalc bonus.
    add_action('bcc_wallet_disconnected', function (int $userId, string $chainSlug, string $walletAddress): void {
        try {
            \BCC\Trust\Onchain\Services\BonusService::handleWalletDisconnect($userId, $chainSlug, $walletAddress);
        } catch (\Throwable $e) {
            if (class_exists('BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::warning('[bcc-trust] claim revocation failed on disconnect', [
                    'user_id' => $userId, 'error' => $e->getMessage(),
                ]);
            }
        }
    }, 10, 3);

    // User deletion: clean up wallet links, signals, and claims.
    add_action('delete_user', function (int $userId): void {
        \BCC\Trust\Onchain\Repositories\WalletRepository::deleteForUser($userId);
        \BCC\Trust\Onchain\Repositories\SignalRepository::deleteForUser($userId);
        \BCC\Trust\Onchain\Repositories\ClaimRepository::deleteForUser($userId);
    }, 10, 1);

    // REST API.
    add_action('rest_api_init', [\BCC\Trust\Onchain\Controllers\SignalController::class, 'registerRoutes']);
    add_action('rest_api_init', [\BCC\Trust\Onchain\Controllers\CollectionController::class, 'registerRoutes']);

    // Manual cron triggers (admin only, CSRF-protected).
    add_action('admin_init', function () {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!empty($_GET['bcc_run_index_validators']) || !empty($_GET['bcc_run_index_collections'])
            || !empty($_GET['bcc_run_enrich_validators']) || !empty($_GET['bcc_run_index_all'])) {
            check_admin_referer('bcc_onchain_admin_trigger');
        }

        $ran = [];

        if (!empty($_GET['bcc_run_index_validators']) || !empty($_GET['bcc_run_index_all'])) {
            \BCC\Trust\Onchain\Services\ChainRefreshService::index_validators();
            $ran[] = 'validators';
        }

        if (!empty($_GET['bcc_run_index_collections']) || !empty($_GET['bcc_run_index_all'])) {
            \BCC\Trust\Onchain\Services\ChainRefreshService::index_collections();
            $ran[] = 'collections';
        }

        if (!empty($_GET['bcc_run_enrich_validators']) || !empty($_GET['bcc_run_index_all'])) {
            \BCC\Trust\Onchain\Services\ChainRefreshService::refresh_validators();
            $ran[] = 'validator enrichment';
        }

        if (!empty($ran)) {
            $label = implode(' + ', $ran);
            $enrichStats = get_option('bcc_onchain_enrichment_stats', []);
            add_action('admin_notices', function () use ($label, $enrichStats) {
                echo '<div class="notice notice-success is-dismissible"><p><strong>BCC On-Chain:</strong> Indexing complete (' . esc_html($label) . ').</p>';
                if (!empty($enrichStats)) {
                    printf(
                        '<p>Enrichment: %d processed, %d failed, %d skipped, %d API calls. Stop: %s</p>',
                        (int) ($enrichStats['processed'] ?? 0),
                        (int) ($enrichStats['failed'] ?? 0),
                        (int) ($enrichStats['skipped'] ?? 0),
                        (int) ($enrichStats['api_calls'] ?? 0),
                        esc_html($enrichStats['stopped_reason'] ?? '—')
                    );
                }
                echo '</div>';
            });
        }
    });

    // Admin settings pages.
    add_action('admin_menu', function () {
        \BCC\Trust\Onchain\Admin\SettingsPage::register_page();
        \BCC\Trust\Onchain\Admin\ChainsPage::register_page();
    }, 20);
    \BCC\Trust\Onchain\Admin\ChainsPage::register_ajax();

    add_action('admin_enqueue_scripts', function ($hook) {
        if (strpos($hook, 'bcc-onchain') !== false) {
            wp_enqueue_script('bcc-onchain-admin', BCC_TRUST_URL . 'assets/js/bcc-onchain-admin.js', [], BCC_TRUST_VERSION, true);
            wp_localize_script('bcc-onchain-admin', 'bccOnchain', [
                'restUrl' => esc_url_raw(rest_url('bcc/v1/onchain')),
                'nonce'   => wp_create_nonce('wp_rest'),
            ]);
            wp_enqueue_style('bcc-onchain-admin', BCC_TRUST_URL . 'assets/css/bcc-onchain.css', [], BCC_TRUST_VERSION);
        }
    });

    // Gutenberg block.
    add_filter('block_categories_all', function ($categories) {
        array_unshift($categories, [
            'slug'  => 'bcc-onchain',
            'title' => 'BCC On-Chain',
            'icon'  => 'networking',
        ]);
        return $categories;
    });

    add_action('init', function () {
        if (function_exists('register_block_type')) {
            register_block_type(BCC_TRUST_PATH . 'blocks/onchain-signals');
        }
    });

    // Shortcode.
    add_shortcode('bcc_onchain_signals', function ($atts) {
        $atts    = shortcode_atts(['page_id' => 0], $atts, 'bcc_onchain_signals');
        $page_id = (int) $atts['page_id'] ?: get_the_ID();
        if (!$page_id) return '';

        $signals = \BCC\Trust\Onchain\Repositories\SignalRepository::get_for_page($page_id);

        ob_start();
        include BCC_TRUST_PATH . 'templates/signals-widget.php';
        return ob_get_clean();
    });
}, 20);

/*
|--------------------------------------------------------------------------
| DISPUTES DOMAIN BOOT
|--------------------------------------------------------------------------
| Scheduler, admin, REST, blocks, shortcodes, and the PeepSo profile
| report-button injection live here. Mirrors the original
| bcc-disputes.php hook layout, namespace-rewritten for BCC\Trust\Disputes.
*/

add_action('plugins_loaded', function () {
    if (is_admin()) {
        \BCC\Trust\Disputes\Admin\DisputeAdmin::boot();
    }
}, 15);

add_action('init', function () {
    \BCC\Trust\Disputes\Services\DisputeScheduler::boot();
    \BCC\Trust\Disputes\Services\DisputeNotificationService::registerAsyncHandlers();
});

// User deletion: clean up disputes, panel assignments, and reports.
add_action('delete_user', function (int $userId): void {
    $result = \BCC\Trust\Disputes\Repositories\DisputeRepository::cleanupForDeletedUser($userId);

    if (!$result['committed']) {
        return;
    }

    foreach ($result['affected_dispute_ids'] as $disputeId) {
        \BCC\Trust\Disputes\Repositories\DisputeRepository::invalidateDispute((int) $disputeId);
    }
    wp_cache_delete('report_status_counts', 'bcc_disputes');
}, 10, 1);

// REST routes.
add_action('rest_api_init', function () {
    (new \BCC\Trust\Disputes\Controllers\DisputeController())->register_routes();
});

// Frontend enqueue — only on pages with the disputes shortcodes or on
// PeepSo profile pages (where the Report User button is injected).
add_action('wp_enqueue_scripts', function () {
    $should_enqueue = false;

    $post = get_post();
    if ($post instanceof WP_Post) {
        $content = $post->post_content;
        if (has_shortcode($content, 'bcc_dispute_form')
            || has_shortcode($content, 'bcc_dispute_queue')
            || has_shortcode($content, 'bcc_report_button')
        ) {
            $should_enqueue = true;
        }
    }

    if (!$should_enqueue && function_exists('PeepSo') && class_exists('PeepSoProfileShortcode')) {
        $profile_page = PeepSo::get_option('page_profile');
        if ($profile_page && (int) $profile_page === (int) get_the_ID()) {
            $should_enqueue = true;
        }
    }

    if (!$should_enqueue) {
        return;
    }

    wp_enqueue_style('bcc-disputes', BCC_TRUST_URL . 'assets/css/bcc-disputes.css', [], BCC_TRUST_VERSION);
    wp_enqueue_script('bcc-disputes', BCC_TRUST_URL . 'assets/js/bcc-disputes.js', [], BCC_TRUST_VERSION, true);
    wp_localize_script('bcc-disputes', 'bccDisputes', [
        'restUrl'         => esc_url_raw(rest_url('bcc/v1/disputes')),
        'reportUserUrl'   => esc_url_raw(rest_url('bcc/v1/report-user')),
        'nonce'           => wp_create_nonce('wp_rest'),
        'minReasonLength' => BCC_DISPUTES_MIN_REASON_LENGTH,
        'maxReasonLength' => BCC_DISPUTES_MAX_REASON_LENGTH,
        'minDetailLength' => BCC_DISPUTES_MIN_DETAIL_LENGTH,
    ]);
});

// PeepSo profile: inject the Report User button.
add_action('peepso_user_profile_after_buttons', function ($user) {
    if (!is_user_logged_in()) return;
    $profile_uid  = isset($user->id) ? (int) $user->id : (int) ($user->ID ?? 0);
    $current_uid  = get_current_user_id();
    if (!$profile_uid || $profile_uid === $current_uid) return;
    $ud = isset($user->display_name) ? $user : get_userdata($profile_uid);
    $display_name = $ud ? ($ud->display_name ?? '') : '';
    if (!$display_name) return;
    printf(
        '<button class="bcc-report-user-btn" data-user-id="%d" data-user-name="%s">&#9873; %s</button>',
        $profile_uid,
        esc_attr($display_name),
        esc_html__('Report User', 'bcc-disputes')
    );
});

// Disputes shortcodes.
add_shortcode('bcc_report_button', function ($atts) {
    if (!is_user_logged_in()) return '';

    $atts        = shortcode_atts(['user_id' => 0], $atts, 'bcc_report_button');
    $reported_id = (int) $atts['user_id'];
    if (!$reported_id || $reported_id === get_current_user_id()) return '';

    $user = get_userdata($reported_id);
    if (!$user) return '';

    return sprintf(
        '<button class="bcc-report-user-btn" data-user-id="%d" data-user-name="%s">&#9873; %s</button>',
        $reported_id,
        esc_attr($user->display_name),
        esc_html__('Report User', 'bcc-disputes')
    );
});

add_shortcode('bcc_dispute_form', function ($atts) {
    if (!is_user_logged_in()) {
        return '<p class="bcc-dispute-notice">' . esc_html__('Log in to manage disputes.', 'bcc-disputes') . '</p>';
    }

    $atts = shortcode_atts(['page_id' => 0], $atts, 'bcc_dispute_form');
    $attributes = ['pageId' => (int) $atts['page_id'] ?: get_the_ID()];
    if (!$attributes['pageId']) {
        return '';
    }

    ob_start();
    include BCC_TRUST_PATH . 'blocks/dispute-form/render.php';
    return ob_get_clean();
});

add_shortcode('bcc_dispute_queue', function () {
    if (!is_user_logged_in()) {
        return '';
    }

    $attributes = [];
    ob_start();
    include BCC_TRUST_PATH . 'blocks/dispute-queue/render.php';
    return ob_get_clean();
});

// Gutenberg block registration for disputes.
require_once BCC_TRUST_PATH . 'includes/disputes-blocks.php';

// Disputes admin notices: schema-migration failures + panelist-pool health.
add_action('admin_notices', function (): void {
    if (!current_user_can('manage_options')) {
        return;
    }

    $constraintErr = get_option('bcc_disputes_constraint_missing');
    if (!empty($constraintErr)) {
        printf(
            '<div class="notice notice-error"><p><strong>BCC Trust (Disputes):</strong> '
            . 'The DB-level unique constraint on active disputes (<code>uq_active_vote</code>) '
            . 'could not be installed. Concurrent-dispute-per-vote protection relies on the '
            . 'app-layer FOR UPDATE check only. Resolve duplicate reviewing rows, then run '
            . '<code>update_option(\'bcc_trust_schema_version\', \'\')</code> to retry. '
            . 'Error: <code>%s</code></p></div>',
            esc_html((string) $constraintErr)
        );
    }

    $adminNotifiedErr = get_option('bcc_disputes_admin_notified_missing');
    if (!empty($adminNotifiedErr)) {
        printf(
            '<div class="notice notice-error"><p><strong>BCC Trust (Disputes):</strong> '
            . 'The <code>admin_notified_at</code> column is missing on <code>bcc_user_reports</code>. '
            . 'Admin-report email idempotency is broken — duplicates may send on every retry. '
            . 'Error: <code>%s</code></p></div>',
            esc_html((string) $adminNotifiedErr)
        );
    }
});

add_action('admin_notices', function (): void {
    if (!current_user_can('manage_options')) {
        return;
    }

    $cache_key   = 'bcc_disputes_panelist_pool_count';
    $cache_group = 'bcc_disputes';
    $pool_count  = wp_cache_get($cache_key, $cache_group);

    if ($pool_count === false) {
        if (!class_exists('\\BCC\\Core\\ServiceLocator')
            || !\BCC\Core\ServiceLocator::hasRealService(\BCC\Core\Contracts\TrustReadServiceInterface::class)
        ) {
            return;
        }

        $trust_read  = \BCC\Core\ServiceLocator::resolveTrustReadService();
        $eligible    = $trust_read->getEligiblePanelistUserIds([], BCC_DISPUTES_PANEL_SIZE * 3);
        $pool_count  = is_array($eligible) ? count($eligible) : 0;
        wp_cache_set($cache_key, $pool_count, $cache_group, HOUR_IN_SECONDS);
    }

    $minimum_healthy = BCC_DISPUTES_PANEL_SIZE * 2;
    if ((int) $pool_count >= $minimum_healthy) {
        return;
    }

    $level = (int) $pool_count < BCC_DISPUTES_PANEL_SIZE ? 'error' : 'warning';
    printf(
        '<div class="notice notice-%s"><p><strong>BCC Trust (Disputes):</strong> '
        . 'The eligible panelist pool is critically low — only <strong>%d</strong> qualified members found. '
        . 'At least <strong>%d</strong> are needed per dispute (and %d recommended for proper randomization). '
        . 'Disputes cannot be filed until enough Trusted/Elite tier members with clean records are available.</p></div>',
        esc_attr($level),
        (int) $pool_count,
        BCC_DISPUTES_PANEL_SIZE,
        $minimum_healthy
    );
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

    // Onchain schema — signals + chains/wallets/validators/collections/claims.
    if (class_exists('\\BCC\\Trust\\Onchain\\Repositories\\SignalRepository')) {
        \BCC\Trust\Onchain\Repositories\SignalRepository::install_own_table();
    }
    bcc_onchain_ensure_schema();

    if (function_exists('bcc_trust_schedule_cron_jobs')) {
        bcc_trust_schedule_cron_jobs();
    }

    if (!wp_next_scheduled('bcc_trust_initial_user_sync')) {
        wp_schedule_single_event(time() + 60, 'bcc_trust_initial_user_sync');
    }

    if (!wp_next_scheduled('bcc_trust_initial_read_model_sync')) {
        wp_schedule_single_event(time() + 120, 'bcc_trust_initial_read_model_sync');
    }

    // Onchain cron events.
    if (!wp_next_scheduled('bcc_onchain_daily_refresh')) {
        wp_schedule_event(time(), 'daily', 'bcc_onchain_daily_refresh');
    }
    if (!wp_next_scheduled('bcc_onchain_retry_bonus')) {
        wp_schedule_event(time(), 'hourly', 'bcc_onchain_retry_bonus');
    }

    // Defensive: re-register custom intervals (top-level add_filter above
    // should already have done this; WP dedupes by callable signature).
    add_filter('cron_schedules', [\BCC\Trust\Onchain\Services\ChainRefreshService::class, 'add_cron_intervals']);
    \BCC\Trust\Onchain\Services\ChainRefreshService::schedule_crons();

    // Disputes: install schema + schedule reconcile cron.
    add_filter('cron_schedules', [\BCC\Trust\Disputes\Services\DisputeScheduler::class, 'registerIntervals']);
    \BCC\Trust\Disputes\Repositories\DisputeRepository::install();
    \BCC\Trust\Disputes\Services\DisputeScheduler::schedule();

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
        // Onchain cron events.
        'bcc_onchain_daily_refresh',
        'bcc_onchain_retry_bonus',
    ];

    foreach ($cron_hooks as $hook) {
        wp_clear_scheduled_hook($hook);
    }

    // Clear chain-refresh + enrichment crons owned by ChainRefreshService.
    if (class_exists('\\BCC\\Trust\\Onchain\\Services\\ChainRefreshService')) {
        \BCC\Trust\Onchain\Services\ChainRefreshService::deactivate();
    }

    // Clear disputes scheduler cron (auto-resolve + reconcile-orphans).
    if (class_exists('\\BCC\\Trust\\Disputes\\Services\\DisputeScheduler')) {
        \BCC\Trust\Disputes\Services\DisputeScheduler::unschedule();
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

// Onchain stale data + cron health warning.
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) {
        return;
    }

    $notices = [];

    if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
        $nextRefresh = wp_next_scheduled('bcc_onchain_daily_refresh');
        if ($nextRefresh && $nextRefresh < (time() - DAY_IN_SECONDS)) {
            $notices[] = sprintf(
                'DISABLE_WP_CRON is enabled but the daily signal refresh has not fired in over 24 hours. '
                . 'Configure a system cron: <code>*/5 * * * * curl -s %s >/dev/null 2>&1</code>',
                esc_html(site_url('/wp-cron.php?doing_wp_cron'))
            );
        }
    }

    if (class_exists('\\BCC\\Trust\\Onchain\\Repositories\\ChainRepository')
        && class_exists('\\BCC\\Trust\\Onchain\\Support\\CircuitBreaker')
    ) {
        $activeChains = \BCC\Trust\Onchain\Repositories\ChainRepository::getActive();
        $chainIds     = array_map(fn($c) => (int) $c->id, $activeChains);
        $chainNames   = [];
        foreach ($activeChains as $c) {
            $chainNames[(int) $c->id] = $c->name;
        }

        $staleChains = \BCC\Trust\Onchain\Support\CircuitBreaker::getStaleChains($chainIds);

        if (!empty($staleChains)) {
            $parts = [];
            foreach ($staleChains as $id => $info) {
                $name   = $chainNames[$id] ?? "Chain #{$id}";
                $detail = esc_html($info['age_human']);
                if ($info['circuit_status'] !== 'CLOSED') {
                    $detail .= ', circuit: ' . esc_html($info['circuit_status']);
                }
                $parts[] = sprintf('%s (%s)', esc_html($name), $detail);
            }
            $notices[] = 'Chain data is stale for: ' . implode(', ', $parts)
                . '. Trust scores may be understated.';
        }
    }

    if (!empty($notices)) {
        echo '<div class="notice notice-warning"><p><strong>BCC Trust (Onchain):</strong> '
            . implode('</p><p><strong>BCC Trust (Onchain):</strong> ', $notices)
            . '</p></div>';
    }
});

// Missing onchain API key warnings — without these, signals silently
// return empty data and trust scores are systematically understated.
add_action('admin_notices', function () {
    $missing = [];
    if (!defined('BCC_ETHERSCAN_API_KEY') || BCC_ETHERSCAN_API_KEY === '') {
        $missing[] = 'BCC_ETHERSCAN_API_KEY';
    }
    if (!defined('BCC_ALCHEMY_API_KEY') || BCC_ALCHEMY_API_KEY === '') {
        $missing[] = 'BCC_ALCHEMY_API_KEY';
    }
    if (!empty($missing)) {
        printf(
            '<div class="notice notice-error"><p><strong>BCC Trust (Onchain):</strong> Missing API keys in wp-config.php: <code>%s</code>. On-chain trust signals are disabled until these are configured.</p></div>',
            esc_html(implode('</code>, <code>', $missing))
        );
    }
});

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