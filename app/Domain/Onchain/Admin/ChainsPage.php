<?php

namespace BCC\Trust\Onchain\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\ValidatorRepository;
use BCC\Trust\Onchain\Factories\FetcherFactory;

/**
 * Admin page: Chains
 *
 * Two sub-tabs:
 *  - Validators: per-chain validator refresh (existing)
 *  - NFT Collections: per-chain collection refresh (new)
 *
 * @phpstan-import-type ChainRow from ChainRepository
 * @phpstan-import-type ValidatorCountByChain from ValidatorRepository
 * @phpstan-import-type CollectionCountByChain from CollectionRepository
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

    public static function register_ajax(): void
    {
        add_action('wp_ajax_bcc_chain_refresh', [self::class, 'ajax_refresh']);
        add_action('wp_ajax_bcc_collection_refresh', [self::class, 'ajax_collection_refresh']);
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
            wp_send_json_error(['message' => $chain->name . ': ' . $e->getMessage()]);
        }
    }

    // ── AJAX: Collection Refresh ────────────────────────────────────────────

    public static function ajax_collection_refresh(): void
    {
        check_ajax_referer('bcc_chain_refresh', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized.']);
        }

        $chainId = (int) ($_POST['chain_id'] ?? 0);
        $chain   = ChainRepository::getById($chainId);

        \BCC\Core\Log\Logger::info('[bcc-trust] Chain collection refresh (manual)', [
            'action'   => 'chain_refresh_collections',
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

            if (!$fetcher->supports_feature('top_collections')) {
                wp_send_json_error(['message' => $chain->name . ' does not support collection indexing.']);
            }

            $collections = $fetcher->fetch_top_collections(100);

            if (empty($collections)) {
                wp_send_json_success([
                    'message' => "No collections returned for {$chain->name}.",
                    'stats'   => ['total' => 0],
                ]);
            }

            $count = CollectionRepository::bulkUpsert($collections, 4 * HOUR_IN_SECONDS);

            wp_send_json_success([
                'message' => sprintf('%s: %d collections indexed.', $chain->name, $count),
                'stats'   => ['total' => $count],
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $chain->name . ': ' . $e->getMessage()]);
        }
    }

    // ── Render ──────────────────────────────────────────────────────────────

    public static function render_page(): void
    {
        // Handle an Identity-editor POST BEFORE reading chains, so the table
        // re-renders with the saved values (updateIdentity busts the cache).
        $notice = self::handle_identity_save();

        $chains         = ChainRepository::getAll();
        $valCountMap    = ValidatorRepository::getCountsByChain();
        $collCountMap   = CollectionRepository::getCountsByChain();
        $nonce          = wp_create_nonce('bcc_chain_refresh');
        $activeTab      = sanitize_key($_GET['subtab'] ?? 'validators');
        if (!in_array($activeTab, ['validators', 'collections', 'identity'], true)) {
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

            <?php
            // "Run cron now" — manual trigger for the all-chains indexers. Reuses
            // the existing admin_init handler at bcc-trust.php:878-905 which routes
            // through ChainRefreshService::index_validators / index_collections /
            // refresh_validators. Those paths call OnchainCircuitBreaker::recordSuccess()
            // on success, which clears the "Chain data is stale" admin notice that
            // fires from bcc-trust.php:1505. Synchronous run; first invocation can
            // be slow while every active chain's fetcher is exercised.
            //
            // Note: the per-chain AJAX "Refresh" buttons further down call the
            // fetchers directly without going through ChainRefreshService, so they
            // do NOT clear the staleness notice. Use these top-of-page buttons for
            // that.
            $runAllUrl    = wp_nonce_url(
                admin_url('admin.php?page=' . self::PAGE_SLUG . '&bcc_run_index_all=1'),
                'bcc_onchain_admin_trigger'
            );
            $runValUrl    = wp_nonce_url(
                admin_url('admin.php?page=' . self::PAGE_SLUG . '&bcc_run_index_validators=1'),
                'bcc_onchain_admin_trigger'
            );
            $runCollUrl   = wp_nonce_url(
                admin_url('admin.php?page=' . self::PAGE_SLUG . '&bcc_run_index_collections=1'),
                'bcc_onchain_admin_trigger'
            );
            $runEnrichUrl = wp_nonce_url(
                admin_url('admin.php?page=' . self::PAGE_SLUG . '&bcc_run_enrich_validators=1'),
                'bcc_onchain_admin_trigger'
            );
            ?>
            <div style="margin:8px 0 16px 0;padding:10px 12px;background:#f6f7f7;border:1px solid #c3c4c7;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <strong style="margin-right:4px;">Run cron now:</strong>
                <a href="<?php echo esc_url($runAllUrl); ?>"
                   class="button button-primary"
                   onclick="return confirm('Run validator index + collection index + enrichment for every active chain? This is synchronous — first click may take 30+ seconds.');">
                    All (validators + collections + enrichment)
                </a>
                <a href="<?php echo esc_url($runValUrl); ?>" class="button">Validators only</a>
                <a href="<?php echo esc_url($runCollUrl); ?>" class="button">Collections only</a>
                <a href="<?php echo esc_url($runEnrichUrl); ?>" class="button">Enrichment only</a>
                <span style="color:#646970;font-size:11px;margin-left:auto;">
                    Clears the &ldquo;Chain data is stale&rdquo; notice via <code>OnchainCircuitBreaker::recordSuccess</code> on the chains that respond.
                </span>
            </div>

            <nav class="nav-tab-wrapper" style="margin-bottom:16px">
                <a href="<?php echo esc_url(add_query_arg('subtab', 'validators')); ?>"
                   class="nav-tab <?php echo $activeTab === 'validators' ? 'nav-tab-active' : ''; ?>">
                    Validators
                </a>
                <a href="<?php echo esc_url(add_query_arg('subtab', 'collections')); ?>"
                   class="nav-tab <?php echo $activeTab === 'collections' ? 'nav-tab-active' : ''; ?>">
                    NFT Collections
                </a>
                <a href="<?php echo esc_url(add_query_arg('subtab', 'identity')); ?>"
                   class="nav-tab <?php echo $activeTab === 'identity' ? 'nav-tab-active' : ''; ?>">
                    Identity
                </a>
            </nav>

            <?php if ($activeTab === 'validators'): ?>
                <?php self::render_validators_tab($chains, $valCountMap); ?>
            <?php elseif ($activeTab === 'collections'): ?>
                <?php self::render_collections_tab($chains, $collCountMap); ?>
            <?php else: ?>
                <?php self::render_identity_tab($chains); ?>
            <?php endif; ?>
        </div>

        <?php self::render_js($nonce, $activeTab); ?>
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

    /**
     * @param list<ChainRow> $chains
     * @param array<int, CollectionCountByChain> $countMap
     */
    private static function render_collections_tab(array $chains, array $countMap): void
    {
        ?>
        <p>Click <strong>Refresh</strong> to fetch top NFT collections for a chain. Only chains with <code>top_collections</code> support are shown.</p>

        <table class="widefat striped" style="max-width:1000px">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Slug</th>
                    <th>Type</th>
                    <th>Collections</th>
                    <th>Last Indexed</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $hasAny = false;
                foreach ($chains as $chain):
                    $cid       = (int) $chain->id;
                    $hasDriver = FetcherFactory::has_driver($chain->chain_type);
                    $isActive  = (int) $chain->is_active;

                    if (!$isActive || !$hasDriver) {
                        continue;
                    }

                    $fetcher = FetcherFactory::make_for_chain($chain);
                    if (!$fetcher->supports_feature('top_collections')) {
                        continue;
                    }

                    $hasAny    = true;
                    $info      = $countMap[$cid] ?? null;
                    $collCount = $info ? (int) $info->cnt : 0;
                    $lastFetch = $info->last_fetched ?? null;
                ?>
                <tr>
                    <td><?php echo esc_html((string) $cid); ?></td>
                    <td><strong><?php echo esc_html($chain->name); ?></strong></td>
                    <td><code><?php echo esc_html($chain->slug); ?></code></td>
                    <td><code><?php echo esc_html($chain->chain_type); ?></code></td>
                    <td><?php echo esc_html((string) $collCount); ?></td>
                    <td><?php echo $lastFetch ? esc_html($lastFetch) : '<em>Never</em>'; ?></td>
                    <td>
                        <button class="button bcc-chain-refresh-btn"
                                data-chain-id="<?php echo esc_attr((string) $cid); ?>"
                                data-chain-name="<?php echo esc_attr($chain->name); ?>"
                                data-action="bcc_collection_refresh">
                            Refresh
                        </button>
                        <span class="bcc-chain-status" style="margin-left:8px;font-size:12px;"></span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$hasAny): ?>
                <tr><td colspan="7"><em>No chains with collection indexing support.</em></td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <p style="margin-top:16px">
            <button class="button button-primary"
                    id="bcc-refresh-all"
                    onclick="return confirm('Refresh top NFT collections for ALL chains? This fans out per-chain API calls (Alchemy / Helius / Cosmos LCD / etc.) and consumes provider quota. Confirm only if you actually want a full refresh — for one chain, use the per-row Refresh button instead.');">Refresh All Collections</button>
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
     * value). All the actual DB work + cache-bust lives in
     * ChainRepository::updateIdentity (§1 — no $wpdb in an Admin page).
     *
     * @return array{type: string, message: string}|null notice to render,
     *         or null when this request is not an identity save.
     */
    private static function handle_identity_save(): ?array
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return null;
        }
        if (($_POST['bcc_chain_identity_action'] ?? '') !== 'save') {
            return null;
        }

        if (!current_user_can('manage_options')) {
            return ['type' => 'error', 'message' => 'You do not have permission to edit chains.'];
        }

        $nonce = isset($_POST['bcc_chain_identity_nonce'])
            ? sanitize_text_field((string) wp_unslash($_POST['bcc_chain_identity_nonce']))
            : '';
        if (!wp_verify_nonce($nonce, 'bcc_chain_identity_save')) {
            return ['type' => 'error', 'message' => 'Security check failed. Please reload and try again.'];
        }

        $chainId = (int) ($_POST['chain_id'] ?? 0);
        if ($chainId <= 0) {
            return ['type' => 'error', 'message' => 'Invalid chain.'];
        }

        $descriptionRaw = isset($_POST['description']) ? wp_unslash($_POST['description']) : '';
        $iconUrlRaw     = isset($_POST['icon_url']) ? wp_unslash($_POST['icon_url']) : '';
        $colorRaw       = isset($_POST['color']) ? wp_unslash($_POST['color']) : '';

        $description = sanitize_textarea_field(is_string($descriptionRaw) ? $descriptionRaw : '');
        $iconUrl     = esc_url_raw(is_string($iconUrlRaw) ? $iconUrlRaw : '');
        $color       = sanitize_hex_color(is_string($colorRaw) ? $colorRaw : '');

        $ok = ChainRepository::updateIdentity(
            $chainId,
            $description !== '' ? $description : null,
            $iconUrl !== '' ? $iconUrl : null,
            ($color !== null && $color !== '') ? $color : null
        );

        if (!$ok) {
            return ['type' => 'error', 'message' => 'Chain not found — nothing was saved.'];
        }

        return ['type' => 'success', 'message' => 'Chain identity saved.'];
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
        <form method="post" action=""
              style="margin:0 0 12px 0;padding:12px;background:#fff;border:1px solid #c3c4c7;max-width:1100px;">
            <?php wp_nonce_field('bcc_chain_identity_save', 'bcc_chain_identity_nonce'); ?>
            <input type="hidden" name="bcc_chain_identity_action" value="save">
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

    private static function render_js(string $nonce, string $activeTab): void
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
                            refreshAllBtn.textContent = '<?php echo $activeTab === 'validators' ? 'Refresh All Chains' : 'Refresh All Collections'; ?>';
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
