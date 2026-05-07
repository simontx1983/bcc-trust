<?php
/**
 * Admin "Verify Collections" page.
 *
 * Submenu under bcc-trust-dashboard. Lists collections from
 * wp_bcc_onchain_collections (paginated, ordered by holder count) with
 * an `is_verified` checkbox per row. Submitting the form persists the
 * flag changes, then the daily provisioning sweep auto-creates a
 * closed PeepSo group for each newly-verified collection.
 *
 * "Provision now" button triggers GatedGroupProvisioningService::provisionAll
 * immediately for admin testing without waiting for cron.
 *
 * @package BCC\Trust\Onchain\Admin
 */

namespace BCC\Trust\Onchain\Admin;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Onchain\Factories\FetcherFactory;
use BCC\Trust\Onchain\Fetchers\CosmosFetcher;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class VerifyCollectionsPage
{
    public const PAGE_SLUG  = 'bcc-verify-collections';
    public const NONCE_KEY  = 'bcc_verify_collections_nonce';
    public const NONCE_NAME = '_bcc_vc_nonce';

    public static function register_page(): void
    {
        add_submenu_page(
            'bcc-trust-dashboard',
            'Verify Collections',
            'Verify Collections',
            'manage_options',
            self::PAGE_SLUG,
            [__CLASS__, 'render_page']
        );
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        $notices = self::handlePost();

        $page    = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $listing = CollectionRepository::listForAdminVerification($page, 50);
        ?>
        <div class="wrap">
            <h1>Verify Collections</h1>

            <p>Mark a collection as <strong>On-Chain Verified</strong> to auto-create
            a closed PeepSo group for its holders. Holders see "you qualify" suggestions;
            joining is explicit (suggest-don't-auto-join). The provisioning sweep runs
            daily — use <em>Provision now</em> to trigger it immediately.</p>

            <div class="notice notice-warning inline">
                <p><strong>ERC-721 only for now.</strong> The holder gate is wired for
                <code>balanceOf(address)</code> — the ERC-721 selector. Verifying an
                <strong>ERC-1155</strong> collection will silently fail the gate (every
                holder appears ineligible) because 1155 uses a different
                <code>balanceOf(address, tokenId)</code> shape. ERC-1155 rows are
                flagged below; please don't verify them until the gate adds 1155 support.
                Solana / Cosmos collections are unaffected.</p>
            </div>

            <?php foreach ($notices as $notice): ?>
                <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible">
                    <p><?php echo esc_html($notice['message']); ?></p>
                </div>
            <?php endforeach; ?>

            <form method="post" action="">
                <?php wp_nonce_field(self::NONCE_KEY, self::NONCE_NAME); ?>
                <input type="hidden" name="bcc_vc_action" value="save">
                <input type="hidden" name="paged" value="<?php echo (int) $page; ?>">

                <p class="submit" style="margin:0 0 12px 0;">
                    <button type="submit" class="button button-primary">Save Verification Changes</button>
                    <button type="submit" name="bcc_vc_action" value="provision" class="button">Provision Now</button>
                </p>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th style="width:80px;">Verified</th>
                            <th style="width:60px;"></th>
                            <th>Collection</th>
                            <th>Chain</th>
                            <th>Contract</th>
                            <th style="width:120px;">Holders</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($listing['items'] === []): ?>
                            <tr>
                                <td colspan="6"><em>No collections synced yet. Connect a wallet to populate this list.</em></td>
                            </tr>
                        <?php else: foreach ($listing['items'] as $row): ?>
                            <?php
                            $tokenStandard = (string) ($row->token_standard ?? '');
                            $isErc1155     = stripos($tokenStandard, '1155') !== false;
                            ?>
                            <tr>
                                <td>
                                    <input type="hidden" name="known[]" value="<?php echo (int) $row->id; ?>">
                                    <input type="checkbox"
                                           name="verified[<?php echo (int) $row->id; ?>]"
                                           value="1"
                                           <?php checked((int) $row->is_verified, 1); ?>>
                                </td>
                                <td>
                                    <?php if (!empty($row->image_url)): ?>
                                        <img src="<?php echo esc_url($row->image_url); ?>"
                                             alt=""
                                             style="width:40px;height:40px;border-radius:4px;object-fit:cover;">
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo esc_html($row->collection_name ?? '(no name)'); ?></strong>
                                    <?php if ($isErc1155): ?>
                                        <br>
                                        <span title="ERC-1155 — gate not supported yet. Verifying this row will leave the group empty."
                                              style="display:inline-block;background:#f0b849;color:#3c2a00;padding:1px 6px;border-radius:3px;font-size:11px;font-weight:600;margin-top:2px;">
                                            ⚠ ERC-1155 (gate unsupported)
                                        </span>
                                    <?php elseif ($tokenStandard !== ''): ?>
                                        <br>
                                        <span style="color:#646970;font-size:11px;"><?php echo esc_html($tokenStandard); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><code><?php echo esc_html($row->chain_slug); ?></code></td>
                                <td>
                                    <code style="font-size:11px;"><?php echo esc_html($row->contract_address); ?></code>
                                    <?php
                                    // V2 Phase 2: per-row CW-721 sanity-check button. Only
                                    // shown on cosmos-typed rows because the test validates
                                    // CW-721 `contract_info`; clicking it from a non-cosmos
                                    // row would emit a "wrong chain type" notice (handler
                                    // covers gracefully).
                                    $isCosmos = (string) ($row->chain_type ?? '') === 'cosmos';
                                    if ($isCosmos):
                                    ?>
                                        <br>
                                        <button type="submit"
                                                name="bcc_vc_action"
                                                value="testquery_<?php echo (int) $row->id; ?>"
                                                class="button button-small"
                                                style="margin-top:4px;font-size:11px;"
                                                title="Run CW-721 contract_info — confirms the contract is a real CW-721 NFT before flipping is_verified.">
                                            Test CW-721
                                        </button>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo number_format_i18n((int) ($row->unique_holders ?? 0)); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary">Save Verification Changes</button>
                </p>
            </form>

            <?php if ($listing['pages'] > 1): ?>
                <div class="tablenav-pages">
                    <?php
                    echo paginate_links([
                        'base'    => add_query_arg('paged', '%#%'),
                        'format'  => '',
                        'current' => $page,
                        'total'   => $listing['pages'],
                    ]);
                    ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @return list<array{type: string, message: string}>
     */
    private static function handlePost(): array
    {
        if (empty($_POST['bcc_vc_action'])) {
            return [];
        }

        if (!isset($_POST[self::NONCE_NAME]) ||
            !wp_verify_nonce(sanitize_text_field((string) $_POST[self::NONCE_NAME]), self::NONCE_KEY)
        ) {
            return [['type' => 'error', 'message' => 'Security check failed. Please try again.']];
        }

        $action = sanitize_text_field((string) $_POST['bcc_vc_action']);

        if ($action === 'save') {
            return self::handleSave();
        }

        if ($action === 'provision') {
            return self::handleProvision();
        }

        // V2 Phase 2: per-row "Test CW-721 query" button. Encodes the
        // collection id in the action value (`testquery_<id>`) so the
        // existing single-form + single-nonce shape stays intact.
        if (strpos($action, 'testquery_') === 0) {
            $collectionId = (int) substr($action, strlen('testquery_'));
            return self::handleTestQuery($collectionId);
        }

        return [];
    }

    /**
     * V2 Phase 2: pre-verify CW-721 sanity check. Hits the contract's
     * `contract_info` smart query via the per-chain fetcher; renders
     * the result as an admin notice. Catches:
     *   - non-CW-721 contracts (response shape mismatch)
     *   - chains without CosmWasm enabled (Crypto.org returns 501)
     *   - mis-pasted contract addresses (404 from the wasm module)
     *
     * @return list<array{type: string, message: string}>
     */
    private static function handleTestQuery(int $collectionId): array
    {
        if ($collectionId <= 0) {
            return [['type' => 'error', 'message' => 'Test query: invalid collection id.']];
        }

        $coll = CollectionRepository::getByIdWithChain($collectionId);
        if ($coll === null) {
            return [['type' => 'error', 'message' => 'Test query: collection not found.']];
        }

        $contract  = (string) $coll->contract_address;
        $chainSlug = (string) $coll->chain_slug;
        $chainType = (string) $coll->chain_type;

        if ($chainType !== 'cosmos') {
            return [[
                'type'    => 'warning',
                'message' => sprintf(
                    'Test query: %s is %s — this button only validates CW-721 (Cosmos) contracts.',
                    $contract,
                    $chainType
                ),
            ]];
        }

        $chain = ChainRepository::getById((int) $coll->chain_id);
        if ($chain === null) {
            return [['type' => 'error', 'message' => 'Test query: chain not found.']];
        }

        if (!FetcherFactory::has_driver($chainType)) {
            return [['type' => 'error', 'message' => 'Test query: no fetcher driver for ' . $chainSlug]];
        }

        $fetcher = FetcherFactory::make_for_chain($chain);
        if (!($fetcher instanceof CosmosFetcher)) {
            return [['type' => 'error', 'message' => 'Test query: fetcher driver mismatch for ' . $chainSlug]];
        }

        $info = $fetcher->testCw721ContractInfo($contract);
        if ($info === null) {
            return [[
                'type'    => 'error',
                'message' => sprintf(
                    'Test query: contract_info call FAILED on %s for %s. Likely causes: contract is not CW-721, contract address is wrong, or the chain has no CosmWasm enabled (Crypto.org returns 501 Not Implemented). Check the bcc-trust error log for the LCD response.',
                    $chainSlug,
                    $contract
                ),
            ]];
        }

        $name = isset($info['name']) && is_string($info['name']) ? $info['name'] : '(missing)';
        $symbol = isset($info['symbol']) && is_string($info['symbol']) ? $info['symbol'] : '(missing)';
        return [[
            'type'    => 'success',
            'message' => sprintf(
                'Test query OK on %s — name="%s", symbol="%s". Safe to verify.',
                $chainSlug,
                $name,
                $symbol
            ),
        ]];
    }

    /**
     * @return list<array{type: string, message: string}>
     */
    private static function handleSave(): array
    {
        $known = isset($_POST['known']) && is_array($_POST['known'])
            ? array_map('intval', $_POST['known'])
            : [];

        $checkedRaw = isset($_POST['verified']) && is_array($_POST['verified'])
            ? $_POST['verified']
            : [];
        $checked = [];
        foreach ($checkedRaw as $id => $_v) {
            $checked[(int) $id] = true;
        }

        $changed = 0;
        foreach ($known as $collectionId) {
            if ($collectionId <= 0) {
                continue;
            }
            $shouldBeVerified = isset($checked[$collectionId]);
            CollectionRepository::setVerified($collectionId, $shouldBeVerified);
            $changed++;
        }

        return [[
            'type'    => 'success',
            'message' => sprintf('Verification flags saved (%d collections processed).', $changed),
        ]];
    }

    /**
     * @return list<array{type: string, message: string}>
     */
    private static function handleProvision(): array
    {
        // Persist any pending changes first so the operator sees consistent state.
        $saveNotices = self::handleSave();

        $result = Plugin::instance()->gatedGroupProvisioningService()->provisionAll();

        $message = sprintf(
            'Provisioning sweep ran: %d created, %d skipped (already exist or missing metadata).',
            $result['created'],
            $result['skipped']
        );
        $errors = $result['errors'] ?? [];

        $notices = $saveNotices;
        $notices[] = [
            'type'    => empty($errors) ? 'success' : 'warning',
            'message' => $message,
        ];

        foreach ($errors as $err) {
            $notices[] = ['type' => 'error', 'message' => (string) $err];
        }

        return $notices;
    }
}
