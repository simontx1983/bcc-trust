<?php
/**
 * Validator Leaderboard Block – server-side render.
 *
 * Etherscan-style table of all indexed validators.
 * Adapts to light/dark mode via BCC design tokens.
 * Shows site logo for unclaimed, claimer avatar for claimed.
 */

if (!defined('ABSPATH')) {
    exit;
}

$per_page      = (int) ($attributes['perPage'] ?? 25);
$show_claim    = (bool) ($attributes['showClaim'] ?? true);
$time_window   = sanitize_key($attributes['defaultTimeWindow'] ?? '');
$default_chain = sanitize_key($attributes['defaultChain'] ?? 'cosmos');
$user_id       = get_current_user_id();
$instance_id   = 'vlb-' . wp_unique_id();

// Allow URL override: ?vlb_chain=osmosis switches the active chain.
$active_chain_slug = isset($_GET['vlb_chain']) ? sanitize_key($_GET['vlb_chain']) : $default_chain;

// Server-side sort: ?vlb_sort=self_stake&vlb_dir=desc
$allowed_sorts = ['total_stake', 'self_stake', 'commission_rate', 'uptime_30d', 'voting_power_rank'];
$active_sort   = isset($_GET['vlb_sort']) ? sanitize_key($_GET['vlb_sort']) : 'voting_power_rank';
if (!in_array($active_sort, $allowed_sorts, true)) {
    $active_sort = 'voting_power_rank';
}
$active_dir = isset($_GET['vlb_dir']) ? sanitize_key($_GET['vlb_dir']) : null;
if ($active_dir !== 'asc' && $active_dir !== 'desc') {
    $active_dir = null; // let repository decide default
}

// Resolve the active chain ID from slug.
$active_chain_id   = null;
$active_chain_obj  = null;
if (class_exists('\\BCC\\Onchain\\Repositories\\ChainRepository')) {
    $active_chain_obj = \BCC\Onchain\Repositories\ChainRepository::getBySlug($active_chain_slug);
    if ($active_chain_obj) {
        $active_chain_id = (int) $active_chain_obj->id;
    }
    // Fallback to cosmos if slug is invalid.
    if (!$active_chain_id) {
        $active_chain_obj = \BCC\Onchain\Repositories\ChainRepository::getBySlug('cosmos');
        $active_chain_id  = $active_chain_obj ? (int) $active_chain_obj->id : null;
        $active_chain_slug = 'cosmos';
    }
}

// For backward compat in the template below.
$default_chain_id = $active_chain_id;

// Fetch validators sorted server-side across the full dataset.
$data = [];
if (class_exists('\\BCC\\Onchain\\Repositories\\ValidatorRepository')) {
    $data = \BCC\Onchain\Repositories\ValidatorRepository::getTopValidators(
        1, $per_page, $active_sort, $default_chain_id, $time_window ?: null, $active_dir
    );
}

$validators = $data['items'] ?? [];
$total      = $data['total'] ?? 0;

// Batch-load claims.
$claims_map = [];
if (function_exists('bcc_onchain_claims_table') && !empty($validators)
    && class_exists('\\BCC\\Onchain\\Repositories\\ClaimRepository')
) {
    $vids = array_map(fn($v) => (int) $v->id, $validators);
    $all_claims = \BCC\Onchain\Repositories\ClaimRepository::getForEntityBatch('validator', $vids);
    foreach ($all_claims as $vid => $claims) {
        $claims_map[$vid] = $claims[0]; // First verified claimer.
    }
}

// Site logo for unclaimed avatars.
$site_logo_url = '';
$custom_logo_id = get_theme_mod('custom_logo');
if ($custom_logo_id) {
    $site_logo_url = wp_get_attachment_image_url($custom_logo_id, 'thumbnail');
}
if (!$site_logo_url) {
    $site_logo_url = get_site_icon_url(64);
}

// Chain filter chips — only chain types that have validators.
$chains = [];
if (class_exists('\\BCC\\Onchain\\Repositories\\ChainRepository')) {
    $chains = array_merge(
        \BCC\Onchain\Repositories\ChainRepository::getActive('cosmos'),
        \BCC\Onchain\Repositories\ChainRepository::getActive('thorchain'),
        \BCC\Onchain\Repositories\ChainRepository::getActive('solana'),
        \BCC\Onchain\Repositories\ChainRepository::getActive('polkadot'),
        \BCC\Onchain\Repositories\ChainRepository::getActive('near')
    );
}

// Helpers.
if (!function_exists('bcc_vlb_format')) {
    function bcc_vlb_format($n, string $token = ''): string {
        if ($n === null) return '--';
        $n = (float) $n;
        $s = $n >= 1000000 ? round($n / 1000000, 1) . 'M'
           : ($n >= 1000 ? round($n / 1000, 1) . 'K'
           : number_format($n, 0));
        return $token ? "{$s} {$token}" : $s;
    }
}

if (!function_exists('bcc_vlb_truncate_addr')) {
    function bcc_vlb_truncate_addr(string $addr, int $start = 12, int $end = 4): string {
        if (strlen($addr) <= $start + $end + 3) return $addr;
        return substr($addr, 0, $start) . '...' . substr($addr, -$end);
    }
}

if (!function_exists('bcc_vlb_account_addr')) {
    /**
     * Derive account address from valoper address via proper bech32 decode/re-encode.
     * Falls back to the operator address if decoding fails.
     */
    function bcc_vlb_account_addr(string $operatorAddr): string {
        if (!class_exists('\\BCC\\Onchain\\Factories\\FetcherFactory')
            || !class_exists('\\BCC\\Onchain\\Repositories\\ChainRepository')) {
            return $operatorAddr;
        }

        $pos = strpos($operatorAddr, 'valoper1');
        if ($pos === false) {
            return $operatorAddr;
        }

        // Reuse a single CosmosFetcher for the bech32 utility (static cache).
        static $fetcher = null;
        if ($fetcher === null) {
            $chains = \BCC\Onchain\Repositories\ChainRepository::getActive('cosmos');
            if (!empty($chains)) {
                $fetcher = \BCC\Onchain\Factories\FetcherFactory::make_for_chain($chains[0]);
            }
        }

        if ($fetcher) {
            $result = $fetcher->valoperToAccountAddress($operatorAddr);
            if ($result) {
                return $result;
            }
        }

        return $operatorAddr;
    }
}

// Time window options.
$time_options = [
    ''    => __('All', 'bcc-trust'),
    '1h'  => '1h',
    '6h'  => '6h',
    '12h' => '12h',
    '1d'  => '1d',
    '7d'  => '7d',
    '30d' => '30d',
];

$total_pages = $total > 0 ? (int) ceil($total / $per_page) : 0;

// Compute the effective direction (for displaying arrows and toggling).
$effective_dir = $active_dir;
if (!$effective_dir) {
    $effective_dir = ($active_sort === 'voting_power_rank' || $active_sort === 'commission_rate') ? 'asc' : 'desc';
}

$wrapper_attributes = get_block_wrapper_attributes([
    'class'               => 'bcc-vlb',
    'id'                  => esc_attr($instance_id),
    'data-per-page'       => esc_attr((string) $per_page),
    'data-show-claim'     => $show_claim ? '1' : '0',
    'data-time-window'    => esc_attr($time_window),
    'data-chain-id'       => esc_attr((string) ($active_chain_id ?? '')),
    'data-current-page'   => '1',
    'data-total-pages'    => esc_attr((string) $total_pages),
    'data-total'          => esc_attr((string) $total),
    'data-sort'           => esc_attr($active_sort),
    'data-dir'            => esc_attr($effective_dir),
]);
?>
<div <?php echo $wrapper_attributes; ?>>

    <div class="bcc-vlb__header">
        <span class="bcc-vlb__title">
            <?php
                $chain_label = $active_chain_obj ? $active_chain_obj->name : '';
                printf(esc_html__('Showing the top %1$d %2$s Validators', 'bcc-trust'), $total, esc_html($chain_label));
            ?>
            <span class="bcc-vlb__tooltip" tabindex="0" aria-label="<?php esc_attr_e('Data delay notice', 'bcc-trust'); ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                <span class="bcc-vlb__tooltip-text"><?php esc_html_e('Data may be delayed 2-4 hours. Check Mintscan for up-to-the-minute information.', 'bcc-trust'); ?></span>
            </span>
        </span>
        <div class="bcc-vlb__time-filters">
            <?php foreach ($time_options as $val => $label): ?>
            <button class="bcc-vlb__time-btn <?php echo $time_window === $val ? 'is-active' : ''; ?>" data-window="<?php echo esc_attr($val); ?>">
                <?php echo esc_html($label); ?>
            </button>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (!empty($chains)): ?>
    <div class="bcc-vlb__chain-filters">
        <?php foreach ($chains as $chain):
            $is_active = ($active_chain_slug === $chain->slug);
        ?>
        <a class="bcc-vlb__chain-chip <?php echo $is_active ? 'is-active' : ''; ?>"
           href="<?php echo esc_url(add_query_arg('vlb_chain', $chain->slug)); ?>"
           data-chain-slug="<?php echo esc_attr($chain->slug); ?>">
            <?php echo esc_html($chain->name); ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php
    // Sort column definitions: key => [label, db_column, default_dir, css_class, tooltip]
    // total_stake and self_stake are handled by the stake toggle below, not this loop.
    $sort_columns = [
        'commission_rate'   => [__('Commission', 'bcc-trust'),    'commission_rate',   'asc',  'bcc-vlb__col--sm', ''],
        'uptime_30d'        => [__('Uptime', 'bcc-trust'),        'uptime_30d',        'desc', 'bcc-vlb__col--sm', ''],
    ];

    // Stake toggle state: which mode is active?
    $stake_mode = in_array($active_sort, ['self_stake', 'total_stake'], true) ? $active_sort : 'total_stake';
    $stake_is_self = ($stake_mode === 'self_stake');

    /**
     * Build a sort link URL. If clicking the already-active column, toggle direction.
     * Otherwise, use the column's default direction.
     */
    if ( ! function_exists( 'bcc_vlb_sort_url' ) ) {
        function bcc_vlb_sort_url(string $col, string $defaultDir, string $activeSort, string $activeDir): string {
            if ($col === $activeSort) {
                $newDir = ($activeDir === 'desc') ? 'asc' : 'desc';
            } else {
                $newDir = $defaultDir;
            }
            return esc_url(add_query_arg(['vlb_sort' => $col, 'vlb_dir' => $newDir]));
        }
    }
    ?>
    <div class="bcc-vlb__table">
        <div class="bcc-vlb__thead">
            <span class="bcc-vlb__th bcc-vlb__col--rank"><?php /* translators: line break for stacked header */ echo wp_kses(__('Voting<br>Power', 'bcc-trust'), ['br' => []]); ?></span>
            <span class="bcc-vlb__th bcc-vlb__col--name"><?php esc_html_e('Validator', 'bcc-trust'); ?></span>
            <span class="bcc-vlb__th bcc-vlb__col--addr">
                <button class="bcc-vlb__addr-toggle bcc-vlb__tooltip" data-mode="operator" tabindex="0">
                    <span class="bcc-vlb__addr-toggle-label" data-label-operator="Validator&#10;Address" data-label-account="Account&#10;Address"><?php echo wp_kses(__('Validator<br>Address', 'bcc-trust'), ['br' => []]); ?></span>
                    <svg class="bcc-vlb__addr-toggle-icon" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
                    <span class="bcc-vlb__tooltip-text"><?php esc_html_e('Click to switch. Validator Address (valoper) is for staking. Account Address is the wallet for rewards.', 'bcc-trust'); ?></span>
                </button>
            </span>
            <?php
                // ── Stake toggle column (Total Bonded / Self Bonded) ──
                $stakeIsActive   = in_array($active_sort, ['total_stake', 'self_stake'], true);
                $stakeArrow      = '';
                if ($stakeIsActive) {
                    $stakeArrow = $effective_dir === 'desc' ? '&#9660;' : '&#9650;';
                }
                // Sort link: sorts by the currently visible stake mode.
                $stakeSortHref   = bcc_vlb_sort_url($stake_mode, 'desc', $active_sort, $effective_dir);
                // Toggle link: switches to the OTHER mode (and sorts by it).
                $stakeOtherMode  = $stake_is_self ? 'total_stake' : 'self_stake';
                $stakeToggleHref = esc_url(add_query_arg(['vlb_sort' => $stakeOtherMode, 'vlb_dir' => 'desc']));
                $stakeLabel      = $stake_is_self ? __('Self<br>Bonded', 'bcc-trust') : __('Total<br>Bonded', 'bcc-trust');
                $stakeOtherLabel = $stake_is_self ? __('Total Bonded', 'bcc-trust') : __('Self Bonded', 'bcc-trust');
                $stakeTooltip    = $stake_is_self
                    ? __('Tokens the validator operator has delegated to themselves. Higher self-bond shows the operator has skin in the game and is personally invested in good performance.', 'bcc-trust')
                    : __('Total tokens delegated to this validator by all delegators, including the operator. Higher stake means more voting power and network trust.', 'bcc-trust');
            ?>
            <span class="bcc-vlb__th bcc-vlb__col--stake">
                <a class="bcc-vlb__th--sortable <?php echo $stakeIsActive ? 'is-active' : ''; ?>"
                   href="<?php echo $stakeSortHref; ?>">
                    <?php echo wp_kses($stakeLabel, ['br' => []]); ?> <span class="bcc-vlb__sort-arrow"><?php echo $stakeArrow; ?></span>
                </a>
                <a class="bcc-vlb__stake-toggle bcc-vlb__tooltip" href="<?php echo $stakeToggleHref; ?>" tabindex="0">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
                    <span class="bcc-vlb__tooltip-text"><?php echo esc_html(sprintf(__('Switch to %s. %s', 'bcc-trust'), $stakeOtherLabel, $stakeTooltip)); ?></span>
                </a>
            </span>
            <?php foreach ($sort_columns as $sortKey => [$label, $dbCol, $defaultSortDir, $cssClass, $tooltip]):
                $isActive  = ($active_sort === $dbCol);
                $arrow     = '';
                if ($isActive) {
                    $arrow = $effective_dir === 'desc' ? '&#9660;' : '&#9650;';
                }
                $href = bcc_vlb_sort_url($dbCol, $defaultSortDir, $active_sort, $effective_dir);
            ?>
            <span class="bcc-vlb__th <?php echo esc_attr($cssClass); ?>">
                <a class="bcc-vlb__th--sortable <?php echo $isActive ? 'is-active' : ''; ?>"
                   href="<?php echo $href; ?>">
                    <?php echo esc_html($label); ?> <span class="bcc-vlb__sort-arrow"><?php echo $arrow; ?></span>
                </a>
                <?php if ($tooltip): ?>
                <span class="bcc-vlb__tooltip" tabindex="0">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                    <span class="bcc-vlb__tooltip-text"><?php echo esc_html($tooltip); ?></span>
                </span>
                <?php endif; ?>
            </span>
            <?php endforeach; ?>
            <span class="bcc-vlb__th bcc-vlb__col--action"></span>
        </div>

        <?php if (empty($validators)): ?>
        <div class="bcc-vlb__empty">
            <p><?php esc_html_e('No validators indexed yet. Run the indexer from Settings.', 'bcc-trust'); ?></p>
        </div>
        <?php else: ?>
            <?php foreach ($validators as $rank => $v):
                $vid         = (int) $v->id;
                $claimed     = isset($claims_map[$vid]);
                $claimer     = $claimed ? $claims_map[$vid] : null;
                $claimer_uid = $claimed ? (int) $claimer->user_id : 0;
                $token       = $v->native_token ?? '';
                $chain_name  = $v->chain_name ?? '';
                $operator    = $v->operator_address ?? '';
                $account     = bcc_vlb_account_addr($operator);
                $uptime      = $v->uptime_30d !== null ? round((float) $v->uptime_30d, 1) : null;
                $uptime_class = $uptime === null ? '' : ($uptime >= 99 ? 'is-excellent' : ($uptime >= 95 ? 'is-good' : 'is-warning'));
                $explorer    = $v->explorer_url ?? '';

                // Avatar: claimer photo if claimed, site logo if not.
                $avatar_url = $claimed && $claimer_uid
                    ? get_avatar_url($claimer_uid, ['size' => 64])
                    : $site_logo_url;
            ?>
            <div class="bcc-vlb__row" data-entity-type="validator" data-entity-id="<?php echo esc_attr((string) $vid); ?>" data-chain="<?php echo esc_attr($chain_name); ?>">

                <span class="bcc-vlb__td bcc-vlb__col--rank"><?php echo esc_html((string) ($v->voting_power_rank ?: $rank + 1)); ?></span>

                <span class="bcc-vlb__td bcc-vlb__col--name">
                    <?php if ($avatar_url): ?>
                    <img src="<?php echo esc_url($avatar_url); ?>" alt="" class="bcc-vlb__avatar" width="36" height="36" loading="lazy" />
                    <?php else: ?>
                    <span class="bcc-vlb__avatar bcc-vlb__avatar--placeholder"><?php echo esc_html(mb_strtoupper(mb_substr($v->moniker ?? '?', 0, 1))); ?></span>
                    <?php endif; ?>
                    <span class="bcc-vlb__name-wrap">
                        <strong class="bcc-vlb__moniker"><?php echo esc_html($v->moniker ?: 'Unknown'); ?></strong>
                        <?php if ($claimed): ?>
                        <span class="bcc-vlb__badge bcc-vlb__badge--claimed">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                        </span>
                        <?php endif; ?>
                    </span>
                </span>

                <span class="bcc-vlb__td bcc-vlb__col--addr">
                    <span class="bcc-vlb__addr-val" data-addr-type="operator">
                        <code class="bcc-vlb__addr" title="<?php echo esc_attr($operator); ?>" data-full="<?php echo esc_attr($operator); ?>"><?php echo esc_html(bcc_vlb_truncate_addr($operator)); ?></code>
                        <button class="bcc-vlb__copy" data-copy="<?php echo esc_attr($operator); ?>" title="<?php esc_attr_e('Copy', 'bcc-trust'); ?>">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                        </button>
                    </span>
                    <span class="bcc-vlb__addr-val" data-addr-type="account" style="display:none">
                        <code class="bcc-vlb__addr" title="<?php echo esc_attr($account); ?>" data-full="<?php echo esc_attr($account); ?>"><?php echo esc_html(bcc_vlb_truncate_addr($account)); ?></code>
                        <button class="bcc-vlb__copy" data-copy="<?php echo esc_attr($account); ?>" title="<?php esc_attr_e('Copy', 'bcc-trust'); ?>">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                        </button>
                    </span>
                </span>

                <?php
                    $stakeDisplayLabel = $stake_is_self ? __('Self', 'bcc-trust') : __('Bonded', 'bcc-trust');
                    $stakeValue        = $stake_is_self ? $v->self_stake : $v->total_stake;
                    $stakeSortVal      = (float) ($stakeValue ?? 0);
                    $stakeDisplay      = ($stakeValue !== null && (float) $stakeValue > 0)
                        ? bcc_vlb_format($stakeValue, $token)
                        : ($stake_is_self ? '--' : bcc_vlb_format($v->total_stake, $token));
                ?>
                <span class="bcc-vlb__td bcc-vlb__col--stake" data-label="<?php echo esc_attr($stakeDisplayLabel); ?>" data-sort-value="<?php echo esc_attr((string) $stakeSortVal); ?>"><?php echo esc_html($stakeDisplay); ?></span>
                <span class="bcc-vlb__td bcc-vlb__col--sm" data-label="<?php esc_attr_e('Comm', 'bcc-trust'); ?>" data-sort-value="<?php echo esc_attr((string) ((float) ($v->commission_rate ?? 0))); ?>"><?php echo $v->commission_rate !== null ? esc_html(round((float) $v->commission_rate, 1) . '%') : '--'; ?></span>
                <span class="bcc-vlb__td bcc-vlb__col--sm <?php echo esc_attr($uptime_class); ?>" data-label="<?php esc_attr_e('Uptime', 'bcc-trust'); ?>" data-sort-value="<?php echo esc_attr((string) ((float) ($v->uptime_30d ?? 0))); ?>"><?php echo ($uptime !== null && $uptime > 0) ? esc_html($uptime . '%') : '--'; ?></span>

                <span class="bcc-vlb__td bcc-vlb__col--action">
                    <?php if ($explorer && $operator): ?>
                    <a href="<?php echo esc_url($explorer . '/validators/' . $operator); ?>" class="bcc-vlb__explorer-link" target="_blank" rel="noopener" title="<?php esc_attr_e('View on explorer', 'bcc-trust'); ?>">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                    </a>
                    <?php endif; ?>
                    <?php if ($show_claim && $user_id && !$claimed): ?>
                    <button class="bcc-entity-block__claim-btn" data-entity-type="validator" data-entity-id="<?php echo esc_attr((string) $vid); ?>">
                        <?php esc_html_e('Claim as Operator', 'bcc-trust'); ?>
                    </button>
                    <?php elseif ($claimed): ?>
                    <span class="bcc-vlb__claimed-label"><?php echo esc_html($claimer->claimer_name ?? __('Claimed', 'bcc-trust')); ?></span>
                    <?php endif; ?>
                </span>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="bcc-vlb__footer">
        <button class="bcc-vlb__load-more" data-loading-text="<?php esc_attr_e('Loading...', 'bcc-trust'); ?>">
            <?php esc_html_e('Load More', 'bcc-trust'); ?>
            <span class="bcc-vlb__load-count">(<?php printf(esc_html__('%1$d of %2$d', 'bcc-trust'), min($per_page, $total), $total); ?>)</span>
        </button>
    </div>
    <?php endif; ?>
</div>
