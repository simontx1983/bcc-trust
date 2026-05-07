<?php

namespace BCC\Trust\Onchain\Admin\Views;

use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\NftSpamContractRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Spam-contract admin sub-tab (V2 Phase 1a).
 *
 * Read + write surface for the deny/allow rules consumed by
 * NftSpamFilter on the indexer write path.
 *
 * GET-handled add / remove with nonces. Form posts back to the same
 * sub-tab so the redirect-after-success / redirect-after-error
 * pattern keeps the URL clean for bookmarking.
 *
 * @phpstan-import-type ChainRow from ChainRepository
 * @phpstan-import-type SpamContractRow from NftSpamContractRepository
 */
final class NftSpamContractsView
{
    public static function render(): void
    {
        self::handleActions();

        $rules  = NftSpamContractRepository::findAll(200);
        $chains = ChainRepository::getActive();

        // chain_id → slug map for display.
        $slugMap = [];
        foreach ($chains as $chain) {
            $slugMap[(int) $chain->id] = (string) $chain->slug;
        }
        ?>
        <h2>NFT Spam-Contract Rules</h2>
        <p>
            Indexer-write-path filter. Rule resolution is
            <code>allow</code> &gt; <code>deny</code> &gt; heuristics.
            Spam-flagged rows persist with <code>metadata_status=2</code>
            for audit; user-facing reads filter them out structurally.
        </p>

        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin-bottom:24px">
            <input type="hidden" name="page" value="bcc-onchain-signals">
            <input type="hidden" name="tab" value="spam">
            <input type="hidden" name="action" value="add">
            <?php wp_nonce_field('bcc_nft_spam_add', '_wpnonce'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="bcc-spam-chain">Chain</label></th>
                    <td>
                        <select name="chain_id" id="bcc-spam-chain" required>
                            <option value="">— Select —</option>
                            <?php foreach ($slugMap as $cid => $slug): ?>
                                <option value="<?php echo (int) $cid; ?>"><?php echo esc_html($slug); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="bcc-spam-contract">Contract address</label></th>
                    <td><input type="text" id="bcc-spam-contract" name="contract" class="regular-text" placeholder="0x…" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="bcc-spam-rule">Rule</label></th>
                    <td>
                        <select name="rule" id="bcc-spam-rule">
                            <option value="deny">deny</option>
                            <option value="allow">allow</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="bcc-spam-reason">Reason (optional)</label></th>
                    <td><input type="text" id="bcc-spam-reason" name="reason" class="regular-text" placeholder="airdrop spam — visit-link in name"></td>
                </tr>
            </table>
            <p class="submit"><button type="submit" class="button button-primary">Add rule</button></p>
        </form>

        <h3>Active rules</h3>
        <table class="widefat striped" style="max-width:900px">
            <thead>
                <tr>
                    <th>Chain</th>
                    <th>Contract</th>
                    <th>Rule</th>
                    <th>Reason</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rules === []): ?>
                    <tr><td colspan="6"><em>No rules configured.</em></td></tr>
                <?php else: ?>
                    <?php foreach ($rules as $r):
                        $cid     = (int) $r->chain_id;
                        $slug    = $slugMap[$cid] ?? ('chain_id=' . $cid);
                        $contract = (string) $r->contract_address;
                        $removeNonce = wp_create_nonce('bcc_nft_spam_remove_' . $cid . '_' . $contract);
                        $removeUrl   = add_query_arg([
                            'page'     => 'bcc-onchain-signals',
                            'tab'      => 'spam',
                            'action'   => 'remove',
                            'chain_id' => $cid,
                            'contract' => $contract,
                            '_wpnonce' => $removeNonce,
                        ], admin_url('admin.php'));
                    ?>
                    <tr>
                        <td><?php echo esc_html($slug); ?></td>
                        <td><code><?php echo esc_html($contract); ?></code></td>
                        <td><strong><?php echo esc_html((string) $r->rule); ?></strong></td>
                        <td><?php echo $r->reason !== null ? esc_html((string) $r->reason) : '—'; ?></td>
                        <td><?php echo esc_html((string) $r->created_at); ?></td>
                        <td><a class="button" href="<?php echo esc_url($removeUrl); ?>">Remove</a></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    private static function handleActions(): void
    {
        if (!isset($_GET['action'])) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        $action = sanitize_key((string) $_GET['action']);
        $nonce  = isset($_GET['_wpnonce']) ? (string) $_GET['_wpnonce'] : '';

        if ($action === 'add') {
            if (!wp_verify_nonce($nonce, 'bcc_nft_spam_add')) {
                return;
            }
            $cid      = isset($_GET['chain_id']) ? (int) $_GET['chain_id'] : 0;
            $contract = isset($_GET['contract']) ? trim(sanitize_text_field((string) $_GET['contract'])) : '';
            $rule     = isset($_GET['rule']) ? sanitize_key((string) $_GET['rule']) : '';
            $reason   = isset($_GET['reason']) ? sanitize_text_field((string) $_GET['reason']) : '';

            if ($cid <= 0 || $contract === '' || !in_array($rule, ['deny', 'allow'], true)) {
                add_settings_error('bcc_nft_spam', 'add_invalid', 'Missing required fields.', 'error');
            } elseif (NftSpamContractRepository::addRule($cid, $contract, $rule, $reason)) {
                add_settings_error('bcc_nft_spam', 'add_ok', 'Rule saved.', 'updated');
            } else {
                add_settings_error('bcc_nft_spam', 'add_failed', 'Failed to save rule.', 'error');
            }
            settings_errors('bcc_nft_spam');
            return;
        }

        if ($action === 'remove') {
            $cid      = isset($_GET['chain_id']) ? (int) $_GET['chain_id'] : 0;
            $contract = isset($_GET['contract']) ? (string) $_GET['contract'] : '';
            if ($cid <= 0 || $contract === '') {
                return;
            }
            if (!wp_verify_nonce($nonce, 'bcc_nft_spam_remove_' . $cid . '_' . $contract)) {
                return;
            }
            if (NftSpamContractRepository::removeRule($cid, $contract)) {
                add_settings_error('bcc_nft_spam', 'remove_ok', 'Rule removed.', 'updated');
            } else {
                add_settings_error('bcc_nft_spam', 'remove_failed', 'Rule not found.', 'error');
            }
            settings_errors('bcc_nft_spam');
            return;
        }
    }
}
