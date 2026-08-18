<?php

namespace BCC\Trust\Onchain\Admin\Views;

use BCC\Trust\Onchain\Admin\AdminActionSupport;
use BCC\Trust\Onchain\Admin\SettingsPage;
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
 * Surfaces per-chain checkpoint, lag, CU budget, breaker state, last error.
 *
 * Batch 1 (safety hardening) changed how the operator controls dispatch —
 * not what they do:
 *   - "Run now", "Pause"/"Resume", "Provision shared webhook" and "Resync
 *     addresses" were GET links handled during render. A refresh replayed
 *     them, and two of them mutate EXTERNAL Helius state. All four are now
 *     POST forms → admin-post.php → PRG.
 *   - Nonce/capability failures used to `return` silently, rendering a normal
 *     page while the operator believed the action ran. They now halt with 403.
 *   - Every action writes a durable audit row; none did before.
 *   - The indexer's per-chain advisory lock, BLOCKS_PER_TICK, daily CU budget
 *     and checkpoint semantics are untouched — dispatch changed, work did not.
 *
 * @phpstan-import-type ChainRow from ChainRepository
 * @phpstan-import-type CheckpointRow from ChainCheckpointRepository
 * @phpstan-import-type HoldingRow from NftHoldingsRepository
 */
final class NftIndexerStatusView
{
    public static function render(): void
    {
        // Actions dispatch through admin-post.php and redirect back here;
        // this only replays the outcome from the redirect args.
        self::renderResultNotice();

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
                        <td><?php self::renderChainActionForms($cid, $state, (string) $chain->slug); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <h3 style="margin-top:32px">V1 Discovery (Alchemy NFT API — rolling 1h)</h3>
        <?php self::renderV1DiscoveryPanel($summary['v1_fetch_stats_by_chain']); ?>

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
     *     v1_fetch_failure_chains: list<string>,
     *     v1_fetch_stats_by_chain: array<int, array{slug: string, attempts: int, failures: int, last_error: string|null, last_error_at: int|null}>,
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

                <?php if ($summary['v1_fetch_failure_chains'] !== []): ?>
                    <span style="color:#9b6c00;" title="V1 ownership discovery (Alchemy NFT API) failing on one or more chains. See V1 Discovery panel below."><strong>V1 failures:</strong> <?php echo (int) count($summary['v1_fetch_failure_chains']); ?></span>
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
     * V1 NFT-discovery operator surface (X4).
     *
     * Renders per-chain rolling 1h `fetch_collections` attempts / failures
     * / failure rate + the most recent error message per chain. Reads
     * from the same summary payload the top-card signals use (single
     * source of truth — no recomputation).
     *
     * Operator question this answers: "is V1 ownership discovery
     * actually working right now, and if not, which chain and what's
     * the upstream error?"
     *
     * @param array<int, array{slug: string, attempts: int, failures: int, last_error: string|null, last_error_at: int|null}> $statsByChain
     */
    private static function renderV1DiscoveryPanel(array $statsByChain): void
    {
        if ($statsByChain === []) {
            echo '<p><em>No V1 attempts recorded yet — counters start once `fetch_collections` runs against an EVM chain (gallery refresh, wallet seed, or 4h TTL cron).</em></p>';
            return;
        }
        $yellowRatio = NftIndexerHealthSnapshot::V1_FETCH_FAILURE_YELLOW_RATIO;
        $minSamples  = NftIndexerHealthSnapshot::V1_FETCH_FAILURE_MIN_SAMPLES;
        ?>
        <p>
            Per-chain attempts and transport failures for the V1 ownership
            discovery path (<code>EvmFetcher::fetch_collections</code> via
            Alchemy NFT API <code>getContractsForOwner</code>). Counters are
            rolling 1h via wp-cache. YELLOW fires at &ge;<?php echo (int) ($yellowRatio * 100); ?>%
            failure rate with min <?php echo (int) $minSamples; ?> samples.
        </p>
        <table class="widefat striped" style="max-width:1100px">
            <thead>
                <tr>
                    <th>Chain</th>
                    <th>Attempts (1h)</th>
                    <th>Failures (1h)</th>
                    <th>Failure rate</th>
                    <th>Last error</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($statsByChain as $row):
                    $attempts = (int) $row['attempts'];
                    $failures = (int) $row['failures'];
                    $rate     = $attempts > 0 ? ($failures / $attempts) : 0.0;
                    $hot      = $attempts >= $minSamples && $rate >= $yellowRatio;
                    $lastErr  = $row['last_error'];
                    $lastAt   = $row['last_error_at'];
                ?>
                <tr<?php echo $hot ? ' style="background:#fff8e5;"' : ''; ?>>
                    <td><strong><?php echo esc_html($row['slug']); ?></strong></td>
                    <td><?php echo $attempts; ?></td>
                    <td<?php echo $hot ? ' style="color:#b32d2e;font-weight:bold;"' : ''; ?>><?php echo $failures; ?></td>
                    <td<?php echo $hot ? ' style="color:#b32d2e;font-weight:bold;"' : ''; ?>>
                        <?php
                        if ($attempts === 0) {
                            echo '<small style="color:#999;">no samples</small>';
                        } elseif ($attempts < $minSamples) {
                            printf('<small style="color:#999;">%d%% (insufficient samples)</small>', (int) round($rate * 100));
                        } else {
                            printf('%d%%', (int) round($rate * 100));
                        }
                        ?>
                    </td>
                    <td>
                        <?php if ($lastErr === null || $lastAt === null): ?>
                            <small style="color:#999;">—</small>
                        <?php else: ?>
                            <small><?php echo esc_html($lastErr); ?></small>
                            <br><small style="color:#999;">at <?php echo esc_html(gmdate('c', $lastAt)); ?></small>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
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
        self::renderHeliusActionForm((string) $status['webhook_id']);
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

    // ────────────────────────────────────────────────────────────
    // Action controls (extracted so the confirmation copy and the POST
    // wiring are assertable without rendering the whole page)
    // ────────────────────────────────────────────────────────────

    /**
     * Confirmation copy for the per-chain Run / Pause / Resume controls.
     *
     * Pure. Pause deliberately names the indefinite consequence — the state
     * has no expiry and nothing resumes it automatically.
     *
     * @return array{run: string, state: string}
     */
    public static function chainActionConfirmations(string $slug, bool $isResume): array
    {
        return [
            'run' => sprintf(
                "Run an indexer tick for %s now?\n\n"
                . 'Calls the Alchemy transfers API and consumes this chain\'s daily CU budget.',
                $slug
            ),
            'state' => $isResume
                ? sprintf('Resume indexing for %s?', $slug)
                : sprintf(
                    "Pause the indexer for %s?\n\n"
                    . 'It will stop advancing indefinitely — there is no automatic resume — '
                    . 'and NFT ownership for this chain will go stale until someone resumes it.',
                    $slug
                ),
        ];
    }

    /**
     * Per-chain Run now + Pause/Resume forms.
     *
     * Both POST to admin-post.php. The state form's nonce is bound to the
     * chain AND the target state, so a Pause nonce cannot drive a Resume.
     */
    public static function renderChainActionForms(int $chainId, string $state, string $slug): void
    {
        $isResume  = $state === ChainCheckpointRepository::STATE_DISABLED;
        $pauseTo   = $isResume
            ? ChainCheckpointRepository::STATE_HEALTHY
            : ChainCheckpointRepository::STATE_DISABLED;
        $label     = $isResume ? 'Resume' : 'Pause';
        $confirms  = self::chainActionConfirmations($slug, $isResume);
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
              style="display:inline;margin:0;"
              onsubmit="return confirm(<?php echo esc_attr(AdminActionSupport::confirmLiteral($confirms['run'])); ?>);">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_RUN); ?>">
            <input type="hidden" name="chain_id" value="<?php echo (int) $chainId; ?>">
            <?php wp_nonce_field(self::ACTION_RUN . '_' . $chainId); ?>
            <button type="submit" class="button button-secondary">Run now</button>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
              style="display:inline;margin:0;"
              onsubmit="return confirm(<?php echo esc_attr(AdminActionSupport::confirmLiteral($confirms['state'])); ?>);">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_SET_STATE); ?>">
            <input type="hidden" name="chain_id" value="<?php echo (int) $chainId; ?>">
            <input type="hidden" name="state" value="<?php echo esc_attr($pauseTo); ?>">
            <?php wp_nonce_field(self::ACTION_SET_STATE . '_' . $chainId . '_' . $pauseTo); ?>
            <button type="submit" class="button"><?php echo esc_html($label); ?></button>
        </form>
        <?php
    }

    /**
     * Confirmation copy for the two Helius controls.
     *
     * Both mutate state on Helius, not just locally — the copy says so,
     * because the labels alone read like local operations.
     *
     * @return array{provision: string, resync: string}
     */
    public static function heliusConfirmations(): array
    {
        return [
            'provision' => "Create a new shared webhook on Helius?\n\n"
                . 'This calls the Helius API and creates a billable external resource '
                . 'pointing at the callback URL above.',
            'resync' => "Repoint the remote Helius webhook's address list?\n\n"
                . 'This PATCHes the live webhook on Helius so its address list matches this '
                . 'site\'s wallet_links table. Solana ingestion follows the remote list.',
        ];
    }

    /**
     * Provision (when no webhook exists) or Resync (when one does).
     */
    public static function renderHeliusActionForm(string $webhookId): void
    {
        $confirms = self::heliusConfirmations();
        ?>
        <p style="margin-top:16px">
            <?php if ($webhookId === ''): ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                      style="display:inline;margin:0;"
                      onsubmit="return confirm(<?php echo esc_attr(AdminActionSupport::confirmLiteral($confirms['provision'])); ?>);">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_HELIUS_PROVISION); ?>">
                    <?php wp_nonce_field(self::ACTION_HELIUS_PROVISION); ?>
                    <button type="submit" class="button button-primary">Provision shared webhook</button>
                </form>
                <small style="margin-left:8px">Creates the Helius webhook with the callback URL above and stores its ID.</small>
            <?php else: ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                      style="display:inline;margin:0;"
                      onsubmit="return confirm(<?php echo esc_attr(AdminActionSupport::confirmLiteral($confirms['resync'])); ?>);">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_HELIUS_RESYNC); ?>">
                    <?php wp_nonce_field(self::ACTION_HELIUS_RESYNC); ?>
                    <button type="submit" class="button">Resync addresses from wallet_links</button>
                </form>
                <small style="margin-left:8px">Rebuilds the remote address list from the canonical local table. Use after <code>helius_managed</code> drift.</small>
            <?php endif; ?>
        </p>
        <?php
    }

    // ────────────────────────────────────────────────────────────
    // admin-post handlers (Batch 1: were GET actions handled in render)
    // ────────────────────────────────────────────────────────────

    public const ACTION_RUN               = 'bcc_nft_indexer_run';
    public const ACTION_SET_STATE         = 'bcc_nft_indexer_set_state';
    public const ACTION_HELIUS_PROVISION  = 'bcc_helius_provision';
    public const ACTION_HELIUS_RESYNC     = 'bcc_helius_resync';

    public static function register_actions(): void
    {
        add_action('admin_post_' . self::ACTION_RUN,              [self::class, 'handleRun']);
        add_action('admin_post_' . self::ACTION_SET_STATE,        [self::class, 'handleSetState']);
        add_action('admin_post_' . self::ACTION_HELIUS_PROVISION, [self::class, 'handleHeliusProvision']);
        add_action('admin_post_' . self::ACTION_HELIUS_RESYNC,    [self::class, 'handleHeliusResync']);
    }

    /**
     * Manual indexer tick for one chain.
     *
     * Bounds are the worker's, unchanged: non-blocking per-chain
     * AdvisoryLock, BLOCKS_PER_TICK, and the daily CU budget gate.
     */
    public static function handleRun(): void
    {
        AdminActionSupport::requireCapability();

        // Shape check only. The nonce is derived from this id, so it must be
        // read first — but NOTHING may touch the database until the request
        // has proven itself authentic.
        $chainId = self::requireChainIdShape();
        AdminActionSupport::requireNonce(self::ACTION_RUN . '_' . $chainId);
        self::requireResolvableChain($chainId);

        try {
            NftEthIndexerWorker::runForChain($chainId);
        } catch (\Throwable $e) {
            // Previously rendered `$e->getMessage()` verbatim — the strongest
            // raw-exception-to-browser path in the on-chain admin.
            $ref = AdminActionSupport::failure($e, 'admin_nft_indexer_run_failed', 'chain', $chainId);
            self::redirect('run_failed', $chainId, $ref);
        }

        AdminActionSupport::audit('admin_nft_indexer_run', 'chain', $chainId);
        self::redirect('ran', $chainId);
    }

    /**
     * Pause / Resume one chain's indexer.
     *
     * Pause and Resume deliberately share this route (they are one state
     * transition with two targets), but the nonce is bound to BOTH the chain
     * and the requested state, so a Pause nonce cannot drive a Resume.
     */
    public static function handleSetState(): void
    {
        AdminActionSupport::requireCapability();

        // Shape + allowlist first: both the chain id and the target state feed
        // the nonce action, so both must be read before it can be built — but
        // neither may reach a repository until the nonce has been verified.
        $chainId  = self::requireChainIdShape();
        $newState = isset($_POST['state']) ? sanitize_key((string) $_POST['state']) : '';

        $allowed = [ChainCheckpointRepository::STATE_HEALTHY, ChainCheckpointRepository::STATE_DISABLED];
        if (!in_array($newState, $allowed, true)) {
            // Reject before the nonce action can be built from an
            // attacker-chosen state string.
            self::redirect('state_invalid', $chainId);
        }

        AdminActionSupport::requireNonce(self::ACTION_SET_STATE . '_' . $chainId . '_' . $newState);
        self::requireResolvableChain($chainId);

        if (!ChainCheckpointRepository::setState($chainId, $newState)) {
            AdminActionSupport::audit('admin_nft_indexer_state_failed', 'chain', $chainId, ['state' => $newState]);
            self::redirect('state_invalid', $chainId);
        }

        AdminActionSupport::audit(
            $newState === ChainCheckpointRepository::STATE_DISABLED
                ? 'admin_nft_indexer_paused'
                : 'admin_nft_indexer_resumed',
            'chain',
            $chainId,
            ['state' => $newState]
        );

        self::redirect($newState === ChainCheckpointRepository::STATE_DISABLED ? 'paused' : 'resumed', $chainId);
    }

    /**
     * Create the shared Helius webhook. Mutates EXTERNAL provider state.
     */
    public static function handleHeliusProvision(): void
    {
        AdminActionSupport::requireCapability();
        AdminActionSupport::requireNonce(self::ACTION_HELIUS_PROVISION);

        try {
            $id = HeliusSubscriptionManager::provisionSharedWebhook(HeliusWebhookEndpoint::callbackUrl());
        } catch (\Throwable $e) {
            $ref = AdminActionSupport::failure($e, 'admin_helius_webhook_provision_failed', 'helius_webhook');
            self::redirect('helius_provision_failed', 0, $ref);
        }

        if ($id === null) {
            AdminActionSupport::audit('admin_helius_webhook_provision_failed', 'helius_webhook');
            self::redirect('helius_provision_failed', 0);
        }

        AdminActionSupport::audit('admin_helius_webhook_provisioned', 'helius_webhook');
        self::redirect('helius_provisioned', 0);
    }

    /**
     * Repoint the shared Helius webhook's address list. Mutates EXTERNAL
     * provider state. Idempotent — reports a no-op when already in sync.
     */
    public static function handleHeliusResync(): void
    {
        AdminActionSupport::requireCapability();
        AdminActionSupport::requireNonce(self::ACTION_HELIUS_RESYNC);

        try {
            $stats = HeliusSubscriptionManager::resyncFromWalletLinks();
        } catch (\Throwable $e) {
            $ref = AdminActionSupport::failure($e, 'admin_helius_resync_failed', 'helius_webhook');
            self::redirect('helius_resync_failed', 0, $ref);
        }

        if ($stats === null) {
            AdminActionSupport::audit('admin_helius_resync_failed', 'helius_webhook');
            self::redirect('helius_resync_failed', 0);
        }

        $applied = (bool) $stats['applied'];
        AdminActionSupport::audit(
            $applied ? 'admin_helius_addresses_resynced' : 'admin_helius_resync_noop',
            'helius_webhook',
            null,
            ['remote_count' => (int) $stats['remote_count'], 'local_count' => (int) $stats['local_count']]
        );

        AdminActionSupport::redirect([
            'page'            => SettingsPage::PAGE_SLUG,
            'tab'             => 'nft',
            'bcc_nft_result'  => $applied ? 'helius_resynced' : 'helius_resync_noop',
            'bcc_remote'      => (int) $stats['remote_count'],
            'bcc_local'       => (int) $stats['local_count'],
        ]);
    }

    /**
     * Shape-only validation of chain_id — runs BEFORE the nonce check.
     *
     * The target-scoped nonce action is built from this id, so it has to be
     * read first. Deliberately does no repository work: an unauthenticated
     * request must not be able to probe which chain ids exist, and no domain
     * code should run for a request that fails CSRF validation.
     */
    private static function requireChainIdShape(): int
    {
        $chainId = isset($_POST['chain_id']) ? (int) $_POST['chain_id'] : 0;

        if ($chainId <= 0) {
            wp_die(
                esc_html__('Invalid chain.', 'bcc-trust'),
                esc_html__('Bad Request', 'bcc-trust'),
                ['response' => 400]
            );
        }

        return $chainId;
    }

    /**
     * Authoritative target resolution — runs AFTER the nonce check.
     *
     * A positive integer is not a target; the chain must exist. Separated
     * from the shape check so this repository read happens only for a
     * request already proven authentic.
     */
    private static function requireResolvableChain(int $chainId): void
    {
        if (ChainRepository::getById($chainId) === null) {
            wp_die(
                esc_html__('Unknown chain.', 'bcc-trust'),
                esc_html__('Bad Request', 'bcc-trust'),
                ['response' => 400]
            );
        }
    }

    /**
     * PRG terminator back to the NFT tab.
     *
     * `never` so a guard like `if ($stats === null) { self::redirect(...); }`
     * narrows the value for the code that follows.
     */
    private static function redirect(string $result, int $chainId = 0, string $ref = ''): never
    {
        $args = [
            'page'           => SettingsPage::PAGE_SLUG,
            'tab'            => 'nft',
            'bcc_nft_result' => $result,
        ];
        if ($chainId > 0) {
            $args['bcc_chain'] = $chainId;
        }
        if ($ref !== '') {
            $args['bcc_ref'] = $ref;
        }

        AdminActionSupport::redirect($args);
    }

    /**
     * Render the result notice from the PRG redirect args.
     */
    private static function renderResultNotice(): void
    {
        $result = isset($_GET['bcc_nft_result']) ? sanitize_key((string) $_GET['bcc_nft_result']) : '';
        if ($result === '') {
            return;
        }

        $chainId = isset($_GET['bcc_chain']) ? (int) $_GET['bcc_chain'] : 0;
        $ref     = isset($_GET['bcc_ref']) ? sanitize_text_field((string) $_GET['bcc_ref']) : '';

        $notices = [
            'ran'                     => ['updated', sprintf('Indexer tick complete for chain %d.', $chainId)],
            'paused'                  => ['updated', sprintf('Chain %d paused — its indexer will not advance until resumed.', $chainId)],
            'resumed'                 => ['updated', sprintf('Chain %d resumed.', $chainId)],
            'state_invalid'           => ['error',   'Invalid state — no change was made.'],
            'helius_provisioned'      => ['updated', 'Helius shared webhook provisioned.'],
            'helius_provision_failed' => ['error',   'Provisioning failed. Check BCC_HELIUS_API_KEY + BCC_HELIUS_WEBHOOK_SECRET in wp-config.php.'],
            'helius_resync_failed'    => ['error',   'Resync failed.'],
        ];

        if ($result === 'helius_resynced' || $result === 'helius_resync_noop') {
            $remote = isset($_GET['bcc_remote']) ? (int) $_GET['bcc_remote'] : 0;
            $local  = isset($_GET['bcc_local']) ? (int) $_GET['bcc_local'] : 0;
            $notices[$result] = ['updated', sprintf(
                'Resync %s. Remote: %d addresses · Local: %d addresses.',
                $result === 'helius_resynced' ? 'applied' : 'no-op (already in sync)',
                $remote,
                $local
            )];
        }

        if ($result === 'run_failed') {
            $notices['run_failed'] = ['error', AdminActionSupport::failureMessage($ref)];
        }

        if (!isset($notices[$result])) {
            return;
        }

        [$type, $message] = $notices[$result];
        if ($ref !== '' && $type === 'error' && $result !== 'run_failed') {
            $message .= ' ' . AdminActionSupport::failureMessage($ref);
        }

        add_settings_error('bcc_nft_indexer', $result, $message, $type);
        settings_errors('bcc_nft_indexer');
    }
}
