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
use BCC\Trust\Onchain\OnchainPlugin;
use BCC\Trust\Onchain\Admin\Views\CosmwasmScannerPanel;
use BCC\Trust\Onchain\Factories\FetcherFactory;
use BCC\Trust\Onchain\Fetchers\CosmosFetcher;
use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmContractRepository;
use BCC\Trust\Onchain\Repositories\GatedGroupRepository;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use BCC\Trust\Onchain\Services\CollectionDemandService;
use BCC\Trust\Onchain\Services\CosmwasmDiscoveryHealthSnapshot;
use BCC\Trust\Onchain\Support\CosmwasmDiscoveryGate;
use BCC\Trust\Onchain\Support\CosmwasmTickBudget;
use BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker;

if (!defined('ABSPATH')) {
    exit;
}

final class VerifyCollectionsPage
{
    public const PAGE_SLUG  = 'bcc-verify-collections';
    public const NONCE_KEY  = 'bcc_verify_collections_nonce';
    public const NONCE_NAME = '_bcc_vc_nonce';

    /**
     * Separate nonce for the inline AJAX actions (instant verify toggle,
     * per-row community create). Kept distinct from the form-POST nonce
     * so the two flows don't interfere — mirrors ChainsPage's pattern.
     */
    public const AJAX_NONCE_KEY    = 'bcc_vc_ajax_nonce';
    public const AJAX_ACTION_TOGGLE  = 'bcc_vc_toggle_verified';
    public const AJAX_ACTION_PROVISION = 'bcc_vc_provision_one';

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
    private const PILL_CHAIN_SLUGS = ['ethereum', 'solana', 'cosmos'];

    /**
     * Column count of the verification table.
     *
     * The CosmWasm candidate detail renders as a full-width sub-row under
     * its collection, so it needs the same colspan the "no rows" cell
     * uses. Naming it once stops the two drifting apart the next time a
     * column is added.
     */
    private const TABLE_COLSPAN = 12;

    /**
     * Rows a single operator "Force retry" click may clear the backoff on,
     * per table, per chain.
     *
     * Bounded on purpose: the button is a nudge, not a reset. 100 families
     * plus 100 contracts is comfortably more than one scheduled pass can
     * chew through (25 + 25), so the queue is refilled without letting one
     * click queue an unbounded amount of LCD traffic.
     */
    private const FORCE_RETRY_LIMIT = 100;

    /**
     * Request budget for an operator-triggered backfill slice.
     *
     * Deliberately smaller than the cron budget
     * ({@see CosmwasmDiscoveryGate::DEFAULT_REQUEST_BUDGET}, 50) and on a
     * shorter clock: a cron tick may spend its full 20 seconds because
     * nobody is waiting on it, whereas this one runs INSIDE an admin page
     * load. A slice that ties up the browser for 20 seconds gets clicked
     * twice, and the second click is a wasted advisory-lock miss.
     */
    private const ADMIN_BACKFILL_REQUESTS = 20;
    private const ADMIN_BACKFILL_SECONDS  = 8;

    public static function register_page(): void
    {
        // Audit follow-up: relocated under BCC System alongside the
        // other onchain admin pages. Page slug unchanged.
        add_submenu_page(
            'bcc-system-health',
            'Verify Collections',
            'Verify Collections',
            'manage_options',
            self::PAGE_SLUG,
            [__CLASS__, 'render_page']
        );
    }

    /**
     * Register the inline AJAX handlers. Called from the plugin
     * bootstrap alongside ChainsPage::register_ajax().
     */
    public static function register_ajax(): void
    {
        add_action('wp_ajax_' . self::AJAX_ACTION_TOGGLE, [__CLASS__, 'ajax_toggle_verified']);
        add_action('wp_ajax_' . self::AJAX_ACTION_PROVISION, [__CLASS__, 'ajax_provision_one']);
    }

    /**
     * AJAX: flip a single collection's is_verified flag. Returns the new
     * state so the row UI can re-render without a page reload.
     */
    public static function ajax_toggle_verified(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized.']);
        }
        check_ajax_referer(self::AJAX_NONCE_KEY, 'nonce');

        $collectionId = (int) ($_POST['collection_id'] ?? 0);
        // empty() already treats '0', '', and absent as false — the JS
        // sends '1' to verify and '0' to unverify.
        $verify       = !empty($_POST['verify']);

        if ($collectionId <= 0) {
            wp_send_json_error(['message' => 'Invalid collection id.']);
        }

        if ($verify) {
            $changed = CollectionRepository::setVerifiedBulk([$collectionId], []);
        } else {
            $changed = CollectionRepository::setVerifiedBulk([], [$collectionId]);
        }

        \BCC\Core\Log\Logger::info('[bcc-trust] Verify Collections toggle (ajax)', [
            'action'        => 'verify_collections_toggle_ajax',
            'collection_id' => $collectionId,
            'verify'        => $verify,
            'changed'       => $changed,
            'operator'      => get_current_user_id(),
        ]);

        wp_send_json_success([
            'verified' => $verify,
            'message'  => $verify ? 'Marked verified.' : 'Marked unverified.',
        ]);
    }

    /**
     * AJAX: provision the holder community for one verified collection.
     */
    public static function ajax_provision_one(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized.']);
        }
        check_ajax_referer(self::AJAX_NONCE_KEY, 'nonce');

        $collectionId = (int) ($_POST['collection_id'] ?? 0);
        if ($collectionId <= 0) {
            wp_send_json_error(['message' => 'Invalid collection id.']);
        }

        $result = OnchainPlugin::instance()->gatedGroupProvisioningService()->provisionOne($collectionId);

        if ($result['status'] === 'error' || $result['status'] === 'skipped') {
            wp_send_json_error(['message' => $result['message']]);
        }

        wp_send_json_success([
            'status'   => $result['status'],
            'group_id' => $result['group_id'],
            'message'  => $result['message'],
        ]);
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

        // Verified / Unverified sub-tab. Defaults to "unverified" — the
        // operator's working queue (rows awaiting a decision). Any value
        // other than "verified" collapses to "unverified".
        $vstate    = isset($_GET['vstate']) && $_GET['vstate'] === 'verified' ? 'verified' : 'unverified';
        $isVerified = $vstate === 'verified';

        $chainArg    = $selectedChain !== '' ? $selectedChain : null;
        $standardArg = $selectedTokenStandard !== '' ? $selectedTokenStandard : null;

        $listing = CollectionRepository::listForAdminVerification(
            $page,
            50,
            $chainArg,
            $standardArg,
            $isVerified
        );

        // Demand signals, strongest first:
        //   1. Waitlist count — EXPLICIT user opt-ins ("activate this and
        //      count me in"). Airdrop-proof: a scammer can put tokens in
        //      every wallet but can't check the box for anyone.
        //   2. Linked holders — passive holdings (EVM/SOL from the
        //      holdings index; Cosmos Hub from marketplace rollups).
        //      Forgeable by airdrop, so it's the tiebreaker, not the rank.
        // Spam flags render red and sort flagged rows to the bottom.
        $demand  = CollectionDemandService::linkedHolderCounts();
        $signals = [];
        foreach (\BCC\Trust\Onchain\Repositories\CollectionSignalRepository::countsByCollection() as $t) {
            $signals[CollectionDemandService::key((int) $t->chain_id, (string) $t->contract_address)] = [
                'waitlist' => (int) $t->waitlist_count,
                'spam'     => (int) $t->spam_count,
            ];
        }

        // Re-rank the unverified queue. The repo caps perPage at 100, so
        // ranking re-fetches the scope in chunks up to a 500-row ceiling
        // — same fetch-sort-paginate-in-PHP posture as the §4.7
        // groups-discovery sort. Past the ceiling ranking is DISABLED
        // (not silently partial): SQL order is already a sane fallback
        // and a lying rank order is worse than none.
        $demandRanked = false;
        if (!$isVerified && $listing['total'] > 0 && $listing['total'] <= 500) {
            $all = [];
            $chunkPages = (int) ceil($listing['total'] / 100);
            for ($p = 1; $p <= $chunkPages; $p++) {
                $chunk = CollectionRepository::listForAdminVerification($p, 100, $chainArg, $standardArg, false);
                foreach ($chunk['items'] as $chunkRow) {
                    $all[] = $chunkRow;
                }
                if (count($chunk['items']) < 100) {
                    break;
                }
            }
            usort($all, static function (object $a, object $b) use ($demand, $signals): int {
                $ka = CollectionDemandService::key((int) $a->chain_id, (string) $a->contract_address);
                $kb = CollectionDemandService::key((int) $b->chain_id, (string) $b->contract_address);
                $sa = $signals[$ka] ?? ['waitlist' => 0, 'spam' => 0];
                $sb = $signals[$kb] ?? ['waitlist' => 0, 'spam' => 0];

                // Spam-flagged rows sink below everything unflagged.
                if (($sa['spam'] > 0) !== ($sb['spam'] > 0)) {
                    return $sa['spam'] > 0 ? 1 : -1;
                }
                if ($sa['waitlist'] !== $sb['waitlist']) {
                    return $sb['waitlist'] <=> $sa['waitlist'];
                }
                $da = $demand[$ka] ?? 0;
                $db = $demand[$kb] ?? 0;
                if ($da !== $db) {
                    return $db <=> $da;
                }
                // Tie-break: marketplace-wide holders (nulls last), then id.
                $ha = $a->unique_holders !== null ? (int) $a->unique_holders : -1;
                $hb = $b->unique_holders !== null ? (int) $b->unique_holders : -1;
                if ($ha !== $hb) {
                    return $hb <=> $ha;
                }
                return (int) $b->id <=> (int) $a->id;
            });
            $listing['items'] = array_slice($all, ($page - 1) * 50, 50);
            $demandRanked     = true;
        }

        $stateCounts = CollectionRepository::countByVerification($chainArg, $standardArg);

        // CosmWasm scanner context for the rows about to render.
        //
        // TWO bounded batch reads for the WHOLE page, issued once the row
        // set is final — not one lookup per row. The first pulls the
        // scanner's inventory row for every visible contract; the second
        // pulls the code families those rows point at, for the checksum.
        // Rows the scanner has never seen (manual adds, wallet-link
        // discoveries, non-Cosmos chains) simply have no entry and render
        // no scanner detail.
        $scannerCandidates = [];
        $scannerFamilies   = [];
        $scannerChainIds   = [];
        $scannerAddresses  = [];
        foreach ($listing['items'] as $listRow) {
            if ((string) ($listRow->chain_type ?? '') !== 'cosmos') {
                continue;
            }
            $scannerChainIds[]  = (int) $listRow->chain_id;
            $scannerAddresses[] = (string) $listRow->contract_address;
        }
        if ($scannerAddresses !== []) {
            // Both reads FAIL CLOSED. A row with no scanner entry renders no
            // scanner detail at all — which is correct when the collection
            // genuinely came from another path, and a lie when the lookup
            // simply failed. So a failed read drops the detail for the whole
            // page and SAYS SO, rather than quietly presenting every row as
            // "the scanner has never seen this".
            try {
                $codeIds = [];
                foreach (CosmwasmContractRepository::findManyForChains($scannerChainIds, $scannerAddresses) as $candidate) {
                    $scannerCandidates[(int) $candidate->chain_id . '|' . strtolower((string) $candidate->contract_address)] = $candidate;
                    $codeIds[] = (int) $candidate->code_id;
                }
                if ($codeIds !== []) {
                    foreach (CosmwasmCodeFamilyRepository::findManyForChains($scannerChainIds, $codeIds) as $family) {
                        $scannerFamilies[(int) $family->chain_id . '|' . (int) $family->code_id] = $family;
                    }
                }
            } catch (RepositoryReadFailure $e) {
                $scannerCandidates = [];
                $scannerFamilies   = [];

                \BCC\Core\Log\Logger::error('[bcc-trust] Verify Collections: scanner detail read failed', [
                    'action'   => 'verify_collections_scanner_detail_failed',
                    'method'   => $e->repositoryMethod(),
                    'db_error' => $e->dbError(),
                ]);

                $notices[] = [
                    'type'    => 'error',
                    'message' => 'The CosmWasm scanner detail could not be loaded for this page (a database read failed), '
                        . 'so the per-row scanner evidence is hidden rather than shown as "not seen by the scanner". '
                        . 'The collection rows themselves are unaffected. Check the bcc-trust error log.',
                ];
            }
        }

        // FOUR bounded aggregates for every chain — see the class docblock
        // on CosmwasmDiscoveryHealthSnapshot. Not a per-chain loop.
        $scannerSummary = CosmwasmDiscoveryHealthSnapshot::buildSummary();

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

            <p>Mark a collection as <strong>On-Chain Verified</strong> to give its
            holders a closed <strong>community</strong>. Holders see "you qualify"
            suggestions; joining is explicit (suggest-don't-auto-join). Communities
            are created automatically once a day — use <em>Create Communities</em>
            to do it now instead of waiting.</p>

            <?php foreach ($notices as $notice): ?>
                <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible">
                    <p><?php echo esc_html($notice['message']); ?></p>
                </div>
            <?php endforeach; ?>

            <?php CosmwasmScannerPanel::render($scannerSummary); ?>

            <?php
            // Verified / Unverified sub-tabs. Switching state resets
            // pagination (paged=false) but preserves chain + token_standard
            // filters so the operator stays in the same scope.
            $tabBaseArgs = ['page' => self::PAGE_SLUG, 'paged' => false];
            if ($selectedChain !== '') {
                $tabBaseArgs['chain'] = $selectedChain;
            }
            if ($selectedTokenStandard !== '') {
                $tabBaseArgs['token_standard'] = $selectedTokenStandard;
            }
            $unverifiedUrl = add_query_arg(
                $tabBaseArgs + ['vstate' => false],
                admin_url('admin.php')
            );
            $verifiedUrl = add_query_arg(
                $tabBaseArgs + ['vstate' => 'verified'],
                admin_url('admin.php')
            );
            ?>
            <h2 class="nav-tab-wrapper" style="margin-bottom:16px;">
                <a href="<?php echo esc_url($unverifiedUrl); ?>"
                   class="nav-tab <?php echo $isVerified ? '' : 'nav-tab-active'; ?>">
                    Unverified <span class="count">(<?php echo number_format_i18n($stateCounts['unverified']); ?>)</span>
                </a>
                <a href="<?php echo esc_url($verifiedUrl); ?>"
                   class="nav-tab <?php echo $isVerified ? 'nav-tab-active' : ''; ?>">
                    Verified <span class="count">(<?php echo number_format_i18n($stateCounts['verified']); ?>)</span>
                </a>
            </h2>

            <?php if (!$isVerified && $demandRanked): ?>
                <p style="margin:-6px 0 12px 0;color:#646970;font-size:12px;">
                    Queue ranked by <strong>Linked holders</strong> — collections that
                    real platform wallets hold sort first.
                </p>
            <?php endif; ?>

            <details style="margin:0 0 16px 0;border:1px solid #c3c4c7;border-radius:4px;padding:8px 12px;background:#fff;">
                <summary style="cursor:pointer;font-weight:600;">Add a collection manually</summary>
                <p style="color:#646970;margin:8px 0;">
                    Onboard a collection that auto-discovery can't reach. Cosmos Hub
                    collections auto-discover when a holder links a wallet (Stargaze
                    marketplace rollup); this form covers everything else.
                    Cosmos contracts are validated as
                    CW-721 before saving; other chains are trusted as entered. The row
                    is added <strong>unverified</strong> — verify it below to give its
                    holders a community.
                </p>
                <form method="post" action="" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
                    <?php wp_nonce_field(self::NONCE_KEY, self::NONCE_NAME); ?>
                    <input type="hidden" name="bcc_vc_action" value="add_collection">

                    <label style="display:flex;flex-direction:column;font-size:12px;">
                        Chain <span style="color:#d63638;">*</span>
                        <select name="add_chain_id" required style="min-width:180px;">
                            <option value="">— select chain —</option>
                            <?php foreach ($availableChains as $chain): ?>
                                <option value="<?php echo (int) $chain->id; ?>">
                                    <?php echo esc_html((string) $chain->name); ?>
                                    (<?php echo esc_html((string) $chain->chain_type); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label style="display:flex;flex-direction:column;font-size:12px;">
                        Contract address <span style="color:#d63638;">*</span>
                        <input type="text" name="add_contract_address" required
                               placeholder="dungeon1… / 0x… / mint address"
                               style="min-width:340px;font-family:monospace;">
                    </label>

                    <label style="display:flex;flex-direction:column;font-size:12px;">
                        Collection name
                        <input type="text" name="add_collection_name"
                               placeholder="(auto-filled for Cosmos)" style="min-width:200px;">
                    </label>

                    <label style="display:flex;flex-direction:column;font-size:12px;">
                        Token standard
                        <select name="add_token_standard" style="min-width:120px;">
                            <option value="">— auto / none —</option>
                            <?php foreach (self::ADD_TOKEN_STANDARDS as $std): ?>
                                <option value="<?php echo esc_attr($std); ?>"><?php echo esc_html($std); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label style="display:flex;flex-direction:column;font-size:12px;">
                        Total supply
                        <input type="number" name="add_total_supply" min="0" step="1"
                               placeholder="optional" style="width:120px;">
                    </label>

                    <label style="display:flex;flex-direction:column;font-size:12px;">
                        Image URL
                        <input type="url" name="add_image_url" placeholder="optional" style="min-width:240px;">
                    </label>

                    <button type="submit" class="button button-primary">Add Collection</button>
                </form>
            </details>

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
                    if ($isVerified) {
                        $allUrl = add_query_arg('vstate', 'verified', $allUrl);
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
                        if ($isVerified) {
                            $pillUrl = add_query_arg('vstate', 'verified', $pillUrl);
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
                           placeholder="CW-721 contract address (cosmos1… / inj1… / …)"
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
                <?php if ($isVerified): ?>
                    <input type="hidden" name="vstate" value="verified">
                <?php endif; ?>
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
                <?php if ($isVerified): ?>
                    <input type="hidden" name="vstate" value="verified">
                <?php endif; ?>

                <p class="submit" style="margin:0 0 12px 0;">
                    <button type="submit" class="button button-primary">Save Verification Changes</button>
                    <button type="submit"
                            name="bcc_vc_action"
                            value="provision"
                            class="button"
                            onclick="return confirm('Create communities for all verified collections that don\'t have one yet? This creates a closed holder community per collection. Existing communities are left untouched, and no members are added. Continue?');">Create Communities</button>
                </p>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th style="width:90px;">Verified</th>
                            <th style="width:60px;"></th>
                            <th>Collection</th>
                            <th style="width:90px;">Source</th>
                            <th>Chain</th>
                            <th>Contract</th>
                            <th style="width:90px;"
                                title="Users who explicitly opted in via the stance panel — the strongest demand signal (airdrop-proof). The unverified queue ranks by it.">
                                Waitlist
                            </th>
                            <th style="width:80px;"
                                title="Users who flagged this as airdropped scam junk. Flagged rows sink to the bottom; at the soft-hide threshold the collection stops surfacing to users.">
                                Flags
                            </th>
                            <th style="width:110px;"
                                title="Linked platform wallets currently holding this collection (passive — airdrops inflate it; tiebreaker only).">
                                Linked holders
                            </th>
                            <th style="width:100px;" title="Marketplace-wide unique holders (upstream metadata).">Holders</th>
                            <th style="width:160px;" title="Members of the collection's holder community (only meaningful once verified).">
                                Community
                            </th>
                            <th style="width:80px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($listing['items'] === []): ?>
                            <tr>
                                <td colspan="<?php echo (int) self::TABLE_COLSPAN; ?>"><em>
                                    <?php if ($isVerified): ?>
                                        No verified collections yet. Verify a collection in the Unverified tab to give its holders a community.
                                    <?php else: ?>
                                        No unverified collections. Add one above, or connect a wallet to populate this list.
                                    <?php endif; ?>
                                </em></td>
                            </tr>
                        <?php else: foreach ($listing['items'] as $row): ?>
                            <?php
                            $tokenStandard = (string) ($row->token_standard ?? '');
                            $rowId         = (int) $row->id;
                            $rowVerified   = (int) $row->is_verified === 1;
                            $source        = (string) ($row->source ?? 'discovery');
                            ?>
                            <tr data-collection-id="<?php echo $rowId; ?>">
                                <td>
                                    <input type="hidden" name="known[]" value="<?php echo $rowId; ?>">
                                    <label class="bcc-vc-toggle" style="display:inline-flex;align-items:center;gap:4px;">
                                        <input type="checkbox"
                                               class="bcc-vc-verify"
                                               name="verified[<?php echo $rowId; ?>]"
                                               value="1"
                                               <?php checked($rowVerified); ?>>
                                        <span class="bcc-vc-toggle-status" style="font-size:11px;color:#999;"></span>
                                    </label>
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
                                <td>
                                    <?php
                                    // Source badge — where the row came from.
                                    //   manual    → operator added it by hand (safe to remove)
                                    //   toplist   → auto-pulled from a chain's top-collections
                                    //               sync (was 'stargaze' pre-Hub-migration)
                                    //   discovery → seen by the holdings transfer indexer
                                    $badge = [
                                        'manual'    => ['Manual', '#2271b1'],
                                        'toplist'   => ['Top list', '#646970'],
                                        'discovery' => ['Discovered', '#646970'],
                                    ][$source] ?? ['Discovered', '#646970'];
                                    ?>
                                    <span style="display:inline-block;padding:1px 8px;border-radius:10px;font-size:11px;color:#fff;background:<?php echo esc_attr($badge[1]); ?>;">
                                        <?php echo esc_html($badge[0]); ?>
                                    </span>
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
                                <?php
                                $rowKey    = CollectionDemandService::key((int) $row->chain_id, (string) $row->contract_address);
                                $rowSignal = $signals[$rowKey] ?? ['waitlist' => 0, 'spam' => 0];
                                ?>
                                <td>
                                    <?php if ($rowSignal['waitlist'] > 0): ?>
                                        <strong style="color:#2271b1;"><?php echo number_format_i18n($rowSignal['waitlist']); ?></strong>
                                    <?php else: ?>
                                        <span style="color:#999;">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($rowSignal['spam'] > 0): ?>
                                        <strong style="color:#d63638;">⚑ <?php echo number_format_i18n($rowSignal['spam']); ?></strong>
                                    <?php else: ?>
                                        <span style="color:#999;">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $demandCount = $demand[$rowKey] ?? 0;
                                    if ($demandCount > 0): ?>
                                        <strong style="color:#00a32a;"><?php echo number_format_i18n($demandCount); ?></strong>
                                    <?php else: ?>
                                        <span style="color:#999;">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo number_format_i18n((int) ($row->unique_holders ?? 0)); ?></td>
                                <td class="bcc-vc-community">
                                    <?php
                                    // Reuse-join: collection (chain_id, contract) →
                                    // PeepSo group post id → member count.
                                    // Cell semantics:
                                    //   unverified         → "—"               (no group possible)
                                    //   verified, no group → "Create now" btn   (provision inline)
                                    //   verified + group   → linked member count
                                    // N+1 caveat: 50 collections × 2 queries per row.
                                    // Acceptable on this admin-only page; revisit if
                                    // perf bites or perPage grows materially.
                                    if (!$rowVerified) {
                                        echo '<span style="color:#999;">&mdash;</span>';
                                    } else {
                                        $groupId = GatedGroupRepository::findGroupForCollection(
                                            (int) $row->chain_id,
                                            (string) $row->contract_address
                                        );
                                        if ($groupId === null): ?>
                                            <button type="button"
                                                    class="button button-small bcc-vc-create-community"
                                                    title="Create the closed holder community for this collection now, instead of waiting for the daily sweep.">
                                                Create now
                                            </button>
                                        <?php else:
                                            $count    = PeepSoGroupRepository::countGroupMembers($groupId);
                                            $permalink = get_permalink($groupId);
                                            if (is_string($permalink) && $permalink !== ''): ?>
                                                <a href="<?php echo esc_url($permalink); ?>" target="_blank" rel="noopener">
                                                    <?php echo number_format_i18n($count); ?> member<?php echo $count === 1 ? '' : 's'; ?>
                                                </a>
                                            <?php else:
                                                echo number_format_i18n($count) . ' member' . ($count === 1 ? '' : 's');
                                            endif;
                                        endif;
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    // Hide/Unhide — the flag-don't-delete kill switch. A
                                    // DENY rule on the contract survives rediscovery
                                    // (delete doesn't: the next wallet link would land
                                    // the row again).
                                    $rowRule = \BCC\Trust\Onchain\Repositories\NftSpamContractRepository::getRule(
                                        (int) $row->chain_id,
                                        (string) $row->contract_address
                                    );
                                    $isHidden = $rowRule === \BCC\Trust\Onchain\Repositories\NftSpamContractRepository::RULE_DENY;
                                    ?>
                                    <?php if ($isHidden): ?>
                                        <span style="display:inline-block;margin-bottom:4px;padding:1px 8px;border-radius:10px;font-size:11px;color:#fff;background:#d63638;">HIDDEN</span>
                                        <button type="submit"
                                                name="bcc_vc_action"
                                                value="unhide_<?php echo $rowId; ?>"
                                                class="button button-small"
                                                title="Restore this collection for users (writes an explicit ALLOW rule on the contract).">
                                            Unhide
                                        </button>
                                    <?php else: ?>
                                        <button type="submit"
                                                name="bcc_vc_action"
                                                value="hide_<?php echo $rowId; ?>"
                                                class="button button-small"
                                                title="Hide this collection from all user-facing surfaces and block rediscovery (writes a DENY rule on the contract — reversible, unlike delete)."
                                                onclick="return confirm('Hide this collection from users? The contract gets a DENY rule, so it stays hidden even if wallets holding it link later. Reversible via Unhide.');">
                                            Hide
                                        </button>
                                    <?php endif; ?>
                                    <button type="submit"
                                            name="bcc_vc_action"
                                            value="delete_<?php echo $rowId; ?>"
                                            class="button button-small button-link-delete"
                                            style="color:#b32d2e;"
                                            onclick="return confirm('Remove this collection from the list? This deletes the row only — rediscovery can bring it back. Use Hide to keep it away permanently. A collection with a live community can\'t be removed until its community is gone.');">
                                        Remove
                                    </button>
                                </td>
                            </tr>
                            <?php
                            // Scanner detail sub-row. Pre-fetched above; no
                            // query here. Absent for anything the CosmWasm
                            // scanner has no record of.
                            $scannerKey = (int) $row->chain_id . '|' . strtolower((string) $row->contract_address);
                            if (isset($scannerCandidates[$scannerKey])) {
                                $candidateRow = $scannerCandidates[$scannerKey];
                                $familyKey    = (int) $row->chain_id . '|' . (int) $candidateRow->code_id;
                                CosmwasmScannerPanel::renderCandidateDetail(
                                    $row,
                                    $candidateRow,
                                    $scannerFamilies[$familyKey] ?? null,
                                    $rowVerified,
                                    self::TABLE_COLSPAN
                                );
                            }
                            ?>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>

                <p class="submit">
                    <button type="submit" class="button">Save Verification Changes</button>
                    <span style="color:#646970;font-size:12px;margin-left:8px;">
                        Verify toggles save instantly. This button is a fallback if JavaScript is off.
                    </span>
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
        <script>
        (function () {
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce   = <?php echo wp_json_encode(wp_create_nonce(self::AJAX_NONCE_KEY)); ?>;
            var ACTION_TOGGLE    = <?php echo wp_json_encode(self::AJAX_ACTION_TOGGLE); ?>;
            var ACTION_PROVISION = <?php echo wp_json_encode(self::AJAX_ACTION_PROVISION); ?>;

            function post(action, collectionId, extra) {
                var body = new FormData();
                body.append('action', action);
                body.append('nonce', nonce);
                body.append('collection_id', collectionId);
                if (extra) { Object.keys(extra).forEach(function (k) { body.append(k, extra[k]); }); }
                return fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function (r) { return r.json(); });
            }

            // Instant verify toggle.
            document.querySelectorAll('.bcc-vc-verify').forEach(function (cb) {
                cb.addEventListener('change', function () {
                    var row    = cb.closest('tr');
                    var id     = row && row.getAttribute('data-collection-id');
                    var status = row && row.querySelector('.bcc-vc-toggle-status');
                    if (!id) { return; }
                    cb.disabled = true;
                    if (status) { status.textContent = 'saving…'; status.style.color = '#999'; }
                    post(ACTION_TOGGLE, id, { verify: cb.checked ? '1' : '0' })
                        .then(function (resp) {
                            cb.disabled = false;
                            if (resp && resp.success) {
                                if (status) { status.textContent = '✓'; status.style.color = '#00a32a'; }
                                setTimeout(function () { if (status) { status.textContent = ''; } }, 1500);
                            } else {
                                cb.checked = !cb.checked; // revert
                                if (status) { status.textContent = '✗'; status.style.color = '#d63638'; }
                            }
                        })
                        .catch(function () {
                            cb.disabled = false;
                            cb.checked = !cb.checked;
                            if (status) { status.textContent = '✗'; status.style.color = '#d63638'; }
                        });
                });
            });

            // Inline "Create now" community provisioning.
            document.querySelectorAll('.bcc-vc-create-community').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var row  = btn.closest('tr');
                    var cell = btn.closest('.bcc-vc-community');
                    var id   = row && row.getAttribute('data-collection-id');
                    if (!id) { return; }
                    btn.disabled = true;
                    btn.textContent = 'Creating…';
                    post(ACTION_PROVISION, id)
                        .then(function (resp) {
                            if (resp && resp.success) {
                                if (cell) { cell.innerHTML = '<span style="color:#00a32a;font-size:12px;">Community created</span>'; }
                            } else {
                                btn.disabled = false;
                                btn.textContent = 'Create now';
                                alert((resp && resp.data && resp.data.message) || 'Could not create community.');
                            }
                        })
                        .catch(function () {
                            btn.disabled = false;
                            btn.textContent = 'Create now';
                            alert('Network error creating community.');
                        });
                });
            });
        })();
        </script>
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

        if ($action === 'add_collection') {
            return self::handleAddCollection();
        }

        // Per-row "Remove" button. Encodes the collection id in the
        // action value (`delete_<id>`), same shape as testquery_<id>.
        if (strpos($action, 'delete_') === 0) {
            $collectionId = (int) substr($action, strlen('delete_'));
            return self::handleDeleteCollection($collectionId);
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

        // Per-row Hide / Unhide. Writes an operator rule to the spam
        // table rather than touching the collection row — a DENY rule
        // is what survives rediscovery (wallet-link discovery consults
        // it before landing rows), so a hidden scam can't resurrect
        // itself the way a deleted row would.
        if (strpos($action, 'hide_') === 0) {
            return self::handleHideToggle((int) substr($action, strlen('hide_')), true);
        }
        if (strpos($action, 'unhide_') === 0) {
            return self::handleHideToggle((int) substr($action, strlen('unhide_')), false);
        }

        // CosmWasm scanner controls. Same `<verb>_<id>` encoding as the
        // per-row buttons above, prefixed `cw_` so the scanner's chain ids
        // can never be mistaken for a collection id.
        if (strpos($action, 'cw_pause_') === 0) {
            return self::handleScannerPause((int) substr($action, strlen('cw_pause_')), true);
        }
        if (strpos($action, 'cw_resume_') === 0) {
            return self::handleScannerPause((int) substr($action, strlen('cw_resume_')), false);
        }
        if (strpos($action, 'cw_backfill_') === 0) {
            return self::handleScannerBackfill((int) substr($action, strlen('cw_backfill_')));
        }
        if (strpos($action, 'cw_retry_') === 0) {
            return self::handleScannerForceRetry((int) substr($action, strlen('cw_retry_')));
        }

        return [];
    }

    /**
     * Pause / resume the CosmWasm scanner for ONE chain.
     *
     * The switch is `wp_bcc_chain_checkpoints.cw_discovery_state`, which
     * the worker already honours in two places — it refuses to prepare a
     * paused chain, and the backfill rotation query excludes one. There is
     * deliberately no separate "paused" option: a second flag is how
     * "paused in the UI, still hammering the LCD" happens.
     *
     * Resume does NOT restore a remembered previous value (there is
     * nowhere it was kept). It re-derives the state from the chain's own
     * durable progress, so a chain whose backfill had completed comes back
     * as `backfilled` and is not re-walked.
     *
     * @return list<array{type: string, message: string}>
     */
    private static function handleScannerPause(int $chainId, bool $pause): array
    {
        if ($chainId <= 0) {
            return [['type' => 'error', 'message' => 'Scanner: invalid chain.']];
        }

        $chain = ChainRepository::getById($chainId);
        $slug  = $chain !== null ? (string) $chain->slug : (string) $chainId;

        $ok = $pause
            ? ChainCheckpointRepository::pauseCwDiscovery($chainId)
            : ChainCheckpointRepository::resumeCwDiscovery($chainId);

        if (!$ok) {
            return [[
                'type'    => 'warning',
                'message' => $pause
                    ? sprintf('Scanner: %s could not be paused — it is already paused, or it has no CosmWasm module.', $slug)
                    : sprintf('Scanner: %s is not paused, so there was nothing to resume.', $slug),
            ]];
        }

        \BCC\Core\Log\Logger::info('[bcc-trust] CosmWasm scanner pause toggle', [
            'action'   => 'cosmwasm_scanner_pause',
            'chain_id' => $chainId,
            'paused'   => $pause,
            'operator' => get_current_user_id(),
        ]);

        if ($pause) {
            return [[
                'type'    => 'success',
                'message' => sprintf(
                    'Scanner paused for %s. Nothing runs for it — no backfill, no daily pass, no retries — and its progress is kept.',
                    $slug
                ),
            ]];
        }

        $row   = ChainCheckpointRepository::get($chainId);
        $state = $row !== null ? (string) $row->cw_discovery_state : '';

        return [[
            'type'    => 'success',
            'message' => sprintf(
                'Scanner resumed for %s (state: %s).',
                $slug,
                $state !== '' ? $state : 'unknown'
            ),
        ]];
    }

    /**
     * Run ONE bounded slice of the historical backfill for a chain, now.
     *
     * The gates are re-checked here even though the button renders
     * disabled without them: a disabled attribute is a UI hint, not
     * authorization, and a crafted POST must hit the same fail-closed
     * answer the cron path does.
     *
     * The slice runs with a SMALLER budget than a cron tick — see
     * {@see ADMIN_BACKFILL_REQUESTS} — because this one executes inside an
     * admin page load. It is genuinely one slice: the worker's advisory
     * lock, wall clock and request budget all still apply, and progress is
     * written durably as it goes, so clicking it is equivalent to the next
     * scheduled tick arriving early.
     *
     * @return list<array{type: string, message: string}>
     */
    private static function handleScannerBackfill(int $chainId): array
    {
        if ($chainId <= 0) {
            return [['type' => 'error', 'message' => 'Scanner: invalid chain.']];
        }

        if (!CosmwasmDiscoveryGate::discoveryEnabled()) {
            return [[
                'type'    => 'error',
                'message' => 'Scanner: discovery is switched off (BCC_COSMWASM_DISCOVERY_ENABLED is not defined in wp-config.php). Nothing was run.',
            ]];
        }
        if (!CosmwasmDiscoveryGate::backfillEnabled()) {
            return [[
                'type'    => 'error',
                'message' => 'Scanner: the historical backfill is switched off (BCC_COSMWASM_BACKFILL_ENABLED is not defined in wp-config.php). Nothing was run.',
            ]];
        }

        $chain = ChainRepository::getById($chainId);
        if ($chain === null) {
            return [['type' => 'error', 'message' => 'Scanner: chain not found.']];
        }
        $slug = (string) $chain->slug;

        $checkpoint = ChainCheckpointRepository::get($chainId);
        if ($checkpoint !== null
            && (string) $checkpoint->cw_discovery_state === ChainCheckpointRepository::CW_STATE_PAUSED
        ) {
            return [[
                'type'    => 'warning',
                'message' => sprintf('Scanner: %s is paused. Resume it before running a slice.', $slug),
            ]];
        }

        CosmwasmDiscoveryWorker::runBackfillForChain(
            $chainId,
            new CosmwasmTickBudget(self::ADMIN_BACKFILL_REQUESTS, self::ADMIN_BACKFILL_SECONDS)
        );

        \BCC\Core\Log\Logger::info('[bcc-trust] CosmWasm scanner manual backfill slice', [
            'action'   => 'cosmwasm_scanner_backfill_slice',
            'chain_id' => $chainId,
            'operator' => get_current_user_id(),
        ]);

        $after = ChainCheckpointRepository::get($chainId);
        $state = $after !== null ? (string) $after->cw_discovery_state : 'unknown';
        $error = $after !== null && is_string($after->cw_last_error) && $after->cw_last_error !== ''
            ? (string) $after->cw_last_error
            : null;

        if ($error !== null) {
            return [[
                'type'    => 'warning',
                'message' => sprintf(
                    'Scanner: ran a slice for %s and it recorded a problem (state: %s). Progress was kept and it will be retried. Reason: %s',
                    $slug,
                    $state,
                    $error
                ),
            ]];
        }

        return [[
            'type'    => 'success',
            'message' => sprintf(
                'Scanner: ran one backfill slice for %s (state: %s). Run it again to continue, or leave it to the scheduled ticks.',
                $slug,
                $state
            ),
        ]];
    }

    /**
     * Clear the wait on unresolved scanner rows for one chain.
     *
     * "Unresolved" means `inconclusive` or `temporarily_unreachable` only.
     * A settled `not_cw721` is never re-checked (that exclusion lives in
     * the repository, not here), a decided CW-721 has nothing to redecide,
     * and a DENY-ruled contract stays suppressed — a force-retry button
     * must not become a back door around the operator's own hide.
     *
     * @return list<array{type: string, message: string}>
     */
    private static function handleScannerForceRetry(int $chainId): array
    {
        if ($chainId <= 0) {
            return [['type' => 'error', 'message' => 'Scanner: invalid chain.']];
        }

        if (!CosmwasmDiscoveryGate::discoveryEnabled()) {
            return [[
                'type'    => 'error',
                'message' => 'Scanner: discovery is switched off, so a retry would never run. Nothing was changed.',
            ]];
        }

        $chain = ChainRepository::getById($chainId);
        if ($chain === null) {
            return [['type' => 'error', 'message' => 'Scanner: chain not found.']];
        }

        $families  = CosmwasmCodeFamilyRepository::forceRetryUnresolved($chainId, self::FORCE_RETRY_LIMIT);
        $contracts = CosmwasmContractRepository::forceRetryUnresolved($chainId, self::FORCE_RETRY_LIMIT);

        \BCC\Core\Log\Logger::info('[bcc-trust] CosmWasm scanner force retry', [
            'action'    => 'cosmwasm_scanner_force_retry',
            'chain_id'  => $chainId,
            'families'  => $families,
            'contracts' => $contracts,
            'operator'  => get_current_user_id(),
        ]);

        return [[
            'type'    => 'success',
            'message' => sprintf(
                'Scanner: queued %d code famil%s and %d contract%s on %s for another look. Settled non-NFT results and hidden contracts were left alone.',
                $families,
                $families === 1 ? 'y' : 'ies',
                $contracts,
                $contracts === 1 ? '' : 's',
                (string) $chain->slug
            ),
        ]];
    }

    /**
     * Hide = RULE_DENY (drops from every user-facing surface + blocks
     * rediscovery). Unhide = RULE_ALLOW (explicit operator allow — also
     * wins over the name heuristics, which is exactly what "I looked at
     * this and it's fine" means).
     *
     * ── THE RULE IS AUTHORITY; THE SCANNER FLAG IS A CACHE ──────────────
     * Writing the rule is not the whole job. The CosmWasm scanner keeps a
     * `denied` flag ON ITS OWN INVENTORY so its queue predicates stay
     * cheap and indexed, and that flag is what
     * {@see CosmwasmContractRepository::findEmittable()} and
     * {@see CosmwasmContractRepository::countDenied()} read. Leaving it
     * stale means the hidden contract keeps sitting in the emit queue and
     * the panel keeps counting it as visible — the emit path's live rule
     * re-check catches it every single sweep, forever, instead of it
     * being dropped once.
     *
     * So the rule write is followed by
     * {@see CosmwasmDiscoveryService::syncDenyFlags()} — DENY POINT 4,
     * which had no production caller at all before this. It is called
     * with the SINGLE affected contract; it is already bounded to 200 and
     * needs no widening, and nothing here scans the table.
     *
     * ── THE LOOKUP IS KEYED, NOT POSITIONAL ─────────────────────────────
     * {@see CollectionRepository::findManyByIds()} returns a map keyed by
     * collection id. This handler read `$rows[0]`, which is null for every
     * id the admin page can actually emit, so hide and unhide both stopped
     * at "collection not found" and never wrote a rule at all. See the
     * comment on the lookup below before touching it.
     *
     * @return list<array{type: string, message: string}>
     */
    private static function handleHideToggle(int $collectionId, bool $hide): array
    {
        // THE LOOKUP CONTRACT. `findManyByIds()` ends
        // `$map[(int) $row->id] = $row` — a MAP KEYED BY COLLECTION ID,
        // not a positional list. The original `$rows[0]` therefore
        // resolved to null for every id except the impossible 0, and
        // EVERY hide and unhide answered "Hide: collection not found."
        // while writing nothing. Proven on staging:
        // `findManyByIds_keys=261764, rows[0]_is_NULL`.
        //
        // Index by the id that was asked for. NOT `reset()`, NOT
        // `array_values()[0]`, NOT `current()` — a first-row shortcut
        // turns a wrongly-shaped map into a plausible WRONG answer, and
        // the wrong answer here is a permanent DENY rule on somebody
        // else's contract.
        $rows = CollectionRepository::findManyByIds([$collectionId]);
        $row  = $rows[$collectionId] ?? null;
        if ($row === null) {
            return [['type' => 'error', 'message' => 'Hide: collection not found.']];
        }

        // Belt AND braces. The map key already implies this for the real
        // repository — that is exactly why it is cheap to assert and why
        // it stays: one comparison makes "we denied the wrong contract"
        // unreachable however the lookup is re-implemented later. A row
        // whose own id disagrees with the key is a data-integrity anomaly,
        // not a miss, so it is logged and NOTHING is written: no rule, no
        // cached flag, no inventory sync.
        $rowId = (int) $row->id;
        if ($rowId !== $collectionId) {
            \BCC\Core\Log\Logger::error('[bcc-trust] hide toggle: collection lookup returned a mismatched row', [
                'action'       => 'verify_collections_hide_id_mismatch',
                'requested_id' => $collectionId,
                'returned_id'  => $rowId,
                'operator'     => get_current_user_id(),
            ]);

            return [[
                'type'    => 'error',
                'message' => 'Hide: the collection lookup returned a different collection than the one asked for. '
                    . 'Nothing was changed. Check the bcc-trust error log.',
            ]];
        }

        $chainId  = (int) $row->chain_id;
        $contract = (string) $row->contract_address;
        $rule     = $hide
            ? \BCC\Trust\Onchain\Repositories\NftSpamContractRepository::RULE_DENY
            : \BCC\Trust\Onchain\Repositories\NftSpamContractRepository::RULE_ALLOW;

        $ok = \BCC\Trust\Onchain\Repositories\NftSpamContractRepository::addRule(
            $chainId,
            $contract,
            $rule,
            sprintf('operator %s via Verify Collections', $hide ? 'hide' : 'unhide')
        );

        if (!$ok) {
            // The rule did not land, so the cached flag is deliberately NOT
            // touched: syncing it here would suppress (or un-suppress) a
            // contract on the strength of a rule that does not exist.
            return [['type' => 'error', 'message' => 'Hide: rule write failed. Check the bcc-trust error log.']];
        }

        $scannerSynced = self::syncScannerDenyFlag($chainId, $contract, $hide);

        \BCC\Core\Log\Logger::info('[bcc-trust] Verify Collections hide toggle', [
            'action'         => 'verify_collections_hide',
            'collection_id'  => $collectionId,
            'chain_id'       => $chainId,
            'contract'       => $contract,
            'rule'           => $rule,
            'scanner_synced' => $scannerSynced,
            'operator'       => get_current_user_id(),
        ]);

        $name = (string) ($row->collection_name ?? $contract);

        if (!$scannerSynced) {
            // PARTIAL COMPLETION. Naming both halves matters: the operator's
            // intent IS in force (the rule is authoritative and every emit
            // re-checks it live), but the scanner's cached flag and the
            // "hidden by a rule" count derived from it are now stale, so the
            // page they are looking at will disagree with reality.
            return [[
                'type'    => 'warning',
                'message' => sprintf(
                    '%s "%s" %s users — the %s rule was written and IS in force. '
                        . 'What did NOT happen: the CosmWasm scanner\'s cached flag for this contract could not be updated, '
                        . 'so its inventory still lists the contract the old way and the "hidden by a rule" count on this page is stale. '
                        . 'Nothing is exposed by that — the emit path re-checks the live rule on every pass — but click %s again once the '
                        . 'database error clears so the scanner stops reconsidering it. Check the bcc-trust error log.',
                    $hide ? 'Hid' : 'Restored',
                    $name,
                    $hide ? 'from' : 'for',
                    $rule,
                    $hide ? 'Hide' : 'Unhide'
                ),
            ]];
        }

        return [[
            'type'    => 'success',
            'message' => sprintf(
                '%s "%s" %s users (%s rule on the contract).',
                $hide ? 'Hid' : 'Restored',
                $name,
                $hide ? 'from' : 'for',
                $rule
            ),
        ]];
    }

    /**
     * Sync the scanner's cached deny flag for ONE contract, and report
     * whether it actually landed.
     *
     * ── WHY IT VERIFIES INSTEAD OF TRUSTING THE RETURN ──────────────────
     * {@see CosmwasmDiscoveryService::syncDenyFlags()} returns "how many
     * flags changed", and 0 is a perfectly good answer (the flag was
     * already right). It is therefore useless as a success signal, and it
     * is 0 in both of the ways this can fail: its per-contract read came
     * back empty because the query errored, or its UPDATE did not stick.
     *
     * So the flag is READ BACK through
     * {@see CosmwasmContractRepository::deniedFlag()}, which throws rather
     * than confusing "no row" with "could not read". Three outcomes:
     *   null  → the scanner has never inventoried this contract, so there
     *           was nothing to sync and that is a success;
     *   ===   → the cache agrees with the rule;
     *   !==   → the write silently did not land — report partial.
     */
    private static function syncScannerDenyFlag(int $chainId, string $contract, bool $hide): bool
    {
        try {
            \BCC\Trust\Onchain\Services\CosmwasmDiscoveryService::syncDenyFlags($chainId, [$contract]);

            $flag = CosmwasmContractRepository::deniedFlag($chainId, $contract);
        } catch (RepositoryReadFailure $e) {
            \BCC\Core\Log\Logger::error('[bcc-trust] hide toggle: scanner deny-flag sync could not be confirmed', [
                'action'   => 'verify_collections_hide_sync_failed',
                'chain_id' => $chainId,
                'contract' => $contract,
                'method'   => $e->repositoryMethod(),
                'db_error' => $e->dbError(),
            ]);

            return false;
        }

        return $flag === null || $flag === $hide;
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
     * Per-row "Remove" handler. Deletes a single collection row, with a
     * guard: a row whose holder community already exists is refused, so
     * deletion can't silently orphan a live PeepSo group. The operator
     * must unverify (and tear the group down) first.
     *
     * @return list<array{type: string, message: string}>
     */
    private static function handleDeleteCollection(int $collectionId): array
    {
        if ($collectionId <= 0) {
            return [['type' => 'error', 'message' => 'Remove: invalid collection id.']];
        }

        $coll = CollectionRepository::getByIdWithChain($collectionId);
        if ($coll === null) {
            return [['type' => 'error', 'message' => 'Remove: collection not found (already deleted?).']];
        }

        $contract = (string) $coll->contract_address;
        $chainId  = (int) $coll->chain_id;

        $groupId = GatedGroupRepository::findGroupForCollection($chainId, $contract);
        if ($groupId !== null) {
            return [[
                'type'    => 'warning',
                'message' => sprintf(
                    'Remove blocked: %s still has a holder community (group #%d). Unverify it and remove the community first, then delete.',
                    $contract,
                    $groupId
                ),
            ]];
        }

        $deleted = CollectionRepository::deleteById($collectionId);
        if ($deleted < 1) {
            return [['type' => 'error', 'message' => 'Remove: nothing was deleted.']];
        }

        \BCC\Core\Log\Logger::info('[bcc-trust] Verify Collections remove', [
            'action'        => 'verify_collections_remove',
            'collection_id' => $collectionId,
            'chain_id'      => $chainId,
            'contract'      => $contract,
            'operator'      => get_current_user_id(),
        ]);

        return [[
            'type'    => 'success',
            'message' => sprintf('Removed collection %s.', $contract),
        ]];
    }

    /**
     * Token standards offered in the Add Collection form. Operator-typed
     * value is constrained to this set so a malformed standard can't slip
     * into a row that downstream code branches on.
     *
     * @var list<string>
     */
    private const ADD_TOKEN_STANDARDS = ['CW-721', 'ERC-721', 'ERC-1155', 'SPL'];

    /**
     * Operator-curated "Add Collection" handler. Inserts one collection
     * row (chain + contract + metadata) so chains with no auto-discovery
     * (e.g. a Cosmos Hub CW-721 — no public Hub indexer) can be onboarded.
     *
     * The row lands unverified — the operator still flips `is_verified`
     * via the existing checkbox, which is what gates holdings queries and
     * group provisioning. For Cosmos chains we validate the contract is a
     * real CW-721 (and auto-fill the name) before inserting; other chain
     * types are trusted as typed (no generic on-chain validator exists).
     *
     * @return list<array{type: string, message: string}>
     */
    private static function handleAddCollection(): array
    {
        $chainId  = isset($_POST['add_chain_id']) ? (int) $_POST['add_chain_id'] : 0;
        $contract = isset($_POST['add_contract_address'])
            ? trim(sanitize_text_field((string) $_POST['add_contract_address']))
            : '';
        $name     = isset($_POST['add_collection_name'])
            ? trim(sanitize_text_field((string) $_POST['add_collection_name']))
            : '';
        $standard = isset($_POST['add_token_standard'])
            ? sanitize_text_field((string) $_POST['add_token_standard'])
            : '';
        $imageUrl = isset($_POST['add_image_url'])
            ? trim((string) $_POST['add_image_url'])
            : '';
        $supplyRaw = isset($_POST['add_total_supply'])
            ? trim((string) $_POST['add_total_supply'])
            : '';

        if ($chainId <= 0 || $contract === '') {
            return [['type' => 'error', 'message' => 'Add Collection: chain and contract address are required.']];
        }

        if ($standard !== '' && !in_array($standard, self::ADD_TOKEN_STANDARDS, true)) {
            return [['type' => 'error', 'message' => 'Add Collection: unknown token standard.']];
        }

        $chain = ChainRepository::getById($chainId);
        if ($chain === null) {
            return [['type' => 'error', 'message' => 'Add Collection: chain not found.']];
        }

        $chainType = (string) $chain->chain_type;
        $chainSlug = (string) $chain->slug;
        $notices   = [];

        // Cosmos: validate the contract is a real CW-721 before inserting,
        // and auto-fill the collection name from contract_info when blank.
        if ($chainType === 'cosmos') {
            if (!FetcherFactory::has_driver($chainType)) {
                return [['type' => 'error', 'message' => 'Add Collection: no fetcher driver for ' . $chainSlug]];
            }

            $fetcher = FetcherFactory::make_for_chain($chain);
            if (!($fetcher instanceof CosmosFetcher)) {
                return [['type' => 'error', 'message' => 'Add Collection: fetcher driver mismatch for ' . $chainSlug]];
            }

            $info = $fetcher->testCw721ContractInfo($contract);
            if ($info === null) {
                return [[
                    'type'    => 'error',
                    'message' => sprintf(
                        'Add Collection: %s is not a reachable CW-721 contract on %s (contract_info failed). Not added. Check the address, or the bcc-trust error log for the LCD response.',
                        $contract,
                        $chainSlug
                    ),
                ]];
            }

            if ($name === '' && isset($info['name']) && is_string($info['name'])) {
                $name = trim($info['name']);
            }
            if ($standard === '') {
                $standard = 'CW-721';
            }
        } else {
            $notices[] = [
                'type'    => 'warning',
                'message' => sprintf(
                    'Add Collection: %s is a %s chain — no on-chain validation available, the contract was trusted as entered. Double-check the address before verifying.',
                    $contract,
                    $chainType
                ),
            ];
        }

        $data = [
            'chain_id'         => $chainId,
            'contract_address' => $contract,
            'collection_name'  => $name !== '' ? $name : null,
            'token_standard'   => $standard !== '' ? $standard : null,
            'image_url'        => $imageUrl !== '' ? $imageUrl : null,
            'total_supply'     => ($supplyRaw !== '' && ctype_digit($supplyRaw)) ? (int) $supplyRaw : null,
        ];

        $rowId = CollectionRepository::addManual($data);
        if ($rowId === false) {
            $notices[] = ['type' => 'error', 'message' => 'Add Collection: insert failed. See the bcc-trust error log.'];
            return $notices;
        }

        \BCC\Core\Log\Logger::info('[bcc-trust] Verify Collections add (manual)', [
            'action'         => 'verify_collections_add_manual',
            'row_id'         => (int) $rowId,
            'chain_id'       => $chainId,
            'chain_slug'     => $chainSlug,
            'contract'       => $contract,
            'token_standard' => $standard,
            'operator'       => get_current_user_id(),
        ]);

        $notices[] = [
            'type'    => 'success',
            'message' => sprintf(
                'Added "%s" (%s) on %s. It is unverified — review it below and check Verified to give its holders a community.',
                $name !== '' ? $name : '(no name)',
                $contract,
                $chainSlug
            ),
        ];

        return $notices;
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

        \BCC\Core\Log\Logger::info('[bcc-trust] Verify Collections save', [
            'action'    => 'verify_collections_save',
            'verified'  => count($verify),
            'unverified' => count($unverify),
            'changed'   => $changed,
            'operator'  => get_current_user_id(),
        ]);

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

        $result = OnchainPlugin::instance()->gatedGroupProvisioningService()->provisionAll();

        \BCC\Core\Log\Logger::info('[bcc-trust] Verify Collections provision (manual)', [
            'action'   => 'gated_group_provision_manual',
            'created'  => (int) ($result['created'] ?? 0),
            'skipped'  => (int) ($result['skipped'] ?? 0),
            'errors'   => count($result['errors'] ?? []),
            'operator' => get_current_user_id(),
        ]);

        $message = sprintf(
            'Communities: %d created, %d skipped (already exist or missing metadata).',
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
