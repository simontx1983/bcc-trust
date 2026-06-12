<?php
/**
 * Plugin Name: Blue Collar Crypto – Trust
 * Description: Unified reputation, dispute, and on-chain signal plugin. Merges bcc-trust-engine, bcc-disputes, and bcc-onchain-signals into a single bounded-context codebase.
 * Version: 1.2.14
 * Author: Blue Collar Labs LLC
 * Text Domain: bcc-trust
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 8.1
 * GitHub Plugin URI: https://github.com/simontx1983/bcc-trust
 * Primary Branch: main
 */

if (!defined('ABSPATH')) {
    exit;
}

/*
|--------------------------------------------------------------------------
| CONSTANTS
|--------------------------------------------------------------------------
*/

define('BCC_TRUST_VERSION', '1.2.14');
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
 *
 * Computed live on every request via md5_file() of each schema file.
 * Reads ~30 small files (<10KB each) from disk; warm OS-cache cost is
 * ~1ms total — negligible vs the cost of the alternative (an mtime+size
 * signature cache stored in `bcc_trust_schema_version_cache`), which
 * we ran in 2026-05 and which silently mis-fired during deploys where
 * mtime stayed stable (rsync --times, atomic symlink swap, fast file
 * replacement). The stale-signature path left `ChainRepository::COLUMNS`
 * referencing a column the migration hadn't yet added, poisoning the
 * chains cache with an ERROR_SENTINEL. Live-computation removes that
 * failure class entirely. Per-deploy md5 thrash is ~1ms; we keep it.
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

// Best-effort cleanup of the old version-cache option. Harmless to call
// when the option doesn't exist; eventually-removed once every site has
// upgraded past the 2026-05 PR-B deploy that retired the cache.
add_action('plugins_loaded', static function (): void {
    if (get_option('bcc_trust_schema_version_cache') !== false) {
        delete_option('bcc_trust_schema_version_cache');
    }
}, 4); // priority 4 — strictly before the schema migration at priority 5

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
require_once BCC_TRUST_PATH . 'includes/database/schema-delegations.php';
require_once BCC_TRUST_PATH . 'includes/database/schema-collections.php';
require_once BCC_TRUST_PATH . 'includes/database/schema-nft-selections.php';
require_once BCC_TRUST_PATH . 'includes/database/schema-claims.php';
// V2 Phase 1a — confirmation-gated NFT indexer
require_once BCC_TRUST_PATH . 'includes/database/schema-nft-holdings.php';
require_once BCC_TRUST_PATH . 'includes/database/schema-collection-pieces.php';
require_once BCC_TRUST_PATH . 'includes/database/schema-chain-checkpoints.php';
require_once BCC_TRUST_PATH . 'includes/database/schema-nft-spam-contracts.php';
// V2 Phase 1b — Helius webhook replay protection (LRU)
require_once BCC_TRUST_PATH . 'includes/database/schema-helius-seen-signatures.php';
// V1.5 §D6 — crypto-blog composer chain-tag join + bcc_onchain_chains.color
require_once BCC_TRUST_PATH . 'includes/database/schema-blog-chain-tags.php';
require_once BCC_TRUST_PATH . 'includes/block-helpers.php';

/**
 * Onchain table installer — called from activation and from the
 * content-hash schema-version hook when any schema-*.php file changes.
 */
function bcc_onchain_ensure_schema(): void {
    bcc_onchain_create_chains_table();
    bcc_onchain_create_wallet_links_table();
    bcc_onchain_create_validators_table();
    bcc_onchain_create_delegations_table();
    bcc_onchain_create_collections_table();
    bcc_onchain_create_user_nft_selections_table();
    bcc_onchain_create_claims_table();
    // V2 Phase 1a NFT indexer
    bcc_onchain_create_nft_holdings_table();
    bcc_onchain_create_chain_checkpoints_table();
    bcc_onchain_create_nft_spam_contracts_table();
    // V2 Phase 1b Helius replay protection
    bcc_onchain_create_helius_seen_signatures_table();
    // V2 Phase 6 (§H1) NFT-piece detail metadata cache
    bcc_onchain_create_collection_pieces_table();
    // V1.5 §D6 crypto-blog composer — chains.color column + Bitcoin seed,
    // then the post↔chain join table. Order matters: the join table
    // references chain ids that the seed may have just inserted.
    bcc_blog_extend_chains_table();
    bcc_blog_create_chain_tags_table();

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
add_action('bcc_trust_weekly_digest', function () {
    \BCC\Trust\Core\Plugin::instance()->digestService()->sendWeeklyDigest();
});
// Scale-hardening Phase 3 (2026-05-13): weekly slow-ring endorsement
// scan. Catches paced reciprocity patterns the burst gates miss.
add_action('bcc_trust_weekly_slow_ring_scan', function () {
    \BCC\Trust\Core\Plugin::instance()->cronService()->weeklySlowRingScan();
});

// V2: NFT-gated holder groups — daily provisioning sweep. Reads
// wp_bcc_onchain_collections.is_verified=1 and creates a closed PeepSo
// group for any verified collection that doesn't have one yet.
// Idempotent — re-running creates no duplicates.
add_action('bcc_gated_group_provision', function () {
    $result = \BCC\Trust\Core\Plugin::instance()
        ->gatedGroupProvisioningService()
        ->provisionAll();

    if ($result['created'] > 0 || !empty($result['errors'])) {
        \BCC\Core\Log\Logger::info('[bcc-trust] Holder-group provisioning sweep', $result);
    }
});

// V2 (PR 4): NFT-gated holder groups — reconcile sweep for users who
// opted into auto-join via `bcc_auto_join_eligible_groups` user_meta.
// Default users are NEVER touched here (suggest-don't-auto-join is the
// default).
//
// Bounded to 20 users per tick × twicedaily = 40 users/day. Each
// reconcile makes per-(wallet, contract) RPC calls via HoldingsService,
// which can take 20–30s on cold-start (transient cache empty). 20 ×
// 25s = 500s, well under typical cron worker runtimes. After the 24h
// holdings cache warms, throughput goes way up. If the active opt-in
// pool exceeds 40 users, see the `last_reconciled_at` rotation pattern
// (deferred follow-up) — the current ID-ASC ordering biases toward
// older accounts which is a reasonable v1 default.
// V2 Phase 1a: NFT ETH indexer tick. Walks confirmed Transfer events
// (N=12 confirmations) per chain and persists into wp_bcc_nft_holdings
// via NftHoldingsIndexer. The handler is intentionally thin — all
// behaviour lives in the worker class so it's testable in isolation.
add_action(
    \BCC\Trust\Onchain\Workers\NftEthIndexerWorker::CRON_HOOK,
    [\BCC\Trust\Onchain\Workers\NftEthIndexerWorker::class, 'runAllChains']
);

// V2 Phase 1a: contribute NFT-indexer + Helius-dedupe state to the
// bcc-core system-health endpoint. The filter is owned by bcc-core
// (apply_filters('bcc_system_health', ...)) and this is the
// canonical extension seam — do not invent a parallel /health/indexer.
add_filter(
    'bcc_system_health',
    [\BCC\Trust\Onchain\Services\NftIndexerHealthSnapshot::class, 'contribute']
);

// Operator OS v1 Phase 3: contribute the Read Model panel to
// bcc-core's DeveloperPage. Renders coverage / drift / dirty-queue
// state from ReadModelHealthRepository — no new domain logic.
add_filter('bcc_developer_panels', function (array $panels): array {
    $panels['bcc-trust:read-model'] = [
        'title' => 'Read Model (bcc-trust)',
        'sort'  => 10,
        'render' => function (): void {
            $repo = new \BCC\Trust\Core\Repositories\ReadModelHealthRepository();

            $published = $repo->countPublishedPages();
            $rmRows    = $repo->countReadModelRows();
            $pending   = $repo->countPendingRecalculations();
            $dirtyRows = $repo->getDirtyQueueSize();
            $lagSec    = $repo->getDirtyQueueLagSeconds();
            $drift     = $repo->countDriftedPages(100);
            $gaps      = $repo->countGapPages(1000);
            $oldest    = $repo->getOldestUpdate();
            $newest    = $repo->getNewestUpdate();

            $coverage  = $published > 0 ? round(($rmRows / $published) * 100, 1) : 0.0;

            echo '<table class="widefat striped" style="max-width:760px;"><tbody>';
            printf('<tr><th style="width:280px;">Published peepso-pages</th><td>%s</td></tr>',                esc_html(number_format($published)));
            printf('<tr><th>Read-model rows</th><td>%s &nbsp; <span style="color:#888;">(%s%% coverage)</span></td></tr>', esc_html(number_format($rmRows)), esc_html((string) $coverage));
            printf('<tr><th>Gap pages (scored, RM missing)</th><td>%s</td></tr>',                            esc_html(number_format($gaps)));
            printf('<tr><th>Drifted pages (RM vs live &gt; 1.0)</th><td>%s</td></tr>',                       esc_html(number_format($drift)));
            printf('<tr><th>Pending recalculations</th><td>%s</td></tr>',                                    esc_html(number_format($pending)));
            printf('<tr><th>Dirty queue (rows)</th><td>%s</td></tr>',                                        esc_html(number_format($dirtyRows)));
            printf('<tr><th>Dirty queue lag (seconds)</th><td>%s</td></tr>',                                 esc_html(number_format($lagSec)));
            printf('<tr><th>Oldest RM updated_at</th><td><code>%s</code></td></tr>',                          esc_html($oldest ?? '—'));
            printf('<tr><th>Newest RM updated_at</th><td><code>%s</code></td></tr>',                          esc_html($newest ?? '—'));
            echo '</tbody></table>';

            if ($drift > 0) {
                echo '<h3 style="margin-top:16px;">Top drift samples</h3>';
                $samples = $repo->getDriftSamples(5);
                if ($samples === []) {
                    echo '<p>(none returned)</p>';
                } else {
                    echo '<table class="widefat striped" style="max-width:760px;">';
                    echo '<thead><tr><th>Page ID</th><th style="text-align:right;">RM score</th><th style="text-align:right;">Live score</th><th style="text-align:right;">Drift</th><th>RM updated_at</th></tr></thead><tbody>';
                    foreach ($samples as $row) {
                        printf(
                            '<tr><td>%s</td><td style="text-align:right;">%s</td><td style="text-align:right;">%s</td><td style="text-align:right;font-weight:bold;color:#dc3232;">%s</td><td><code>%s</code></td></tr>',
                            esc_html((string) ($row->page_id   ?? '')),
                            esc_html((string) ($row->rm_score   ?? '')),
                            esc_html((string) ($row->live_score ?? '')),
                            esc_html((string) ($row->drift      ?? '')),
                            esc_html((string) ($row->rm_updated ?? '—'))
                        );
                    }
                    echo '</tbody></table>';
                }
            }
        },
    ];
    return $panels;
});

// Operator OS v1 Phase 2: contribute bcc-trust's secret/API-key
// inventory to bcc-core's ApiKeysPage. Never raw values; the page
// renders status + masked previews only. When introducing a new
// secret elsewhere in bcc-trust, add it here too so an operator
// can see its status without grepping the codebase.
add_filter('bcc_api_keys_inventory', function (array $inventory): array {
    $bccTrustKeys = [
        // Critical — missing locks out the affected subsystem entirely.
        'BCC_ENCRYPTION_KEY'        => ['severity' => 'critical',  'description' => 'Trust-engine secret-encryption key. Missing = all non-admin users locked out (NullTrustReadService).'],
        'BCC_HELIUS_WEBHOOK_SECRET' => ['severity' => 'critical',  'description' => 'Helius webhook authentication header. Missing = Solana ingestion silently dark.'],
        // Important — missing significantly degrades a subsystem.
        'BCC_ALCHEMY_API_KEY'       => ['severity' => 'important', 'description' => 'Alchemy EVM RPC + NFT-metadata API key. Missing = ETH NFT indexer + balance lookups degrade to fallback paths.'],
        'BCC_HELIUS_API_KEY'        => ['severity' => 'important', 'description' => 'Helius Solana enriched-RPC key. Missing = Solana enrichment falls back to public RPC rate limits.'],
        'BCC_INTERNAL_CRON_SECRET'  => ['severity' => 'important', 'description' => 'Internal shared challenge for the Vercel cron relay → IndexerTickEndpoint. Missing = remote-triggered cron rejected.'],
        'BCC_GITHUB_CLIENT_SECRET'  => ['severity' => 'important', 'description' => 'GitHub OAuth client secret. Missing = GitHub identity-verification flow broken.'],
        'BCC_PUSH_VAPID_PUBLIC_KEY' => ['severity' => 'important', 'description' => 'Web Push VAPID public key (browser-exposed). Triplet with VAPID_PRIVATE_KEY + _SUBJECT — all three required for push delivery.'],
        'BCC_PUSH_VAPID_PRIVATE_KEY'=> ['severity' => 'important', 'description' => 'Web Push VAPID private key. Rotating invalidates ALL existing browser subscriptions — rotate with intent.'],
        'BCC_PUSH_VAPID_SUBJECT'    => ['severity' => 'important', 'description' => 'Web Push VAPID subject (mailto: or site URL). Not secret, but required for push.'],
        // Optional — missing causes mild degradation only.
        'BCC_SUBSCAN_API_KEY'       => ['severity' => 'optional',  'description' => 'Subscan Polkadot API key. Missing = validator-info reads fall back to anonymous rate limits.'],
        'BCC_ETHERSCAN_API_KEY'     => ['severity' => 'optional',  'description' => 'Etherscan API key for validator score signal enrichment. Missing = mild degradation.'],
    ];
    foreach ($bccTrustKeys as $constant => $meta) {
        $inventory[$constant] = array_merge($meta, ['source' => 'bcc-trust']);
    }
    return $inventory;
});

// Operator OS v1 Phase 2: contribute bcc-trust's canonical cron hooks
// to bcc-core's CronPage drift detector. The hook list mirrors the
// recurring-hooks table in docs/cron-registry.md — keep in sync when
// adding/retiring a recurring hook. Dynamic hooks (per-chain
// bcc_chain_refresh_*) and single-event hooks intentionally excluded
// from drift detection (the former are registered at runtime, the
// latter are fire-once jobs whose "missing" state means "no recent
// queue activity," not "drift").
add_filter('bcc_expected_cron_hooks', function (array $hooks): array {
    $bccTrustHooks = [
        // Core domain
        'bcc_trust_daily_cleanup'         => ['interval' => 'daily',                'description' => 'audit-log retention + daily housekeeping'],
        'bcc_trust_hourly_recalc'         => ['interval' => 'hourly',               'description' => 'page-score recalculation sweep'],
        'bcc_trust_daily_ml_update'       => ['interval' => 'daily',                'description' => 'fraud-detection ML refresh'],
        'bcc_trust_daily_graph_update'    => ['interval' => 'daily',                'description' => 'trust-graph rank + vote/endorsement ring detection'],
        'bcc_trust_daily_vesting'         => ['interval' => 'daily',                'description' => 'vote-weight vesting promotion'],
        'bcc_trust_process_recalculations'=> ['interval' => 'bcc_five_minutes',     'description' => 'recalc queue worker'],
        'bcc_trust_daily_maintenance'     => ['interval' => 'daily',                'description' => 'read-model sync safety net'],
        'bcc_trust_weekly_digest'         => ['interval' => 'bcc_weekly',           'description' => 'weekly digest mailer'],
        'bcc_trust_deferred_rm_sync'      => ['interval' => 'bcc_thirty_seconds',   'description' => 'read-model deferred-rebuild for staleness recovery'],
        'bcc_trust_divergence_state_sweep'=> ['interval' => 'daily',                'description' => 'divergence-state classification + §J.7 notifications'],
        // Onchain domain
        'bcc_onchain_daily_refresh'       => ['interval' => 'daily',                'description' => 'onchain holdings refresh sweep'],
        'bcc_onchain_retry_bonus'         => ['interval' => 'hourly',               'description' => 'onchain bonus-application retry'],
        'bcc_gated_group_provision'       => ['interval' => 'daily',                'description' => 'holder-group provisioning (PeepSo write surface)'],
        'bcc_gated_group_reconcile_sweep' => ['interval' => 'twicedaily',           'description' => 'holder-group reconcile sweep'],
        'bcc_gated_group_revoke_sweep'    => ['interval' => 'twicedaily',           'description' => 'holder-group revoke re-verification sweep'],
        'bcc_nft_eth_indexer_tick'        => ['interval' => 'bcc_one_minute',       'description' => 'NFT EVM indexer per-chain tick'],
        'bcc_helius_dedupe_sweep'         => ['interval' => 'bcc_five_minutes',     'description' => 'Helius signature replay LRU eviction'],
        'bcc_nft_enrichment_tick'         => ['interval' => 'bcc_five_minutes',     'description' => 'NFT metadata backfill (name + image_url)'],
        'bcc_watch_batch_sweep'           => ['interval' => 'bcc_pull_batch_sweep_minute', 'description' => 'WatchBatchAggregator sweep'],
        // Disputes domain
        'bcc_disputes_auto_resolve'       => ['interval' => 'daily',                'description' => 'dispute auto-resolve sweep'],
        'bcc_disputes_reconcile'          => ['interval' => 'bcc_five_minutes',     'description' => 'dispute reconcile (covers cron + AS enqueue failures)'],
    ];
    foreach ($bccTrustHooks as $hook => $meta) {
        $hooks[$hook] = array_merge($meta, ['source' => 'bcc-trust']);
    }
    return $hooks;
});

// V2 Phase 1b: dedupe-sweep cron handler. Bounded operationally —
// see HeliusSeenSignaturesRepository for the cap + alarm rules.
add_action('bcc_helius_dedupe_sweep', static function (): void {
    $stats = \BCC\Trust\Onchain\Repositories\HeliusSeenSignaturesRepository::sweep();
    update_option('bcc_helius_dedupe_size', (int) $stats['remaining'], false);
});

// V2 Phase 1c: NFT metadata enrichment cron handler. Per-batch + per-
// chain tick walks rows where enriched_at IS NULL and backfills name,
// image_url, metadata_uri, collection_name via the per-chain fetcher's
// metadata API.
add_action(
    \BCC\Trust\Onchain\Services\NftEnrichmentService::CRON_HOOK,
    [\BCC\Trust\Onchain\Services\NftEnrichmentService::class, 'runAllChains']
);

// V2 Phase 1 hardening: self-heal cron registration.
//
// ┌─ DO NOT REMOVE AS "REDUNDANT WITH ACTIVATION" ─────────────────┐
// │ This block looks duplicative of the schedule calls inside      │
// │ `bcc_trust_activate()`. It is not. The duplication is the      │
// │ fix.                                                           │
// │                                                                │
// │ History: the V2 Phase 1 NFT cron hooks (eth indexer, helius    │
// │ dedupe, enrichment) were originally scheduled ONLY in the      │
// │ activation hook. Phase 1a + 1c each added a new cron hook      │
// │ without triggering a reactivation. Result: on every site that  │
// │ updated the plugin via composer/git/SFTP (not via the WP       │
// │ admin's deactivate-then-activate flow), the new hooks never    │
// │ got scheduled. The empty `wp_bcc_chain_checkpoints` table was  │
// │ the only outward signal. The worker silently never ticked.     │
// │                                                                │
// │ Self-heal closes that gap: any drift is corrected on the next  │
// │ request after deployment. Removing this block re-opens the     │
// │ exact same silent-failure mode.                                │
// │                                                                │
// │ Cost: three `wp_next_scheduled()` calls per request — each a   │
// │ lookup against the autoloaded `cron` option, no DB query. The  │
// │ rare cold path (when something is actually missing) hits       │
// │ `wp_schedule_event` once per missing hook.                     │
// └────────────────────────────────────────────────────────────────┘
//
// This path NEVER unschedules. Cleanup is owned by the plugin's
// deactivation hook (`bcc_trust_deactivate`).
add_action('plugins_loaded', static function (): void {
    \BCC\Trust\Onchain\Workers\NftEthIndexerWorker::register();
    \BCC\Trust\Onchain\Services\NftEnrichmentService::register();

    // Helius dedupe sweep has no host service class (its handler is the
    // inline closure above) so its schedule is inlined here. Same shape
    // as the two register() calls — guarded, additive, no clearing.
    if (!wp_next_scheduled('bcc_helius_dedupe_sweep')) {
        wp_schedule_event(time() + 60, 'bcc_five_minutes', 'bcc_helius_dedupe_sweep');
    }

    // PR-8b — divergence-state sweep self-heal. Mirrors activation-time
    // schedule so installs that updated without reactivation pick up
    // the cron. Hot path: one autoloaded-option lookup per request.
    if (!wp_next_scheduled('bcc_trust_divergence_state_sweep')) {
        wp_schedule_event(time() + 2 * HOUR_IN_SECONDS, 'daily', 'bcc_trust_divergence_state_sweep');
    }

    // Holder-group revoke (re-verification) sweep self-heal. This hook is
    // NEW — sites updated without a reactivation would otherwise never
    // schedule it (the exact silent-drift failure mode documented above
    // and in the V2 NFT cron-drift incident memory). Guarded + additive.
    if (!wp_next_scheduled(\BCC\Trust\Onchain\Services\NftGroupRevokeService::CRON_HOOK)) {
        wp_schedule_event(time() + 120 * MINUTE_IN_SECONDS, 'twicedaily', \BCC\Trust\Onchain\Services\NftGroupRevokeService::CRON_HOOK);
    }
}, 5);

// PR-8b — daily divergence-state sweep callback. Runs the worker;
// any per-target failure is contained inside `sweep()` (per the
// fire-and-forget posture documented in the service class). The
// summary is logged for cron-observability use.
add_action('bcc_trust_divergence_state_sweep', static function (): void {
    try {
        $summary = \BCC\Trust\Core\Plugin::instance()
            ->polarizationTransitionNotifier()
            ->sweep();
        \BCC\Core\Log\Logger::info('[bcc-trust] Divergence-state sweep complete', $summary);
    } catch (\Throwable $e) {
        // Belt-and-suspenders — sweep() itself swallows errors, but
        // a constructor/DI failure could escape. Log + drop.
        \BCC\Core\Log\Logger::warning('[bcc-trust] Divergence-state sweep callback failed', [
            'error' => $e->getMessage(),
        ]);
    }
});

add_action('bcc_gated_group_reconcile_sweep', function () {
    // Rotation cursor — without it the prior `ORDER BY ID ASC LIMIT 20`
    // re-processed the SAME first-20 opted-in users on every tick forever,
    // so the 21st-onward auto-join users were never reconciled. Mirrors
    // CronService::dailyFraudRefresh: page forward by user_id, advance the
    // cursor to the largest id returned, wrap to 0 on a short batch.
    $batchSize    = 20;
    $cursorOption = 'bcc_gated_group_reconcile_cursor';
    $afterId      = (int) get_option($cursorOption, 0);

    // Cursor-paged via the repository (§1 — no direct $wpdb in bootstrap).
    $userIds = \BCC\Trust\Onchain\Repositories\GatedGroupRepository::listAutoJoinUserIdsAfter(
        $afterId,
        $batchSize
    );

    if ($userIds === []) {
        // End of the cursor — reset so the next tick wraps to the start.
        if ($afterId > 0) {
            update_option($cursorOption, 0, false);
        }
        return;
    }

    // Advance the cursor to the largest id returned. A short batch
    // (< $batchSize) means we reached the end → wrap to 0 next tick.
    $maxReturned = (int) max(array_map('intval', $userIds));
    $nextCursor  = count($userIds) < $batchSize ? 0 : $maxReturned;
    update_option($cursorOption, $nextCursor, false);

    $service     = \BCC\Trust\Core\Plugin::instance()->nftGroupGateService();
    $totalJoined = 0;
    $usersTouched = 0;

    foreach ($userIds as $uid) {
        $result = $service->reconcileForUser((int) $uid);
        if ($result['joined'] > 0) {
            $totalJoined += $result['joined'];
            $usersTouched++;
        }
    }

    if ($usersTouched > 0) {
        \BCC\Core\Log\Logger::info('[bcc-trust] Holder-group reconcile sweep', [
            'users_eligible' => count($userIds),
            'users_touched'  => $usersTouched,
            'joins_total'    => $totalJoined,
        ]);
    }
});

// V2 (PR revoke): NFT-gated holder-group RE-VERIFICATION revoke sweep.
// Twicedaily. Re-derives eligibility for current members of every gated
// group and removes the ones we are CERTAIN no longer qualify
// (INELIGIBLE = real-zero across all wallets). UNKNOWN (provider outage /
// breaker-open) members are SKIPPED — never revoked on a hiccup. Bounded
// + rotated (see NftGroupRevokeService) so a single tick never does
// unbounded RPC and every member is eventually covered.
add_action(\BCC\Trust\Onchain\Services\NftGroupRevokeService::CRON_HOOK, function () {
    try {
        $stats = \BCC\Trust\Core\Plugin::instance()
            ->nftGroupRevokeService()
            ->sweep();
        if ($stats['revoked'] > 0 || $stats['skipped_unknown'] > 0) {
            \BCC\Core\Log\Logger::info('[bcc-trust] Holder-group revoke sweep', $stats);
        }
    } catch (\Throwable $e) {
        \BCC\Core\Log\Logger::warning('[bcc-trust] Holder-group revoke sweep failed', [
            'error' => $e->getMessage(),
        ]);
    }
});

// V2: when PeepSo evicts a user from a group via mod action, record a
// permanent opt-out so our reconcile sweep won't re-add a banned user.
// Only writes opt-out for our holder groups; ignores others.
//
// ┌─ Permanent-opt-out trap (DO NOT "simplify" away the guard) ─────────┐
// │ This listener fires on EVERY peepso_action_group_user_delete,       │
// │ including the leave() calls our own NftGroupRevokeService makes in  │
// │ cron. In cron there is no current user, so the                      │
// │ `$userId === get_current_user_id()` self-leave check below does NOT │
// │ match — without the system-revoke guard, our automated              │
// │ re-verification revoke would be mis-classified as a mod eviction    │
// │ and write a PERMANENT opt-out, locking the user out FOREVER even    │
// │ after they re-acquire the NFT. The guard flag lets us skip the      │
// │ permanent opt-out for OUR leaves while still writing it for genuine │
// │ PeepSo-UI mod removals.                                             │
// │                                                                     │
// │ Distinction test (the three paths through this listener):           │
// │   1. User leaves via the app  → $userId === current user → return   │
// │      early (REST endpoint already wrote a TTL'd opt-out).            │
// │   2. System revoke sweep      → $systemRevokeInProgress === true →   │
// │      return early, NO opt-out (user re-qualifies on re-acquire).     │
// │   3. Mod evicts via PeepSo UI → neither of the above → PERMANENT     │
// │      opt-out written (the intended behaviour — a banned user must    │
// │      not be auto-re-added).                                          │
// └─────────────────────────────────────────────────────────────────────┘
add_action('peepso_action_group_user_delete', function ($groupId, $userId) {
    $groupId = (int) $groupId;
    $userId  = (int) $userId;
    if ($groupId <= 0 || $userId <= 0) {
        return;
    }
    if (\BCC\Trust\Onchain\Services\NftGroupRevokeService::$systemRevokeInProgress) {
        // Path 2: our automated re-verification revoke. NOT a mod
        // eviction — skip the permanent opt-out so the user can rejoin
        // the instant they re-acquire the gating NFT.
        return;
    }
    if ($userId === get_current_user_id()) {
        // Path 1: voluntary leave (user removed self via UI) — TTL'd
        // opt-out is recorded by the REST endpoint or the gate service.
        // Skip here to avoid double-writing the opt-out timestamp.
        return;
    }
    // Path 3: mod-initiated eviction via the PeepSo UI.
    $config = \BCC\Trust\Onchain\Repositories\GatedGroupRepository::getGateConfig($groupId);
    if ($config === null) {
        return; // Not a holder group.
    }
    \BCC\Trust\Core\Plugin::instance()
        ->nftGroupGateService()
        ->recordPermanentOptOut($userId, $groupId);
}, 10, 2);

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

add_filter('bcc.resolve.page_owner_resolver', function ($service = null) {
    if ($service instanceof \BCC\Core\Contracts\PageOwnerResolverInterface) {
        return $service;
    }
    return \BCC\Trust\Core\Plugin::instance()->pageOwnerResolver();
});

add_filter('bcc.resolve.trending_data', function ($service = null) {
    if ($service instanceof \BCC\Core\Contracts\TrendingDataInterface) {
        return $service;
    }
    return new \BCC\Trust\Core\Application\TrendingDataService();
});

add_filter('bcc.resolve.recalc_queue_read', function ($service = null) {
    if ($service instanceof \BCC\Core\Contracts\RecalcQueueReadInterface) {
        return $service;
    }
    return new \BCC\Trust\Core\Application\RecalcQueueReadService(
        \BCC\Trust\Core\Plugin::instance()->scoreRepository()
    );
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
\BCC\Core\ServiceLocator::resolvePageOwnerResolver();
\BCC\Core\ServiceLocator::resolveTrendingData();
\BCC\Core\ServiceLocator::resolveWalletLinkRead();
\BCC\Core\ServiceLocator::resolveWalletLinkWrite();
\BCC\Core\ServiceLocator::resolveWalletSignalWrite();
\BCC\Core\ServiceLocator::resolveOnchainDataRead();
\BCC\Core\ServiceLocator::resolveRecalcQueueRead();

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
| HEADLESS-FRONTEND BRIDGES (CORS + Bearer-JWT auth)
|--------------------------------------------------------------------------
| BearerAuth → reads `Authorization: Bearer <jwt>`, verifies via
|              JwtToken (HS256 + wp_salt('auth')), and authenticates
|              the request as the JWT's user_id claim. WP cookie auth
|              wins when both are present (same-origin admin tooling).
|
| CorsHandler → CORS for /bcc/v1/* gated by BCC_FRONTEND_ORIGIN.
|               Same-origin only when the constant is undefined.
*/

\BCC\Trust\Core\Support\BearerAuth::register();
\BCC\Trust\Core\Support\CorsHandler::register();

// §K2 / §G1: hook PeepSo's user-search filter so users with
// `bcc_privacy_discovery_optout = 1` are excluded from search results.
// Registered at file load (top-level) so the filter is in place by the
// time PeepSo's UserSearch fires — before any REST or AJAX request.
\BCC\Trust\Core\Support\PrivacySettings::registerSearchFilter();

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
| cron handlers, domain events, REST routes, and admin triggers/settings.
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

    // V2 Phase 1b: Solana wallet → Helius shared-webhook subscription
    // membership. Per spike 2: one shared webhook handles up to 100k
    // addresses; we just PATCH the address list on link/unlink.
    // Done in a fire-and-forget async dispatch so wallet-verify AJAX
    // never blocks on a Helius API call.
    add_action('bcc_wallet_verified', function (int $userId, string $chainSlug, string $walletAddress): void {
        if ($chainSlug !== 'solana') {
            return;
        }
        \BCC\Core\Cron\AsyncDispatcher::enqueueAsync(
            'bcc_helius_subscribe_wallet',
            [$userId, $walletAddress],
            'bcc-onchain'
        );
    }, 10, 3);

    add_action('bcc_helius_subscribe_wallet', function (int $userId, string $walletAddress): void {
        $chain = \BCC\Trust\Onchain\Repositories\ChainRepository::getBySlug('solana');
        if ($chain === null) {
            return;
        }
        $walletLinkId = \BCC\Trust\Onchain\Repositories\WalletRepository::findIdByUserChainAddress(
            $userId,
            (int) $chain->id,
            $walletAddress
        );
        if ($walletLinkId <= 0) {
            return;
        }
        \BCC\Trust\Onchain\Services\HeliusSubscriptionManager::addAddress($walletLinkId, $walletAddress);
    }, 10, 2);

    // $_userId is unused by this handler — the do_action signature is
    // (userId, chainSlug, walletAddress) and PHP positional binding
    // requires the slot stay in place. The leading underscore signals
    // "intentionally unused" to linters.
    add_action('bcc_wallet_disconnected', function (int $_userId, string $chainSlug, string $walletAddress): void {
        if ($chainSlug !== 'solana') {
            return;
        }
        // bcc_wallet_disconnected fires AFTER WalletRepository::delete()
        // (see WalletIdentityService::unlinkWallet at bcc-core), so the
        // wallet_links row is already gone by the time we get here.
        // Pass walletLinkId = 0 — removeAddress recognises the
        // already-deleted-row case and only does remote PATCH cleanup.
        \BCC\Core\Cron\AsyncDispatcher::enqueueAsync(
            'bcc_helius_unsubscribe_wallet',
            [0, $walletAddress],
            'bcc-onchain'
        );
    }, 10, 3);

    add_action('bcc_helius_unsubscribe_wallet', function (int $walletLinkId, string $walletAddress): void {
        \BCC\Trust\Onchain\Services\HeliusSubscriptionManager::removeAddress($walletLinkId, $walletAddress);
    }, 10, 2);

    // User deletion: clean up wallet links, signals, claims, and the
    // per-wallet on-chain data hung off them (NFT holdings + profile
    // selections). NftHoldings resolves ownership by joining wallet_links,
    // so it MUST run BEFORE WalletRepository::deleteForUser deletes those
    // rows — otherwise the join finds nothing and holdings orphan.
    add_action('delete_user', function (int $userId): void {
        \BCC\Trust\Onchain\Repositories\NftHoldingsRepository::deleteForUser($userId);
        \BCC\Trust\Onchain\Repositories\NftSelectionRepository::deleteForUser($userId);
        \BCC\Trust\Onchain\Repositories\WalletRepository::deleteForUser($userId);
        \BCC\Trust\Onchain\Repositories\SignalRepository::deleteForUser($userId);
        \BCC\Trust\Onchain\Repositories\ClaimRepository::deleteForUser($userId);
    }, 10, 1);

    // REST API.
    add_action('rest_api_init', [\BCC\Trust\Onchain\Controllers\SignalController::class, 'registerRoutes']);
    add_action('rest_api_init', [\BCC\Trust\Onchain\Controllers\NftSelectionController::class, 'register_rest_routes']);
    // V2 Phase 1b: Helius webhook receiver. Always-200 + tx_signature
    // dedupe — see HeliusWebhookEndpoint for the auth + replay model.
    add_action('rest_api_init', [\BCC\Trust\Onchain\REST\HeliusWebhookEndpoint::class, 'register']);

    // Hostinger-shared cron compat: signed POST relay invoked by
    // Vercel Cron at the 1-min cadence the WP-Cron registration
    // assumes. Auth via X-Bcc-Internal header against
    // BCC_INTERNAL_CRON_SECRET. WP-Cron remains registered as a
    // fallback — per-chain AdvisoryLock makes duplicate ticks a
    // no-op. See IndexerTickEndpoint for the auth + idempotency
    // rationale.
    add_action('rest_api_init', [\BCC\Trust\Onchain\REST\IndexerTickEndpoint::class, 'register']);

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
        \BCC\Trust\Onchain\Admin\VerifyCollectionsPage::register_page();
        \BCC\Trust\Onchain\Admin\WebhooksPage::register_page();
        \BCC\Trust\Onchain\Admin\HolderGroupsPage::register_page();
    }, 20);
    \BCC\Trust\Onchain\Admin\ChainsPage::register_ajax();
    \BCC\Trust\Onchain\Admin\VerifyCollectionsPage::register_ajax();
    \BCC\Trust\Onchain\Admin\WebhooksPage::register_actions();
    \BCC\Trust\Onchain\Admin\HolderGroupsPage::register_actions();

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

}, 20);

/*
|--------------------------------------------------------------------------
| DISPUTES DOMAIN BOOT
|--------------------------------------------------------------------------
| Scheduler, admin, REST, and the PeepSo profile report-button injection
| live here. Mirrors the original bcc-disputes.php hook layout,
| namespace-rewritten for BCC\Trust\Disputes.
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

/*
|--------------------------------------------------------------------------
| PeepSo email pipeline — silenced (headless interop)
|--------------------------------------------------------------------------
|
| PeepSo schedules `peepso_mailqueue_send_event` every 5 minutes. The
| handler reads `peepso_email_intensity` + `peepso_notifications` per
| user and dispatches PeepSo's own activity emails (reactions, replies,
| friend requests, etc.).
|
| Our headless transition made BCC the canonical user-facing email
| surface (DigestService + NotificationDispatcher). PeepSo's email
| pipeline is parallel and reads keys (`peepso_*`) that our settings
| page does NOT write — so a user who opts out of email in our UI
| still gets PeepSo's emails, and a user who enables our digest may
| get duplicates.
|
| Fix: unschedule PeepSo's mail queue cron. Idempotent — running it
| every request is a no-op when the cron isn't scheduled. We do NOT
| touch PeepSo's other crons (maintenance, GDPR, inactive-user
| cleanup, super-queue) — those are non-email infrastructure we want
| to keep. Only the email send loop is silenced.
|
| If a future requirement reinstates PeepSo emails (e.g., a feature
| we don't want to reimplement), remove this block AND wire our
| /settings/notifications page to also write `peepso_email_intensity`
| / `peepso_notifications` so the two surfaces stay in sync.
*/
add_action('init', function () {
    if (defined('PeepSo::CRON_MAILQUEUE')) {
        wp_clear_scheduled_hook(\PeepSo::CRON_MAILQUEUE);
    } else {
        // Hardcoded fallback — the constant value is stable and named
        // the same across PeepSo versions we've seen, but if PeepSo
        // hasn't loaded yet (race during early init) we still want
        // the cron unscheduled.
        wp_clear_scheduled_hook('peepso_mailqueue_send_event');
    }
}, 20);

/*
|--------------------------------------------------------------------------
| V2 Phase 1 — Web push subscribers + flush worker
|--------------------------------------------------------------------------
|
| Three things wired here:
|   1. The Action-Scheduler worker that drains the per-(recipient, type)
|      queue 5 minutes after the first event lands in the window.
|   2. A push subscriber on `bcc_disputes_email_reporter_result` —
|      fires alongside the existing email handler. When the dispute
|      reporter has push enabled, they get a real-time ping in addition
|      to the email; both surfaces stay independent.
|   3. A push subscriber on `bcc_disputes_notify_panelist` — same
|      additive pattern for the "you've been picked for panel duty"
|      notification.
|
| Review + endorse pushes are wired inside NotificationDispatcher itself
| (alongside the bell write), not here.
*/
add_action('init', function () {
    add_action(
        \BCC\Trust\Core\Services\PushDispatcher::FLUSH_HOOK,
        function (int $recipientId, string $eventType): void {
            \BCC\Trust\Core\Plugin::instance()->pushDispatcher()->flush($recipientId, $eventType);
        },
        10,
        2
    );

    add_action(
        'bcc_disputes_email_reporter_result',
        function (int $disputeId, int $reporterId, string $outcome): void {
            \BCC\Trust\Core\Plugin::instance()->pushDispatcher()->enqueue(
                $reporterId,
                'dispute_outcome',
                [
                    'dispute_id' => $disputeId,
                    'outcome'    => $outcome,
                ]
            );
        },
        10,
        3
    );

    add_action(
        'bcc_disputes_notify_panelist',
        function (int $userId, int $disputeId, int $pageId): void {
            $pageName = '';
            $page = get_post($pageId);
            if ($page instanceof \WP_Post) {
                $pageName = (string) $page->post_title;
            }
            \BCC\Trust\Core\Plugin::instance()->pushDispatcher()->enqueue(
                $userId,
                'panelist_selected',
                [
                    'dispute_id' => $disputeId,
                    'page_id'    => $pageId,
                    'page_name'  => $pageName,
                ]
            );
        },
        10,
        3
    );
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

// Panelist pool health check — moved to NotificationCenter as the
// `disputes.panelist_pool_low` item. See
// \BCC\Trust\Core\Admin\NotificationCenter::checkPanelistPool().

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
| WP-CLI commands (V2 Phase 1 onwards)
|--------------------------------------------------------------------------
| Registered only when WP-CLI is loaded. Each sub-namespace lives in its
| own command class under app/Domain/Core/CLI/.
*/

if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command(
        'bcc-trust push',
        \BCC\Trust\Core\CLI\PushCommand::class
    );
    \WP_CLI::add_command(
        'bcc-trust validators',
        \BCC\Trust\Onchain\CLI\BackfillValidatorPagesCommand::class
    );
    // 2026-05-16 SMALLINT-coercion recovery — repairs legacy
    // peepso_activities rows where the pre-fix writer wrote module
    // names as strings (coerced to 0 by MySQL). One-shot, idempotent.
    \WP_CLI::add_command(
        'bcc-trust activity',
        \BCC\Trust\Core\CLI\BackfillActivityModuleIdsCommand::class
    );
}

/*
|--------------------------------------------------------------------------
| FRONTEND
|--------------------------------------------------------------------------
*/

require_once BCC_TRUST_PATH . 'includes/enqueue.php';

\BCC\Trust\Core\Plugin::instance()->peepSoIntegration()->register();

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
    if (!wp_next_scheduled('bcc_gated_group_provision')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'bcc_gated_group_provision');
    }
    if (!wp_next_scheduled('bcc_gated_group_reconcile_sweep')) {
        // Twicedaily × 20 users per tick = 40 users/day capacity, well
        // within cron worker timeout even on cold-start. Offset 90m past
        // provision so any newly-provisioned groups exist before the
        // sweep tries to auto-join opted-in users.
        wp_schedule_event(time() + 90 * MINUTE_IN_SECONDS, 'twicedaily', 'bcc_gated_group_reconcile_sweep');
    }
    // Holder-group revoke (re-verification) sweep — twicedaily, bounded +
    // rotated. Offset 120m so it trails provision + reconcile; a member
    // mid-acquire isn't revoked before the add side has had its window.
    // Self-heal mirror lives in Plugin::registerAsyncJobs (plugins_loaded)
    // per the V2 cron-drift memory — sites updated WITHOUT a reactivation
    // still get this scheduled. DO NOT remove either as "redundant."
    if (!wp_next_scheduled(\BCC\Trust\Onchain\Services\NftGroupRevokeService::CRON_HOOK)) {
        wp_schedule_event(time() + 120 * MINUTE_IN_SECONDS, 'twicedaily', \BCC\Trust\Onchain\Services\NftGroupRevokeService::CRON_HOOK);
    }

    // PR-8b — daily divergence-state sweep. Detects targets that
    // transitioned into polarizing/disputed and fires the §J.7
    // `divergence_state_warning` heads-up. Self-heal at plugins_loaded
    // below — DO NOT REMOVE the activation-side schedule as "redundant."
    if (!wp_next_scheduled('bcc_trust_divergence_state_sweep')) {
        wp_schedule_event(time() + 2 * HOUR_IN_SECONDS, 'daily', 'bcc_trust_divergence_state_sweep');
    }

    // V2 Phase 1 NFT cron schedules (activation fast path).
    //
    // ┌─ DO NOT REMOVE AS "REDUNDANT" ────────────────────────────────┐
    // │ These three schedule calls duplicate the `plugins_loaded`     │
    // │ self-heal at the top of this file. Both are intentional.      │
    // │                                                               │
    // │ Activation here = fast path on first activate (saves the      │
    // │ ~1-request lag until plugins_loaded fires).                   │
    // │                                                               │
    // │ plugins_loaded self-heal = drift insurance. The V2 Phase 1a / │
    // │ 1c cron hooks were originally activation-only. Any plugin     │
    // │ update that ADDED a new cron hook without triggering a        │
    // │ reactivation left the hook permanently absent from            │
    // │ `wp_options.cron` — empty `wp_bcc_chain_checkpoints` was the  │
    // │ only outward sign. Self-heal closes that gap.                 │
    // │                                                               │
    // │ Removing this activation block would not break things on the  │
    // │ first install (self-heal catches up on the next request) but  │
    // │ would re-introduce a small startup window where the worker    │
    // │ isn't scheduled yet. Removing the self-heal would re-open the │
    // │ silent-drift outage. Keep both.                               │
    // │                                                               │
    // │ See: NftEthIndexerWorker::register() and                      │
    // │      NftEnrichmentService::register().                        │
    // └───────────────────────────────────────────────────────────────┘

    // V2 Phase 1a: NFT ETH indexer worker — confirmation-gated polling.
    // Every minute via 'bcc_one_minute' interval registered in CronService.
    if (!wp_next_scheduled(\BCC\Trust\Onchain\Workers\NftEthIndexerWorker::CRON_HOOK)) {
        wp_schedule_event(time() + 30, 'bcc_one_minute', \BCC\Trust\Onchain\Workers\NftEthIndexerWorker::CRON_HOOK);
    }

    // V2 Phase 1b: Helius dedupe-sweep cron. Every 5 minutes deletes
    // signatures older than 1 hour AND trims oldest-first to keep the
    // table at ≤ 10 000 rows. Replay-protection tables turning into
    // infinite append-only junk drawers is a known footgun — this is
    // the bound.
    if (!wp_next_scheduled('bcc_helius_dedupe_sweep')) {
        wp_schedule_event(time() + 60, 'bcc_five_minutes', 'bcc_helius_dedupe_sweep');
    }

    // V2 Phase 1c: NFT enrichment scheduler. Backfills name +
    // image_url + collection_name on freshly-indexed rows so the
    // gallery's read-path swap doesn't render thumbnail-less rows.
    // 5-min cadence — enrichment is not time-sensitive (worst-case a
    // newly-minted NFT shows up in the V1 transient gallery first,
    // then renders from the persistent table on the next page load
    // after enrichment lands).
    if (!wp_next_scheduled(\BCC\Trust\Onchain\Services\NftEnrichmentService::CRON_HOOK)) {
        wp_schedule_event(time() + 90, 'bcc_five_minutes', \BCC\Trust\Onchain\Services\NftEnrichmentService::CRON_HOOK);
    }

    // §C3 watch-batch sweep — activation fast path + legacy hook drain.
    // Mirrors the plugins_loaded self-heal in Plugin::registerAsyncJobs:
    // unschedule the legacy `bcc_pull_batch_sweep` hook (release N) and
    // schedule the canonical `bcc_watch_batch_sweep` hook. Defense in
    // depth — see the V2 NFT cron-drift incident memory.
    if (class_exists('\\BCC\\Trust\\Core\\Services\\WatchBatchAggregator')) {
        wp_clear_scheduled_hook('bcc_pull_batch_sweep');
        if (!wp_next_scheduled(\BCC\Trust\Core\Services\WatchBatchAggregator::SWEEP_HOOK)) {
            wp_schedule_event(
                time(),
                \BCC\Trust\Core\Services\WatchBatchAggregator::SWEEP_INTERVAL,
                \BCC\Trust\Core\Services\WatchBatchAggregator::SWEEP_HOOK
            );
        }
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
        'bcc_trust_weekly_slow_ring_scan', // scale-hardening Phase 3
        // Onchain cron events.
        'bcc_onchain_daily_refresh',
        'bcc_onchain_retry_bonus',
        'bcc_gated_group_provision',
        'bcc_gated_group_reconcile_sweep',
        'bcc_gated_group_revoke_sweep',
        // V2 Phase 1a: NFT indexer tick.
        \BCC\Trust\Onchain\Workers\NftEthIndexerWorker::CRON_HOOK,
        // V2 Phase 1b: Helius dedupe-sweep.
        'bcc_helius_dedupe_sweep',
        // V2 Phase 1c: NFT enrichment scheduler.
        \BCC\Trust\Onchain\Services\NftEnrichmentService::CRON_HOOK,
        // §C3 watch-batch sweep + legacy hook (release-N drain).
        'bcc_pull_batch_sweep',
        'bcc_watch_batch_sweep',
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

// Onchain stale-chains check — moved to NotificationCenter as the
// `onchain.stale_chains` item. See
// \BCC\Trust\Core\Admin\NotificationCenter::checkStaleChains().
//
// The DISABLE_WP_CRON warning that previously co-lived in this handler
// is kept inline below as a direct banner because it's a setup/config
// failure (external cron not wired up) rather than a recurring health
// signal — it requires operator config action, not just awareness.
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (!defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON) {
        return;
    }

    $nextRefresh = wp_next_scheduled('bcc_onchain_daily_refresh');
    if (!$nextRefresh || $nextRefresh >= (time() - DAY_IN_SECONDS)) {
        return;
    }

    printf(
        '<div class="notice notice-warning"><p><strong>BCC Trust (Onchain):</strong> '
        . 'DISABLE_WP_CRON is enabled but the daily signal refresh has not fired in over 24 hours. '
        . 'Configure a system cron: <code>*/5 * * * * curl -s %s >/dev/null 2>&1</code></p></div>',
        esc_html(site_url('/wp-cron.php?doing_wp_cron'))
    );
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

/**
 * WordPress plugin_action_links_* filter callback.
 *
 * @param array<int, string> $links Existing action links (Activate / Deactivate / Delete).
 * @return array<int, string>
 */
function bcc_trust_action_links(array $links): array {
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

    // Audit follow-up (HIGH item #1): Repair was moved out of the
    // Dashboard tab nav to its own submenu under bcc-core's BCC
    // System menu. The render function still lives in tabs/tab-repair.php
    // (renaming would just churn references); load it eagerly here
    // so the new admin page can call it even when the Dashboard
    // isn't visited.
    require_once BCC_TRUST_PATH . 'includes/admin/tabs/tab-repair.php';
    add_action('admin_menu', function () {
        add_submenu_page(
            'bcc-system-health',
            'Repair (dangerous)',
            '🔧 Repair',
            'manage_options',
            'bcc-system-repair',
            'bcc_trust_render_repair_tab'
        );
    }, 25);

    // Notification Center — single admin surface for operational
    // health signals (panelist pool, stale chains, dispute
    // adjudication delays, permanent orphans). Replaces N separate
    // banners with one summary banner + a dedicated page.
    \BCC\Trust\Core\Admin\NotificationCenter::register();

    // Phase 3 deferred items — Wallet audit + Sessions surfaces.
    // Both diagnostic-grade (read-only); destructive wallet actions
    // remain on the per-user Moderation page.
    \BCC\Trust\Core\Admin\WalletAuditPage::register();
    \BCC\Trust\Core\Admin\SessionsPage::register();
}