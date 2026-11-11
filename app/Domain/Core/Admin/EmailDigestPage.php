<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Admin;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\REST\AdminDigestEndpoint;
use BCC\Trust\Core\Services\DigestScheduler;
use BCC\Trust\Core\Services\DigestService;
use BCC\Trust\Core\Support\DigestSettings;
use BCC\Trust\Core\Support\NotificationPrefs;
use BCC\Trust\Onchain\Admin\AdminActionSupport;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * BCC System → Email Digest. Replaces PeepSo's Email Digest add-on (removed
 * 2026-11-11) with controls for BCC's own §I1 weekly digest.
 *
 *   - on/off, weekday + hour (site timezone), lookback window, max items;
 *   - opted-in count, next scheduled run, the last 8 runs;
 *   - preview of the CURRENT ADMIN'S OWN digest (never another member's);
 *   - "send test to me" (the admin's own digest, subject [TEST]);
 *   - "run now" (shares the REST run-now cooldown).
 *
 * Consent: only members with `email_digest = 1` ever receive it; this page
 * cannot opt anyone in. Every action is POST + nonce + manage_options.
 */
final class EmailDigestPage
{
    public const PAGE_SLUG = 'bcc-email-digest';

    private const NONCE_SAVE = 'bcc_digest_save';
    private const NONCE_RUN  = 'bcc_digest_run_now';
    private const NONCE_TEST = 'bcc_digest_send_test';

    private const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'registerMenu']);
        add_action('admin_post_' . self::NONCE_SAVE, [self::class, 'handleSave']);
        add_action('admin_post_' . self::NONCE_RUN, [self::class, 'handleRunNow']);
        add_action('admin_post_' . self::NONCE_TEST, [self::class, 'handleSendTest']);
    }

    public static function registerMenu(): void
    {
        add_submenu_page(
            AdminMenus::parentFor(self::PAGE_SLUG),
            'Email Digest',
            'Email Digest',
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render']
        );
    }

    // ── actions ─────────────────────────────────────────────────────────

    public static function handleSave(): void
    {
        self::guard(self::NONCE_SAVE);

        $before = DigestSettings::get();
        $after  = DigestSettings::save([
            'enabled'     => !empty($_POST['enabled']),
            'weekday'     => isset($_POST['weekday']) ? (int) $_POST['weekday'] : null,
            'hour'        => isset($_POST['hour']) ? (int) $_POST['hour'] : null,
            'window_days' => isset($_POST['window_days']) ? (int) $_POST['window_days'] : null,
            'max_items'   => isset($_POST['max_items']) ? (int) $_POST['max_items'] : null,
        ]);
        DigestScheduler::apply();

        AdminActionSupport::audit('admin_email_digest_settings_saved', 'email_digest', null, [
            'before' => $before,
            'after'  => $after,
        ]);

        self::back('saved');
    }

    public static function handleRunNow(): void
    {
        self::guard(self::NONCE_RUN);

        $last = get_option(AdminDigestEndpoint::COOLDOWN_OPTION, 0);
        $last = is_numeric($last) ? (int) $last : 0;
        if ($last > 0 && time() - $last < AdminDigestEndpoint::RUN_COOLDOWN_SECONDS) {
            self::back('cooldown');
        }
        update_option(AdminDigestEndpoint::COOLDOWN_OPTION, time(), false);

        AdminActionSupport::audit('admin_email_digest_run_now', 'email_digest');
        $sent = Plugin::instance()->digestService()->sendWeeklyDigest(DigestService::TRIGGER_MANUAL);
        self::back('ran', ['sent' => $sent]);
    }

    public static function handleSendTest(): void
    {
        self::guard(self::NONCE_TEST);

        $result = Plugin::instance()->digestService()->sendTestTo(get_current_user_id());
        self::back('test', ['result' => $result]);
    }

    private static function guard(string $nonce): void
    {
        AdminActionSupport::requireCapability();
        AdminActionSupport::requirePost();
        check_admin_referer($nonce);
    }

    /**
     * @param array<string, int|string> $extra
     */
    private static function back(string $notice, array $extra = []): never
    {
        AdminActionSupport::redirect(array_merge(['page' => self::PAGE_SLUG, 'bcc_digest' => $notice], $extra));
    }

    // ── page ────────────────────────────────────────────────────────────

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $s = DigestSettings::get();

        echo '<div class="wrap"><h1>Email Digest</h1>';
        echo '<p>The weekly email of unread BCC notifications. It goes only to members who turned it on '
            . 'in their notification settings, and every email has a one-click unsubscribe. '
            . 'Suspended and banned members are skipped.</p>';

        self::renderNotice();
        self::renderStatus($s);
        self::renderSettings($s);
        self::renderActions();
        self::renderRuns();
        self::renderPreview();

        echo '</div>';
    }

    private static function renderNotice(): void
    {
        $notice = isset($_GET['bcc_digest']) ? sanitize_key((string) $_GET['bcc_digest']) : '';
        $text   = '';
        $type   = 'success';
        switch ($notice) {
            case 'saved':
                $text = 'Settings saved. The schedule was updated.';
                break;
            case 'ran':
                $sent = isset($_GET['sent']) ? (int) $_GET['sent'] : 0;
                $text = sprintf('Digest run finished: %d %s sent. Details are in "Recent runs".', $sent, $sent === 1 ? 'email' : 'emails');
                break;
            case 'cooldown':
                $text = 'The digest was run in the last 5 minutes. Wait a moment before running it again.';
                $type = 'warning';
                break;
            case 'test':
                $result = isset($_GET['result']) ? sanitize_key((string) $_GET['result']) : '';
                $map    = [
                    DigestService::RESULT_SENT     => ['Test sent to your email address.', 'success'],
                    DigestService::RESULT_NO_EMAIL => ['Your account has no email address.', 'error'],
                    DigestService::RESULT_FAILED   => ['The mail system did not accept the test email. Check the site mail settings.', 'error'],
                ];
                [$text, $type] = $map[$result] ?? ['Test finished.', 'info'];
                break;
        }
        if ($text !== '') {
            printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($type), esc_html($text));
        }
    }

    /**
     * @param array{enabled: bool, custom_schedule: bool, weekday: int, hour: int, window_days: int, max_items: int} $s
     */
    private static function renderStatus(array $s): void
    {
        $next     = DigestScheduler::next();
        $nextText = $s['enabled'] ? 'not scheduled' : '—';
        if ($next !== null) {
            $local    = wp_date('l j F Y, H:i T', $next);
            $nextText = is_string($local) ? $local : gmdate('Y-m-d H:i', $next) . ' UTC';
        }

        echo '<h2>Status</h2><table class="widefat striped" style="max-width:720px"><tbody>';
        printf(
            '<tr><th>Digest</th><td>%s</td></tr>',
            $s['enabled'] ? AdminUiCompat::badge('on', AdminUiCompat::OK) : AdminUiCompat::badge('off', AdminUiCompat::MUTED)
        );
        printf('<tr><th>Members opted in</th><td>%d</td></tr>', NotificationPrefs::countEmailDigestOptIns());
        printf('<tr><th>Next run</th><td>%s</td></tr>', esc_html($nextText));
        if (!$s['custom_schedule']) {
            echo '<tr><th>Schedule</th><td>Every 7 days from when it was first set up. Save the settings below to pick a day and time.</td></tr>';
        }
        echo '</tbody></table>';
    }

    /**
     * @param array{enabled: bool, custom_schedule: bool, weekday: int, hour: int, window_days: int, max_items: int} $s
     */
    private static function renderSettings(array $s): void
    {
        echo '<h2>Settings</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::NONCE_SAVE) . '">';
        wp_nonce_field(self::NONCE_SAVE);

        echo '<table class="form-table"><tbody>';

        printf(
            '<tr><th scope="row">Send the digest</th><td><label><input type="checkbox" name="enabled" value="1" %s> On</label></td></tr>',
            checked($s['enabled'], true, false)
        );

        echo '<tr><th scope="row">Day and time</th><td><select name="weekday">';
        foreach (self::WEEKDAYS as $i => $day) {
            printf('<option value="%d" %s>%s</option>', $i, selected($s['weekday'], $i, false), esc_html($day));
        }
        echo '</select> at <select name="hour">';
        for ($h = 0; $h < 24; $h++) {
            printf('<option value="%d" %s>%02d:00</option>', $h, selected($s['hour'], $h, false), $h);
        }
        printf('</select> <span class="description">Site time zone (%s).</span></td></tr>', esc_html(wp_timezone_string()));

        printf(
            '<tr><th scope="row">Look back</th><td><input type="number" name="window_days" min="%d" max="%d" value="%d" class="small-text"> days of unread notifications</td></tr>',
            DigestSettings::WINDOW_MIN,
            DigestSettings::WINDOW_MAX,
            $s['window_days']
        );
        printf(
            '<tr><th scope="row">Most items per email</th><td><input type="number" name="max_items" min="%d" max="%d" value="%d" class="small-text"></td></tr>',
            DigestSettings::ITEMS_MIN,
            DigestSettings::ITEMS_MAX,
            $s['max_items']
        );

        echo '</tbody></table>';
        submit_button('Save settings');
        echo '</form>';
    }

    private static function renderActions(): void
    {
        echo '<h2>Actions</h2><p style="display:flex;gap:8px;flex-wrap:wrap">';
        self::actionButton(self::NONCE_TEST, 'Send a test to me', 'button');
        self::actionButton(
            self::NONCE_RUN,
            'Run the digest now',
            'button button-primary',
            'Send this week\'s digest to every opted-in member now?'
        );
        echo '</p>';
    }

    private static function actionButton(string $action, string $label, string $class, string $confirm = ''): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0"'
            . ($confirm !== '' ? ' onsubmit="return confirm(' . esc_attr(AdminActionSupport::confirmLiteral($confirm)) . ');"' : '')
            . '>';
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        wp_nonce_field($action);
        echo '<button type="submit" class="' . esc_attr($class) . '">' . esc_html($label) . '</button>';
        echo '</form>';
    }

    private static function renderRuns(): void
    {
        $runs = DigestSettings::runs();

        echo '<h2>Recent runs</h2>';
        if ($runs === []) {
            echo '<p>No runs recorded yet.</p>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>'
            . '<th>When</th><th>Trigger</th><th>Opted in</th><th>Sent</th><th>Nothing unread</th>'
            . '<th>Skipped (suspended / banned)</th><th>No email</th><th>Failed</th></tr></thead><tbody>';
        foreach ($runs as $r) {
            $failed = (int) ($r['failed'] ?? 0);
            printf(
                '<tr><td>%s</td><td>%s</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td><td>%s</td></tr>',
                esc_html(AdminUiCompat::relative((int) ($r['started_at'] ?? 0))),
                esc_html((string) ($r['trigger'] ?? '')),
                (int) ($r['opted_in'] ?? 0),
                (int) ($r['sent'] ?? 0),
                (int) ($r['no_unread'] ?? 0),
                (int) ($r['excluded'] ?? 0),
                (int) ($r['no_email'] ?? 0),
                $failed > 0 ? AdminUiCompat::badge((string) $failed, AdminUiCompat::BAD) : '0'
            );
        }
        echo '</tbody></table>';
    }

    private static function renderPreview(): void
    {
        echo '<h2>Preview: your own digest</h2>';
        $preview = Plugin::instance()->digestService()->previewFor(get_current_user_id());
        if ($preview === null) {
            echo '<p>You have no unread notifications in the look-back window, so you would not get a digest this week.</p>';
            return;
        }
        printf('<p><strong>Subject:</strong> %s</p>', esc_html($preview['subject']));
        printf(
            '<pre style="white-space:pre-wrap;max-width:720px;padding:12px;border:1px solid var(--bcc-admin-edge,#ccc);background:var(--bcc-admin-surface,#fff)">%s</pre>',
            esc_html($preview['body'])
        );
    }
}
