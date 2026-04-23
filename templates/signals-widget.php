<?php
// Variables: $page_id (int), $signals (array of rows from bcc_onchain_signals)
if (!defined('ABSPATH')) exit;

if (empty($signals)) {
    echo '<p class="bcc-onchain-empty">No on-chain signals available for this project yet.</p>';
    return;
}

$total_bonus = array_sum(array_column($signals, 'score_contribution'));

// Ownership check: only the page owner and admins see wallet addresses.
$_bcc_current_user = get_current_user_id();
$_bcc_post         = get_post($page_id);
$_bcc_is_owner     = $_bcc_post && (int) $_bcc_post->post_author === $_bcc_current_user;
$_bcc_is_admin     = current_user_can('manage_options');
$_bcc_show_addr    = $_bcc_is_owner || $_bcc_is_admin;
?>
<div class="bcc-onchain-widget">
    <div class="bcc-onchain-widget__header">
        <span class="bcc-onchain-widget__title">On-Chain Signals</span>
        <span class="bcc-onchain-widget__total">+<?php echo (float) round($total_bonus, 1); ?> pts</span>
    </div>

    <?php foreach ($signals as $row):
        $bd = \BCC\Trust\Onchain\Services\SignalScorer::breakdown($row);
        $chain_label = ucfirst($row['chain'] ?? 'Unknown');
        $age_years  = $row['wallet_age_days'] > 0 ? round($row['wallet_age_days'] / 365, 1) : null;

        // Only show wallet addresses to the page owner / admins.
        $addr       = $_bcc_show_addr ? ($row['wallet_address'] ?? '') : '';
        $short_addr = strlen($addr) > 12 ? substr($addr, 0, 6) . '…' . substr($addr, -4) : '';
    ?>
    <div class="bcc-onchain-chain-card">
        <div class="bcc-onchain-chain-card__header">
            <span class="bcc-onchain-chain-label"><?php echo esc_html($chain_label); ?></span>
            <?php if ($short_addr): ?>
                <span class="bcc-onchain-chain-addr" title="<?php echo esc_attr($addr); ?>"><?php echo esc_html($short_addr); ?></span>
            <?php endif; ?>
            <span class="bcc-onchain-chain-score">+<?php echo (float) round($row['score_contribution'], 1); ?> pts</span>
        </div>

        <div class="bcc-onchain-signals-grid">
            <!-- Wallet age -->
            <div class="bcc-onchain-signal">
                <div class="bcc-onchain-signal__label">Wallet Age</div>
                <div class="bcc-onchain-signal__value">
                    <?php echo $age_years !== null ? esc_html($age_years) . ' yrs' : '—'; ?>
                </div>
                <div class="bcc-onchain-signal__bar">
                    <div class="bcc-onchain-signal__bar-fill"
                         style="width:<?php echo (int) round(($bd['age_score'] / BCC_ONCHAIN_MAX_AGE_SCORE) * 100); ?>%"></div>
                </div>
                <div class="bcc-onchain-signal__pts">+<?php echo (float) $bd['age_score']; ?></div>
            </div>

            <!-- Tx depth -->
            <div class="bcc-onchain-signal">
                <div class="bcc-onchain-signal__label">Transactions</div>
                <div class="bcc-onchain-signal__value">
                    <?php echo number_format((int) $row['tx_count']); ?>
                </div>
                <div class="bcc-onchain-signal__bar">
                    <div class="bcc-onchain-signal__bar-fill"
                         style="width:<?php echo (int) round(($bd['depth_score'] / BCC_ONCHAIN_MAX_DEPTH_SCORE) * 100); ?>%"></div>
                </div>
                <div class="bcc-onchain-signal__pts">+<?php echo (float) $bd['depth_score']; ?></div>
            </div>

            <!-- Contracts -->
            <div class="bcc-onchain-signal">
                <div class="bcc-onchain-signal__label">Contracts Deployed</div>
                <div class="bcc-onchain-signal__value">
                    <?php echo (int) $row['contract_count']; ?>
                </div>
                <div class="bcc-onchain-signal__bar">
                    <div class="bcc-onchain-signal__bar-fill"
                         style="width:<?php echo (int) ($bd['contract_score'] > 0 ? round(($bd['contract_score'] / BCC_ONCHAIN_MAX_CONTRACT_SCORE) * 100) : 0); ?>%"></div>
                </div>
                <div class="bcc-onchain-signal__pts">+<?php echo (float) $bd['contract_score']; ?></div>
            </div>
        </div>

        <?php if ($row['fetched_at']): ?>
        <div class="bcc-onchain-chain-card__footer">
            Last updated: <?php echo esc_html(human_time_diff(strtotime($row['fetched_at']), current_time('timestamp'))); ?> ago
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
