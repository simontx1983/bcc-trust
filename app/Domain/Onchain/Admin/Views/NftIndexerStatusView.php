<?php

namespace BCC\Trust\Onchain\Admin\Views;

use BCC\Trust\Onchain\REST\HeliusWebhookEndpoint;
use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\HeliusSeenSignaturesRepository;
use BCC\Trust\Onchain\Repositories\NftHoldingsRepository;
use BCC\Trust\Onchain\Services\HeliusSubscriptionManager;
use BCC\Trust\Onchain\Services\NftIndexerHealthSnapshot;
use BCC\Trust\Onchain\Workers\NftEthIndexerWorker;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * NFT Indexer admin sub-tab (V2 Phase 1a).
 *
 * Surfaces per-chain checkpoint, lag, CU budget, breaker state, last
 * error. Pure read view — operator controls (manual run, pause, reset
 * checkpoint) are GET-handled at the bottom and live here so the
 * SettingsPage dispatcher stays thin.
 *
 * @phpstan-import-type ChainRow from ChainRepository
 * @phpstan-import-type CheckpointRow from ChainCheckpointRepository
 * @phpstan-import-type HoldingRow from NftHoldingsRepository
 */
final class NftIndexerStatusView
{
    public static function render(): void
    {
        // Lightweight admin actions via GET + nonce. POST would be
        // cleaner but the existing onchain admin surface uses GET +
        // wp_verify_nonce for "run now" / "pause" controls, so this
        // matches that convention.
        self::handleActions();

        $chains      = ChainRepository::getActive('evm');
        $checkpoints = ChainCheckpointRepository::getAll();
        $byChain     = [];
        foreach ($checkpoints as $cp) {
            $byChain[(int) $cp->chain_id] = $cp;
        }

        // §3: pre-fetch the spam preview ONCE before the render block —
        // a single IN-clause query replaces one query per active chain
        // and keeps the view itself a pure renderer of pre-fetched data.
        $chainIds = [];
        $slugByChainId = [];
        foreach ($chains as $chain) {
            $cid = (int) $chain->id;
            $chainIds[]               = $cid;
            $slugByChainId[$cid]      = (string) $chain->slug;
        }
        $spamRecent = $chainIds === []
            ? []
            : NftHoldingsRepository::findByStatusAcrossChains($chainIds, NftHoldingsRepository::STATUS_SPAM, 50);

        $dailyBudget = defined('BCC_ETH_DAILY_RPC_BUDGET')
            ? (int) constant('BCC_ETH_DAILY_RPC_BUDGET')
            : NftEthIndexerWorker::DEFAULT_DAILY_BUDGET;

        $summary = NftIndexerHealthSnapshot::buildSummary();
        ?>
        <h2>NFT Indexer (Confirmation-Gated)</h2>
        <?php self::renderHealthSummary($summary); ?>
        <p>
            Walks <code>alchemy_getAssetTransfers</code> at
            <strong>N=<?php echo NftEthIndexerWorker::CONFIRMATIONS; ?> confirmations</strong>
            per tick. ~2-minute write lag is the locked tradeoff —
            phantom ownership state is unacceptable. Daily CU budget
            cap: <strong><?php echo number_format($dailyBudget); ?> CU/day</strong>
            (one <code>getAssetTransfers</code> call = 120 CU).
        </p>

        <table class="widefat striped" style="max-width:1200px">
            <thead>
                <tr>
                    <th>Chain</th>
                    <th>State</th>
                    <th>Last processed</th>
                    <th>Head</th>
                    <th>Lag (blocks)</th>
                    <th>CU used today</th>
                    <th title="EnrichmentScheduler per-chain 10-min rolling counter. Now scoped to scheduler activity only after Phase X2 decoupling.">Calls (10m)</th>
                    <th title="Per-tick block-progression sparkline (last 5 ticks). ↑ = advanced, — = stagnant, ↓ = regressed.">Progression</th>
                    <th>Last run</th>
                    <th>Last error</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($chains === []): ?>
                    <tr><td colspan="11"><em>No active EVM chains.</em></td></tr>
                <?php else: ?>
                    <?php foreach ($chains as $chain):
                        $cid     = (int) $chain->id;
                        $cp      = $byChain[$cid] ?? null;
                        $state   = $cp ? (string) $cp->state : ChainCheckpointRepository::STATE_DISABLED;
                        $last    = $cp ? (int) $cp->last_processed_block : 0;
                        $head    = $cp ? (int) $cp->head_block : 0;
                        $lag     = max(0, $head - $last);
                        $cuUsed  = $cp ? (int) $cp->cu_used_today : 0;
                        $lastRun = $cp && $cp->last_run_at ? (string) $cp->last_run_at : '—';
                        $lastErr = $cp && $cp->last_error ? (string) $cp->last_error : '—';

                        // Call-count gauge (X1). Read from the shared summary
                        // payload so the per-row figure agrees with the
                        // top-card pressure signal.
                        $callsRow   = $summary['call_count_by_chain'][$cid] ?? null;
                        $callsCount = $callsRow !== null ? (int) $callsRow['count'] : 0;
                        $callsCap   = $callsRow !== null ? (int) $callsRow['cap']   : 0;
                        $callsHot   = $callsCap > 0
                            && ($callsCount / $callsCap) >= NftIndexerHealthSnapshot::CALL_COUNT_PRESSURE_RATIO
                            && $state !== ChainCheckpointRepository::STATE_DISABLED;

                        // Progression sparkline (X3). Read from the same
                        // summary payload — `progression_by_chain` is the
                        // shared source of truth for the per-chain table
                        // AND the top-card signals.
                        $progRow      = $summary['progression_by_chain'][$cid] ?? null;
                        $progDeltas   = $progRow !== null ? $progRow['deltas'] : [];
                        $progSamples  = $progRow !== null ? (int) $progRow['sample_count'] : 0;
                        $progHasRegression = false;
                        foreach ($progDeltas as $d) {
                            if ($d < 0) { $progHasRegression = true; break; }
                        }

                        $runNonce   = wp_create_nonce('bcc_nft_indexer_run_' . $cid);
                        $pauseNonce = wp_create_nonce('bcc_nft_indexer_state_' . $cid);
                        $runUrl     = self::actionUrl(['action' => 'run', 'chain_id' => $cid, '_wpnonce' => $runNonce]);
                        $pauseTo    = $state === ChainCheckpointRepository::STATE_DISABLED
                            ? ChainCheckpointRepository::STATE_HEALTHY
                            : ChainCheckpointRepository::STATE_DISABLED;
                        $pauseLabel = $state === ChainCheckpointRepository::STATE_DISABLED ? 'Resume' : 'Pause';
                        $pauseUrl   = self::actionUrl([
                            'action'   => 'set_state',
                            'chain_id' => $cid,
                            'state'    => $pauseTo,
                            '_wpnonce' => $pauseNonce,
                        ]);
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html((string) $chain->slug); ?></strong></td>
                        <td><?php echo esc_html($state); ?></td>
                        <td><?php echo $last; ?></td>
                        <td><?php echo $head; ?></td>
                        <td><?php echo $lag; ?></td>
                        <td><?php echo $cuUsed; ?> / <?php echo $dailyBudget; ?></td>
                        <td<?php echo $callsHot ? ' style="color:#b32d2e;font-weight:bold;"' : ''; ?>>
                            <?php echo $callsCount; ?> / <?php echo $callsCap; ?>
                        </td>
                        <td<?php echo $progHasRegression ? ' style="color:#b32d2e;font-weight:bold;"' : ''; ?>>
                            <?php
                            // Sparkline: ↑ advance, — stagnant, ↓ regression.
                            // 4 arrows for 5-entry history (4 deltas). Insufficient
                            // sample shows "—" placeholders until enough ticks
                            // accumulate. Pure presentation of the same `deltas`
                            // the snapshot reads; no recomputation here.
                            if ($progSamples === 0) {
                                echo '<span style="color:#999;font-size:11px;">no data</span>';
                            } else {
                                $glyphs = [];
                                foreach ($progDeltas as $d) {
                                    if ($d > 0)      { $glyphs[] = '<span style="color:#46b450;">&uarr;</span>'; }
                                    elseif ($d < 0)  { $glyphs[] = '<span style="color:#b32d2e;font-weight:bold;">&darr;</span>'; }
                                    else             { $glyphs[] = '<span style="color:#999;">&mdash;</span>'; }
                                }
                                echo '<span style="font-family:monospace;">' . implode(' ', $glyphs) . '</span>';
                                if ($progSamples < ChainCheckpointRepository::MAX_PROGRESSION_ENTRIES) {
                                    echo '<span style="color:#999;font-size:10px;margin-left:6px;">(' . (int) $progSamples . '/' . (int) ChainCheckpointRepository::MAX_PROGRESSION_ENTRIES . ')</span>';
                                }
                            }
                            ?>
                        </td>
                        <td><?php echo esc_html($lastRun); ?></td>
                        <td><?php echo esc_html($lastErr); ?></td>
                        <td>
                            <a class="button button-secondary" href="<?php echo esc_url($runUrl); ?>">Run now</a>
                            <a class="button" href="<?php echo esc_url($pauseUrl); ?>"><?php echo esc_html($pauseLabel); ?></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <h3 style="margin-top:32px">Helius Webhook (Solana, V2 Phase 1b)</h3>
        <?php self::renderHeliusPanel($summary['helius_freshness']); ?>

        <h3 style="margin-top:32px">Recent spam-flagged rows</h3>
        <p>Persisted with <code>metadata_status = 2</code> for review. Never visible to user-facing surfaces.</p>
        <?php self::renderSpamRecent($spamRecent, $slugByChainId); ?>

        <p style="margin-top:32px"><small>
            Plan reference: <code>v2-phase-1-nft-scaling.md</code> · Worker:
            <code>BCC\Trust\Onchain\Workers\NftEthIndexerWorker</code> ·
            Tick: <code>bcc_nft_eth_indexer_tick</code> (every minute)
        </small></p>
        <?php
    }

    /**
     * Operator health summary card. Renders an RGB status badge,
     * top-level metrics (active chains / cron health / dedupe size),
     * and an ordered actionable-issues list. Reads from a single
     * source of truth — `NftIndexerHealthSnapshot::buildSummary()` —
     * so this view and any future system-health probe never disagree.
     *
     * @param array{
     *     status: 'green'|'yellow'|'red',
     *     cron_scheduled: bool,
     *     cron_overdue: bool,
     *     cron_overdue_seconds: int,
     *     active_chains_count: int,
     *     total_evm_chains_count: int,
     *     stalled_chains: list<string>,
     *     degraded_chains: list<string>,
     *     cu_pressure_chains: list<string>,
     *     call_count_pressure_chains: list<string>,
     *     call_count_by_chain: array<int, array{slug: string, count: int, cap: int}>,
     *     fake_healthy_chains: list<string>,
     *     lag_drift_chains: list<string>,
     *     regression_chains: list<string>,
     *     progression_by_chain: array<int, array{slug: string, deltas: list<int>, last_block: int, sample_count: int}>,
     *     dedupe_overgrown: bool,
     *     helius_freshness: array{state: 'green'|'yellow'|'red'|'never_delivered'|'not_provisioned', last_delivery_at: int|null, age_seconds: int|null},
     *     issues: list<string>
     * } $summary
     */
    private static function renderHealthSummary(array $summary): void
    {
        $statusColors = [
            NftIndexerHealthSnapshot::STATUS_GREEN  => ['bg' => '#e7f5ec', 'border' => '#46b450', 'label' => 'HEALTHY'],
            NftIndexerHealthSnapshot::STATUS_YELLOW => ['bg' => '#fff8e5', 'border' => '#f0b849', 'label' => 'ATTENTION'],
            NftIndexerHealthSnapshot::STATUS_RED    => ['bg' => '#fbeaea', 'border' => '#dc3232', 'label' => 'ACTION REQUIRED'],
        ];
        $palette = $statusColors[$summary['status']] ?? $statusColors[NftIndexerHealthSnapshot::STATUS_RED];

        $cronLabel = $summary['cron_scheduled']
            ? ($summary['cron_overdue']
                ? sprintf('overdue %ds', $summary['cron_overdue_seconds'])
                : 'scheduled')
            : 'NOT SCHEDULED';

        ?>
        <div style="
            background:<?php echo esc_attr($palette['bg']); ?>;
            border-left:4px solid <?php echo esc_attr($palette['border']); ?>;
            padding:12px 16px;
            margin:12px 0 16px;
            max-width:1100px;
        ">
            <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                <strong style="
                    background:<?php echo esc_attr($palette['border']); ?>;
                    color:#fff;
                    padding:4px 10px;
                    border-radius:2px;
                    font-size:11px;
                    letter-spacing:0.08em;
                "><?php echo esc_html($palette['label']); ?></strong>

                <span><strong>Active chains:</strong>
                    <?php echo (int) $summary['active_chains_count']; ?> / <?php echo (int) $summary['total_evm_chains_count']; ?>
                </span>

                <span><strong>Cron:</strong> <?php echo esc_html($cronLabel); ?></span>

                <?php if ($summary['degraded_chains'] !== []): ?>
                    <span><strong>Degraded:</strong> <?php echo (int) count($summary['degraded_chains']); ?></span>
                <?php endif; ?>

                <?php if ($summary['stalled_chains'] !== []): ?>
                    <span><strong>Stalled:</strong> <?php echo (int) count($summary['stalled_chains']); ?></span>
                <?php endif; ?>

                <?php if ($summary['cu_pressure_chains'] !== []): ?>
                    <span><strong>CU pressure:</strong> <?php echo (int) count($summary['cu_pressure_chains']); ?></span>
                <?php endif; ?>

                <?php if ($summary['call_count_pressure_chains'] !== []): ?>
                    <span title="ApiRetry per-chain budget pressure (V2 retries may be pre-blocking V1 fetches on the same chain)"><strong>Call pressure:</strong> <?php echo (int) count($summary['call_count_pressure_chains']); ?></span>
                <?php endif; ?>

                <?php if ($summary['regression_chains'] !== []): ?>
                    <span style="color:#b32d2e;font-weight:bold;" title="Backward progression — last_processed_block went DOWN. Correctness anomaly."><strong>↓ Regression:</strong> <?php echo (int) count($summary['regression_chains']); ?></span>
                <?php endif; ?>

                <?php if ($summary['fake_healthy_chains'] !== []): ?>
                    <span title="Worker is ticking but the checkpoint is not advancing while head_block moves — heartbeat is lying."><strong>Stalled progress:</strong> <?php echo (int) count($summary['fake_healthy_chains']); ?></span>
                <?php endif; ?>

                <?php if ($summary['lag_drift_chains'] !== []): ?>
                    <span title="Lag is drifting monotonically upward — BLOCKS_PER_TICK is below chain block-production rate."><strong>Lag drift:</strong> <?php echo (int) count($summary['lag_drift_chains']); ?></span>
                <?php endif; ?>

                <?php
                $hfState = $summary['helius_freshness']['state'];
                $hfAge   = $summary['helius_freshness']['age_seconds'];
                if ($hfState === 'red' || $hfState === 'yellow'):
                    $hfColor = $hfState === 'red' ? '#b32d2e' : '#9b6c00';
                    $hfLabel = $hfAge !== null
                        ? NftIndexerHealthSnapshot::formatDuration((int) $hfAge)
                        : '?';
                ?>
                    <span style="color:<?php echo esc_attr($hfColor); ?>;font-weight:bold;" title="Solana ingestion has not received a Helius delivery for this long."><strong>Solana silent:</strong> <?php echo esc_html($hfLabel); ?></span>
                <?php elseif ($hfState === 'never_delivered'): ?>
                    <span style="color:#9b6c00;" title="Helius webhook is provisioned but no deliveries yet — verify configuration."><strong>Solana ingestion:</strong> never delivered</span>
                <?php endif; ?>

                <?php if ($summary['dedupe_overgrown']): ?>
                    <span><strong>Helius dedupe:</strong> overgrown</span>
                <?php endif; ?>
            </div>

            <?php if ($summary['issues'] !== []): ?>
                <ul style="margin:10px 0 0 1.2em;padding:0;">
                    <?php foreach ($summary['issues'] as $issue): ?>
                        <li style="margin:4px 0;"><?php echo esc_html($issue); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p style="margin:8px 0 0 0;color:#646970;font-size:12px;">
                    No outstanding issues. Block-walking and holdings writes are flowing for active chains.
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Helius webhook operator surface — provisioning + status + resync.
     * Phase 1b deliverable. Lives inside the NFT Indexer sub-tab so
     * operators don't have to chase a separate page for related state.
     *
     * @param array{state: 'green'|'yellow'|'red'|'never_delivered'|'not_provisioned', last_delivery_at: int|null, age_seconds: int|null} $freshness
     */
    private static function renderHeliusPanel(array $freshness): void
    {
        $apiKeyDefined = defined('BCC_HELIUS_API_KEY') && (string) constant('BCC_HELIUS_API_KEY') !== '';
        $secretDefined = defined('BCC_HELIUS_WEBHOOK_SECRET') && (string) constant('BCC_HELIUS_WEBHOOK_SECRET') !== '';
        $callbackUrl   = HeliusWebhookEndpoint::callbackUrl();
        $status        = HeliusSubscriptionManager::status();
        $dedupeRows    = HeliusSeenSignaturesRepository::rowCount();
        $dedupeAlarm   = HeliusSeenSignaturesRepository::ALARM_THRESHOLD;
        $sigfailCount  = (int) get_option('bcc_helius_signature_sigfail_total', 0);
        $seenCount     = (int) get_option('bcc_helius_signature_seen_total', 0);
        $newCount      = (int) get_option('bcc_helius_signature_new_total', 0);

        ?>
        <p>
            Solana NFT events are delivered via a single shared Helius webhook
            (up to 100 000 addresses). Configure both env constants in
            <code>wp-config.php</code> before provisioning:
        </p>
        <ul style="margin-left:1.5em">
            <li><code>BCC_HELIUS_API_KEY</code> — <?php echo $apiKeyDefined ? '<strong style="color:green">defined</strong>' : '<strong style="color:#c00">missing</strong>'; ?></li>
            <li><code>BCC_HELIUS_WEBHOOK_SECRET</code> — <?php echo $secretDefined ? '<strong style="color:green">defined</strong>' : '<strong style="color:#c00">missing</strong>'; ?></li>
        </ul>

        <table class="widefat striped" style="max-width:900px">
            <tbody>
                <tr><th style="width:240px">Callback URL (Helius posts here)</th>
                    <td><code><?php echo esc_html($callbackUrl); ?></code></td></tr>
                <tr><th>Webhook ID</th>
                    <td><?php echo $status['webhook_id'] !== '' ? '<code>' . esc_html($status['webhook_id']) . '</code>' : '<em>not provisioned</em>'; ?></td></tr>
                <tr><th>Configured callback (remote)</th>
                    <td><?php echo $status['callback_url'] !== null ? '<code>' . esc_html($status['callback_url']) . '</code>' : '—'; ?></td></tr>
                <tr><th>Remote address count</th>
                    <td><?php echo $status['remote_address_count'] !== null ? (int) $status['remote_address_count'] : '—'; ?></td></tr>
                <tr><th>Local managed count (helius_managed = 1)</th>
                    <td><?php echo (int) $status['local_managed_count']; ?></td></tr>
                <tr><th>Dedupe table size</th>
                    <td><?php echo $dedupeRows; ?> / <?php echo $dedupeAlarm; ?> <?php if ($dedupeRows > $dedupeAlarm): ?><strong style="color:#c00">OVERGROWN — sweep cron may have stalled</strong><?php endif; ?></td></tr>
                <tr><th>Counters (since last reset)</th>
                    <td>
                        deliveries new: <strong><?php echo $newCount; ?></strong> ·
                        replays blocked: <strong><?php echo $seenCount; ?></strong> ·
                        signature failures: <strong><?php echo $sigfailCount; ?></strong>
                    </td></tr>
                <tr><th title="The single 'is Solana ingestion alive?' signal — updated on every authenticated webhook delivery (including empty-payload pings).">Last delivery (X5 freshness)</th>
                    <td>
                        <?php
                        $fState = $freshness['state'];
                        $fAge   = $freshness['age_seconds'];
                        $fAt    = $freshness['last_delivery_at'];
                        if ($fState === 'not_provisioned') {
                            echo '<em>webhook not provisioned</em>';
                        } elseif ($fState === 'never_delivered') {
                            echo '<strong style="color:#9b6c00;">never — webhook provisioned but no deliveries yet</strong>';
                        } else {
                            $color = $fState === 'red' ? '#b32d2e' : ($fState === 'yellow' ? '#9b6c00' : '#46b450');
                            $atIso = $fAt !== null ? gmdate('c', (int) $fAt) : '?';
                            $ageStr = $fAge !== null ? NftIndexerHealthSnapshot::formatDuration((int) $fAge) : '?';
                            echo '<strong style="color:' . esc_attr($color) . ';">' . esc_html($ageStr) . ' ago</strong> ';
                            echo '<small>(' . esc_html($atIso) . ')</small>';
                            if ($fState === 'yellow') {
                                echo ' <small style="color:#9b6c00;">— could be a quiet window OR a stalled webhook; see top-card issue line</small>';
                            } elseif ($fState === 'red') {
                                echo ' <small style="color:#b32d2e;">— Solana ingestion is offline; see top-card issue line</small>';
                            }
                        }
                        ?>
                    </td></tr>
            </tbody>
        </table>

        <?php
        $provNonce   = wp_create_nonce('bcc_helius_provision');
        $resyncNonce = wp_create_nonce('bcc_helius_resync');
        $provUrl     = self::actionUrl(['action' => 'helius_provision', '_wpnonce' => $provNonce]);
        $resyncUrl   = self::actionUrl(['action' => 'helius_resync', '_wpnonce' => $resyncNonce]);
        ?>

        <p style="margin-top:16px">
            <?php if ($status['webhook_id'] === ''): ?>
                <a class="button button-primary" href="<?php echo esc_url($provUrl); ?>">Provision shared webhook</a>
                <small style="margin-left:8px">Creates the Helius webhook with the callback URL above and stores its ID.</small>
            <?php else: ?>
                <a class="button" href="<?php echo esc_url($resyncUrl); ?>">Resync addresses from wallet_links</a>
                <small style="margin-left:8px">Rebuilds the remote address list from the canonical local table. Use after <code>helius_managed</code> drift.</small>
            <?php endif; ?>
        </p>
        <?php
    }

    /**
     * @param list<HoldingRow>     $rows
     * @param array<int, string>   $slugByChainId
     */
    private static function renderSpamRecent(array $rows, array $slugByChainId): void
    {
        ?>
        <table class="widefat striped" style="max-width:900px">
            <thead>
                <tr>
                    <th>Chain</th>
                    <th>Contract</th>
                    <th>Token</th>
                    <th>Wallet link</th>
                    <th>Indexed</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="5"><em>No spam-flagged rows persisted yet.</em></td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r):
                        $cid  = (int) $r->chain_id;
                        $slug = $slugByChainId[$cid] ?? ('chain_id=' . $cid);
                    ?>
                        <tr>
                            <td><?php echo esc_html($slug); ?></td>
                            <td><code><?php echo esc_html((string) $r->contract_address); ?></code></td>
                            <td><?php echo esc_html((string) $r->token_id); ?></td>
                            <td>#<?php echo (int) $r->wallet_link_id; ?></td>
                            <td><?php echo esc_html((string) $r->indexed_at); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * GET-driven actions. Each is nonce-verified per chain so a stale
     * page can't trigger an action against a chain it didn't intend.
     */
    private static function handleActions(): void
    {
        if (!isset($_GET['action'])) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        $action  = sanitize_key((string) $_GET['action']);
        $chainId = isset($_GET['chain_id']) ? (int) $_GET['chain_id'] : 0;
        $nonce   = isset($_GET['_wpnonce']) ? (string) $_GET['_wpnonce'] : '';

        // Per-chain actions require a valid chain_id. Each branch below
        // also gates on $action so unknown values fall through harmlessly.
        $chainScopedActions = ['run', 'set_state'];
        if (in_array($action, $chainScopedActions, true) && $chainId <= 0) {
            return;
        }

        if ($action === 'run') {
            if (!wp_verify_nonce($nonce, 'bcc_nft_indexer_run_' . $chainId)) {
                return;
            }
            try {
                NftEthIndexerWorker::runForChain($chainId);
                add_settings_error('bcc_nft_indexer', 'ran', 'Indexer tick complete for chain ' . $chainId, 'updated');
            } catch (\Throwable $e) {
                add_settings_error('bcc_nft_indexer', 'failed', 'Indexer tick failed: ' . $e->getMessage(), 'error');
            }
            settings_errors('bcc_nft_indexer');
            return;
        }

        if ($action === 'set_state') {
            if (!wp_verify_nonce($nonce, 'bcc_nft_indexer_state_' . $chainId)) {
                return;
            }
            $newState = isset($_GET['state']) ? sanitize_key((string) $_GET['state']) : '';
            if (ChainCheckpointRepository::setState($chainId, $newState)) {
                add_settings_error('bcc_nft_indexer', 'state', 'Chain ' . $chainId . ' set to ' . $newState, 'updated');
            } else {
                add_settings_error('bcc_nft_indexer', 'state_failed', 'Invalid state.', 'error');
            }
            settings_errors('bcc_nft_indexer');
            return;
        }

        if ($action === 'helius_provision') {
            if (!wp_verify_nonce($nonce, 'bcc_helius_provision')) {
                return;
            }
            $callbackUrl = HeliusWebhookEndpoint::callbackUrl();
            $id = HeliusSubscriptionManager::provisionSharedWebhook($callbackUrl);
            if ($id !== null) {
                add_settings_error('bcc_nft_indexer', 'helius_ok', 'Helius webhook provisioned: ' . $id, 'updated');
            } else {
                add_settings_error('bcc_nft_indexer', 'helius_failed', 'Provisioning failed. Check BCC_HELIUS_API_KEY + BCC_HELIUS_WEBHOOK_SECRET in wp-config.php and the error log.', 'error');
            }
            settings_errors('bcc_nft_indexer');
            return;
        }

        if ($action === 'helius_resync') {
            if (!wp_verify_nonce($nonce, 'bcc_helius_resync')) {
                return;
            }
            $stats = HeliusSubscriptionManager::resyncFromWalletLinks();
            if ($stats === null) {
                add_settings_error('bcc_nft_indexer', 'helius_resync_failed', 'Resync failed — check the error log.', 'error');
            } else {
                $msg = sprintf(
                    'Resync %s. Remote: %d addresses · Local: %d addresses.',
                    $stats['applied'] ? 'applied' : 'no-op (already in sync)',
                    $stats['remote_count'],
                    $stats['local_count']
                );
                add_settings_error('bcc_nft_indexer', 'helius_resync_ok', $msg, 'updated');
            }
            settings_errors('bcc_nft_indexer');
            return;
        }
    }

    /**
     * @param array<string, scalar> $args
     */
    private static function actionUrl(array $args): string
    {
        return add_query_arg(
            array_merge(['page' => 'bcc-onchain-signals', 'tab' => 'nft'], $args),
            admin_url('admin.php')
        );
    }
}
