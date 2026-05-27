<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Admin;

use BCC\Trust\Core\Plugin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Wallet audit admin surface — Phase 3 #5.
 *
 * Aggregates the linked-wallet table state + recent wallet activity
 * for incident response. Complementary to the per-user Trust Engine
 * Debug page (which is forensic on a single user); this page is the
 * cross-user view.
 *
 * Renders four sections:
 *   1. Top-line counters — total wallets, unique users with ≥1
 *      wallet, oldest and newest link timestamps.
 *   2. Per-chain breakdown — wallet count + distinct users per chain.
 *   3. Recent wallet events (last 30) — linked / unlinked / verified
 *      sourced from the activity log via the wallet_% action prefix.
 *
 * Read-only. All mutating wallet actions live on the per-user
 * Moderation surface; this page is diagnostics.
 */
final class WalletAuditPage
{
    public const PAGE_SLUG = 'bcc-wallets';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'registerMenu']);
    }

    public static function registerMenu(): void
    {
        add_submenu_page(
            'bcc-system-health',
            'Wallets',
            'Wallets',
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to access this page.'));
        }

        $repo = Plugin::instance()->adminDashboardRepository();

        echo '<div class="wrap">';
        echo '<h1>BCC Wallets</h1>';
        echo '<p style="color:#666;">Cross-user wallet-link audit. For per-user wallet forensics, use Trust Engine → Debug.</p>';

        self::renderCounters($repo->getWalletAuditCounts());
        self::renderByChain($repo->getWalletCountsByChain());
        self::renderRecentEvents($repo->getRecentActivityByPrefix('wallet_%', 30));

        echo '</div>';
    }

    // ────────────────────────────────────────────────────────────
    // Section renderers
    // ────────────────────────────────────────────────────────────

    /** @param array{total:int,unique_users:int,oldest_linked_at:?string,newest_linked_at:?string} $counts */
    private static function renderCounters(array $counts): void
    {
        echo '<h2 style="margin-top:24px;">Summary</h2>';
        echo '<table class="widefat striped" style="max-width:560px;"><tbody>';
        printf(
            '<tr><th style="width:240px;">Total wallet links</th><td>%s</td></tr>',
            esc_html(number_format($counts['total']))
        );
        printf(
            '<tr><th>Unique users with wallets</th><td>%s</td></tr>',
            esc_html(number_format($counts['unique_users']))
        );
        printf(
            '<tr><th>Oldest link</th><td><code>%s</code></td></tr>',
            esc_html($counts['oldest_linked_at'] ?? '—')
        );
        printf(
            '<tr><th>Newest link</th><td><code>%s</code></td></tr>',
            esc_html($counts['newest_linked_at'] ?? '—')
        );
        echo '</tbody></table>';
    }

    /** @param list<object> $rows */
    private static function renderByChain(array $rows): void
    {
        echo '<h2 style="margin-top:24px;">By chain</h2>';

        if ($rows === []) {
            echo '<p>(no chains found)</p>';
            return;
        }

        echo '<table class="widefat striped" style="max-width:760px;">';
        echo '<thead><tr>';
        echo '<th>Chain</th>';
        echo '<th>Type</th>';
        echo '<th>Active?</th>';
        echo '<th style="text-align:right;">Wallets</th>';
        echo '<th style="text-align:right;">Unique users</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $name        = (string) ($row->name        ?? '');
            $slug        = (string) ($row->slug        ?? '');
            $chainType   = (string) ($row->chain_type  ?? '');
            $isActive    = (int)    ($row->is_active   ?? 0);
            $walletCount = (int)    ($row->wallet_count ?? 0);
            $uniqueUsers = (int)    ($row->unique_users ?? 0);

            $activeBadge = $isActive
                ? '<span style="color:#46b450;">✓</span>'
                : '<span style="color:#dba617;">no</span>';

            $countStyle = $walletCount === 0
                ? 'color:#888;'
                : 'font-weight:bold;color:#2271b1;';

            echo '<tr>';
            printf('<td><strong>%s</strong> <code style="font-size:11px;color:#666;">%s</code></td>',
                esc_html($name), esc_html($slug));
            echo '<td><code>' . esc_html($chainType) . '</code></td>';
            echo '<td>' . $activeBadge . '</td>';
            echo '<td style="text-align:right;' . esc_attr($countStyle) . '">' . esc_html((string) $walletCount) . '</td>';
            echo '<td style="text-align:right;">' . esc_html((string) $uniqueUsers) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /** @param list<object> $rows */
    private static function renderRecentEvents(array $rows): void
    {
        echo '<h2 style="margin-top:24px;">Recent wallet events (last 30)</h2>';

        if ($rows === []) {
            echo '<p>(no wallet events in the activity log)</p>';
            return;
        }

        echo '<table class="widefat striped" style="max-width:1100px;">';
        echo '<thead><tr>';
        echo '<th>When</th>';
        echo '<th>Action</th>';
        echo '<th>User</th>';
        echo '<th>Target ID</th>';
        echo '<th>IP</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $action   = (string) ($row->action      ?? '');
            $userId   = (int)    ($row->user_id     ?? 0);
            $userName = (string) ($row->user_name   ?? '');
            $targetId = (int)    ($row->target_id   ?? 0);
            $ip       = (string) ($row->ip_address  ?? '');
            $when     = (string) ($row->created_at  ?? '');

            $actionColor = str_starts_with($action, 'wallet_unlinked') ? '#dba617'
                : (str_starts_with($action, 'wallet_linked') ? '#46b450' : '#2271b1');

            echo '<tr>';
            echo '<td><code style="font-size:12px;">' . esc_html($when) . '</code></td>';
            printf(
                '<td><span style="display:inline-block;padding:2px 8px;background:%1$s;color:#fff;border-radius:3px;font-size:11px;font-weight:bold;">%2$s</span></td>',
                esc_attr($actionColor),
                esc_html($action)
            );
            printf(
                '<td>%s <span style="color:#888;">#%d</span></td>',
                esc_html($userName !== '' ? $userName : '(user removed)'),
                $userId
            );
            echo '<td>' . esc_html($targetId > 0 ? (string) $targetId : '—') . '</td>';
            echo '<td><code style="font-size:11px;">' . esc_html($ip) . '</code></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }
}
