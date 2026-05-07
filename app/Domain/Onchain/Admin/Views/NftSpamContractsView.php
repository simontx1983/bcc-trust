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
 * POST-handled add / remove. State-changing actions use POST + an
 * action-bound nonce verified via check_admin_referer, mirroring the
 * DisputeAdmin pattern. Forms omit the action= attribute so the
 * browser posts back to the current admin URL (which carries
 * ?page=bcc-onchain-signals&tab=spam in the query string).
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

        <form method="post" style="margin-bottom:24px">
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
                    ?>
                    <tr>
                        <td><?php echo esc_html($slug); ?></td>
                        <td><code><?php echo esc_html($contract); ?></code></td>
                        <td><strong><?php echo esc_html((string) $r->rule); ?></strong></td>
                        <td><?php echo $r->reason !== null ? esc_html((string) $r->reason) : '—'; ?></td>
                        <td><?php echo esc_html((string) $r->created_at); ?></td>
                        <td>
                            <form method="post" style="display:inline" onsubmit="return confirm('Remove this rule?');">
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="chain_id" value="<?php echo (int) $cid; ?>">
                                <input type="hidden" name="contract" value="<?php echo esc_attr($contract); ?>">
                                <?php wp_nonce_field('bcc_nft_spam_remove_' . $cid . '_' . $contract, '_wpnonce'); ?>
                                <button type="submit" class="button">Remove</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    private static function handleActions(): void
    {
        if (!isset($_POST['action'])) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        $action = sanitize_key((string) $_POST['action']);

        if ($action === 'add') {
            // Action-bound nonce; check_admin_referer wp_die()s on failure
            // (403) so we never reach the write path with a forged or
            // missing nonce. Mirrors DisputeAdmin::handle_actions.
            check_admin_referer('bcc_nft_spam_add');

            $cid      = isset($_POST['chain_id']) ? (int) $_POST['chain_id'] : 0;
            $contract = isset($_POST['contract']) ? trim(sanitize_text_field((string) $_POST['contract'])) : '';
            $rule     = isset($_POST['rule']) ? sanitize_key((string) $_POST['rule']) : '';
            $reason   = isset($_POST['reason']) ? sanitize_text_field((string) $_POST['reason']) : '';

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
            $cid      = isset($_POST['chain_id']) ? (int) $_POST['chain_id'] : 0;
            $contract = isset($_POST['contract']) ? (string) $_POST['contract'] : '';
            if ($cid <= 0 || $contract === '') {
                return;
            }
            check_admin_referer('bcc_nft_spam_remove_' . $cid . '_' . $contract);

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
