<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Admin;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Onchain\Services\NftGroupGateService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * wp-admin manual-trigger for the holder-group reconcile sweep.
 *
 * Operator OS v1 Phase 2 item #5. Closes the diagnostic gap for the
 * `bcc_gated_group_reconcile_sweep` twicedaily cron: there is a manual
 * "Provision Now" on VerifyCollectionsPage for the daily provision
 * sweep, but no equivalent for reconcile (which actually moves
 * memberships, not group creation).
 *
 * Two operations:
 *   1. Run sweep now — mimics the cron handler at bcc-trust.php:533:
 *      iterates up to 20 opt-in users (USER_META_AUTO_JOIN='1'),
 *      ID-ASC ordering, calls NftGroupGateService::reconcileForUser
 *      on each. Same opt-in respect as the cron.
 *   2. Reconcile specific user — input field for user ID, runs
 *      reconcileForUser regardless of opt-in (which the service
 *      itself enforces and returns zeros for opted-out users).
 *
 * PeepSo write-surface posture (peepso-write-guard skill):
 *   - This page introduces NO new PeepSoGroupWriter::join call sites.
 *   - It calls NftGroupGateService::reconcileForUser, the same entry
 *     point the cron uses. The service self-enforces the
 *     autoJoinEnabled() gate (returns zeros for opted-out users),
 *     uses findEligibleGroups (NFT-holder filter), and excludes
 *     already-joined members.
 *   - Per-join AuditLogger row is written by the service with
 *     `via=reconcile` (works the same whether triggered by cron or
 *     by this admin page).
 *
 * Render-only state surface (no persistent admin storage). Run
 * results display inline on the redirect target via URL query
 * params; if you need a persistent sweep journal, that's Phase 3
 * scope.
 */
final class HolderGroupsPage
{
    public const PAGE_SLUG = 'bcc-holder-groups';

    private const SWEEP_BATCH_SIZE = 20;
    private const NONCE_SWEEP      = 'bcc_holder_groups_run_sweep';
    private const NONCE_USER       = 'bcc_holder_groups_reconcile_user';

    public static function register_page(): void
    {
        add_submenu_page(
            'bcc-system-health',
            'Holder Groups',
            'Holder Groups',
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render_page']
        );
    }

    public static function register_actions(): void
    {
        add_action('admin_post_bcc_holder_groups_run_sweep',       [self::class, 'handle_run_sweep']);
        add_action('admin_post_bcc_holder_groups_reconcile_user',  [self::class, 'handle_reconcile_user']);
    }

    // ────────────────────────────────────────────────────────────
    // POST handlers
    // ────────────────────────────────────────────────────────────

    public static function handle_run_sweep(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized.'));
        }
        check_admin_referer(self::NONCE_SWEEP);

        $userIds = get_users([
            'meta_key'   => NftGroupGateService::USER_META_AUTO_JOIN,
            'meta_value' => '1',
            'fields'     => 'ID',
            'number'     => self::SWEEP_BATCH_SIZE,
            'orderby'    => 'ID',
            'order'      => 'ASC',
        ]);

        $service       = Plugin::instance()->nftGroupGateService();
        $totalJoined   = 0;
        $usersTouched  = 0;
        $usersConsidered = is_array($userIds) ? count($userIds) : 0;

        if (is_array($userIds)) {
            foreach ($userIds as $uid) {
                $r = $service->reconcileForUser((int) $uid);
                if (($r['joined'] ?? 0) > 0) {
                    $totalJoined += (int) $r['joined'];
                    $usersTouched++;
                }
            }
        }

        \BCC\Core\Log\Logger::info('[bcc-trust] Holder-group reconcile sweep (manual)', [
            'considered' => $usersConsidered,
            'touched'    => $usersTouched,
            'joined'     => $totalJoined,
            'operator'   => get_current_user_id(),
        ]);

        wp_safe_redirect(add_query_arg([
            'page'        => self::PAGE_SLUG,
            'op'          => 'sweep',
            'considered'  => $usersConsidered,
            'touched'     => $usersTouched,
            'joined'      => $totalJoined,
        ], admin_url('admin.php')));
        exit;
    }

    public static function handle_reconcile_user(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized.'));
        }
        check_admin_referer(self::NONCE_USER);

        $userId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;

        $args = [
            'page'    => self::PAGE_SLUG,
            'op'      => 'user',
            'user_id' => $userId,
        ];

        if ($userId <= 0) {
            $args['result'] = 'invalid_id';
            wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
            exit;
        }

        $user = get_userdata($userId);
        if (!$user) {
            $args['result'] = 'not_found';
            wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
            exit;
        }

        $service = Plugin::instance()->nftGroupGateService();
        $optIn   = $service->autoJoinEnabled($userId);

        // Service itself returns zeros if opt-in is off, but call it
        // either way so the UI can distinguish "opt-out → no action"
        // from "opt-in but already up-to-date."
        $r = $service->reconcileForUser($userId);

        \BCC\Core\Log\Logger::info('[bcc-trust] Holder-group reconcile (manual single-user)', [
            'user_id'  => $userId,
            'opt_in'   => $optIn,
            'joined'   => (int) ($r['joined'] ?? 0),
            'skipped'  => (int) ($r['skipped'] ?? 0),
            'operator' => get_current_user_id(),
        ]);

        $args['opt_in'] = $optIn ? '1' : '0';
        $args['joined'] = (int) ($r['joined']  ?? 0);
        $args['skipped'] = (int) ($r['skipped'] ?? 0);
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    // ────────────────────────────────────────────────────────────
    // Render
    // ────────────────────────────────────────────────────────────

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to access this page.'));
        }

        echo '<div class="wrap">';
        echo '<h1>Holder Groups</h1>';

        self::renderResultNotices();
        self::renderState();
        self::renderRunSweep();
        self::renderReconcileUser();

        echo '</div>';
    }

    private static function renderResultNotices(): void
    {
        $op = isset($_GET['op']) ? sanitize_key((string) $_GET['op']) : '';
        if ($op === '') {
            return;
        }

        if ($op === 'sweep') {
            $considered = isset($_GET['considered']) ? (int) $_GET['considered'] : 0;
            $touched    = isset($_GET['touched'])    ? (int) $_GET['touched']    : 0;
            $joined     = isset($_GET['joined'])     ? (int) $_GET['joined']     : 0;

            printf(
                '<div class="notice notice-success is-dismissible"><p>'
                . '<strong>Sweep complete.</strong> Considered %d opt-in user(s), '
                . 'touched %d, joined %d holder-group membership(s).</p></div>',
                (int) $considered,
                (int) $touched,
                (int) $joined
            );
            return;
        }

        if ($op === 'user') {
            $result = isset($_GET['result']) ? sanitize_key((string) $_GET['result']) : '';
            $userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;

            if ($result === 'invalid_id') {
                echo '<div class="notice notice-error is-dismissible"><p>Invalid user ID — must be a positive integer.</p></div>';
                return;
            }
            if ($result === 'not_found') {
                printf(
                    '<div class="notice notice-error is-dismissible"><p>No WordPress user found with ID <code>%d</code>.</p></div>',
                    (int) $userId
                );
                return;
            }

            $optIn  = isset($_GET['opt_in']) && $_GET['opt_in'] === '1';
            $joined = isset($_GET['joined'])  ? (int) $_GET['joined']  : 0;

            if (!$optIn) {
                printf(
                    '<div class="notice notice-warning is-dismissible"><p>'
                    . '<strong>User #%d</strong> has NOT opted into auto-join. No action taken. '
                    . 'The service returns zeros for opted-out users — this is by design.</p></div>',
                    (int) $userId
                );
                return;
            }

            if ($joined === 0) {
                printf(
                    '<div class="notice notice-success is-dismissible"><p>'
                    . '<strong>User #%d</strong> reconciled. Already up-to-date — no new memberships needed.</p></div>',
                    (int) $userId
                );
                return;
            }

            printf(
                '<div class="notice notice-success is-dismissible"><p>'
                . '<strong>User #%d</strong> reconciled. Joined %d new holder-group membership(s).</p></div>',
                (int) $userId,
                (int) $joined
            );
        }
    }

    private static function renderState(): void
    {
        $optInCount = (int) self::countOptInUsers();
        $nextRun    = wp_next_scheduled('bcc_gated_group_reconcile_sweep');

        echo '<h2 style="margin-top:24px;">State</h2>';
        echo '<table class="widefat striped" style="max-width:560px;"><tbody>';

        printf(
            '<tr><th style="width:280px;">Opt-in users (USER_META_AUTO_JOIN=1)</th><td>%s</td></tr>',
            esc_html(number_format($optInCount))
        );

        printf(
            '<tr><th>Cron <code>bcc_gated_group_reconcile_sweep</code></th><td>%s</td></tr>',
            $nextRun === false
                ? self::badge('NOT SCHEDULED', '#dc3232')
                : '<code>' . esc_html(gmdate('Y-m-d H:i:s', (int) $nextRun)) . ' UTC</code> ' . self::badge(self::relative((int) $nextRun), '#46b450')
        );

        echo '</tbody></table>';

        echo '<p style="color:#666;margin-top:8px;">'
            . 'The cron runs <strong>twicedaily</strong>, processing up to ' . esc_html((string) self::SWEEP_BATCH_SIZE)
            . ' opt-in users per tick (ID-ASC ordering). Manual triggers below mirror that bounded behavior.</p>';
    }

    private static function renderRunSweep(): void
    {
        echo '<h2 style="margin-top:24px;">Run reconcile sweep now</h2>';
        echo '<p style="color:#666;">Processes up to ' . esc_html((string) self::SWEEP_BATCH_SIZE)
            . ' opt-in users in one batch (same logic as the cron tick). Safe to repeat — already-joined memberships are skipped.</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px;">';
        echo '<input type="hidden" name="action" value="bcc_holder_groups_run_sweep">';
        wp_nonce_field(self::NONCE_SWEEP);
        echo '<button type="submit" class="button button-primary" '
            . 'onclick="return confirm(\'Run the reconcile sweep now? This may fire PeepSo group-join writes for opt-in users who match newly-acquired NFTs.\');">'
            . 'Run sweep</button>';
        echo '</form>';
    }

    private static function renderReconcileUser(): void
    {
        echo '<h2 style="margin-top:32px;">Reconcile specific user</h2>';
        echo '<p style="color:#666;">Useful when a user reports "I hold the NFT but I\'m not in the group." '
            . 'Service self-enforces opt-in — for opted-out users the call is a no-op.</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px;">';
        echo '<input type="hidden" name="action" value="bcc_holder_groups_reconcile_user">';
        wp_nonce_field(self::NONCE_USER);
        echo '<label style="display:inline-block;margin-right:8px;">'
            . 'User ID '
            . '<input type="number" min="1" step="1" name="user_id" required style="width:120px;">'
            . '</label>';
        echo '<button type="submit" class="button">Reconcile</button>';
        echo '</form>';
    }

    // ────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────

    private static function countOptInUsers(): int
    {
        // WP_User_Query keeps this admin-side count off the raw-$wpdb
        // surface (§1 — Repository-only DB access). `number=1` caps the
        // returned rows; `count_total=true` still walks the full match
        // set via WP's prepared COUNT(*) query.
        $query = new \WP_User_Query([
            'meta_key'    => NftGroupGateService::USER_META_AUTO_JOIN,
            'meta_value'  => '1',
            'fields'      => 'ID',
            'number'      => 1,
            'count_total' => true,
        ]);
        return (int) $query->get_total();
    }

    private static function relative(int $ts): string
    {
        $delta = $ts - time();
        $abs   = abs($delta);
        if ($abs < 60)    { $val = $abs . 's'; }
        elseif ($abs < 3600) { $val = floor($abs / 60) . 'm'; }
        elseif ($abs < 86400){ $val = floor($abs / 3600) . 'h'; }
        else                 { $val = floor($abs / 86400) . 'd'; }
        return $delta >= 0 ? 'in ' . $val : $val . ' ago';
    }

    private static function badge(string $text, string $color): string
    {
        return sprintf(
            '<span style="display:inline-block;padding:2px 10px;background:%1$s;color:#fff;border-radius:3px;font-weight:bold;font-size:12px;letter-spacing:0.5px;">%2$s</span>',
            esc_attr($color),
            esc_html($text)
        );
    }
}
