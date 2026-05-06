<?php

namespace BCC\Trust\Onchain\Admin\Views;

use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\NftHoldingsRepository;
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

        $dailyBudget = defined('BCC_ETH_DAILY_RPC_BUDGET')
            ? (int) constant('BCC_ETH_DAILY_RPC_BUDGET')
            : NftEthIndexerWorker::DEFAULT_DAILY_BUDGET;
        ?>
        <h2>NFT Indexer (Confirmation-Gated)</h2>
        <p>
            Walks <code>alchemy_getAssetTransfers</code> at
            <strong>N=<?php echo NftEthIndexerWorker::CONFIRMATIONS; ?> confirmations</strong>
            per tick. ~2-minute write lag is the locked tradeoff —
            phantom ownership state is unacceptable. Daily CU budget
            cap: <strong><?php echo number_format($dailyBudget); ?> CU/day</strong>
            (one <code>getAssetTransfers</code> call = 120 CU).
        </p>

        <table class="widefat striped" style="max-width:1100px">
            <thead>
                <tr>
                    <th>Chain</th>
                    <th>State</th>
                    <th>Last processed</th>
                    <th>Head</th>
                    <th>Lag (blocks)</th>
                    <th>CU used today</th>
                    <th>Last run</th>
                    <th>Last error</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!is_array($chains) || $chains === []): ?>
                    <tr><td colspan="9"><em>No active EVM chains.</em></td></tr>
                <?php else: ?>
                    <?php foreach ($chains as $chain):
                        $cid     = (int) ($chain->id ?? 0);
                        $cp      = $byChain[$cid] ?? null;
                        $state   = $cp ? (string) $cp->state : ChainCheckpointRepository::STATE_DISABLED;
                        $last    = $cp ? (int) $cp->last_processed_block : 0;
                        $head    = $cp ? (int) $cp->head_block : 0;
                        $lag     = max(0, $head - $last);
                        $cuUsed  = $cp ? (int) $cp->cu_used_today : 0;
                        $lastRun = $cp && $cp->last_run_at ? (string) $cp->last_run_at : '—';
                        $lastErr = $cp && $cp->last_error ? (string) $cp->last_error : '—';

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
                        <td><strong><?php echo esc_html((string) ($chain->slug ?? '')); ?></strong></td>
                        <td><?php echo esc_html($state); ?></td>
                        <td><?php echo $last; ?></td>
                        <td><?php echo $head; ?></td>
                        <td><?php echo $lag; ?></td>
                        <td><?php echo $cuUsed; ?> / <?php echo $dailyBudget; ?></td>
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

        <h3 style="margin-top:32px">Recent spam-flagged rows</h3>
        <p>Persisted with <code>metadata_status = 2</code> for review. Never visible to user-facing surfaces.</p>
        <?php self::renderSpamRecent($chains); ?>

        <p style="margin-top:32px"><small>
            Plan reference: <code>v2-phase-1-nft-scaling.md</code> · Worker:
            <code>BCC\Trust\Onchain\Workers\NftEthIndexerWorker</code> ·
            Tick: <code>bcc_nft_eth_indexer_tick</code> (every minute)
        </small></p>
        <?php
    }

    /**
     * @param list<object> $chains
     */
    private static function renderSpamRecent(array $chains): void
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
                <?php
                $total = 0;
                foreach ($chains as $chain) {
                    if (!is_object($chain)) {
                        continue;
                    }
                    $cid  = (int) ($chain->id ?? 0);
                    $rows = NftHoldingsRepository::findByStatus($cid, NftHoldingsRepository::STATUS_SPAM, 10);
                    foreach ($rows as $r) {
                        $total++;
                        ?>
                        <tr>
                            <td><?php echo esc_html((string) ($chain->slug ?? '')); ?></td>
                            <td><code><?php echo esc_html((string) $r->contract_address); ?></code></td>
                            <td><?php echo esc_html((string) $r->token_id); ?></td>
                            <td>#<?php echo (int) $r->wallet_link_id; ?></td>
                            <td><?php echo esc_html((string) $r->indexed_at); ?></td>
                        </tr>
                        <?php
                    }
                }
                if ($total === 0) {
                    ?>
                    <tr><td colspan="5"><em>No spam-flagged rows persisted yet.</em></td></tr>
                    <?php
                }
                ?>
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
        if ($chainId <= 0) {
            return;
        }

        $nonce = isset($_GET['_wpnonce']) ? (string) $_GET['_wpnonce'] : '';

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
