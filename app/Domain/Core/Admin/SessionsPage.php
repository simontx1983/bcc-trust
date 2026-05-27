<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Admin;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Support\JwtToken;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sessions / auth-events admin surface — Phase 3 #10.
 *
 * NextAuth uses stateless JWTs (no server-side session store), so a
 * literal "active session count" isn't a meaningful query. What IS
 * meaningful, and what this page exposes:
 *
 *   1. JWT lifetime configuration — TTL_SECONDS + REFRESH_GRACE_SECONDS
 *      from BCC\Trust\Core\Support\JwtToken. Informational only.
 *   2. Recent auth events from the activity log (account_email_changed,
 *      account_password_changed, sessions_revoked_all, account_deleted,
 *      wallet_linked, wallet_unlinked) — the audit-trail of identity-
 *      bearing changes.
 *
 * Companion to Trust Engine → Activity (which is the broader filtered
 * activity view) and to the AccountSecurityMailer side-channel email
 * (which fires on these same actions).
 */
final class SessionsPage
{
    public const PAGE_SLUG = 'bcc-sessions';

    private const AUTH_EVENT_ACTIONS = [
        'account_email_changed',
        'account_password_changed',
        'account_deleted',
        'sessions_revoked_all',
        'wallet_linked',
        'wallet_unlinked',
    ];

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'registerMenu']);
    }

    public static function registerMenu(): void
    {
        add_submenu_page(
            'bcc-system-health',
            'Sessions',
            'Sessions',
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

        echo '<div class="wrap">';
        echo '<h1>BCC Sessions</h1>';
        echo '<p style="color:#666;">NextAuth uses stateless JWTs — no server-side session store, so a literal "active session count" '
            . 'is not a meaningful query. What is meaningful: JWT lifetime configuration + recent identity-bearing audit events.</p>';

        self::renderJwtConfig();
        self::renderRecentAuthEvents();

        echo '</div>';
    }

    // ────────────────────────────────────────────────────────────
    // Section renderers
    // ────────────────────────────────────────────────────────────

    private static function renderJwtConfig(): void
    {
        $ttl     = JwtToken::TTL_SECONDS;
        $grace   = JwtToken::REFRESH_GRACE_SECONDS;
        $totalTtl = $ttl + $grace;

        echo '<h2 style="margin-top:24px;">JWT configuration</h2>';
        echo '<table class="widefat striped" style="max-width:560px;"><tbody>';

        printf(
            '<tr><th style="width:240px;">TTL_SECONDS</th><td><code>%d</code> &nbsp; <span style="color:#888;">(%s)</span></td></tr>',
            $ttl,
            esc_html(self::humanDuration($ttl))
        );
        printf(
            '<tr><th>REFRESH_GRACE_SECONDS</th><td><code>%d</code> &nbsp; <span style="color:#888;">(%s)</span></td></tr>',
            $grace,
            esc_html(self::humanDuration($grace))
        );
        printf(
            '<tr><th>Effective max-age</th><td><code>%d</code> &nbsp; <span style="color:#888;">(%s — TTL + grace)</span></td></tr>',
            $totalTtl,
            esc_html(self::humanDuration($totalTtl))
        );

        echo '</tbody></table>';

        echo '<p style="color:#666;margin-top:8px;">'
            . 'V1 posture: 7-day TTL is by-design (see <code>AuthEndpoint::JWT_TTL_SECONDS</code> docblock). '
            . 'Tightening TTL requires building a refresh-token flow first.</p>';
    }

    private static function renderRecentAuthEvents(): void
    {
        $repo = Plugin::instance()->adminDashboardRepository();
        $rows = $repo->getRecentActivityByActions(self::AUTH_EVENT_ACTIONS, 30);

        echo '<h2 style="margin-top:24px;">Recent auth events (last 30)</h2>';
        echo '<p style="color:#666;">'
            . 'Identity-bearing actions captured by AuditLogger. Each of these triggers an account-security side-channel '
            . 'email (see AccountSecurityMailer); failures emit <code>account_security_mail.*</code> DegradationMetric '
            . 'events on the System → Health page.</p>';

        if ($rows === []) {
            echo '<p>(no auth events in the activity log)</p>';
            return;
        }

        echo '<table class="widefat striped" style="max-width:1100px;">';
        echo '<thead><tr>';
        echo '<th>When</th>';
        echo '<th>Action</th>';
        echo '<th>User</th>';
        echo '<th>IP</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $action   = (string) ($row->action     ?? '');
            $userId   = (int)    ($row->user_id    ?? 0);
            $userName = (string) ($row->user_name  ?? '');
            $ip       = (string) ($row->ip_address ?? '');
            $when     = (string) ($row->created_at ?? '');

            $actionColor = match (true) {
                $action === 'account_deleted',
                $action === 'sessions_revoked_all'    => '#dc3232',
                $action === 'account_password_changed',
                $action === 'account_email_changed'   => '#dba617',
                $action === 'wallet_unlinked'         => '#dba617',
                $action === 'wallet_linked'           => '#46b450',
                default                                => '#2271b1',
            };

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
            echo '<td><code style="font-size:11px;">' . esc_html($ip) . '</code></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private static function humanDuration(int $seconds): string
    {
        if ($seconds < 60)    { return $seconds . 's'; }
        if ($seconds < 3600)  { return round($seconds / 60, 1) . 'm'; }
        if ($seconds < 86400) { return round($seconds / 3600, 1) . 'h'; }
        return round($seconds / 86400, 1) . 'd';
    }
}
