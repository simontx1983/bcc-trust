<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Admin;

use BCC\Trust\Onchain\REST\HeliusWebhookEndpoint;
use BCC\Trust\Onchain\Services\HeliusDeliveryLog;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * wp-admin renderer for the Helius webhook surface.
 *
 * Operator OS v1 Phase 2 item #2. Lives under "BCC System → Webhooks"
 * (parent menu owned by bcc-core's SystemHealthPage). Renders:
 *
 *   - Config: callback URL, secret presence, last-delivery freshness.
 *   - Lifetime counters (new_total / seen_total / sigfail_total).
 *   - DegradationMetric snapshot for the `helius_dedup` subsystem.
 *   - Recent 50 delivery rows from HeliusDeliveryLog (per-row outcome,
 *     auth-pass, tx count, events, replays, source IP, age).
 *   - "Clear log" action (POST → admin-post.php with nonce).
 *
 * All reads are wp_options + transient lookups — no DB queries. Render
 * is one full page reload; no AJAX needed at this size.
 */
final class WebhooksPage
{
    public const PAGE_SLUG  = 'bcc-onchain-webhooks';
    private const NONCE_KEY = 'bcc_webhooks_clear';

    public static function register_page(): void
    {
        add_submenu_page(
            'bcc-system-health',
            'Webhooks',
            'Webhooks',
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render_page']
        );
    }

    public static function register_actions(): void
    {
        add_action('admin_post_bcc_helius_clear_delivery_log', [self::class, 'handle_clear']);
    }

    public static function handle_clear(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized.'));
        }
        check_admin_referer(self::NONCE_KEY);

        HeliusDeliveryLog::clear();

        \BCC\Core\Log\Logger::info('[bcc-trust] Helius delivery log cleared (manual)', [
            'operator' => get_current_user_id(),
        ]);

        wp_safe_redirect(add_query_arg(
            ['page' => self::PAGE_SLUG, 'cleared' => '1'],
            admin_url('admin.php')
        ));
        exit;
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to access this page.'));
        }

        echo '<div class="wrap">';
        echo '<h1>Webhooks</h1>';

        if (isset($_GET['cleared'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Delivery log cleared.</p></div>';
        }

        self::renderHeliusConfig();
        self::renderCounters();
        self::renderRecentDeliveries();

        echo '</div>';
    }

    // ────────────────────────────────────────────────────────────
    // Section renderers
    // ────────────────────────────────────────────────────────────

    private static function renderHeliusConfig(): void
    {
        $callbackUrl    = HeliusWebhookEndpoint::callbackUrl();
        $secretPresent  = defined('BCC_HELIUS_WEBHOOK_SECRET')
            && (string) constant('BCC_HELIUS_WEBHOOK_SECRET') !== '';
        $lastDeliveryTs = (int) get_option(HeliusWebhookEndpoint::OPTION_LAST_DELIVERY_AT, 0);

        echo '<h2 style="margin-top:24px;">Helius</h2>';
        echo '<table class="widefat striped" style="max-width:760px;"><tbody>';

        echo '<tr><th style="width:240px;">Callback URL</th><td><code>'
            . esc_html($callbackUrl) . '</code></td></tr>';

        echo '<tr><th>Webhook secret</th><td>'
            . ($secretPresent
                ? self::badge('configured', '#46b450')
                : self::badge('NOT CONFIGURED', '#dc3232')
                  . ' <span style="color:#888;font-size:12px;">— define <code>BCC_HELIUS_WEBHOOK_SECRET</code> in wp-config.php</span>')
            . '</td></tr>';

        echo '<tr><th>Last delivery</th><td>'
            . self::formatLastDelivery($lastDeliveryTs)
            . '</td></tr>';

        echo '</tbody></table>';
    }

    private static function renderCounters(): void
    {
        $new     = (int) get_option('bcc_helius_signature_new_total', 0);
        $seen    = (int) get_option('bcc_helius_signature_seen_total', 0);
        $sigfail = (int) get_option('bcc_helius_signature_sigfail_total', 0);

        echo '<h2 style="margin-top:24px;">Lifetime counters</h2>';
        echo '<table class="widefat striped" style="max-width:560px;"><tbody>';
        printf(
            '<tr><th style="width:240px;">New events processed</th><td>%s</td></tr>',
            esc_html(number_format($new))
        );
        printf(
            '<tr><th>Replays blocked (dedupe)</th><td>%s</td></tr>',
            esc_html(number_format($seen))
        );
        printf(
            '<tr><th>Signature mismatches</th><td>%s%s</td></tr>',
            esc_html(number_format($sigfail)),
            $sigfail > 0
                ? ' <span style="color:#dba617;font-size:12px;">— probes detected</span>'
                : ''
        );
        echo '</tbody></table>';
    }

    private static function renderRecentDeliveries(): void
    {
        $rows = HeliusDeliveryLog::recent(50);

        echo '<h2 style="margin-top:24px;">Recent deliveries (last 50 of '
            . esc_html((string) HeliusDeliveryLog::MAX_ENTRIES) . ')</h2>';

        if ($rows === []) {
            echo '<p style="color:#666;">No deliveries logged yet.</p>';
            self::renderClearButton(false);
            return;
        }

        echo '<table class="widefat striped" style="max-width:1000px;">';
        echo '<thead><tr>';
        echo '<th>When</th>';
        echo '<th>Outcome</th>';
        echo '<th>Auth</th>';
        echo '<th style="text-align:right;">Txs</th>';
        echo '<th style="text-align:right;">Events</th>';
        echo '<th style="text-align:right;">Replays</th>';
        echo '<th>Source IP</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $r) {
            $when    = (int)    ($r['received_at'] ?? 0);
            $outcome = (string) ($r['outcome']     ?? 'unknown');
            $auth    = (bool)   ($r['auth_passed'] ?? false);
            $txs     = (int)    ($r['tx_count']    ?? 0);
            $events  = (int)    ($r['events']      ?? 0);
            $replays = (int)    ($r['replays']     ?? 0);
            $ip      = (string) ($r['ip']          ?? '');

            $rowBg = $outcome === HeliusDeliveryLog::OUTCOME_AUTH_FAILED ? 'background:#fcf0f1;' : '';

            echo '<tr style="' . esc_attr($rowBg) . '">';
            printf(
                '<td><code style="font-size:12px;">%s</code> <span style="color:#888;font-size:11px;">(%s)</span></td>',
                esc_html(gmdate('Y-m-d H:i:s', $when)),
                esc_html(self::ago($when))
            );
            echo '<td>' . self::outcomeBadge($outcome) . '</td>';
            echo '<td>' . ($auth
                ? self::badge('✓', '#46b450')
                : self::badge('✗', '#dc3232'))
                . '</td>';
            echo '<td style="text-align:right;">' . esc_html((string) $txs) . '</td>';
            echo '<td style="text-align:right;' . ($events > 0 ? 'font-weight:bold;color:#46b450;' : 'color:#888;') . '">'
                . esc_html((string) $events) . '</td>';
            echo '<td style="text-align:right;' . ($replays > 0 ? 'color:#dba617;' : 'color:#888;') . '">'
                . esc_html((string) $replays) . '</td>';
            echo '<td><code style="font-size:12px;">' . esc_html($ip) . '</code></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        self::renderClearButton(true);
    }

    private static function renderClearButton(bool $haveRows): void
    {
        $disabledAttr = $haveRows ? '' : ' disabled';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:16px;">';
        echo '<input type="hidden" name="action" value="bcc_helius_clear_delivery_log">';
        wp_nonce_field(self::NONCE_KEY);
        echo '<button type="submit" class="button"' . $disabledAttr
            . ' onclick="return confirm(\'Clear the delivery log? Counters and DegradationMetrics are NOT affected.\');">'
            . 'Clear delivery log</button>';
        echo '</form>';
    }

    // ────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────

    private static function formatLastDelivery(int $ts): string
    {
        if ($ts <= 0) {
            return self::badge('never', '#dba617');
        }

        $ageSec = time() - $ts;

        $color = '#46b450';
        if ($ageSec > 14400) {        // > 4h → red (matches X5 freshness signal)
            $color = '#dc3232';
        } elseif ($ageSec > 3600) {   // > 1h → amber
            $color = '#dba617';
        }

        return sprintf(
            '<code style="font-size:12px;">%s UTC</code> %s',
            esc_html(gmdate('Y-m-d H:i:s', $ts)),
            self::badge(self::ago($ts), $color)
        );
    }

    private static function outcomeBadge(string $outcome): string
    {
        return match ($outcome) {
            HeliusDeliveryLog::OUTCOME_PROCESSED   => self::badge('processed',   '#46b450'),
            HeliusDeliveryLog::OUTCOME_AUTH_FAILED => self::badge('auth_failed', '#dc3232'),
            HeliusDeliveryLog::OUTCOME_NO_PAYLOAD  => self::badge('no_payload',  '#dba617'),
            HeliusDeliveryLog::OUTCOME_NO_CHAIN    => self::badge('no_chain',    '#dba617'),
            default                                => self::badge($outcome,     '#888'),
        };
    }

    private static function badge(string $text, string $color): string
    {
        return sprintf(
            '<span style="display:inline-block;padding:2px 10px;background:%1$s;color:#fff;border-radius:3px;font-weight:bold;font-size:12px;letter-spacing:0.5px;">%2$s</span>',
            esc_attr($color),
            esc_html($text)
        );
    }

    private static function ago(int $ts): string
    {
        if ($ts <= 0) {
            return 'never';
        }
        $sec = max(0, time() - $ts);
        if ($sec < 60)     { return $sec . 's ago'; }
        if ($sec < 3600)   { return floor($sec / 60) . 'm ago'; }
        if ($sec < 86400)  { return floor($sec / 3600) . 'h ago'; }
        return floor($sec / 86400) . 'd ago';
    }
}
