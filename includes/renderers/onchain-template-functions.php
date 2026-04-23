<?php
/**
 * On-Chain Rendering Functions
 *
 * Read-only renderers for auto-fetched on-chain data.
 * These complement the existing bcc_render_rows() (manual ACF fields)
 * and bcc_render_repeater_slider() (manual repeaters) functions.
 *
 * Key differences from ACF renderers:
 *  - No edit button, no visibility toggle
 *  - "On-chain · Synced Xh ago" badge
 *  - Refresh button (rate-limited)
 *  - Paginated card grid for large datasets
 *
 * @package BCC_Onchain_Signals
 * @subpackage Renderers
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Render a block of read-only on-chain stat rows.
 *
 * @param array  $stats       Key-value pairs of stat_label => stat_value.
 * @param string $fetched_at  MySQL datetime of last fetch.
 * @param array  $options     {
 *     @type string $title    Section title (e.g. "On-Chain Metrics").
 *     @type string $icon     Optional icon class.
 *     @type bool   $show_refresh Whether to show refresh button.
 *     @type int    $post_id  For refresh callback.
 * }
 */
function bcc_render_onchain_stats(array $stats, string $fetched_at, array $options = []): void {
    $title        = $options['title'] ?? 'On-Chain Data';
    $show_refresh = $options['show_refresh'] ?? false;
    $post_id      = $options['post_id'] ?? 0;

    $fetched_ts = strtotime( $fetched_at );
    $time_ago   = ( false !== $fetched_ts ) ? human_time_diff( $fetched_ts, current_time( 'timestamp', true ) ) : '';

    ?>
    <section class="bcc-onchain-stats">
        <div class="bcc-onchain-stats__header">
            <h3 class="bcc-section-title"><?php echo esc_html($title); ?></h3>
            <?php if (function_exists('bcc_render_source_badge')) : ?>
                <?php bcc_render_source_badge('onchain'); ?>
            <?php endif; ?>
            <?php if ( $time_ago ) : ?>
                <span class="bcc-onchain-synced-at"><?php echo esc_html( sprintf( 'Synced %s ago', $time_ago ) ); ?></span>
            <?php endif; ?>
            <?php if ( $show_refresh && $post_id ) : ?>
                <button class="bcc-onchain-refresh" data-post-id="<?php echo (int) $post_id; ?>">
                    &#x21bb; Refresh
                </button>
            <?php endif; ?>
        </div>

        <div class="bcc-onchain-stats__grid">
            <?php foreach ($stats as $label => $value) : ?>
                <div class="bcc-onchain-stat">
                    <span class="bcc-onchain-stat__label"><?php echo esc_html($label); ?></span>
                    <span class="bcc-onchain-stat__value"><?php echo esc_html($value); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
}

/**
 * Render a paginated card grid for on-chain items (validators, collections, etc.).
 *
 * @param array  $items     Array of item objects to render.
 * @param int    $total     Total item count (for pagination).
 * @param array  $options   {
 *     @type string $title       Section title.
 *     @type string $type        Item type: 'validator', 'collection', 'contract', 'treasury'.
 *     @type int    $per_page    Items per page.
 *     @type int    $current_page Current page number.
 *     @type string $fetched_at  Last fetch time.
 *     @type callable $card_renderer  Function to render each card. Receives ($item).
 * }
 */
function bcc_render_onchain_cards(array $items, int $total, array $options = []): void {
    $title         = $options['title'] ?? 'On-Chain Items';
    $type          = $options['type'] ?? 'item';
    $per_page      = $options['per_page'] ?? 8;
    $current_page  = $options['current_page'] ?? 1;
    $fetched_at    = $options['fetched_at'] ?? '';
    $card_renderer = $options['card_renderer'] ?? null;
    $total_pages   = (int) ceil($total / $per_page);

    $time_ago = $fetched_at ? human_time_diff(strtotime($fetched_at), current_time('timestamp', true)) : '';

    ?>
    <section class="bcc-onchain-cards" data-type="<?php echo esc_attr($type); ?>">
        <div class="bcc-onchain-cards__header">
            <h3 class="bcc-section-title"><?php echo esc_html($title); ?></h3>
            <?php if (function_exists('bcc_render_source_badge')) : ?>
                <?php bcc_render_source_badge('onchain'); ?>
            <?php endif; ?>
            <?php if ($time_ago) : ?>
                <span class="bcc-onchain-synced-at">Synced <?php echo esc_html($time_ago); ?> ago</span>
            <?php endif; ?>
            <?php if ($total > 0) : ?>
                <span class="bcc-onchain-cards__count">
                    <?php echo esc_html($total); ?> <?php echo esc_html($type); ?><?php echo $total !== 1 ? 's' : ''; ?>
                </span>
            <?php endif; ?>
        </div>

        <?php if (empty($items)) : ?>
            <p class="bcc-onchain-empty">No on-chain <?php echo esc_html($type); ?> data yet. Connect a wallet to auto-fill.</p>
        <?php else : ?>
            <div class="bcc-onchain-cards__grid">
                <?php foreach ($items as $item) : ?>
                    <div class="bcc-onchain-card">
                        <?php
                        if (is_callable($card_renderer)) {
                            call_user_func($card_renderer, $item);
                        } else {
                            bcc_render_default_card($item, $type);
                        }
                        ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($total_pages > 1) : ?>
                <div class="bcc-onchain-cards__pagination">
                    <span class="bcc-onchain-cards__page-info">
                        Page <?php echo (int) $current_page; ?> of <?php echo (int) $total_pages; ?>
                    </span>
                    <?php if ($current_page < $total_pages) : ?>
                        <button class="bcc-onchain-load-more"
                                data-type="<?php echo esc_attr($type); ?>"
                                data-page="<?php echo (int) ($current_page + 1); ?>"
                                data-per-page="<?php echo (int) $per_page; ?>">
                            Load More
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
    <?php
}

/**
 * Default card renderer (fallback).
 */
function bcc_render_default_card(object $item, string $type): void {
    echo '<div class="bcc-onchain-card--default"><em>' . esc_html(ucfirst($type)) . ' data available</em></div>';
}

// ── Type-Specific Card Renderers ─────────────────────────────────────────────

/**
 * Render a single validator chain card.
 *
 * @param object $item Row from wp_bcc_onchain_validators joined with wp_bcc_chains.
 */
function bcc_render_validator_chain_card(object $item): void {
    $status_class = $item->status === 'active' ? 'bcc-status-active' : 'bcc-status-inactive';
    $explorer_link = $item->explorer_url
        ? esc_url($item->explorer_url . '/validator/' . $item->operator_address)
        : '#';
    $short_addr = substr($item->operator_address, 0, 12) . '…' . substr($item->operator_address, -6);

    ?>
    <div class="bcc-validator-card__header">
        <span class="bcc-validator-card__chain"><?php echo esc_html($item->chain_name); ?></span>
        <span class="bcc-status-pill <?php echo esc_attr($status_class); ?>"><?php echo esc_html(ucfirst($item->status)); ?></span>
    </div>

    <?php if ($item->moniker) : ?>
        <div class="bcc-validator-card__moniker"><?php echo esc_html($item->moniker); ?></div>
    <?php endif; ?>

    <div class="bcc-validator-card__stats">
        <?php if ($item->voting_power_rank) : ?>
            <div class="bcc-validator-card__stat">
                <span class="bcc-stat-label">Rank</span>
                <span class="bcc-stat-value">#<?php echo (int) $item->voting_power_rank; ?></span>
            </div>
        <?php endif; ?>

        <?php if ($item->commission_rate !== null) : ?>
            <div class="bcc-validator-card__stat">
                <span class="bcc-stat-label">Commission</span>
                <span class="bcc-stat-value"><?php echo esc_html(number_format($item->commission_rate, 1)); ?>%</span>
            </div>
        <?php endif; ?>

        <?php if ($item->total_stake !== null) : ?>
            <div class="bcc-validator-card__stat">
                <span class="bcc-stat-label">Total Stake</span>
                <span class="bcc-stat-value"><?php echo esc_html(bcc_format_number($item->total_stake)); ?> <?php echo esc_html($item->native_token); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($item->delegator_count !== null) : ?>
            <div class="bcc-validator-card__stat">
                <span class="bcc-stat-label">Delegators</span>
                <span class="bcc-stat-value"><?php echo esc_html(number_format($item->delegator_count)); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($item->uptime_30d !== null) : ?>
            <div class="bcc-validator-card__stat">
                <span class="bcc-stat-label">Uptime (30d)</span>
                <span class="bcc-stat-value"><?php echo esc_html(number_format($item->uptime_30d, 1)); ?>%</span>
            </div>
        <?php endif; ?>
    </div>

    <div class="bcc-validator-card__footer">
        <a href="<?php echo esc_url($explorer_link); ?>" target="_blank" rel="noopener" class="bcc-validator-card__explorer">
            <?php echo esc_html($short_addr); ?> &#x2197;
        </a>
    </div>
    <?php
}

/**
 * Render a single NFT collection card.
 *
 * Expects these additional properties on $item (set by CollectionService::enrichWithBadges
 * or by the template before calling):
 *   ->is_creator       bool  Page owner created this collection.
 *   ->viewer_holds     bool  Viewing user holds NFTs from this collection.
 *   ->show_on_profile  int   1 = visible on profile, 0 = hidden.
 *   ->can_toggle       bool  True if viewer is the page owner (can toggle visibility).
 *
 * @param object $item Row from wp_bcc_onchain_collections joined with wp_bcc_chains.
 */
function bcc_render_collection_card(object $item): void {
    $explorer_link = ($item->explorer_url && $item->contract_address)
        ? esc_url($item->explorer_url . '/token/' . $item->contract_address)
        : '#';
    $short_addr = $item->contract_address
        ? substr($item->contract_address, 0, 8) . '…' . substr($item->contract_address, -4)
        : '—';
    $currency = $item->floor_currency ?? $item->native_token ?? 'ETH';

    $is_creator   = !empty($item->is_creator);
    $viewer_holds = !empty($item->viewer_holds);
    $can_toggle   = !empty($item->can_toggle);
    $show_on      = (int) ($item->show_on_profile ?? 1);
    $data_source  = $item->data_source ?? 'onchain';
    $is_verified  = ($data_source === 'onchain');

    ?>
    <div class="bcc-collection-card__header">
        <span class="bcc-collection-card__chain"><?php echo esc_html($item->chain_name); ?></span>
        <span class="bcc-collection-card__badges">
            <?php if ($can_toggle && !$show_on) : ?>
                <span class="bcc-badge bcc-badge--hidden" title="This collection is hidden from your profile">Hidden</span>
            <?php endif; ?>
            <?php if ($is_verified) : ?>
                <?php if (function_exists('bcc_render_source_badge')) { bcc_render_source_badge('onchain'); } ?>
            <?php else : ?>
                <?php if (function_exists('bcc_render_source_badge')) { bcc_render_source_badge('user'); } ?>
            <?php endif; ?>
            <?php if ($is_creator) : ?>
                <span class="bcc-badge bcc-badge--creator" title="Page owner created this collection">Creator</span>
            <?php endif; ?>
            <?php if ($viewer_holds) : ?>
                <span class="bcc-badge bcc-badge--holder" title="You hold NFTs from this collection">Community Member</span>
            <?php endif; ?>
            <?php if ($item->token_standard) : ?>
                <span class="bcc-status-pill bcc-status-neutral"><?php echo esc_html($item->token_standard); ?></span>
            <?php endif; ?>
        </span>
    </div>

    <?php if ($item->collection_name) : ?>
        <div class="bcc-collection-card__name"><?php echo esc_html($item->collection_name); ?></div>
    <?php endif; ?>

    <?php
    // Cast to numeric — ACF manual rows may pass empty strings or string numbers.
    $floor   = ($item->floor_price   !== null && $item->floor_price   !== '') ? (float) $item->floor_price   : null;
    $volume  = ($item->total_volume  !== null && $item->total_volume  !== '') ? (float) $item->total_volume  : null;
    $holders = ($item->unique_holders !== null && $item->unique_holders !== '') ? (int)   $item->unique_holders : null;
    $supply  = ($item->total_supply  !== null && $item->total_supply  !== '') ? (int)   $item->total_supply  : null;
    ?>
    <div class="bcc-collection-card__stats">
        <?php if ($floor !== null) : ?>
            <div class="bcc-collection-card__stat">
                <span class="bcc-stat-label">Floor</span>
                <span class="bcc-stat-value"><?php echo esc_html(bcc_format_number($floor)); ?> <?php echo esc_html($currency); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($volume !== null) : ?>
            <div class="bcc-collection-card__stat">
                <span class="bcc-stat-label">Volume</span>
                <span class="bcc-stat-value"><?php echo esc_html(bcc_format_number($volume)); ?> <?php echo esc_html($currency); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($holders !== null) : ?>
            <div class="bcc-collection-card__stat">
                <span class="bcc-stat-label">Holders</span>
                <span class="bcc-stat-value"><?php echo esc_html(number_format($holders)); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($supply !== null) : ?>
            <div class="bcc-collection-card__stat">
                <span class="bcc-stat-label">Supply</span>
                <span class="bcc-stat-value"><?php echo esc_html(number_format($supply)); ?></span>
            </div>
        <?php endif; ?>
    </div>

    <div class="bcc-collection-card__footer">
        <a href="<?php echo esc_url($explorer_link); ?>" target="_blank" rel="noopener" class="bcc-collection-card__explorer">
            <?php echo esc_html($short_addr); ?> &#x2197;
        </a>
        <?php if ($can_toggle) : ?>
            <label class="bcc-collection-card__toggle" title="Show this collection on your profile">
                <input type="checkbox"
                       class="bcc-collection-profile-toggle"
                       data-collection-id="<?php echo (int) $item->id; ?>"
                       <?php checked($show_on, 1); ?>>
                <span class="bcc-collection-card__toggle-label">Show on profile</span>
            </label>
        <?php endif; ?>
    </div>
    <?php
}

// ── Formatting Helpers ───────────────────────────────────────────────────────

/**
 * Format large numbers for display (1.2M, 450K, etc.).
 */
function bcc_format_number(float $number): string {
    if ($number >= 1e9) {
        return number_format($number / 1e9, 1) . 'B';
    }
    if ($number >= 1e6) {
        return number_format($number / 1e6, 1) . 'M';
    }
    if ($number >= 1e3) {
        return number_format($number / 1e3, 1) . 'K';
    }
    return number_format($number, 2);
}

/**
 * Render a "Connect Wallet" CTA with three ecosystem buttons:
 *   1. MetaMask (EVM) — connects directly or shows EVM chain picker if multiple
 *   2. Cosmos (Keplr) — always shows chain picker dropdown
 *   3. Solana (Phantom) — connects directly
 *
 * @param string $profile_type validator, nft, dao, builder
 * @param int    $post_id
 */
function bcc_render_wallet_connect_cta(string $profile_type, int $post_id): void {
    $wallet_type = $profile_type === 'validator' ? 'validator' : 'user';

    // Group active chains by ecosystem
    $all_chains    = \BCC\Trust\Onchain\Repositories\ChainRepository::getActive();
    $evm_chains    = [];
    $cosmos_chains = [];
    $solana_chain  = null;

    foreach ($all_chains as $c) {
        if ($c->chain_type === 'evm')    $evm_chains[]    = $c;
        if ($c->chain_type === 'cosmos') $cosmos_chains[] = $c;
        if ($c->chain_type === 'solana') $solana_chain    = $c;
    }
    ?>
    <div class="bcc-wallet-connect-cta">
        <p>Connect your wallet to auto-fill on-chain data.</p>

        <div class="bcc-ecosystem-buttons">

            <?php /* ── MetaMask (EVM) ─────────────────────────────── */ ?>
            <?php if ($evm_chains) : ?>
            <div class="bcc-ecosystem" data-ecosystem="evm">
                <button type="button"
                        class="bcc-ecosystem-btn bcc-ecosystem-btn--metamask"
                        <?php if (count($evm_chains) === 1) : ?>
                            data-chain="<?php echo esc_attr($evm_chains[0]->slug); ?>"
                        <?php endif; ?>
                        data-post-id="<?php echo (int) $post_id; ?>"
                        data-wallet-type="<?php echo esc_attr($wallet_type); ?>">
                    <img src="<?php echo esc_url(BCC_TRUST_URL . 'assets/img/metamask.svg'); ?>" alt="" class="bcc-ecosystem-icon">
                    <span>MetaMask</span>
                </button>

                <?php if (count($evm_chains) > 1) : ?>
                <div class="bcc-chain-picker" hidden>
                    <select class="bcc-chain-picker__select">
                        <option value="">Choose EVM chain&hellip;</option>
                        <?php foreach ($evm_chains as $c) : ?>
                            <option value="<?php echo esc_attr($c->slug); ?>"><?php echo esc_html($c->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button"
                            class="bcc-chain-picker__go bcc-wallet-connect"
                            data-post-id="<?php echo (int) $post_id; ?>"
                            data-wallet-type="<?php echo esc_attr($wallet_type); ?>"
                            disabled>
                        Connect
                    </button>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php /* ── Cosmos (Keplr) ─────────────────────────────── */ ?>
            <?php if ($cosmos_chains) : ?>
            <div class="bcc-ecosystem" data-ecosystem="cosmos">
                <button type="button"
                        class="bcc-ecosystem-btn bcc-ecosystem-btn--cosmos"
                        <?php if (count($cosmos_chains) === 1) : ?>
                            data-chain="<?php echo esc_attr($cosmos_chains[0]->slug); ?>"
                        <?php endif; ?>
                        data-post-id="<?php echo (int) $post_id; ?>"
                        data-wallet-type="<?php echo esc_attr($wallet_type); ?>">
                    <img src="<?php echo esc_url(BCC_TRUST_URL . 'assets/img/keplr.svg'); ?>" alt="" class="bcc-ecosystem-icon">
                    <span>Cosmos</span>
                </button>

                <?php if (count($cosmos_chains) > 1) : ?>
                <div class="bcc-chain-picker" hidden>
                    <select class="bcc-chain-picker__select">
                        <option value="">Choose Cosmos chain&hellip;</option>
                        <?php foreach ($cosmos_chains as $c) : ?>
                            <option value="<?php echo esc_attr($c->slug); ?>"><?php echo esc_html($c->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button"
                            class="bcc-chain-picker__go bcc-wallet-connect"
                            data-post-id="<?php echo (int) $post_id; ?>"
                            data-wallet-type="<?php echo esc_attr($wallet_type); ?>"
                            disabled>
                        Connect
                    </button>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php /* ── Solana (Phantom) ───────────────────────────── */ ?>
            <?php if ($solana_chain) : ?>
            <div class="bcc-ecosystem" data-ecosystem="solana">
                <button type="button"
                        class="bcc-ecosystem-btn bcc-ecosystem-btn--solana bcc-wallet-connect"
                        data-chain="<?php echo esc_attr($solana_chain->slug); ?>"
                        data-post-id="<?php echo (int) $post_id; ?>"
                        data-wallet-type="<?php echo esc_attr($wallet_type); ?>">
                    <img src="<?php echo esc_url(BCC_TRUST_URL . 'assets/img/phantom.svg'); ?>" alt="" class="bcc-ecosystem-icon">
                    <span>Solana</span>
                </button>
            </div>
            <?php endif; ?>

        </div>
    </div>
    <?php
}
