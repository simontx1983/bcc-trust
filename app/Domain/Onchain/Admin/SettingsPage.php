<?php

namespace BCC\Trust\Onchain\Admin;

use BCC\Trust\Onchain\Support\HeliusEndpoint;
use BCC\Trust\Onchain\Support\OnchainCircuitBreaker;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-import-type ChainRow from \BCC\Trust\Onchain\Repositories\ChainRepository
 * @phpstan-import-type CheckpointRow from \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository
 */
class SettingsPage
{
    const PAGE_SLUG  = 'bcc-onchain-signals';
    const OPT_GROUP  = 'bcc_onchain_settings';

    /**
     * Sub-tab catalogue. Operators think "onchain system health," not
     * "validator indexing health vs NFT indexing health." UI is unified;
     * service boundaries underneath stay strictly separate.
     *
     * @var array<string, string>
     */
    private const TABS = [
        'validator' => 'Validator Indexer',
        'nft'       => 'NFT Indexer',
        'rpc'       => 'RPC / Breakers',
        'spam'      => 'Spam Contracts',
        'health'    => 'Health / Lag',
    ];

    public static function register_page(): void
    {
        // Audit follow-up (HIGH item #3): the three onchain admin pages
        // (On-Chain Signals, Chains, Verify Collections) were relocated
        // out of the Trust Engine menu and under BCC System so all
        // onchain config + monitoring lives in one menu alongside
        // Webhooks + Holder Groups (Phase 2 additions). Page slug
        // unchanged — existing bookmarks still work.
        add_submenu_page(
            'bcc-system-health',
            'On-Chain Signals',
            'On-Chain Signals',
            'manage_options',
            self::PAGE_SLUG,
            [__CLASS__, 'render_page']
        );
    }

    public static function render_page(): void
    {
        // Defense in depth: add_submenu_page() already gates this page on
        // manage_options, but relying on menu registration alone was the gap
        // every sibling page had already closed.
        if (!current_user_can('manage_options')) {
            wp_die(
                esc_html__('Sorry, you are not allowed to access this page.', 'bcc-trust'),
                esc_html__('Forbidden', 'bcc-trust'),
                ['response' => 403]
            );
        }

        // GET-driven sub-tab; default to validator for backwards-compat
        // with bookmarks/links that don't carry the tab parameter.
        $tab = isset($_GET['tab']) && is_string($_GET['tab']) ? sanitize_key($_GET['tab']) : 'validator';
        if (!array_key_exists($tab, self::TABS)) {
            $tab = 'validator';
        }
        ?>
        <div class="wrap">
            <h1>BCC On-Chain Signals</h1>

            <h2 class="nav-tab-wrapper">
                <?php foreach (self::TABS as $key => $label):
                    $url = add_query_arg(
                        ['page' => self::PAGE_SLUG, 'tab' => $key],
                        admin_url('admin.php')
                    );
                    $cls = 'nav-tab' . ($tab === $key ? ' nav-tab-active' : '');
                ?>
                    <a href="<?php echo esc_url($url); ?>" class="<?php echo esc_attr($cls); ?>">
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
            </h2>

            <?php
            switch ($tab) {
                case 'nft':
                    \BCC\Trust\Onchain\Admin\Views\NftIndexerStatusView::render();
                    break;
                case 'rpc':
                    self::render_rpc_breakers_tab();
                    break;
                case 'spam':
                    \BCC\Trust\Onchain\Admin\Views\NftSpamContractsView::render();
                    break;
                case 'health':
                    self::render_health_lag_tab();
                    break;
                case 'validator':
                default:
                    self::render_validator_tab();
                    break;
            }
            ?>
        </div>
        <?php
    }

    /**
     * The legacy SettingsPage content lives here under the
     * "Validator Indexer" tab. No behaviour change — this is purely
     * the migration of the original render_page body into a tab.
     */
    private static function render_validator_tab(): void
    {
        $key_from_config = defined('BCC_ETHERSCAN_API_KEY');
        ?>
        <div class="bcc-onchain-tab-validator">

            <?php if ($key_from_config): ?>
                <div class="notice notice-success inline">
                    <p><strong>BCC_ETHERSCAN_API_KEY</strong> is defined in wp-config.php — Ethereum signals are active.</p>
                </div>
            <?php else: ?>
                <div class="notice notice-warning inline">
                    <p><strong>Ethereum (Etherscan):</strong> No API key configured.
                       Define <code>BCC_ETHERSCAN_API_KEY</code> in <code>wp-config.php</code> to enable Ethereum signals.
                       Get a free key at <a href="https://etherscan.io/myapikey" target="_blank">etherscan.io</a>.
                    </p>
                </div>
            <?php endif; ?>

            <div class="notice notice-info inline">
                <p><strong>Solana:</strong> Uses the public mainnet RPC — no API key required.</p>
            </div>

            <?php self::render_das_warnings(); ?>

            <hr>

            <h2>Score Breakdown Reference</h2>
            <p>Per-wallet score maximum: <strong>40 points</strong></p>
            <p>Total on-chain bonus per user: <strong>capped at <?php echo (int) BCC_ONCHAIN_MAX_TOTAL_BONUS; ?> points</strong> regardless of how many wallets are connected.</p>
            <table class="widefat striped" style="max-width:700px">
                <thead>
                    <tr><th>Signal</th><th>Condition</th><th>Points</th></tr>
                </thead>
                <tbody>
                    <tr><td rowspan="6"><strong>Wallet Age</strong> (max <?php echo BCC_ONCHAIN_MAX_AGE_SCORE; ?>)</td>
                        <td>&lt; 180 days</td><td>0.2</td></tr>
                    <tr><td>180 – 364 days</td><td>0.5</td></tr>
                    <tr><td>1 – 2 years</td><td>2</td></tr>
                    <tr><td>2 – 3 years</td><td>3</td></tr>
                    <tr><td>3 – 5 years</td><td>6</td></tr>
                    <tr><td>5+ years</td><td>8 (cap <?php echo BCC_ONCHAIN_MAX_AGE_SCORE; ?>)</td></tr>

                    <tr><td rowspan="5"><strong>Transaction Depth</strong> (max <?php echo BCC_ONCHAIN_MAX_DEPTH_SCORE; ?>)</td>
                        <td>&lt; 20 txs</td><td>0.2</td></tr>
                    <tr><td>20 – 99</td><td>1</td></tr>
                    <tr><td>100 – 499</td><td>3</td></tr>
                    <tr><td>500 – 1,999</td><td>5</td></tr>
                    <tr><td>2,000+</td><td>7 (cap <?php echo BCC_ONCHAIN_MAX_DEPTH_SCORE; ?>)</td></tr>

                    <tr><td rowspan="7"><strong>Contract Deployments</strong> (max <?php echo BCC_ONCHAIN_MAX_CONTRACT_SCORE; ?>)</td>
                        <td>0 contracts</td><td>0.2</td></tr>
                    <tr><td>1 contract</td><td>0.5</td></tr>
                    <tr><td>2 contracts</td><td>1</td></tr>
                    <tr><td>3 contracts</td><td>3</td></tr>
                    <tr><td>5 contracts</td><td>4</td></tr>
                    <tr><td>10 contracts</td><td>5</td></tr>
                    <tr><td>20+ contracts</td><td>8 (cap <?php echo BCC_ONCHAIN_MAX_CONTRACT_SCORE; ?>)</td></tr>
                </tbody>
            </table>

            <h2>Anti-Gaming Multiplier (Contract Score)</h2>
            <p>Applied when contract age data is available:</p>
            <table class="widefat striped" style="max-width:400px">
                <thead>
                    <tr><th>Contract Age</th><th>Multiplier</th></tr>
                </thead>
                <tbody>
                    <tr><td>&lt; 30 days</td><td>× 0.15</td></tr>
                    <tr><td>30 – 90 days</td><td>× 0.30</td></tr>
                    <tr><td>90 – 365 days</td><td>× 0.45</td></tr>
                    <tr><td>1+ year</td><td>× 0.60</td></tr>
                </tbody>
            </table>

            <hr>

            <h2>Manual Refresh</h2>
            <p>Enter a PeepSo page ID to force-refresh its on-chain signals right now (bypasses the 24-hour cache).</p>
            <div id="bcc-onchain-refresh-form" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                <input type="number" id="bcc-onchain-page-id" placeholder="Page ID" class="regular-text" min="1">
                <button class="button button-primary" id="bcc-onchain-refresh-btn">Refresh Now</button>
                <span id="bcc-onchain-refresh-status"></span>
            </div>

            <hr>

            <h2>Cached Signals</h2>
            <p>Signals are re-fetched from the blockchain APIs every <?php echo BCC_ONCHAIN_CACHE_HOURS; ?> hours for active users. The daily cron runs at the time the plugin was activated.</p>

            <hr>

            <?php self::render_indexer_stats(); ?>
            <?php self::render_enrichment_stats(); ?>
            <?php self::render_signal_health(); ?>

        </div>
        <?php
    }

    /**
     * RPC / circuit-breaker state per chain. READ-ONLY, and read-only in the
     * strong sense: rendering this page changes nothing.
     *
     * ── ⚠ WHY THIS DOES NOT CALL isOpen() ───────────────────────────────
     * `isOpen()` HAS A SIDE EFFECT. In the HALF-OPEN window it atomically
     * claims a cluster-wide advisory lock, and the caller that wins it is
     * expected to go and make a request. This page used to call it ONCE PER
     * CHAIN, so an administrator opening this tab could claim the single
     * probe slot for EVERY chain at once — turning away the worker that had
     * been waiting for the cooldown to elapse, and doing it invisibly,
     * because looking at a status page is the last thing anyone suspects.
     *
     * {@see OnchainCircuitBreaker::phase()} exists precisely so observing is
     * not probing. {@see OnchainCircuitBreaker::getAllStatus()} is its bulk
     * form: it routes through the same shared phase calculation, takes ONE
     * clock for the whole sweep so two chains cannot straddle the cooldown
     * boundary mid-render, and acquires no lock.
     *
     * ⚠ ONE CALL FOR ALL CHAINS, not one per row — the previous shape made
     * the damage proportional to the number of chains.
     */
    private static function render_rpc_breakers_tab(): void
    {
        $chains   = \BCC\Trust\Onchain\Repositories\ChainRepository::getActive();
        $chainIds = array_map(static fn(object $c): int => (int) $c->id, $chains);

        // Pure read. No lock, no probe, no write, no provider request.
        $status = $chainIds === []
            ? []
            : \BCC\Trust\Onchain\Support\OnchainCircuitBreaker::getAllStatus($chainIds);

        $stateLabels = [
            'closed'    => 'CLOSED',
            'open'      => 'OPEN / cooldown',
            // ⚠ HALF-OPEN IS NOT RECOVERY. The cooldown elapsing only means
            // one cautious attempt is permitted; the provider has proved
            // nothing until that attempt succeeds. Run 8 sat half-open for
            // ~100 minutes without recovering.
            'half-open' => 'HALF-OPEN',
        ];
        ?>
        <h2>Per-Chain Circuit Breakers</h2>
        <p>State of <code>BCC\Trust\Onchain\Support\OnchainCircuitBreaker</code> per chain. CLOSED = traffic flows; OPEN = blocked for the cooldown window; HALF-OPEN = cooldown complete; one cautious probe may be attempted.</p>
        <p><small>This page only observes. Viewing it never consumes a probe attempt and never changes breaker state.</small></p>
        <table class="widefat striped" style="max-width:900px">
            <thead>
                <tr><th>Chain</th><th>State</th><th>Failures</th><th>Cooldown ends</th><th>Last failure reason</th><th>Request type</th></tr>
            </thead>
            <tbody>
                <?php if ($chains === []): ?>
                    <tr><td colspan="6"><em>No active chains.</em></td></tr>
                <?php else: ?>
                    <?php foreach ($chains as $chain):
                        $cid  = (int) $chain->id;
                        $row  = $status[$cid] ?? null;
                        $ph   = is_array($row) ? (string) ($row['status'] ?? 'closed') : 'closed';
                        $fail = is_array($row) ? (int) ($row['failures'] ?? 0) : 0;
                        $open = is_array($row) ? (int) ($row['opened_at'] ?? 0) : 0;

                        $stateLabel = $stateLabels[$ph] ?? 'CLOSED';

                        // Only meaningful while a cooldown is actually running.
                        $cooldownEnds = ($ph === 'open' && $open > 0)
                            ? gmdate('Y-m-d H:i:s', $open + \BCC\Trust\Onchain\Support\OnchainCircuitBreaker::COOLDOWN_SECONDS) . 'Z'
                            : '—';

                        // ⚠ LABELS, NEVER TOKENS — and "Not recorded" for a
                        // legacy row or a domain-level charge, which is
                        // neither a success nor a provider fault.
                        $reasonLabel = \BCC\Trust\Onchain\ValueObjects\ProviderFailureKind::label(
                            is_array($row) && isset($row['kind']) && is_string($row['kind']) ? $row['kind'] : null
                        );
                        $classLabel = \BCC\Trust\Onchain\ValueObjects\ProviderRequestClass::label(
                            is_array($row) && isset($row['request_class']) && is_string($row['request_class'])
                                ? $row['request_class']
                                : null
                        );

                        // A chain that never failed has nothing to explain.
                        if ($fail === 0) {
                            $reasonLabel = '—';
                            $classLabel  = '—';
                        }
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html((string) $chain->slug); ?></strong></td>
                        <td><?php echo esc_html($stateLabel); ?></td>
                        <td><?php echo (int) $fail; ?></td>
                        <td><?php echo esc_html($cooldownEnds); ?></td>
                        <td><?php echo esc_html($reasonLabel); ?></td>
                        <td><?php echo esc_html($classLabel); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <p><small>“Not recorded” means no bounded reason was stored — an older record, or a failure raised by domain logic rather than by a provider response. It is not a success, and it is not evidence the provider misbehaved. Manual reset controls land with Phase 1c.</small></p>
        <?php
    }

    /**
     * Health / lag overview — rolls up checkpoint state across every
     * chain plus the production-cron staleness detector. Mirrors the
     * canonical pattern from CronService::admin_notices.
     */
    private static function render_health_lag_tab(): void
    {
        $checkpoints = \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::getAll();
        $cronDisabled = defined('DISABLE_WP_CRON') && constant('DISABLE_WP_CRON') === true;
        $envProd = function_exists('wp_get_environment_type') && wp_get_environment_type() === 'production';
        $tickHook = \BCC\Trust\Onchain\Workers\NftEthIndexerWorker::CRON_HOOK;
        $nextTick = wp_next_scheduled($tickHook);
        $tickOverdueSec = $nextTick ? (time() - (int) $nextTick) : 0;
        $tickOverdue = $nextTick && $tickOverdueSec > \BCC\Trust\Onchain\Workers\NftEthIndexerWorker::CRON_OVERDUE_THRESHOLD_SECONDS;
        ?>

        <h2>Production-Cron Health</h2>
        <?php if ($envProd && !$cronDisabled): ?>
            <div class="notice notice-error inline" style="max-width:760px">
                <p><strong>Indexing reliability reduced.</strong> Server cron is required in production. The site is still relying on user-request-driven wp-cron, which silently lags during low-traffic periods. Add this line to your server crontab:</p>
                <p><code>*/1 * * * * curl -s <?php echo esc_html(site_url('/wp-cron.php?doing_wp_cron')); ?> &gt;/dev/null 2&gt;&amp;1</code></p>
                <p>And set <code>define('DISABLE_WP_CRON', true);</code> in <code>wp-config.php</code>.</p>
            </div>
        <?php elseif (!$envProd): ?>
            <div class="notice notice-info inline" style="max-width:760px">
                <p>Environment is not production; wp-cron is acceptable here. In production, real server cron is required.</p>
            </div>
        <?php else: ?>
            <div class="notice notice-success inline" style="max-width:760px">
                <p><strong>Server cron is configured.</strong> wp-cron is disabled; ticks are driven by an external scheduler.</p>
            </div>
        <?php endif; ?>

        <?php if ($tickOverdue): ?>
            <div class="notice notice-error inline" style="max-width:760px">
                <p><strong>NFT indexer tick is overdue by <?php echo (int) ($tickOverdueSec / 60); ?> minutes.</strong> Check that the server cron is firing — the next scheduled run is <?php echo esc_html(human_time_diff((int) $nextTick, time())); ?> ago.</p>
            </div>
        <?php endif; ?>

        <h2>Per-Chain Indexer Lag</h2>
        <table class="widefat striped" style="max-width:900px">
            <thead>
                <tr>
                    <th>Chain</th>
                    <th>State</th>
                    <th>Last block</th>
                    <th>Head block</th>
                    <th>Lag (blocks)</th>
                    <th>CU used today</th>
                    <th>Last error</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($checkpoints === []): ?>
                    <tr><td colspan="7"><em>No checkpoints recorded yet — indexer has not started.</em></td></tr>
                <?php else: ?>
                    <?php foreach ($checkpoints as $cp):
                        $last = (int) $cp->last_processed_block;
                        $head = (int) $cp->head_block;
                        $lag  = max(0, $head - $last);
                    ?>
                    <tr>
                        <td>chain_id=<?php echo (int) $cp->chain_id; ?></td>
                        <td><strong><?php echo esc_html((string) $cp->state); ?></strong></td>
                        <td><?php echo $last; ?></td>
                        <td><?php echo $head; ?></td>
                        <td><?php echo $lag; ?></td>
                        <td><?php echo (int) $cp->cu_used_today; ?></td>
                        <td><?php echo $cp->last_error !== null ? esc_html((string) $cp->last_error) : '—'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render the validator indexer metrics panel.
     */
    private static function render_indexer_stats(): void
    {
        $allStats = get_option('bcc_onchain_indexer_stats', []);

        ?>
        <h2>Validator Indexer</h2>
        <?php if (empty($allStats)): ?>
            <p>No indexer runs recorded yet. The indexer runs every 4 hours.</p>
        <?php else: ?>
            <table class="widefat striped" style="max-width:800px">
                <thead>
                    <tr>
                        <th>Chain</th>
                        <th>Total</th>
                        <th>New</th>
                        <th>Updated</th>
                        <th>Unchanged</th>
                        <th>Refreshed</th>
                        <th>Last Run</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allStats as $slug => $s): ?>
                    <tr>
                        <td><strong><?php echo esc_html($s['chain'] ?? $slug); ?></strong></td>
                        <td><?php echo (int) ($s['total'] ?? 0); ?></td>
                        <td><?php echo (int) ($s['new'] ?? 0); ?></td>
                        <td><?php echo (int) ($s['updated'] ?? 0); ?></td>
                        <td><?php echo (int) ($s['unchanged'] ?? 0); ?></td>
                        <td><?php echo (int) ($s['refreshed'] ?? 0); ?></td>
                        <td><?php echo esc_html($s['timestamp'] ?? '—'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php
            // Totals row
            $totals = ['total' => 0, 'new' => 0, 'updated' => 0, 'unchanged' => 0];
            foreach ($allStats as $s) {
                $totals['total']     += (int) ($s['total'] ?? 0);
                $totals['new']       += (int) ($s['new'] ?? 0);
                $totals['updated']   += (int) ($s['updated'] ?? 0);
                $totals['unchanged'] += (int) ($s['unchanged'] ?? 0);
            }
            $writeRate = $totals['total'] > 0
                ? round((($totals['new'] + $totals['updated']) / $totals['total']) * 100, 1)
                : 0;
            ?>
            <p style="margin-top:8px">
                <strong>Write rate:</strong> <?php echo $writeRate; ?>% of validators required a DB write.
                <?php if ($writeRate < 10): ?>
                    <span style="color:#46b450">&#10003; Lean — most validators unchanged.</span>
                <?php elseif ($writeRate > 50): ?>
                    <span style="color:#d63638">&#9888; High churn — investigate if expected.</span>
                <?php endif; ?>
            </p>
        <?php endif;
    }

    /**
     * Render the enrichment scheduler metrics panel.
     */
    private static function render_enrichment_stats(): void
    {
        $stats = get_option('bcc_onchain_enrichment_stats', []);

        ?>
        <hr>
        <h2>Enrichment Scheduler</h2>
        <?php if (empty($stats)): ?>
            <p>No enrichment runs recorded yet. The scheduler runs every hour.</p>
        <?php else: ?>
            <table class="widefat striped" style="max-width:600px">
                <tbody>
                    <tr><th>Processed</th><td><?php echo (int) ($stats['processed'] ?? 0); ?></td></tr>
                    <tr><th>Failed</th><td><?php echo (int) ($stats['failed'] ?? 0); ?></td></tr>
                    <tr><th>Skipped</th><td><?php echo (int) ($stats['skipped'] ?? 0); ?></td></tr>
                    <tr><th>API Calls Used</th><td><?php echo (int) ($stats['api_calls'] ?? 0); ?> / 200</td></tr>
                    <tr><th>Stop Reason</th><td><code><?php echo esc_html($stats['stopped_reason'] ?? '—'); ?></code></td></tr>
                    <tr><th>Last Run</th><td><?php echo esc_html($stats['timestamp'] ?? '—'); ?></td></tr>
                </tbody>
            </table>
            <?php
            $failed = (int) ($stats['failed'] ?? 0);
            $processed = (int) ($stats['processed'] ?? 0);
            if ($failed > 0 && $processed > 0):
                $failRate = round(($failed / ($processed + $failed)) * 100, 1);
                ?>
                <p style="margin-top:8px">
                    <strong>Failure rate:</strong> <?php echo $failRate; ?>%
                    <?php if ($failRate > 20): ?>
                        <span style="color:#d63638">&#9888; High failure rate — check LCD endpoint health.</span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        <?php endif;
    }

    /**
     * Warn operators when an RPC endpoint has been observed returning
     * "method not found" for DAS-family calls (getAssetsByOwner, etc).
     * The public Solana RPC silently fails this way — NFT collections
     * appear empty with no other signal. Written by SolanaFetcher on
     * first detection; persists until the operator clears it.
     */
    private static function render_das_warnings(): void
    {
        if (!class_exists('\\BCC\\Trust\\Onchain\\Repositories\\ChainRepository')) {
            return;
        }

        $chains = \BCC\Trust\Onchain\Repositories\ChainRepository::getActive();
        foreach ($chains as $chain) {
            $flag = get_option(HeliusEndpoint::dasUnsupportedOptionKey((int) $chain->id), null);
            if (!is_array($flag)) {
                continue;
            }
            $rpc     = isset($flag['rpc_url']) ? (string) $flag['rpc_url'] : '';
            $code    = isset($flag['code']) ? (int) $flag['code'] : 0;
            $message = isset($flag['message']) ? (string) $flag['message'] : '';
            $when    = isset($flag['detected_at']) ? (int) $flag['detected_at'] : 0;
            ?>
            <div class="notice notice-error inline">
                <p><strong><?php echo esc_html($chain->name); ?> — NFT collection fetching is disabled.</strong>
                   RPC endpoint <code><?php echo esc_html($rpc); ?></code> does not support DAS methods
                   (<code>getAssetsByOwner</code>). Error <?php echo $code; ?>: <em><?php echo esc_html($message); ?></em>.
                   Configure a DAS-capable RPC (Helius, QuickNode, Triton) via the
                   <code>rpc_url</code> column on the <code>bcc_chains</code> row for this chain,
                   or define <code>BCC_SOLANA_RPC_URL</code> in <code>wp-config.php</code>.
                   <?php if ($when > 0): ?>
                       <br><small>First detected <?php echo esc_html(human_time_diff($when)); ?> ago.</small>
                   <?php endif; ?>
                </p>
            </div>
            <?php
        }
    }

    /**
     * Render the signal fetcher health panel.
     *
     * Shows per-chain last success, consecutive failures, and circuit
     * breaker state so admins can diagnose silent degradation.
     */
    private static function render_signal_health(): void
    {
        if (!class_exists('\\BCC\\Trust\\Onchain\\Services\\SignalFetcher')) {
            return;
        }

        $statuses = \BCC\Trust\Onchain\Services\SignalFetcher::getChainHealthStatus();

        ?>
        <hr>
        <h2>Signal Fetcher Health</h2>
        <?php if (empty(array_filter($statuses))): ?>
            <p>No health data recorded yet. Signals are fetched on demand when users connect wallets.</p>
        <?php else: ?>
            <table class="widefat striped" style="max-width:900px">
                <thead>
                    <tr>
                        <th>Chain</th>
                        <th>Status</th>
                        <th>Last Success</th>
                        <th>Consecutive Failures</th>
                        <th>Last Failure</th>
                        <th>Circuit Breaker</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($statuses as $chain => $health):
                        if (empty($health)) {
                            continue;
                        }
                        $status   = $health['status'] ?? 'unknown';
                        $lastOk   = isset($health['last_success']) ? human_time_diff((int) $health['last_success']) . ' ago' : 'never';
                        $failures = (int) ($health['consecutive_failures'] ?? 0);
                        $lastFail = isset($health['last_failure']) ? human_time_diff((int) $health['last_failure']) . ' ago' : '—';

                        $statusColor = match ($status) {
                            'healthy'      => '#00a32a',
                            'intermittent' => '#dba617',
                            'degraded'     => '#d63638',
                            default        => '#666',
                        };

                        $breakerOpen = $failures >= OnchainCircuitBreaker::FAILURE_THRESHOLD && isset($health['last_failure'])
                            && (time() - (int) $health['last_failure']) < OnchainCircuitBreaker::COOLDOWN_SECONDS;
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html(ucfirst($chain)); ?></strong></td>
                        <td style="color:<?php echo esc_attr($statusColor); ?>;font-weight:600">
                            <?php echo esc_html(ucfirst($status)); ?>
                        </td>
                        <td><?php echo esc_html($lastOk); ?></td>
                        <td><?php echo $failures; ?></td>
                        <td><?php echo esc_html($lastFail); ?></td>
                        <td>
                            <?php if ($breakerOpen): ?>
                                <span style="color:#d63638;font-weight:600">&#9940; OPEN</span>
                                <br><small>Cooldown: <?php echo OnchainCircuitBreaker::COOLDOWN_SECONDS - (time() - (int) $health['last_failure']); ?>s remaining</small>
                            <?php else: ?>
                                <span style="color:#00a32a">&#9989; Closed</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif;

        // ── Data Freshness & Validator Coverage ─────────────────────────
        $indexerStats = get_option('bcc_onchain_indexer_stats', []);
        $validatorCounts = class_exists('\\BCC\\Trust\\Onchain\\Repositories\\ValidatorRepository')
            ? \BCC\Trust\Onchain\Repositories\ValidatorRepository::getCountsByChain()
            : [];

        if (!empty($indexerStats) || !empty($validatorCounts)):
        ?>
        <hr>
        <h2>Data Freshness</h2>
        <table class="widefat striped" style="max-width:900px">
            <thead>
                <tr>
                    <th>Chain</th>
                    <th>Known Validators</th>
                    <th>Last Indexed</th>
                    <th>Freshness</th>
                    <th>Last Run Result</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($indexerStats as $slug => $stats):
                    $chainId    = 0;
                    $knownCount = 0;
                    $lastFetched = '—';

                    // Match chain slug to validator counts
                    foreach ($validatorCounts as $cid => $vc) {
                        // Best-effort match via indexer stats
                        $knownCount = (int) $vc->cnt;
                        $lastFetched = $vc->last_fetched ?? '—';
                    }

                    $timestamp   = $stats['timestamp'] ?? '';
                    $indexedAgo  = $timestamp ? human_time_diff(strtotime($timestamp)) . ' ago' : 'never';
                    $isPartial   = !empty($stats['partial']);
                    $isStale     = $timestamp && (time() - strtotime($timestamp)) > 6 * HOUR_IN_SECONDS;
                ?>
                <tr>
                    <td><strong><?php echo esc_html($stats['chain'] ?? $slug); ?></strong></td>
                    <td><?php echo $knownCount; ?></td>
                    <td><?php echo esc_html($indexedAgo); ?></td>
                    <td>
                        <?php if ($isStale): ?>
                            <span style="color:#d63638;font-weight:600">STALE (&gt;6h)</span>
                        <?php else: ?>
                            <span style="color:#00a32a">Fresh</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($isPartial): ?>
                            <span style="color:#dba617">Partial fetch</span>
                        <?php else: ?>
                            <?php echo (int) ($stats['total'] ?? 0); ?> validators
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        endif;

        // ── API Budget ──────────────────────────────────────────────────
        if (class_exists('\\BCC\\Trust\\Onchain\\Services\\EnrichmentScheduler')):
            /** @var array<string, mixed> $budgetStats */
            $budgetStats = get_option('bcc_onchain_enrichment_stats', []);
            $apiCallsUsed = (int) ($budgetStats['api_calls'] ?? 0);
            $maxCalls     = defined('BCC_ONCHAIN_MAX_API_CALLS') ? (int) BCC_ONCHAIN_MAX_API_CALLS : 200;
            $budgetPct    = $maxCalls > 0 ? min(100, (int) (($apiCallsUsed * 100) / $maxCalls)) : 0;
            $budgetColor  = $budgetPct > 90 ? '#d63638' : ($budgetPct > 70 ? '#dba617' : '#00a32a');
        ?>
        <hr>
        <h2>API Budget</h2>
        <p>
            Enrichment API calls this cycle:
            <strong style="color:<?php echo esc_attr($budgetColor); ?>">
                <?php echo $apiCallsUsed; ?> / <?php echo $maxCalls; ?>
            </strong>
            (<?php echo $budgetPct; ?>%)
        </p>
        <div style="width:300px;height:20px;background:#ddd;border-radius:3px;overflow:hidden">
            <div style="width:<?php echo $budgetPct; ?>%;height:100%;background:<?php echo esc_attr($budgetColor); ?>"></div>
        </div>
        <?php endif;
    }
}
