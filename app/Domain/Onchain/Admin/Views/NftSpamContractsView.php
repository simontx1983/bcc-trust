<?php

namespace BCC\Trust\Onchain\Admin\Views;

use BCC\Trust\Onchain\Admin\AdminActionSupport;
use BCC\Trust\Onchain\Admin\SettingsPage;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\NftSpamContractRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Spam-contract admin sub-tab (V2 Phase 1a).
 *
 * Read + write surface for the deny/allow rules consumed by
 * NftSpamFilter on the indexer write path. These rules govern what every
 * user sees, so both mutations are audited.
 *
 * Batch 1 (safety hardening):
 *   - Both actions already used POST with action-scoped nonces; that is
 *     unchanged. They now dispatch via admin-post.php and PRG, so a refresh
 *     no longer re-POSTs through the browser's resubmit dialog.
 *   - A capability failure used to `return` silently, rendering a normal page.
 *     It now halts with 403.
 *   - Both mutations now write a durable audit row. Neither did before —
 *     adding a platform-wide DENY rule was invisible.
 *   - The `action` field previously carried a page-local operation name
 *     (`add` / `remove`) because the form posted back to the page. It now
 *     carries the admin-post route key, so the two operations are separate
 *     handlers rather than branches of one.
 *
 * @phpstan-import-type ChainRow from ChainRepository
 * @phpstan-import-type SpamContractRow from NftSpamContractRepository
 */
final class NftSpamContractsView
{
    public const ACTION_ADD    = 'bcc_nft_spam_add';
    public const ACTION_REMOVE = 'bcc_nft_spam_remove';

    public static function register_actions(): void
    {
        add_action('admin_post_' . self::ACTION_ADD,    [self::class, 'handleAdd']);
        add_action('admin_post_' . self::ACTION_REMOVE, [self::class, 'handleRemove']);
    }

    public static function render(): void
    {
        self::renderResultNotice();

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

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:24px">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_ADD); ?>">
            <?php wp_nonce_field(self::ACTION_ADD, '_wpnonce'); ?>
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
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                  style="display:inline"
                                  onsubmit="return confirm('Remove this rule? Holdings for this contract will stop being filtered on the next indexer tick.');">
                                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_REMOVE); ?>">
                                <input type="hidden" name="chain_id" value="<?php echo (int) $cid; ?>">
                                <input type="hidden" name="contract" value="<?php echo esc_attr($contract); ?>">
                                <?php wp_nonce_field(self::ACTION_REMOVE . '_' . $cid . '_' . $contract, '_wpnonce'); ?>
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

    /**
     * Add a deny/allow rule.
     *
     * check_admin_referer() wp_die()s with a 403 on failure, so the write path
     * is never reached with a forged or missing nonce.
     */
    public static function handleAdd(): void
    {
        AdminActionSupport::requireCapability();
        AdminActionSupport::requireNonce(self::ACTION_ADD);

        $cid      = isset($_POST['chain_id']) ? (int) $_POST['chain_id'] : 0;
        $contract = isset($_POST['contract']) ? trim(sanitize_text_field((string) $_POST['contract'])) : '';
        $rule     = isset($_POST['rule']) ? sanitize_key((string) $_POST['rule']) : '';
        $reason   = isset($_POST['reason']) ? sanitize_text_field((string) $_POST['reason']) : '';

        if ($cid <= 0 || $contract === '' || !in_array($rule, ['deny', 'allow'], true)) {
            self::redirect('add_invalid');
        }

        // The chain must resolve — a positive integer alone is not a target.
        if (ChainRepository::getById($cid) === null) {
            self::redirect('add_invalid');
        }

        try {
            $ok = NftSpamContractRepository::addRule($cid, $contract, $rule, $reason);
        } catch (\Throwable $e) {
            $ref = AdminActionSupport::failure(
                $e,
                'admin_nft_spam_rule_failed',
                'nft_spam_rule',
                $cid,
                ['contract' => $contract, 'rule' => $rule]
            );
            self::redirect('add_failed', $ref);
        }

        if (!$ok) {
            AdminActionSupport::audit(
                'admin_nft_spam_rule_failed',
                'nft_spam_rule',
                $cid,
                ['contract' => $contract, 'rule' => $rule]
            );
            self::redirect('add_failed');
        }

        // This rule governs what every user sees. It was previously invisible.
        AdminActionSupport::audit(
            'admin_nft_spam_rule_added',
            'nft_spam_rule',
            $cid,
            ['contract' => $contract, 'rule' => $rule, 'reason' => $reason]
        );

        self::redirect('add_ok');
    }

    /**
     * Remove a rule. The nonce is bound to the specific (chain, contract)
     * pair, so a nonce minted for one rule cannot remove another.
     */
    public static function handleRemove(): void
    {
        AdminActionSupport::requireCapability();

        $cid      = isset($_POST['chain_id']) ? (int) $_POST['chain_id'] : 0;
        $contract = isset($_POST['contract']) ? trim(sanitize_text_field((string) $_POST['contract'])) : '';

        if ($cid <= 0 || $contract === '') {
            self::redirect('remove_failed');
        }

        AdminActionSupport::requireNonce(self::ACTION_REMOVE . '_' . $cid . '_' . $contract);

        try {
            $ok = NftSpamContractRepository::removeRule($cid, $contract);
        } catch (\Throwable $e) {
            $ref = AdminActionSupport::failure(
                $e,
                'admin_nft_spam_rule_failed',
                'nft_spam_rule',
                $cid,
                ['contract' => $contract, 'op' => 'remove']
            );
            self::redirect('remove_failed', $ref);
        }

        if (!$ok) {
            // Legitimate no-op: the rule was already gone. Distinguished from
            // a failure so the audit trail does not imply a change happened.
            self::redirect('remove_missing');
        }

        AdminActionSupport::audit(
            'admin_nft_spam_rule_removed',
            'nft_spam_rule',
            $cid,
            ['contract' => $contract]
        );

        self::redirect('remove_ok');
    }

    /**
     * PRG terminator back to the spam tab.
     */
    private static function redirect(string $result, string $ref = ''): never
    {
        $args = [
            'page'            => SettingsPage::PAGE_SLUG,
            'tab'             => 'spam',
            'bcc_spam_result' => $result,
        ];
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
        $result = isset($_GET['bcc_spam_result']) ? sanitize_key((string) $_GET['bcc_spam_result']) : '';
        if ($result === '') {
            return;
        }

        $notices = [
            'add_ok'         => ['updated', 'Rule saved.'],
            'add_invalid'    => ['error',   'Missing or invalid required fields — no rule was saved.'],
            'add_failed'     => ['error',   'Failed to save rule.'],
            'remove_ok'      => ['updated', 'Rule removed.'],
            'remove_missing' => ['error',   'Rule not found — it may already have been removed.'],
            'remove_failed'  => ['error',   'Failed to remove rule.'],
        ];

        if (!isset($notices[$result])) {
            return;
        }

        [$type, $message] = $notices[$result];

        $ref = isset($_GET['bcc_ref']) ? sanitize_text_field((string) $_GET['bcc_ref']) : '';
        if ($ref !== '' && $type === 'error') {
            $message .= ' ' . AdminActionSupport::failureMessage($ref);
        }

        add_settings_error('bcc_nft_spam', $result, $message, $type);
        settings_errors('bcc_nft_spam');
    }
}
