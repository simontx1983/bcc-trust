<?php

namespace BCC\Trust\Onchain\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Trust\Onchain\OnchainPlugin;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Services\CosmosEndpointTransition;
use BCC\Trust\Onchain\Services\CosmosEndpointReview;
use BCC\Trust\Onchain\ValueObjects\CosmosEndpointPolicy;
use BCC\Trust\Onchain\Repositories\HallRepository;
use BCC\Trust\Onchain\Repositories\ValidatorRepository;
use BCC\Trust\Onchain\Services\HallProvisioningService;
use BCC\Trust\Onchain\Factories\FetcherFactory;

/**
 * Admin page: Chains
 *
 * Two sub-tabs:
 *  - Validators: per-chain validator refresh
 *  - Identity:   per-chain description, icon and colour
 *
 * ── NFT DISCOVERY USED TO BE A THIRD SUB-TAB ────────────────────────────
 * It is now its own page, {@see NftDiscoveryPage}, which absorbed this
 * page's CosmWasm/CW-721 section and all six of its discovery routes
 * unchanged. It was promoted rather than copied: a per-chain NFT control
 * plane needs family navigation and a capability matrix of its own, and two
 * surfaces both called "NFT Discovery" would be a place for an operator to
 * read two different answers about one chain.
 *
 * `?page=bcc-onchain-chains&subtab=nft-discovery` still works — see
 * {@see NftDiscoveryPage::maybe_redirect_legacy_url()}.
 *
 * @phpstan-import-type ChainRow from ChainRepository
 * @phpstan-import-type ReviewRecord from CosmosEndpointReview
 * @phpstan-import-type ValidatorCountByChain from ValidatorRepository
 */
class ChainsPage
{
    const PAGE_SLUG = 'bcc-onchain-chains';

    public static function register_page(): void
    {
        // Audit follow-up: relocated under BCC System alongside the
        // other onchain admin pages. Page slug unchanged.
        add_submenu_page(
            'bcc-system-health',
            'Chains',
            'Chains',
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render_page']
        );
    }

    /** admin-post action for the Identity editor (Batch 1: PRG). */
    public const ACTION_IDENTITY_SAVE = 'bcc_chain_identity_save';

    /**
     * admin-post action for per-chain Hall creation.
     *
     * A Hall is an official, administrator-created, chain-connected public
     * group. There is no automatic creator any more — no cron, no activation
     * hook, no self-heal, no bulk sweep — so this action is the ONLY path by
     * which a Hall comes into existence.
     */
    public const ACTION_HALL_CREATE = 'bcc_chain_hall_create';

    /**
     * admin-post action for the audited Cosmos endpoint switch.
     *
     * ⚠ THE ONLY WAY THE ENDPOINT MOVES. Deliberately not a migration, not an
     * activation hook and not a cron task: a provider change must happen at a
     * moment a person chose, on an environment they chose, against a plan
     * they read. See {@see CosmosEndpointTransition}.
     */
    public const ACTION_ENDPOINT_SWITCH = 'bcc_chain_endpoint_switch';

    /**
     * Step 1 of the switch: record a plan for confirmation.
     *
     * A separate POST so the confirmation screen can be a pure GET. See
     * {@see handle_endpoint_review()}.
     */
    public const ACTION_ENDPOINT_REVIEW = 'bcc_chain_endpoint_review';

    public static function register_ajax(): void
    {
        add_action('wp_ajax_bcc_chain_refresh', [self::class, 'ajax_refresh']);
    }

    /**
     * Batch 1: the Identity editor posted back to the page and rendered its
     * notice inline, so a refresh re-triggered the browser's resubmit dialog
     * and could re-run the write. It now goes through admin-post.php and
     * redirects, and the notice is rebuilt from the redirect args.
     */
    public static function register_actions(): void
    {
        add_action('admin_post_' . self::ACTION_IDENTITY_SAVE, [self::class, 'handle_identity_save']);
        add_action('admin_post_' . self::ACTION_HALL_CREATE,   [self::class, 'handle_hall_create']);
        add_action('admin_post_' . self::ACTION_ENDPOINT_REVIEW, [self::class, 'handle_endpoint_review']);
        add_action('admin_post_' . self::ACTION_ENDPOINT_SWITCH, [self::class, 'handle_endpoint_switch']);

        // The six CosmWasm discovery routes that used to be registered here
        // now live on NftDiscoveryPage, along with the sub-tab they served.
        // Every route STRING is unchanged, so a form rendered before the
        // move still posts to a route that exists — only the class that
        // answers it moved. See NftDiscoveryPage::register_actions().
    }

    // ── AJAX: Validator Refresh ─────────────────────────────────────────────

    public static function ajax_refresh(): void
    {
        check_ajax_referer('bcc_chain_refresh', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized.']);
        }

        $chainId = (int) ($_POST['chain_id'] ?? 0);
        $chain   = ChainRepository::getById($chainId);

        \BCC\Core\Log\Logger::info('[bcc-trust] Chain validator refresh (manual)', [
            'action'   => 'chain_refresh_validators',
            'chain_id' => $chainId,
            'operator' => get_current_user_id(),
        ]);

        if (!$chain) {
            wp_send_json_error(['message' => 'Chain not found.']);
        }

        if (!FetcherFactory::has_driver($chain->chain_type)) {
            wp_send_json_error(['message' => "No fetcher driver for chain type: {$chain->chain_type}"]);
        }

        try {
            $fetcher = FetcherFactory::make_for_chain($chain);

            if (!$fetcher->supports_feature('validator')) {
                wp_send_json_error(['message' => 'This chain type does not support validator indexing.']);
            }

            $validators = $fetcher->fetch_all_validators();
            $fetchErr   = $fetcher->last_fetch_error();

            if (empty($validators)) {
                // Distinguish transport failure from API-returned-empty.
                // The two used to render the same "No validators returned"
                // message, which made dead-endpoint diagnostics painful
                // (operator spent N round-trips probing alternative URLs
                // before realising the upstream was unreachable).
                if ($fetchErr !== null) {
                    wp_send_json_error([
                        'message' => sprintf(
                            'Refresh failed for %s: %s. Check Logger for full context; the upstream endpoint may be down or its URL may have changed (see wp_bcc_chains.rest_url).',
                            $chain->name,
                            $fetchErr
                        ),
                    ]);
                }
                wp_send_json_success([
                    'message' => "No validators returned for {$chain->name} (API succeeded; empty response).",
                    'stats'   => ['total' => 0, 'new' => 0, 'updated' => 0, 'unchanged' => 0, 'enriched' => 0],
                ]);
            }

            $stats = ValidatorRepository::bulkUpsert($validators, 4 * HOUR_IN_SECONDS);

            // Per-validator enrichment is handled by the hourly EnrichmentScheduler
            // cron, not inline during admin refresh. Running 500 sequential API calls
            // in a single AJAX request guarantees a PHP timeout.
            // Schedule an immediate enrichment run if one isn't already pending.
            $enrichHook = 'bcc_refresh_validators';
            $scheduled  = false;
            if (!wp_next_scheduled($enrichHook)) {
                \BCC\Core\Cron\AsyncDispatcher::scheduleSingle(
                    time() + 10,
                    $enrichHook,
                    [],
                    'bcc-onchain'
                );
                $scheduled = true;
            }

            $stats['enriched'] = 0;

            AdminActionSupport::audit(
                'admin_chain_validators_refreshed',
                'chain',
                $chainId,
                [
                    'total'   => (int) $stats['total'],
                    'new'     => (int) $stats['new'],
                    'updated' => (int) $stats['updated'],
                ]
            );

            wp_send_json_success([
                'message' => sprintf(
                    '%s: %d indexed (%d new, %d updated). %s',
                    $chain->name,
                    $stats['total'],
                    $stats['new'],
                    $stats['updated'],
                    $scheduled ? 'Enrichment scheduled.' : 'Enrichment already scheduled.'
                ),
                'stats'     => $stats,
                'scheduled' => (bool) $scheduled,
            ]);
        } catch (\Throwable $e) {
            // Raw Throwable text used to be painted straight into the page —
            // it can carry SQL fragments and absolute paths.
            $correlationId = AdminActionSupport::failure(
                $e,
                'admin_chain_validators_refresh_failed',
                'chain',
                $chainId,
                ['chain' => (string) $chain->name]
            );
            wp_send_json_error([
                'message' => $chain->name . ': ' . AdminActionSupport::failureMessage($correlationId),
            ]);
        }
    }

    // ── Render ──────────────────────────────────────────────────────────────

    public static function render_page(): void
    {
        // Defense in depth: add_submenu_page() already gates on this
        // capability, but relying on menu registration alone was the gap
        // every sibling page had already closed.
        if (!current_user_can('manage_options')) {
            wp_die(
                esc_html__('Sorry, you are not allowed to access this page.', 'bcc-trust'),
                esc_html__('Forbidden', 'bcc-trust'),
                ['response' => 403]
            );
        }

        // Identity saves now redirect here after writing (PRG), so the notice
        // is rebuilt from the redirect args rather than from a live POST.
        $notice = self::identity_notice_from_query();

        $chains         = ChainRepository::getAll();
        $valCountMap    = ValidatorRepository::getCountsByChain();
        $nonce          = wp_create_nonce('bcc_chain_refresh');
        // `nft-discovery` is deliberately NOT in this allowlist any more.
        // A request carrying it never reaches here — NftDiscoveryPage
        // redirects it on admin_init — and if that redirect were ever
        // removed, falling back to Validators is the right failure: an
        // unknown sub-tab must not render a blank page.
        $activeTab = sanitize_key($_GET['subtab'] ?? 'validators');
        if (!in_array($activeTab, ['validators', 'identity', 'halls'], true)) {
            $activeTab = 'validators';
        }
        ?>
        <div class="wrap">
            <h1>Chains</h1>

            <?php if ($notice !== null): ?>
                <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible">
                    <p><?php echo esc_html($notice['message']); ?></p>
                </div>
            <?php endif; ?>

            <?php $hallNotice = self::halls_notice_from_query(); ?>
            <?php if ($hallNotice !== null): ?>
                <div class="notice notice-<?php echo esc_attr($hallNotice['type']); ?> is-dismissible">
                    <p><?php echo esc_html($hallNotice['message']); ?></p>
                </div>
            <?php endif; ?>

            <?php $endpointNotice = self::endpoint_notice_from_query(); ?>
            <?php if ($endpointNotice !== null): ?>
                <div class="notice notice-<?php echo esc_attr($endpointNotice['type']); ?> is-dismissible">
                    <p><?php echo esc_html($endpointNotice['message']); ?></p>
                </div>
            <?php endif; ?>
            <?php self::render_sweep_notice(); ?>

            <?php self::render_sweep_bar(); ?>

            <nav class="nav-tab-wrapper" style="margin-bottom:16px">
                <a href="<?php echo esc_url(add_query_arg('subtab', 'validators')); ?>"
                   class="nav-tab <?php echo $activeTab === 'validators' ? 'nav-tab-active' : ''; ?>">
                    Validators
                </a>
                <a href="<?php echo esc_url(add_query_arg('subtab', 'identity')); ?>"
                   class="nav-tab <?php echo $activeTab === 'identity' ? 'nav-tab-active' : ''; ?>">
                    Identity
                </a>
                <a href="<?php echo esc_url(add_query_arg('subtab', 'halls')); ?>"
                   class="nav-tab <?php echo $activeTab === 'halls' ? 'nav-tab-active' : ''; ?>">
                    Halls
                </a>
            </nav>

            <?php if ($activeTab === 'validators'): ?>
                <?php self::render_validators_tab($chains, $valCountMap); ?>
            <?php elseif ($activeTab === 'halls'): ?>
                <?php self::render_halls_tab($chains); ?>
            <?php else: ?>
                <?php self::render_endpoint_review_panel(); ?>
                <?php self::render_endpoint_entry_form($chains); ?>
                <?php self::render_identity_tab($chains); ?>
            <?php endif; ?>
        </div>

        <?php self::render_js($nonce); ?>
        <?php
    }

    /**
     * @param list<ChainRow> $chains
     * @param array<int, ValidatorCountByChain> $countMap
     */
    private static function render_validators_tab(array $chains, array $countMap): void
    {
        ?>
        <p>Click <strong>Refresh</strong> to re-index validators for a specific chain.</p>

        <table class="widefat striped" style="max-width:1100px">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Slug</th>
                    <th>Type</th>
                    <th>Token</th>
                    <th>Validators</th>
                    <th>Last Indexed</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($chains as $chain):
                    $cid       = (int) $chain->id;
                    $info      = $countMap[$cid] ?? null;
                    $valCount  = $info ? (int) $info->cnt : 0;
                    $lastFetch = $info->last_fetched ?? null;
                    $hasDriver = FetcherFactory::has_driver($chain->chain_type);
                    $isActive  = (int) $chain->is_active;
                    $hasValidators = $hasDriver && $isActive
                        && FetcherFactory::make_for_chain($chain)->supports_feature('validator');
                ?>
                <tr>
                    <td><?php echo esc_html((string) $cid); ?></td>
                    <td><strong><?php echo esc_html($chain->name); ?></strong></td>
                    <td><code><?php echo esc_html($chain->slug); ?></code></td>
                    <td><code><?php echo esc_html($chain->chain_type); ?></code></td>
                    <td><?php echo esc_html($chain->native_token ?? '—'); ?></td>
                    <td><?php echo esc_html((string) $valCount); ?></td>
                    <td><?php echo $lastFetch ? esc_html($lastFetch) : '<em>Never</em>'; ?></td>
                    <td>
                        <?php if (!$isActive): ?>
                            <span style="color:#d63638;">Inactive</span>
                        <?php elseif (!$hasDriver): ?>
                            <span style="color:#dba617;">No Driver</span>
                        <?php else: ?>
                            <span style="color:#00a32a;">Active</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($hasValidators): ?>
                        <button class="button bcc-chain-refresh-btn"
                                data-chain-id="<?php echo esc_attr((string) $cid); ?>"
                                data-chain-name="<?php echo esc_attr($chain->name); ?>"
                                data-action="bcc_chain_refresh">
                            Refresh
                        </button>
                        <span class="bcc-chain-status" style="margin-left:8px;font-size:12px;"></span>
                        <?php else: ?>
                        <span style="color:#94a3b8;font-size:12px;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p style="margin-top:16px">
            <button class="button button-primary"
                    id="bcc-refresh-all"
                    onclick="return confirm('Refresh validators for ALL chains? This fans out per-chain API calls (Alchemy / Helius / Subscan / etc.), can take 1-3 minutes, and consumes provider quota. Confirm only if you actually want a full refresh — for one chain, use the per-row Refresh button instead.');">Refresh All Chains</button>
            <span id="bcc-refresh-all-status" style="margin-left:12px;font-size:13px;"></span>
        </p>
        <?php
    }

    // ── Identity editor (per-chain "About this chain") ──────────────────────

    /**
     * Handle a POST from the Identity tab's per-chain editor.
     *
     * add_submenu_page already gates this page on `manage_options`; the
     * write re-checks the capability and a per-action nonce before touching
     * the DB (defense-in-depth). Sanitisation is strict: description →
     * plain-text (sanitize_textarea_field), icon_url → esc_url_raw, color →
     * validated #RRGGBB (sanitize_hex_color, which returns null on a bad
     * value). A non-empty submitted value the sanitiser rejects is NOT
     * written as NULL — the field keeps its stored value and the notice
     * names the rejected field; empty input remains the deliberate
     * clear → NULL path. All the actual DB work + cache-bust lives in
     * ChainRepository::updateIdentity (§1 — no $wpdb in an Admin page).
     *
     * Batch 1: this was a render-path POST handler returning a notice array.
     * It is now an admin-post handler that redirects (PRG), writes a durable
     * audit row, and never renders a raw exception. The validation semantics
     * below — including the "reject the field, keep the stored value" rule —
     * are unchanged.
     */
    public static function handle_identity_save(): void
    {
        AdminActionSupport::requireCapability();
        AdminActionSupport::requireNonce(self::ACTION_IDENTITY_SAVE, 'bcc_chain_identity_nonce');

        $chainId = (int) ($_POST['chain_id'] ?? 0);
        if ($chainId <= 0) {
            self::redirect_identity('invalid_chain');
        }

        // The chain id must resolve through the authoritative repository —
        // a positive integer is not by itself a valid target.
        if (ChainRepository::getById($chainId) === null) {
            self::redirect_identity('invalid_chain');
        }

        $descriptionRaw = isset($_POST['description']) ? wp_unslash($_POST['description']) : '';
        $iconUrlRaw     = isset($_POST['icon_url']) ? wp_unslash($_POST['icon_url']) : '';
        $colorRaw       = isset($_POST['color']) ? wp_unslash($_POST['color']) : '';

        $descriptionRaw = is_string($descriptionRaw) ? $descriptionRaw : '';
        $iconUrlRaw     = is_string($iconUrlRaw) ? $iconUrlRaw : '';
        $colorRaw       = is_string($colorRaw) ? $colorRaw : '';

        $description = sanitize_textarea_field($descriptionRaw);
        $iconUrl     = esc_url_raw($iconUrlRaw);
        $color       = sanitize_hex_color($colorRaw);

        // Empty input = deliberate clear → NULL (documented contract).
        // But a NON-empty input the sanitiser rejects must never silently
        // become a clear: surface the field error and keep that column's
        // stored value untouched — every other valid field still saves.
        $current     = ChainRepository::getById($chainId);
        $fieldErrors = [];

        $iconUrlValue = $iconUrl !== '' ? $iconUrl : null;
        if (trim($iconUrlRaw) !== '' && $iconUrl === '') {
            $fieldErrors[] = 'Icon URL must be a valid URL like https://example.com/icon.svg — nothing was saved for this field.';
            $iconUrlValue  = isset($current->icon_url) && is_string($current->icon_url) && $current->icon_url !== ''
                ? $current->icon_url
                : null;
        }

        $colorValue = ($color !== null && $color !== '') ? $color : null;
        if (trim($colorRaw) !== '' && ($color === null || $color === '')) {
            $fieldErrors[] = 'Color must be a hex value like #627EEA — nothing was saved for this field.';
            $colorValue    = isset($current->color) && is_string($current->color) && $current->color !== ''
                ? $current->color
                : null;
        }

        try {
            $ok = ChainRepository::updateIdentity(
                $chainId,
                $description !== '' ? $description : null,
                $iconUrlValue,
                $colorValue
            );
        } catch (\Throwable $e) {
            $correlationId = AdminActionSupport::failure(
                $e,
                'admin_chain_identity_failed',
                'chain',
                $chainId
            );
            self::redirect_identity('exception', $chainId, $correlationId);
        }

        if (!$ok) {
            self::redirect_identity('not_found', $chainId);
        }

        // Public-facing chain profile changed (chain_profile on /halls/:slug).
        // This was the only mutation on the page with no record of any kind.
        AdminActionSupport::audit(
            'admin_chain_identity_saved',
            'chain',
            $chainId,
            [
                'fields_rejected' => count($fieldErrors),
                'cleared'         => $description === '' ? 'description' : '',
            ]
        );

        self::redirect_identity($fieldErrors !== [] ? 'partial' : 'ok', $chainId);
    }

    // ── Halls ───────────────────────────────────────────────────────────────

    /**
     * Per-chain Hall control.
     *
     * READ-ONLY about absence. A chain without a Hall says so and offers the
     * button; nothing here repairs, backfills or creates anything on render.
     * That is the whole point of retiring the sweep — a page load must never
     * be a write.
     *
     * @param list<ChainRow> $chains
     */
    private static function render_halls_tab(array $chains): void
    {
        $chainIds = [];
        foreach ($chains as $chain) {
            $chainIds[] = (int) $chain->id;
        }

        // One bounded query for every chain, rather than N lookups.
        $halls = HallRepository::findHallsForChains($chainIds);
        ?>
        <p>
            A <strong>Hall</strong> is an official, administrator-created public group
            connected to exactly one chain. A chain has zero or one Hall.
        </p>
        <p class="description" style="max-width:820px;">
            Halls are never created automatically — adding a chain does not create one,
            and no scheduled job does either. Creating a Hall publishes a public, open
            group that anyone can join, and assigns it to the platform's first
            administrator account. It cannot be undone from this screen.
        </p>

        <table class="widefat striped" style="max-width:1100px;">
            <caption class="screen-reader-text">Chains and their Halls</caption>
            <thead>
                <tr>
                    <th scope="col">Chain</th>
                    <th scope="col">Hall</th>
                    <th scope="col">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($chains as $chain):
                $cid       = (int) $chain->id;
                $chainName = (string) $chain->name;
                $groupId   = $halls[$cid] ?? 0;
                $incomplete = $groupId > 0 && HallRepository::isOwnerIncomplete($groupId);
                $editLink  = $groupId > 0 ? get_edit_post_link($groupId) : null;
                $confirm   = sprintf(
                    'Create the %s Hall? This publishes a public, open group that anyone can join. It cannot be undone from this screen.',
                    $chainName
                );
            ?>
                <tr>
                    <th scope="row" style="width:220px;">
                        <strong><?php echo esc_html($chainName); ?></strong><br>
                        <code><?php echo esc_html((string) $chain->slug); ?></code>
                    </th>
                    <td>
                        <?php if ($groupId === 0): ?>
                            <span>This chain does not have a Hall.</span>
                        <?php else: ?>
                            <span>Hall #<?php echo (int) $groupId; ?></span>
                            <?php if ($incomplete): ?>
                                <br>
                                <strong
                                    id="bcc-hall-warn-<?php echo (int) $cid; ?>"
                                    style="color:#b32d2e;"
                                >
                                    Ownership incomplete — this Hall is not owned by the
                                    canonical administrator account. Use Repair ownership.
                                </strong>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td style="width:260px;">
                        <?php if ($groupId === 0 || $incomplete): ?>
                            <form method="post"
                                  action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                  style="margin:0;"
                                  onsubmit="return confirm(<?php echo esc_attr(AdminActionSupport::confirmLiteral($confirm)); ?>);">
                                <input type="hidden" name="action"
                                       value="<?php echo esc_attr(self::ACTION_HALL_CREATE); ?>">
                                <input type="hidden" name="chain_id"
                                       value="<?php echo esc_attr((string) $cid); ?>">
                                <?php wp_nonce_field(self::ACTION_HALL_CREATE . '_' . $cid); ?>
                                <button type="submit"
                                        class="button <?php echo $incomplete ? '' : 'button-primary'; ?>"
                                        <?php if ($incomplete): ?>
                                            aria-describedby="bcc-hall-warn-<?php echo (int) $cid; ?>"
                                        <?php endif; ?>>
                                    <?php echo $incomplete ? 'Repair ownership' : 'Create Chain Hall'; ?>
                                </button>
                            </form>
                        <?php elseif (is_string($editLink) && $editLink !== ''): ?>
                            <a class="button" href="<?php echo esc_url($editLink); ?>">
                                View Hall<span class="screen-reader-text"> for <?php echo esc_html($chainName); ?></span>
                            </a>
                        <?php else: ?>
                            <span>View Hall (#<?php echo (int) $groupId; ?>)</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($chains === []): ?>
                <tr><td colspan="3">No chains are registered.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Create the Hall for ONE named chain.
     *
     * Gate order matches the canonical admin handler
     * ({@see Views\NftSpamContractsView::handleAdd()}): capability, method,
     * cheap validation, then the per-chain nonce — so a malformed request
     * never reaches a nonce action built from its own input — then the
     * authoritative repository, then the service.
     */
    public static function handle_hall_create(): void
    {
        AdminActionSupport::requireCapability();
        AdminActionSupport::requirePost();

        $chainId = isset($_POST['chain_id']) ? (int) $_POST['chain_id'] : 0;
        if ($chainId <= 0) {
            self::redirect_halls('invalid_chain');
        }

        // Bound to THIS chain: a nonce minted for chain 7 cannot create
        // chain 8's Hall.
        AdminActionSupport::requireNonce(self::ACTION_HALL_CREATE . '_' . $chainId);

        // A positive integer is not by itself a valid target.
        if (ChainRepository::getById($chainId) === null) {
            self::redirect_halls('invalid_chain');
        }

        try {
            $result = OnchainPlugin::instance()->hallProvisioningService()->provisionOne($chainId);
        } catch (\Throwable $e) {
            $correlationId = AdminActionSupport::failure(
                $e,
                HallProvisioningService::AUDIT_FAILED,
                'chain',
                $chainId
            );
            self::redirect_halls('exception', $chainId, $correlationId);
        }

        switch ((string) $result['status']) {
            case 'created':
                self::redirect_halls('created', $chainId);
                // no break — redirect() is `never`.
            case 'exists':
                self::redirect_halls('exists', $chainId);
                // no break
            case 'incomplete':
                self::redirect_halls('incomplete', $chainId);
                // no break
            case 'skipped':
                self::redirect_halls('refused', $chainId);
                // no break
            default:
                self::redirect_halls('failed', $chainId);
        }
    }

    /** PRG terminator back to the Halls sub-tab. */
    private static function redirect_halls(string $result, int $chainId = 0, string $ref = ''): never
    {
        $args = [
            'page'      => self::PAGE_SLUG,
            'subtab'    => 'halls',
            'bcc_hall'  => $result,
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
     * Rebuild the Halls notice from the PRG args.
     *
     * The message text is looked up HERE, server-side. Only an opaque result
     * key and an optional correlation id travel in the URL, so no upstream
     * error text can be reflected onto the page.
     *
     * @return array{type: string, message: string}|null
     */
    private static function halls_notice_from_query(): ?array
    {
        $result = isset($_GET['bcc_hall']) ? sanitize_key((string) $_GET['bcc_hall']) : '';
        if ($result === '') {
            return null;
        }

        switch ($result) {
            case 'created':
                return ['type' => 'success', 'message' => __('Hall created.', 'bcc-trust')];
            case 'exists':
                return ['type' => 'info', 'message' => __('This chain already has a Hall — nothing was created.', 'bcc-trust')];
            case 'incomplete':
                return [
                    'type'    => 'error',
                    'message' => __(
                        'The Hall exists, but its ownership could not be set. It is flagged for repair — use Repair ownership to try again.',
                        'bcc-trust'
                    ),
                ];
            case 'invalid_chain':
                return ['type' => 'error', 'message' => __('Invalid chain — nothing was created.', 'bcc-trust')];
            case 'refused':
                return [
                    'type'    => 'error',
                    'message' => __('This chain cannot have a Hall yet — check that it has a display name.', 'bcc-trust'),
                ];
            case 'failed':
                return [
                    'type'    => 'error',
                    'message' => __('The Hall could not be created. The reason is in the bcc-trust log.', 'bcc-trust'),
                ];
            case 'exception':
                $ref = isset($_GET['bcc_ref']) ? sanitize_text_field((string) $_GET['bcc_ref']) : '';
                return ['type' => 'error', 'message' => AdminActionSupport::failureMessage($ref)];
            default:
                return null;
        }
    }

    /**
     * Descriptors for the four "Run cron now" sweeps.
     *
     * Pure and public so the confirmation copy is assertable without
     * rendering the whole page. Each `confirm` must truthfully state the
     * scope — every active chain, synchronous, live provider APIs, quota —
     * because three of these four controls previously had no confirmation
     * at all.
     *
     * @return list<array{action: string, label: string, primary: bool, confirm: string}>
     */
    public static function sweepControls(): array
    {
        return [
            [
                'action'  => ChainSweepActions::ACTION_ALL,
                'label'   => 'All (validators + enrichment)',
                'primary' => true,
                'confirm' => 'Run validator index + validator enrichment for EVERY active chain, right now?'
                    . "\n\n"
                    . 'This runs synchronously against live provider APIs (Alchemy / Helius / Subscan / Cosmos LCD) '
                    . 'and consumes provider quota. It cannot be cancelled once started and may take 30+ seconds.'
                    . "\n\n"
                    . 'It does NOT discover NFT collections. Chain-wide collection discovery is started one named '
                    . 'chain at a time and is never part of a sweep.',
            ],
            [
                'action'  => ChainSweepActions::ACTION_VALIDATORS,
                'label'   => 'Validators only',
                'primary' => false,
                'confirm' => 'Re-index validators for EVERY active chain now?'
                    . "\n\n"
                    . 'Synchronous, runs against live provider APIs, and consumes provider quota.',
            ],
            [
                'action'  => ChainSweepActions::ACTION_ENRICHMENT,
                'label'   => 'Enrichment only',
                'primary' => false,
                'confirm' => 'Run validator enrichment for EVERY active chain now?'
                    . "\n\n"
                    . 'Synchronous and bounded by the EnrichmentScheduler API-call cap, but still consumes provider quota.',
            ],
        ];
    }

    /**
     * "Run cron now" bar — manual trigger for the all-chains indexers.
     *
     * Routes through ChainRefreshService, which calls
     * OnchainCircuitBreaker::recordSuccess() on success and so clears the
     * "Chain data is stale" notice. The per-chain AJAX "Refresh" buttons
     * further down call the fetchers directly and do NOT clear it.
     *
     * Batch 1: these were four GET links sharing one nonce, only one of which
     * had a confirmation. Each is now a POST form to admin-post.php with its
     * own operation-scoped nonce.
     */
    public static function render_sweep_bar(): void
    {
        ?>
        <div style="margin:8px 0 16px 0;padding:10px 12px;background:#f6f7f7;border:1px solid #c3c4c7;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <strong style="margin-right:4px;">Run cron now:</strong>
            <?php foreach (self::sweepControls() as $sweep): ?>
                <form method="post"
                      action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                      style="display:inline;margin:0;"
                      onsubmit="return confirm(<?php echo esc_attr(AdminActionSupport::confirmLiteral($sweep['confirm'])); ?>);">
                    <input type="hidden" name="action" value="<?php echo esc_attr($sweep['action']); ?>">
                    <?php wp_nonce_field($sweep['action']); ?>
                    <button type="submit"
                            class="button<?php echo $sweep['primary'] ? ' button-primary' : ''; ?>">
                        <?php echo esc_html($sweep['label']); ?>
                    </button>
                </form>
            <?php endforeach; ?>
            <span style="color:#646970;font-size:11px;margin-left:auto;">
                Clears the &ldquo;Chain data is stale&rdquo; notice via <code>OnchainCircuitBreaker::recordSuccess</code> on the chains that respond.
            </span>
        </div>
        <?php
    }

    /**
     * Step 3: apply the switch the operator confirmed.
     *
     * capability → POST → route-scoped nonce → chain resolves → the service
     * (lock → review bindings → live proof → byte-exact CAS → read-back →
     * breaker → audit) → PRG with a result code the operator can read.
     *
     * ⚠ THE TARGET IS NOT SUBMITTED. The only operand is the review's random
     * id, so the thing executed is the thing that was reviewed, and a
     * replacement review retires a stale confirmation screen on its own.
     * {@see CosmosEndpointTransition::execute()} holds the full contract.
     *
     * ⚠ NOTHING HERE TOUCHES PRODUCTION BY ITSELF. This is a button. It runs
     * where an administrator presses it and nowhere else.
     */
    public static function handle_endpoint_switch(): void
    {
        AdminActionSupport::requireCapability();
        AdminActionSupport::requirePost();
        AdminActionSupport::requireNonce(self::ACTION_ENDPOINT_SWITCH, 'bcc_chain_endpoint_nonce');

        $chainId = (int) ($_POST['chain_id'] ?? 0);
        if ($chainId <= 0 || ChainRepository::getById($chainId) === null) {
            self::redirect_endpoint('invalid_chain');
        }

        // ⚠ THE TARGET IS NOT SUBMITTED HERE. It comes from the pending review,
        // so the thing executed is the thing that was reviewed. The only operand
        // is the review's random id, which is also what retires a stale
        // confirmation screen: a replacement review mints a new one.
        $reviewRaw = isset($_POST['review_id']) ? wp_unslash($_POST['review_id']) : '';
        $reviewId  = is_string($reviewRaw) ? trim($reviewRaw) : '';

        if ($reviewId === '') {
            self::redirect_endpoint('missing_input', $chainId);
        }

        // ⚠⚠ AN ESCAPING THROWABLE WOULD BE THE WORST OUTCOME HERE. The switch
        // may already have happened by the time anything throws, and an
        // unhandled exception gives the operator a WordPress fatal: no notice, no
        // redirect, no durable row, and no way to tell whether the endpoint
        // moved. The house pattern — {@see handle_hall_create()} — records a
        // durable failure row and hands back a correlation id, so there is
        // something to grep for. The service audits its own post-write paths, so
        // this is the net for everything else.
        try {
            $result = CosmosEndpointTransition::execute($chainId, $reviewId, get_current_user_id());
        } catch (\Throwable $e) {
            $correlationId = AdminActionSupport::failure(
                $e,
                CosmosEndpointTransition::AUDIT_ACTION,
                'chain',
                $chainId,
                ['outcome' => 'unexpected_error', 'error' => $e->getMessage()]
            );

            self::redirect_endpoint(
                'unexpected_error',
                $chainId,
                ['bcc_ep_ref' => $correlationId]
            );
        }

        $args = [];
        if ($result['failed_followups'] !== []) {
            // A bounded enum list, never free text: `breaker`, `audit`.
            $args['bcc_ep_failed'] = implode(',', $result['failed_followups']);
        }

        self::redirect_endpoint($result['reason'], $chainId, $args);
    }

    /**
     * Step 1 of the switch: record what the operator is about to confirm.
     *
     * ⚠ THIS IS WHY THE CONFIRMATION SCREEN CAN BE A PURE GET. The review has
     * to be recorded somewhere before it can be confirmed, and recording it
     * during render would give a GET a side effect. So the act of asking for a
     * plan is itself a POST, and the screen that follows only reads.
     *
     * No provider call: proving the destination belongs to the switch, under the
     * lock, against the state that will actually be written.
     */
    public static function handle_endpoint_review(): void
    {
        AdminActionSupport::requireCapability();
        AdminActionSupport::requirePost();
        AdminActionSupport::requireNonce(self::ACTION_ENDPOINT_REVIEW, 'bcc_chain_endpoint_review_nonce');

        $chainId = (int) ($_POST['chain_id'] ?? 0);
        if ($chainId <= 0 || ChainRepository::getById($chainId) === null) {
            self::redirect_endpoint('invalid_chain');
        }

        $targetRaw = isset($_POST['target_url']) ? wp_unslash($_POST['target_url']) : '';
        $target    = is_string($targetRaw) ? esc_url_raw(trim($targetRaw)) : '';

        if ($target === '') {
            self::redirect_endpoint('missing_input', $chainId);
        }

        // Nothing is written before the mint, so a throw here costs only the
        // operator's click — but it must still be a notice rather than a fatal.
        try {
            $result = CosmosEndpointTransition::review($chainId, $target, get_current_user_id());
        } catch (\Throwable $e) {
            $correlationId = AdminActionSupport::failure(
                $e,
                CosmosEndpointTransition::AUDIT_ACTION,
                'chain',
                $chainId,
                ['outcome' => 'unexpected_error', 'step' => 'review', 'error' => $e->getMessage()]
            );

            self::redirect_endpoint(
                'unexpected_error',
                $chainId,
                ['bcc_ep_ref' => $correlationId]
            );
        }

        if (!$result['ok']) {
            self::redirect_endpoint($result['reason'], $chainId);
        }

        // ⚠ The review id is NOT in the redirect. A bearer value in a URL lands
        // in history, in a referer and in access logs. The confirmation screen
        // finds the pending review by operator and renders the id as a hidden
        // field instead.
        self::redirect_endpoint('review_ready', $chainId);
    }

    /**
     * @param  array<string, string> $extra bounded, enum-valued only — never free text
     * @return never
     */
    private static function redirect_endpoint(string $result, int $chainId = 0, array $extra = []): never
    {
        $args = [
            'page'         => self::PAGE_SLUG,
            'subtab'       => 'identity',
            'bcc_endpoint' => $result,
        ];
        if ($chainId > 0) {
            $args['bcc_chain'] = $chainId;
        }

        foreach ($extra as $key => $value) {
            $args[$key] = $value;
        }

        AdminActionSupport::redirect($args);
    }

    /**
     * PRG terminator for the Identity editor.
     */
    private static function redirect_identity(string $result, int $chainId = 0, string $ref = ''): never
    {
        $args = [
            'page'         => self::PAGE_SLUG,
            'subtab'       => 'identity',
            'bcc_identity' => $result,
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
     * The pending endpoint switch, awaiting confirmation.
     *
     * ⚠⚠⚠ READ-ONLY. No write, no provider call, nothing minted. The review
     * was recorded by {@see handle_endpoint_review()}, a POST, precisely so
     * that this render has no side effect.
     *
     * ⚠⚠⚠ NOTHING ENDPOINT-DERIVED IS PRINTED EXCEPT WHERE PROVEN SAFE.
     * `rest_url` is a tainted credential column for
     * `scripts/endpoint-exposure-guard.php`, and a hand-edited incumbent may
     * carry userinfo or a query string. So the incumbent is shown in full
     * ONLY when it is itself policy-approved — the allowlist is closed and
     * credential-free — and otherwise through the deny-by-default descriptor,
     * which keeps scheme and host and discards the rest. The review
     * fingerprint is never rendered at all.
     */
    /**
     * STEP 1: start an endpoint switch.
     *
     * ⚠ THIS IS THE CONTROL THE FLOW WAS MISSING. Without it the only way to
     * mint a review was a hand-crafted POST or WP-CLI, so the two-step gesture
     * the design asks for could not be performed from the admin at all — the
     * confirmation panel can only appear once a review already exists.
     *
     * ⚠ THE TARGET IS A CLOSED LIST, NOT A TEXT FIELD. The operator picks from
     * this chain's policy-approved endpoints, so a free-form URL never leaves the
     * browser. `handle_endpoint_review()` re-checks the policy regardless — this
     * is defence in depth and a usability choice, not the enforcement.
     *
     * Capability is the page's own `manage_options`; the POST and the route-scoped
     * nonce are enforced in the handler. Rendering writes nothing.
     *
     * @param list<ChainRow> $chains
     */
    private static function render_endpoint_entry_form(array $chains): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // One pending review per operator, so a second form would be offering to
        // replace the confirmation already on screen.
        if (CosmosEndpointReview::peek(get_current_user_id()) !== null) {
            return;
        }

        $governed = [];
        foreach ($chains as $chain) {
            $slug = (string) ($chain->slug ?? '');
            if ($slug !== '' && CosmosEndpointPolicy::isGoverned($slug) && (int) ($chain->is_active ?? 0) === 1) {
                $governed[] = $chain;
            }
        }

        if ($governed === []) {
            return;
        }
        ?>
        <div class="card" style="max-width:860px;margin-bottom:16px;padding:12px">
            <h2 style="margin-top:0">Endpoint switch</h2>
            <p style="margin-top:0">
                Moving a chain to a different approved endpoint takes two steps: review,
                then confirm. Reviewing contacts nothing and changes nothing.
            </p>
            <?php foreach ($governed as $chain): ?>
                <?php
                $slug    = (string) $chain->slug;
                $current = CosmosEndpointPolicy::normalize((string) ($chain->rest_url ?? ''));
                $choices = [];
                foreach (CosmosEndpointPolicy::approvedEndpoints($slug) as $url => $role) {
                    if ($url !== $current) {
                        $choices[(string) $url] = (string) $role;
                    }
                }
                ?>
                <?php if ($choices !== []): ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                          style="display:flex;gap:8px;align-items:center;margin:0 0 8px">
                        <?php wp_nonce_field(self::ACTION_ENDPOINT_REVIEW, 'bcc_chain_endpoint_review_nonce'); ?>
                        <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_ENDPOINT_REVIEW); ?>">
                        <input type="hidden" name="chain_id" value="<?php echo (int) $chain->id; ?>">
                        <label style="min-width:120px">
                            <code><?php echo esc_html($slug); ?></code>
                        </label>
                        <select name="target_url" required>
                            <option value="">Choose an approved endpoint&hellip;</option>
                            <?php foreach ($choices as $url => $role): ?>
                                <option value="<?php echo esc_attr($url); ?>">
                                    <?php echo esc_html($url); ?> (<?php echo esc_html($role); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="button">Review switch</button>
                    </form>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private static function render_endpoint_review_panel(): void
    {
        $review = CosmosEndpointReview::peek(get_current_user_id());
        if ($review === null) {
            return;
        }

        $chainId = (int) $review['chain_id'];

        // ⚠⚠ UNCACHED, FOR THE SAME REASON THE POST-CAS DIAGNOSIS IS.
        // The only question this read answers is "has the world moved since the
        // operator reviewed it?", and `getById()` serves from a 300-second cached
        // active set — so for up to five minutes after an out-of-band change it
        // answers "no". The panel would then show the reviewed values and offer the
        // button while the row said something else, and the write would refuse with
        // `from_mismatch`: fail-closed, but the operator misled, which is precisely
        // what rendering from the review record was meant to stop.
        //
        // Found in the browser, not by a test: the double answered live.
        $live = ChainRepository::getByIdUncached($chainId);

        // ⚠⚠ A STALE REVIEW IS REFUSED, NOT REDRAWN. An earlier revision
        // recomputed `plan()` against the live row on every render, so a hand edit
        // between review and reload changed what the screen showed while the write
        // still bound the ORIGINAL fingerprint — the panel told the operator it
        // would move the chain "only if it is still on the endpoint shown above"
        // and then refused with `from_mismatch`, because the endpoint shown was
        // not the one bound. Everything below is drawn from the review record;
        // the live row is read ONLY to decide whether it is still confirmable.
        $staleReason = self::endpoint_review_stale_reason($review, $live);
        if ($staleReason !== null) {
            ?>
            <div class="notice notice-warning">
                <p>
                    <strong>Pending endpoint switch can no longer be confirmed.</strong>
                    The chain changed since you reviewed it
                    (<code><?php echo esc_html($staleReason); ?></code>). Review it again.
                </p>
            </div>
            <?php
            return;
        }
        ?>
        <div class="notice notice-info" style="padding:12px">
            <h2 style="margin-top:0">Confirm endpoint switch</h2>
            <p style="max-width:860px;margin-top:0">
                <em>These are the values you reviewed.</em> If the chain has changed since,
                this panel refuses the confirmation rather than showing you something else.
            </p>
            <table class="widefat striped" style="max-width:860px;margin-bottom:12px">
                <tbody>
                    <tr><th style="width:220px">Chain</th>
                        <td><code><?php echo esc_html((string) $review['slug']); ?></code>
                            (#<?php echo (int) $review['chain_id']; ?>)</td></tr>
                    <tr><th>Reviewed endpoint</th>
                        <td>
                            <?php if ((bool) $review['incumbent_null']): ?>
                                <em>none set</em>
                            <?php elseif ((bool) $review['incumbent_shown_in_full']): ?>
                                <code><?php echo esc_html((string) $review['incumbent_normalized']); ?></code>
                            <?php else: ?>
                                <code><?php echo esc_html((string) $review['incumbent_display']); ?></code>
                                <br><span style="color:#d63638;">Off-policy value — not shown in full.</span>
                            <?php endif; ?>
                        </td></tr>
                    <tr><th>New endpoint</th>
                        <td><code><?php echo esc_html((string) $review['target']); ?></code>
                            <?php if (($review['to_role'] ?? null) !== null): ?>
                                &mdash; <?php echo esc_html((string) $review['to_role']); ?>
                            <?php endif; ?>
                        </td></tr>
                    <tr><th>Must report network</th>
                        <td><code><?php echo esc_html((string) $review['network']); ?></code></td></tr>
                </tbody>
            </table>

            <p style="max-width:860px">
                Confirming proves the new endpoint live before anything is written, then
                moves the chain only if it is still on the reviewed endpoint shown above.
                The circuit breaker for this chain is reset once the change is confirmed.
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::ACTION_ENDPOINT_SWITCH, 'bcc_chain_endpoint_nonce'); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_ENDPOINT_SWITCH); ?>">
                <input type="hidden" name="chain_id" value="<?php echo (int) $review['chain_id']; ?>">
                <input type="hidden" name="review_id" value="<?php echo esc_attr((string) $review['review_id']); ?>">
                <button type="submit" class="button button-primary">Switch endpoint</button>
            </form>
        </div>
        <?php
    }

    /**
     * Why this review can no longer be confirmed, or null if it still can.
     *
     * ⚠ THE INCUMBENT IS COMPARED BY HMAC, NOT BY VALUE. The record never holds
     * the raw endpoint — only `CosmosEndpointReview::fingerprint()` of it — so
     * this is the only comparison available, and `hash_equals()` is used because
     * the fingerprint is keyed on a site salt.
     *
     * @param  array<string, mixed> $review
     * @param  ChainRow|null        $live
     */
    private static function endpoint_review_stale_reason(array $review, ?object $live): ?string
    {
        if ($live === null) {
            return 'chain_gone';
        }

        if ((string) ($live->slug ?? '') !== (string) $review['slug']) {
            return 'slug_changed';
        }

        if ((int) ($live->is_active ?? 0) !== (int) $review['is_active']) {
            return 'active_changed';
        }

        $liveFp = CosmosEndpointReview::fingerprint(
            $live->rest_url === null ? null : (string) $live->rest_url
        );
        if (!hash_equals((string) $review['incumbent_fp'], $liveFp)) {
            return 'endpoint_changed';
        }

        return null;
    }
    /**
     * Operator copy for every endpoint result code.
     *
     * ⚠ EVERY POST-WRITE OUTCOME SAYS THE ENDPOINT CHANGED. Once the
     * compare-and-swap affects a row the switch has happened, and no copy
     * below that point may read as "nothing happened" — a follow-up failing
     * is a different fact from the change not landing.
     *
     * @return array{type: string, message: string}|null
     */
    private static function endpoint_notice_from_query(): ?array
    {
        $result = isset($_GET['bcc_endpoint']) ? sanitize_key((string) $_GET['bcc_endpoint']) : '';
        if ($result === '') {
            return null;
        }

        // A log-join token, not a secret, and bounded by `sanitize_key` to the
        // same alphabet `correlationId()` draws from.
        $reference = isset($_GET['bcc_ep_ref']) ? sanitize_key((string) $_GET['bcc_ep_ref']) : '';

        $failedRaw = isset($_GET['bcc_ep_failed']) ? (string) $_GET['bcc_ep_failed'] : '';
        $failed    = [];
        foreach (explode(',', $failedRaw) as $piece) {
            $piece = sanitize_key($piece);
            if (in_array($piece, ['breaker', 'audit'], true)) {
                $failed[] = $piece;
            }
        }

        $followups = [];
        if (in_array('breaker', $failed, true)) {
            $followups[] = 'the circuit breaker for this chain was NOT reset, so it still carries the '
                . 'previous host\'s failure count and may open early';
        }
        if (in_array('audit', $failed, true)) {
            $followups[] = 'the durable audit row was NOT written, so the application log is the only '
                . 'record of this change';
        }

        switch ($result) {
            case 'review_ready':
                return ['type' => 'info', 'message' => 'Review the plan below, then confirm.'];
            case 'switched':
                return ['type' => 'success', 'message' => 'Endpoint switched. The breaker for this chain was reset.'];
            case 'switched_followups_failed':
                return [
                    'type'    => 'warning',
                    'message' => 'THE ENDPOINT WAS CHANGED, but ' . implode('; and ', $followups)
                        . '. The switch itself is complete and confirmed.',
                ];
            case 'switched_unconfirmed':
                return [
                    'type'    => 'warning',
                    'message' => 'THE ENDPOINT WAS CHANGED, but the row could not be read back, so the '
                        . 'change could not be confirmed. The breaker was deliberately left alone. '
                        . 'Re-read the chain before acting on it.',
                ];
            case 'switched_then_superseded':
                return [
                    'type'    => 'warning',
                    'message' => 'THE ENDPOINT WAS CHANGED, and another write landed after it, so the '
                        . 'chain now holds a different value. The breaker was deliberately left '
                        . 'alone. Re-read the chain before acting on it.',
                ];
            case 'write_unconfirmed':
                return [
                    'type'    => 'error',
                    'message' => 'The write did not report success, so it MAY OR MAY NOT have applied. '
                        . 'Re-read the chain before trying again.',
                ];
            case 'from_mismatch':
                return ['type' => 'error', 'message' => 'Nothing changed: the chain is no longer on the endpoint you reviewed.'];
            case 'identity_changed':
                return ['type' => 'error', 'message' => 'Nothing changed: the chain\'s identity changed since you reviewed it.'];
            case 'review_token_invalid':
                return ['type' => 'error', 'message' => 'Nothing changed: that confirmation has expired or been replaced. Start again.'];
            case 'review_chain_mismatch':
                return ['type' => 'error', 'message' => 'Nothing changed: that confirmation belongs to a different chain.'];
            case 'review_consume_failed':
                return ['type' => 'error', 'message' => 'Nothing changed: the confirmation could not be claimed, so nothing was contacted or written.'];
            case 'lock_contended':
                return ['type' => 'warning', 'message' => 'Nothing changed: another endpoint operation is in progress for this chain. Try again.'];
            case 'already_current':
                return ['type' => 'info', 'message' => 'Nothing changed: the chain is already on that endpoint.'];
            case 'not_governed':
                return ['type' => 'error', 'message' => 'Nothing changed: that chain is not governed by endpoint policy.'];
            case 'chain_inactive':
                return ['type' => 'error', 'message' => 'Nothing changed: that chain is not active.'];
            case 'target_malformed':
                return ['type' => 'error', 'message' => 'Nothing changed: that target URL could not be read.'];
            case 'target_not_approved':
                return ['type' => 'error', 'message' => 'Nothing changed: that endpoint is not on the approved list for this chain.'];
            case 'target_unreachable':
                return ['type' => 'error', 'message' => 'Nothing changed: the new endpoint could not be reached, so it was not proven.'];
            case 'target_identity_unreadable':
                return ['type' => 'error', 'message' => 'Nothing changed: the new endpoint did not report a readable network identity.'];
            case 'target_network_mismatch':
                return ['type' => 'error', 'message' => 'Nothing changed: the new endpoint reported a DIFFERENT network. It serves another chain.'];
            case 'invalid_chain':
                return ['type' => 'error', 'message' => 'Nothing changed: unknown chain.'];
            case 'missing_input':
                return ['type' => 'error', 'message' => 'Nothing changed: the request was incomplete.'];
            case 'unexpected_error':
                // ⚠ DELIBERATELY SAYS NOTHING ABOUT THE ENDPOINT. An unexpected
                // throw can happen on either side of the write, so this copy must
                // not claim either outcome. The reference joins it to the log line
                // and the durable `outcome => failed` row.
                return [
                    'type'    => 'error',
                    'message' => AdminActionSupport::failureMessage($reference)
                        . ' Check the chain\'s current endpoint before retrying.',
                ];
            default:
                return null;
        }
    }

    /**
     * Operator copy for the Identity editor's own result codes.
     *
     * @return array{type: string, message: string}|null
     */
    private static function identity_notice_from_query(): ?array
    {
        $result = isset($_GET['bcc_identity']) ? sanitize_key((string) $_GET['bcc_identity']) : '';
        if ($result === '') {
            return null;
        }

        switch ($result) {
            case 'ok':
                return ['type' => 'success', 'message' => __('Chain identity saved.', 'bcc-trust')];
            case 'partial':
                return [
                    'type'    => 'error',
                    'message' => __(
                        'Some fields were rejected and kept their stored values — check the Icon URL (https://…) and Color (#RRGGBB) formats. Every other field was saved.',
                        'bcc-trust'
                    ),
                ];
            case 'invalid_chain':
                return ['type' => 'error', 'message' => __('Invalid chain.', 'bcc-trust')];
            case 'not_found':
                return ['type' => 'error', 'message' => __('Chain not found — nothing was saved.', 'bcc-trust')];
            case 'exception':
                $ref = isset($_GET['bcc_ref']) ? sanitize_text_field((string) $_GET['bcc_ref']) : '';
                return [
                    'type'    => 'error',
                    'message' => AdminActionSupport::failureMessage($ref),
                ];
            default:
                return null;
        }
    }

    /**
     * Result notice for a completed "Run cron now" sweep, rebuilt from the
     * PRG redirect args written by ChainSweepActions.
     */
    private static function render_sweep_notice(): void
    {
        $slug = isset($_GET['bcc_sweep']) ? sanitize_key((string) $_GET['bcc_sweep']) : '';
        if ($slug === '') {
            return;
        }

        $steps = ChainSweepActions::stepsForSlug($slug);
        if ($steps === []) {
            return;
        }

        $result = isset($_GET['bcc_result']) ? sanitize_key((string) $_GET['bcc_result']) : '';

        if ($result === 'failed') {
            $failedAt = isset($_GET['bcc_failed_at']) ? sanitize_key((string) $_GET['bcc_failed_at']) : '';
            $ref      = isset($_GET['bcc_ref']) ? sanitize_text_field((string) $_GET['bcc_ref']) : '';

            echo '<div class="notice notice-error is-dismissible"><p><strong>BCC On-Chain:</strong> ';
            printf(
                /* translators: %s: the sweep step that failed, e.g. "collections". */
                esc_html__('Indexing stopped during the %s step.', 'bcc-trust'),
                esc_html($failedAt !== '' ? $failedAt : 'unknown')
            );
            echo ' ' . esc_html(AdminActionSupport::failureMessage($ref)) . '</p></div>';
            return;
        }

        if ($result !== 'ok') {
            return;
        }

        $enrichStats = get_option('bcc_onchain_enrichment_stats', []);

        echo '<div class="notice notice-success is-dismissible"><p><strong>BCC On-Chain:</strong> ';
        printf(
            /* translators: %s: completed step list, e.g. "validators + collections". */
            esc_html__('Indexing complete (%s).', 'bcc-trust'),
            esc_html(ChainSweepActions::stepLabels($steps))
        );
        echo '</p>';

        if (is_array($enrichStats) && $enrichStats !== [] && in_array('enrichment', $steps, true)) {
            printf(
                '<p>Enrichment: %d processed, %d failed, %d skipped, %d API calls. Stop: %s</p>',
                (int) ($enrichStats['processed'] ?? 0),
                (int) ($enrichStats['failed'] ?? 0),
                (int) ($enrichStats['skipped'] ?? 0),
                (int) ($enrichStats['api_calls'] ?? 0),
                esc_html((string) ($enrichStats['stopped_reason'] ?? '—'))
            );
        }

        echo '</div>';
    }

    /**
     * @param list<ChainRow> $chains
     */
    private static function render_identity_tab(array $chains): void
    {
        ?>
        <p>
            Edit the public chain-identity fields surfaced on each chain's Hall
            (the <code>chain_profile</code> block of the
            <code>/halls/:slug</code> payload): the &ldquo;About this
            chain&rdquo; description, icon, and accent color. Infrastructure
            endpoints (RPC / REST) are configured elsewhere and are never part
            of the public payload.
        </p>

        <?php foreach ($chains as $chain):
            $cid = (int) $chain->id;
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
              style="margin:0 0 12px 0;padding:12px;background:#fff;border:1px solid #c3c4c7;max-width:1100px;">
            <?php wp_nonce_field(self::ACTION_IDENTITY_SAVE, 'bcc_chain_identity_nonce'); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_IDENTITY_SAVE); ?>">
            <input type="hidden" name="chain_id" value="<?php echo esc_attr((string) $cid); ?>">
            <div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
                <div style="min-width:150px;">
                    <strong><?php echo esc_html($chain->name); ?></strong><br>
                    <code><?php echo esc_html($chain->slug); ?></code>
                </div>
                <div style="flex:1 1 320px;min-width:280px;">
                    <label style="display:block;font-weight:600;margin-bottom:4px;">About this chain</label>
                    <textarea name="description" rows="3" style="width:100%;"
                              placeholder="Short description shown on this chain's Hall…"><?php echo esc_textarea($chain->description ?? ''); ?></textarea>
                </div>
                <div style="flex:0 0 240px;">
                    <label style="display:block;font-weight:600;margin-bottom:4px;">Icon URL</label>
                    <input type="url" name="icon_url" style="width:100%;"
                           value="<?php echo esc_attr($chain->icon_url ?? ''); ?>" placeholder="https://…">
                    <label style="display:block;font-weight:600;margin:8px 0 4px;">Color</label>
                    <input type="text" name="color" style="width:100px;" maxlength="7"
                           value="<?php echo esc_attr($chain->color ?? ''); ?>" placeholder="#RRGGBB">
                </div>
                <div style="flex:0 0 auto;align-self:center;">
                    <button type="submit" class="button button-primary">Save</button>
                </div>
            </div>
        </form>
        <?php endforeach; ?>
        <?php
    }

    private static function render_js(string $nonce): void
    {
        ?>
        <script>
        (function() {
            var ajaxUrl = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>';
            var nonce   = '<?php echo esc_js($nonce); ?>';

            function refreshChain(btn) {
                var chainId   = btn.getAttribute('data-chain-id');
                var action    = btn.getAttribute('data-action');
                var statusEl  = btn.parentElement.querySelector('.bcc-chain-status');

                btn.disabled = true;
                btn.textContent = 'Indexing...';
                if (statusEl) statusEl.textContent = '';

                var body = new FormData();
                body.append('action', action);
                body.append('nonce', nonce);
                body.append('chain_id', chainId);

                return fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function(r) { return r.json(); })
                    .then(function(resp) {
                        btn.disabled = false;
                        btn.textContent = 'Refresh';
                        if (statusEl) {
                            statusEl.style.color = resp.success ? '#00a32a' : '#d63638';
                            statusEl.textContent = resp.success ? resp.data.message : (resp.data.message || 'Error');
                        }
                        return resp;
                    })
                    .catch(function() {
                        btn.disabled = false;
                        btn.textContent = 'Refresh';
                        if (statusEl) {
                            statusEl.style.color = '#d63638';
                            statusEl.textContent = 'Network error';
                        }
                    });
            }

            document.querySelectorAll('.bcc-chain-refresh-btn').forEach(function(btn) {
                btn.addEventListener('click', function() { refreshChain(btn); });
            });

            var refreshAllBtn    = document.getElementById('bcc-refresh-all');
            var refreshAllStatus = document.getElementById('bcc-refresh-all-status');

            if (refreshAllBtn) {
                refreshAllBtn.addEventListener('click', function() {
                    var buttons = Array.from(document.querySelectorAll('.bcc-chain-refresh-btn:not(:disabled)'));
                    var total   = buttons.length;
                    var done    = 0;

                    refreshAllBtn.disabled = true;
                    refreshAllBtn.textContent = 'Refreshing...';
                    if (refreshAllStatus) refreshAllStatus.textContent = '0 / ' + total;

                    function next() {
                        if (buttons.length === 0) {
                            refreshAllBtn.disabled = false;
                            refreshAllBtn.textContent = 'Refresh All Chains';
                            if (refreshAllStatus) refreshAllStatus.textContent = 'Done! ' + done + ' / ' + total + ' completed.';
                            return;
                        }

                        var btn = buttons.shift();
                        refreshChain(btn).then(function() {
                            done++;
                            if (refreshAllStatus) refreshAllStatus.textContent = done + ' / ' + total;
                            next();
                        });
                    }

                    next();
                });
            }
        })();
        </script>
        <?php
    }
}
