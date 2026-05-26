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

use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Onchain\Factories\FetcherFactory;
use BCC\Trust\Onchain\Fetchers\CosmosFetcher;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\GatedGroupRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class VerifyCollectionsPage
{
    public const PAGE_SLUG  = 'bcc-verify-collections';
    public const NONCE_KEY  = 'bcc_verify_collections_nonce';
    public const NONCE_NAME = '_bcc_vc_nonce';

    /**
     * Chain slugs surfaced as quick-filter pills above the dropdown.
     * Pills only render if the slug also appears in the active chains
     * registry — a missing/disabled chain silently drops its pill
     * rather than producing a broken link.
     *
     * Filterable via `bcc_verify_collections_pill_chains` for future
     * tuning without code changes.
     *
     * @var list<string>
     */
    private const PILL_CHAIN_SLUGS = ['ethereum', 'solana', 'stargaze'];

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

        $page                  = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $selectedChain         = isset($_GET['chain']) ? sanitize_text_field((string) $_GET['chain']) : '';
        $selectedTokenStandard = isset($_GET['token_standard'])
            ? sanitize_text_field((string) $_GET['token_standard'])
            : '';

        $availableChains    = ChainRepository::getActive();
        $availableStandards = CollectionRepository::getDistinctTokenStandards();

        // Validate token_standard against the auto-derived whitelist
        // before passing it on — defends against a malformed query
        // string slipping a non-existent value into the SQL (it would
        // be safely placeholdered either way, but rejecting unknowns
        // keeps the dropdown's selected-state honest).
        if ($selectedTokenStandard !== '' && !in_array($selectedTokenStandard, $availableStandards, true)) {
            $selectedTokenStandard = '';
        }

        $listing = CollectionRepository::listForAdminVerification(
            $page,
            50,
            $selectedChain !== '' ? $selectedChain : null,
            $selectedTokenStandard !== '' ? $selectedTokenStandard : null
        );

        // Pill chains: intersection of PILL_CHAIN_SLUGS (filterable) and
        // the active chains registry, in the configured order. A
        // missing/disabled chain silently drops its pill.
        /** @var list<string> $pillSlugs */
        $pillSlugs = (array) apply_filters(
            'bcc_verify_collections_pill_chains',
            self::PILL_CHAIN_SLUGS
        );
        $availableChainsBySlug = [];
        foreach ($availableChains as $chain) {
            $availableChainsBySlug[(string) $chain->slug] = $chain;
        }
        $pillChains = [];
        foreach ($pillSlugs as $slug) {
            $slug = (string) $slug;
            if (isset($availableChainsBySlug[$slug])) {
                $pillChains[] = $availableChainsBySlug[$slug];
            }
        }
        ?>
        <div class="wrap">
            <h1>Verify Collections</h1>

            <p>Mark a collection as <strong>On-Chain Verified</strong> to auto-create
            a closed PeepSo group for its holders. Holders see "you qualify" suggestions;
            joining is explicit (suggest-don't-auto-join). The provisioning sweep runs
            daily — use <em>Provision now</em> to trigger it immediately.</p>

            <?php foreach ($notices as $notice): ?>
                <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible">
                    <p><?php echo esc_html($notice['message']); ?></p>
                </div>
            <?php endforeach; ?>

            <?php if ($pillChains !== []): ?>
                <div style="margin:0 0 10px 0;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                    <strong style="margin-right:4px;">Quick filter:</strong>
                    <?php
                    // "All" pill — clears the chain filter while preserving
                    // other filter state (token_standard).
                    $allUrl = add_query_arg(
                        ['page' => self::PAGE_SLUG, 'chain' => false, 'paged' => false],
                        admin_url('admin.php')
                    );
                    if ($selectedTokenStandard !== '') {
                        $allUrl = add_query_arg('token_standard', $selectedTokenStandard, $allUrl);
                    }
                    $allClass = $selectedChain === '' ? 'button button-primary' : 'button';
                    ?>
                    <a href="<?php echo esc_url($allUrl); ?>" class="<?php echo esc_attr($allClass); ?>">All</a>
                    <?php foreach ($pillChains as $pillChain):
                        $pillSlug   = (string) $pillChain->slug;
                        $isActive   = $selectedChain === $pillSlug;
                        // Clicking the active pill clears the filter; clicking
                        // an inactive pill switches to it. Preserve token_standard.
                        $pillUrl = add_query_arg(
                            [
                                'page'  => self::PAGE_SLUG,
                                'chain' => $isActive ? false : $pillSlug,
                                'paged' => false,
                            ],
                            admin_url('admin.php')
                        );
                        if ($selectedTokenStandard !== '') {
                            $pillUrl = add_query_arg('token_standard', $selectedTokenStandard, $pillUrl);
                        }
                        $pillClass = $isActive ? 'button button-primary' : 'button';
                        ?>
                        <a href="<?php echo esc_url($pillUrl); ?>"
                           class="<?php echo esc_attr($pillClass); ?>"
                           aria-pressed="<?php echo $isActive ? 'true' : 'false'; ?>">
                            <?php echo esc_html((string) $pillChain->name); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php
            $cosmosChains = array_values(array_filter(
                $availableChains,
                static fn($c) => (string) ($c->chain_type ?? '') === 'cosmos'
            ));
            if ($cosmosChains !== []):
            ?>
                <form method="post" action="" style="margin:0 0 12px 0;padding:8px 12px;background:#f6f7f7;border:1px solid #c3c4c7;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <?php wp_nonce_field(self::NONCE_KEY, self::NONCE_NAME); ?>
                    <input type="hidden" name="bcc_vc_action" value="add_cosmos_collection">
                    <strong style="margin-right:4px;">Add Cosmos collection:</strong>
                    <label for="bcc-vc-add-chain" class="screen-reader-text">Chain</label>
                    <select name="bcc_vc_add_chain_id" id="bcc-vc-add-chain" required>
                        <?php foreach ($cosmosChains as $cosmosChain): ?>
                            <option value="<?php echo (int) $cosmosChain->id; ?>">
                                <?php echo esc_html((string) $cosmosChain->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <label for="bcc-vc-add-contract" class="screen-reader-text">CW-721 contract address</label>
                    <input type="text"
                           name="bcc_vc_add_contract"
                           id="bcc-vc-add-contract"
                           placeholder="CW-721 contract address (inj1… / stars1… / …)"
                           style="flex:1;min-width:280px;font-family:monospace;font-size:12px;"
                           required>
                    <button type="submit" class="button">Add collection</button>
                    <span style="color:#646970;font-size:11px;">
                        Validates via <code>contract_info</code>. New row lands with <code>is_verified=0</code> — flip the checkbox below to enable provisioning.
                    </span>
                </form>
            <?php endif; ?>

            <form method="get" action="" style="margin:0 0 12px 0;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
                <span>
                    <label for="bcc-vc-chain-filter" style="margin-right:6px;">
                        <strong>Chain:</strong>
                    </label>
                    <select name="chain" id="bcc-vc-chain-filter" onchange="this.form.submit()">
                        <option value="">All chains</option>
                        <?php foreach ($availableChains as $chainOption): ?>
                            <option value="<?php echo esc_attr((string) $chainOption->slug); ?>"
                                <?php selected($selectedChain, (string) $chainOption->slug); ?>>
                                <?php echo esc_html((string) $chainOption->name); ?>
                                (<?php echo esc_html((string) $chainOption->chain_type); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </span>
                <?php if ($availableStandards !== []): ?>
                    <span>
                        <label for="bcc-vc-token-filter" style="margin-right:6px;">
                            <strong>Token standard:</strong>
                        </label>
                        <select name="token_standard" id="bcc-vc-token-filter" onchange="this.form.submit()">
                            <option value="">All standards</option>
                            <?php foreach ($availableStandards as $standard): ?>
                                <option value="<?php echo esc_attr($standard); ?>"
                                    <?php selected($selectedTokenStandard, $standard); ?>>
                                    <?php echo esc_html($standard); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </span>
                <?php endif; ?>
                <noscript>
                    <button type="submit" class="button">Filter</button>
                </noscript>
            </form>

            <form method="post" action="">
                <?php wp_nonce_field(self::NONCE_KEY, self::NONCE_NAME); ?>
                <input type="hidden" name="bcc_vc_action" value="save">
                <input type="hidden" name="paged" value="<?php echo (int) $page; ?>">
                <input type="hidden" name="chain" value="<?php echo esc_attr($selectedChain); ?>">
                <input type="hidden" name="token_standard" value="<?php echo esc_attr($selectedTokenStandard); ?>">

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
                            <th style="width:140px;" title="Members of the auto-provisioned PeepSo group (only meaningful once verified).">
                                Members in group
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($listing['items'] === []): ?>
                            <tr>
                                <td colspan="7"><em>No collections synced yet. Connect a wallet to populate this list.</em></td>
                            </tr>
                        <?php else: foreach ($listing['items'] as $row): ?>
                            <?php
                            $tokenStandard = (string) ($row->token_standard ?? '');
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
                                    <?php if ($tokenStandard !== ''): ?>
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
                                <td>
                                    <?php
                                    // Reuse-join: collection (chain_id, contract) →
                                    // PeepSo group post id → member count.
                                    // Cell semantics:
                                    //   unverified         → "—"           (no group exists)
                                    //   verified, no group → "pending"     (cron hasn't provisioned yet)
                                    //   verified + group   → number        (current member count)
                                    // N+1 caveat: 50 collections × 2 queries per row.
                                    // Acceptable on this admin-only page; revisit if
                                    // perf bites or perPage grows materially.
                                    if ((int) $row->is_verified !== 1) {
                                        echo '<span style="color:#999;">&mdash;</span>';
                                    } else {
                                        $groupId = GatedGroupRepository::findGroupForCollection(
                                            (int) $row->chain_id,
                                            (string) $row->contract_address
                                        );
                                        if ($groupId === null) {
                                            echo '<span style="color:#999;font-size:11px;">pending</span>';
                                        } else {
                                            $count = PeepSoGroupRepository::countGroupMembers($groupId);
                                            echo number_format_i18n($count);
                                        }
                                    }
                                    ?>
                                </td>
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

        if ($action === 'add_cosmos_collection') {
            return self::handleAddCosmosCollection();
        }

        return [];
    }

    /**
     * Curated-admin path: insert a single Cosmos CW-721 collection by
     * contract address. Validates via the existing `contract_info` probe
     * (same one the per-row "Test CW-721" button uses) before writing.
     * Lands with `is_verified=0`; admin still flips the checkbox to enable
     * provisioning. Safe to re-submit an existing contract — `bulkUpsert`
     * preserves `is_verified` on duplicates and only refreshes metadata.
     *
     * Safety valve for Cosmos chains without a registry contract identified
     * (Kujira, Dungeon) and for non-Talis Injective collections that the
     * Talis-whitelist auto-discovery wouldn't surface (e.g., DojoSwap mints,
     * private-deploy CW-721s).
     *
     * @return list<array{type: string, message: string}>
     */
    private static function handleAddCosmosCollection(): array
    {
        $chainId  = isset($_POST['bcc_vc_add_chain_id']) ? (int) $_POST['bcc_vc_add_chain_id'] : 0;
        $contract = isset($_POST['bcc_vc_add_contract'])
            ? trim(sanitize_text_field((string) $_POST['bcc_vc_add_contract']))
            : '';

        if ($chainId <= 0 || $contract === '') {
            return [['type' => 'error', 'message' => 'Add collection: chain and contract address are required.']];
        }

        $chain = ChainRepository::getById($chainId);
        if ($chain === null) {
            return [['type' => 'error', 'message' => 'Add collection: chain not found.']];
        }

        if ((string) $chain->chain_type !== 'cosmos') {
            return [[
                'type'    => 'error',
                'message' => sprintf(
                    'Add collection: %s is not a Cosmos chain. CW-721 validation only runs on cosmos-typed chains.',
                    (string) $chain->slug
                ),
            ]];
        }

        if (!FetcherFactory::has_driver((string) $chain->chain_type)) {
            return [['type' => 'error', 'message' => 'Add collection: no fetcher driver for ' . $chain->slug]];
        }

        $fetcher = FetcherFactory::make_for_chain($chain);
        if (!($fetcher instanceof CosmosFetcher)) {
            return [['type' => 'error', 'message' => 'Add collection: fetcher driver mismatch for ' . $chain->slug]];
        }

        $info = $fetcher->testCw721ContractInfo($contract);
        if ($info === null) {
            return [[
                'type'    => 'error',
                'message' => sprintf(
                    'Add collection: contract_info FAILED on %s for %s. Confirm the address is a real CW-721 contract on this chain before retrying.',
                    (string) $chain->slug,
                    $contract
                ),
            ]];
        }

        $name = isset($info['name']) && is_string($info['name']) ? $info['name'] : null;

        $written = CollectionRepository::bulkUpsert([[
            'contract_address'   => $contract,
            'chain_id'           => (int) $chain->id,
            'collection_name'    => $name,
            'token_standard'     => 'CW-721',
            'total_supply'       => null,
            'floor_price'        => null,
            'floor_currency'     => null,
            'unique_holders'     => null,
            'total_volume'       => null,
            'listed_percentage'  => null,
            'royalty_percentage' => null,
            'metadata_storage'   => null,
            'image_url'          => null,
        ]], 4 * HOUR_IN_SECONDS);

        if ($written === 0) {
            return [['type' => 'error', 'message' => 'Add collection: upsert returned 0 rows. Check the bcc-trust error log.']];
        }

        return [[
            'type'    => 'success',
            'message' => sprintf(
                'Added %s on %s (name="%s"). Flip the Verified checkbox below to enable provisioning.',
                $contract,
                (string) $chain->slug,
                $name ?? '(missing)'
            ),
        ]];
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

        $verify   = [];
        $unverify = [];
        foreach ($known as $collectionId) {
            if ($collectionId <= 0) {
                continue;
            }
            if (isset($checked[$collectionId])) {
                $verify[] = $collectionId;
            } else {
                $unverify[] = $collectionId;
            }
        }

        $changed = CollectionRepository::setVerifiedBulk($verify, $unverify);

        return [[
            'type'    => 'success',
            'message' => sprintf(
                'Verification flags saved (%d processed, %d actually changed).',
                count($verify) + count($unverify),
                $changed
            ),
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
