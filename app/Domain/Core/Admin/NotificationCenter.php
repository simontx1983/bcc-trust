<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Admin;

use BCC\Trust\Disputes\Services\DisputeScheduler;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Notification Center — aggregates operational/health notices into a
 * single admin surface instead of scattering N banners across every
 * wp-admin page.
 *
 * Two-engineer audit follow-up: both engineers were seeing 4 separate
 * red banners on every plugin-list / dashboard / settings page load
 * (panelist pool low, chain data stale, dispute adjudication delays,
 * permanent orphans). Each was operationally useful but the per-page
 * stacking was banner-fatigue territory.
 *
 * Design:
 *   - Each notification source contributes ?NotificationItem via the
 *     `bcc_admin_notifications` filter. Returning null = nothing to
 *     show. Returning an array = item rendered on the center page.
 *   - admin_notices renders a SINGLE consolidated summary banner
 *     ("BCC has N notification(s) — View") instead of the N
 *     individual ones.
 *   - Critical setup banners (BCC_ENCRYPTION_KEY missing, composer
 *     install required, DB constraint failures) intentionally stay
 *     as direct banners — they block work, they should be loud.
 *
 * NotificationItem shape:
 *   [
 *       'id'      => 'disputes.panelist_pool_low',  // stable identifier
 *       'source'  => 'BCC Trust (Disputes)',        // human label
 *       'level'   => 'error' | 'warning' | 'info',
 *       'message' => '<HTML allowed via wp_kses_post>',
 *       'link'    => '?optional action URL',
 *   ]
 *
 * @phpstan-type NotificationItem array{
 *     id: string,
 *     source: string,
 *     level: string,
 *     message: string,
 *     link?: string,
 * }
 */
final class NotificationCenter
{
    public const PAGE_SLUG = 'bcc-notifications';

    private const LEVEL_RANK = ['error' => 0, 'warning' => 1, 'info' => 2];

    public static function register(): void
    {
        add_action('admin_menu',     [self::class, 'registerMenu']);
        add_action('admin_notices',  [self::class, 'renderSummaryBanner']);
        add_filter('bcc_admin_notifications', [self::class, 'contributeBuiltins']);
    }

    public static function registerMenu(): void
    {
        add_submenu_page(
            'bcc-system-health',
            'Notifications',
            'Notifications',
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render']
        );
    }

    /**
     * Aggregate all notifications, sorted by severity (error first).
     *
     * @return list<NotificationItem>
     */
    public static function aggregate(): array
    {
        $items = apply_filters('bcc_admin_notifications', []);
        if (!is_array($items)) {
            return [];
        }

        $valid = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id      = (string) ($item['id']      ?? '');
            $source  = (string) ($item['source']  ?? '');
            $level   = (string) ($item['level']   ?? 'info');
            $message = (string) ($item['message'] ?? '');
            if ($id === '' || $source === '' || $message === '') {
                continue;
            }
            if (!isset(self::LEVEL_RANK[$level])) {
                $level = 'info';
            }
            $normalized = [
                'id'      => $id,
                'source'  => $source,
                'level'   => $level,
                'message' => $message,
            ];
            if (!empty($item['link']) && is_string($item['link'])) {
                $normalized['link'] = $item['link'];
            }
            $valid[] = $normalized;
        }

        usort($valid, function (array $a, array $b): int {
            $la = self::LEVEL_RANK[$a['level']];
            $lb = self::LEVEL_RANK[$b['level']];
            return $la === $lb ? strcmp($a['source'], $b['source']) : $la <=> $lb;
        });

        return $valid;
    }

    // ────────────────────────────────────────────────────────────
    // Builtins — checks for the 4 noisiest existing emitters
    // ────────────────────────────────────────────────────────────

    /**
     * @param list<NotificationItem> $items
     * @return list<NotificationItem>
     */
    public static function contributeBuiltins(array $items): array
    {
        if ($item = self::checkPanelistPool()) {
            $items[] = $item;
        }
        if ($item = self::checkStaleChains()) {
            $items[] = $item;
        }
        if ($item = DisputeScheduler::adjudicationDownNotification()) {
            $items[] = $item;
        }
        if ($item = DisputeScheduler::permanentOrphansNotification()) {
            $items[] = $item;
        }
        return $items;
    }

    /**
     * @return NotificationItem|null
     */
    public static function checkPanelistPool(): ?array
    {
        $cacheKey   = 'bcc_disputes_panelist_pool_count';
        $cacheGroup = 'bcc_disputes';
        $poolCount  = wp_cache_get($cacheKey, $cacheGroup);

        if ($poolCount === false) {
            if (!class_exists('\\BCC\\Core\\ServiceLocator')
                || !\BCC\Core\ServiceLocator::hasRealService(\BCC\Core\Contracts\TrustReadServiceInterface::class)
            ) {
                return null;
            }

            $trustRead = \BCC\Core\ServiceLocator::resolveTrustReadService();
            $eligible  = $trustRead->getEligiblePanelistUserIds([], BCC_DISPUTES_PANEL_SIZE * 3);
            $poolCount = count($eligible);
            wp_cache_set($cacheKey, $poolCount, $cacheGroup, HOUR_IN_SECONDS);
        }

        $minimumHealthy = BCC_DISPUTES_PANEL_SIZE * 2;
        if ((int) $poolCount >= $minimumHealthy) {
            return null;
        }

        $level = (int) $poolCount < BCC_DISPUTES_PANEL_SIZE ? 'error' : 'warning';

        return [
            'id'      => 'disputes.panelist_pool_low',
            'source'  => 'BCC Trust (Disputes)',
            'level'   => $level,
            'message' => sprintf(
                'The eligible panelist pool is critically low — only <strong>%d</strong> qualified members found. '
                . 'At least <strong>%d</strong> are needed per dispute (and %d recommended for proper randomization). '
                . 'Disputes cannot be filed until enough Trusted/Elite tier members with clean records are available.',
                (int) $poolCount,
                BCC_DISPUTES_PANEL_SIZE,
                $minimumHealthy
            ),
        ];
    }

    /**
     * @return NotificationItem|null
     */
    public static function checkStaleChains(): ?array
    {
        if (!class_exists('\\BCC\\Trust\\Onchain\\Repositories\\ChainRepository')
            || !class_exists('\\BCC\\Trust\\Onchain\\Support\\OnchainCircuitBreaker')
        ) {
            return null;
        }

        $activeChains = \BCC\Trust\Onchain\Repositories\ChainRepository::getActive();
        $chainIds     = array_map(static fn($c) => (int) $c->id, $activeChains);
        $chainNames   = [];
        foreach ($activeChains as $c) {
            $chainNames[(int) $c->id] = $c->name;
        }

        $staleChains = \BCC\Trust\Onchain\Support\OnchainCircuitBreaker::getStaleChains($chainIds);
        if (empty($staleChains)) {
            return null;
        }

        $parts = [];
        foreach ($staleChains as $id => $info) {
            $name   = $chainNames[$id] ?? "Chain #{$id}";
            $detail = esc_html((string) $info['age_human']);
            if ($info['circuit_status'] !== 'CLOSED') {
                $detail .= ', circuit: ' . esc_html((string) $info['circuit_status']);
            }
            $parts[] = sprintf('%s (%s)', esc_html($name), $detail);
        }

        return [
            'id'      => 'onchain.stale_chains',
            'source'  => 'BCC Trust (Onchain)',
            'level'   => 'warning',
            'message' => 'Chain data is stale for: ' . implode(', ', $parts)
                . '. Trust scores may be understated.',
        ];
    }

    // ────────────────────────────────────────────────────────────
    // Rendering
    // ────────────────────────────────────────────────────────────

    /**
     * Single consolidated summary banner shown on every wp-admin page
     * when there are any notifications. Replaces the N individual
     * banners that previously stacked.
     */
    public static function renderSummaryBanner(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $items = self::aggregate();
        if ($items === []) {
            return;
        }

        // Don't render the summary ON the notifications page itself —
        // it'd be redundant noise.
        if (isset($_GET['page']) && $_GET['page'] === self::PAGE_SLUG) {
            return;
        }

        $counts = ['error' => 0, 'warning' => 0, 'info' => 0];
        foreach ($items as $it) {
            $counts[$it['level']]++;
        }

        $bannerLevel = $counts['error']   > 0 ? 'error'
                     : ($counts['warning'] > 0 ? 'warning' : 'info');

        printf(
            '<div class="notice notice-%1$s"><p>'
            . '<strong>BCC has %2$d notification(s):</strong> '
            . '%3$d error · %4$d warning · %5$d info. '
            . '<a href="%6$s">View Notification Center &rarr;</a>'
            . '</p></div>',
            esc_attr($bannerLevel),
            count($items),
            $counts['error'],
            $counts['warning'],
            $counts['info'],
            esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG))
        );
    }

    /**
     * Notification Center page render — groups items by level.
     */
    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to access this page.'));
        }

        $items = self::aggregate();

        echo '<div class="wrap">';
        echo '<h1>BCC Notifications</h1>';
        echo '<p style="color:#666;">Operational health notices from the BCC plugins. '
            . 'Critical setup failures (missing encryption key, missing dependencies) '
            . 'still render as direct banners — they block work and should be loud. '
            . 'Recurring health signals collect here.</p>';

        if ($items === []) {
            echo '<div class="notice notice-success inline" style="margin-top:16px;"><p>'
                . '<strong>All clear.</strong> No active notifications.'
                . '</p></div>';
            echo '</div>';
            return;
        }

        $labels = ['error' => 'Errors', 'warning' => 'Warnings', 'info' => 'Info'];

        foreach ($labels as $level => $label) {
            $list = [];
            foreach ($items as $it) {
                if ($it['level'] === $level) {
                    $list[] = $it;
                }
            }
            if ($list === []) {
                continue;
            }

            printf(
                '<h2 style="margin-top:24px;border-top:1px solid #c3c4c7;padding-top:16px;">%s (%d)</h2>',
                esc_html($label),
                count($list)
            );

            foreach ($list as $it) {
                $linkHtml = !empty($it['link'])
                    ? ' &middot; <a href="' . esc_url((string) $it['link']) . '">View &rarr;</a>'
                    : '';

                printf(
                    '<div class="notice notice-%1$s" style="margin:8px 0;padding:12px;">'
                    . '<p style="margin:0 0 4px 0;"><strong>%2$s</strong> '
                    . '<code style="font-size:11px;color:#666;">%3$s</code></p>'
                    . '<p style="margin:0;">%4$s%5$s</p>'
                    . '</div>',
                    esc_attr($level),
                    esc_html($it['source']),
                    esc_html($it['id']),
                    wp_kses_post($it['message']),
                    $linkHtml
                );
            }
        }

        echo '</div>';
    }
}
