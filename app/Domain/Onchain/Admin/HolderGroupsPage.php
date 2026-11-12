<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Admin;

use BCC\Trust\Core\Admin\AdminUiCompat as AdminUi;
use BCC\Trust\Core\Admin\AdminMenus;

use BCC\Trust\Onchain\OnchainPlugin;
use BCC\Trust\Onchain\Services\NftGroupGateService;
use BCC\Trust\Onchain\Services\NftGroupReconcileSweepService;

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
 *   1. Run sweep now — runs ONE tick of the cron's own
 *      NftGroupReconcileSweepService::sweep() (F-93): the next
 *      BATCH_SIZE opt-in users (USER_META_AUTO_JOIN='1') in the shared
 *      rotation, each through NftGroupGateService::reconcileForUser.
 *      Same lock as the cron, so a manual run beside a cron tick skips
 *      instead of reconciling the same users twice.
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

    private const SWEEP_BATCH_SIZE = NftGroupReconcileSweepService::BATCH_SIZE;
    private const NONCE_SWEEP      = 'bcc_holder_groups_run_sweep';
    private const NONCE_USER       = 'bcc_holder_groups_reconcile_user';

    public static function register_page(): void
    {
        add_submenu_page(
            AdminMenus::ONCHAIN,
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
        // `admin_post_bcc_hall_groups_provision` is deliberately GONE. It ran
        // provisionAll() across every active chain, and it had no POST gate —
        // admin-post.php dispatches from $_REQUEST, so a GET carrying a valid
        // nonce created Halls. Hall creation is now per-chain and explicit on
        // ChainsPage::ACTION_HALL_CREATE.
    }

    // ────────────────────────────────────────────────────────────
    // POST handlers
    // ────────────────────────────────────────────────────────────

    public static function handle_run_sweep(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized.'));
        }
        // admin-post.php dispatches admin_post_{action} from $_REQUEST, and
        // check_admin_referer() reads it from there too — so without this a
        // GET carrying a valid nonce reached the mutation.
        AdminActionSupport::requirePost();
        check_admin_referer(self::NONCE_SWEEP);

        // F-93: the cron's own tick, under its lock. It used to be a
        // separate loop over the FIRST 20 opted-in users (always the same
        // 20), with no lock, so it could run beside a cron tick.
        try {
            $stats = OnchainPlugin::instance()->nftGroupReconcileSweepService()->sweep();
        } catch (\Throwable $e) {
            // A per-user failure is contained inside the tick; this is the
            // batch read (or the lock) failing. Log it and show a notice
            // instead of a fatal admin page. The cursor only moves past
            // users that were processed, so nobody is skipped.
            \BCC\Core\Log\Logger::error('[bcc-trust] Holder-group reconcile sweep (manual) failed', [
                'operator' => get_current_user_id(),
                'error'    => $e->getMessage(),
            ]);
            wp_safe_redirect(add_query_arg([
                'page'   => self::PAGE_SLUG,
                'op'     => 'sweep',
                'failed' => 1,
            ], admin_url('admin.php')));
            exit;
        }

        \BCC\Core\Log\Logger::info('[bcc-trust] Holder-group reconcile sweep (manual)', $stats + [
            'operator' => get_current_user_id(),
        ]);

        wp_safe_redirect(add_query_arg([
            'page'        => self::PAGE_SLUG,
            'op'          => 'sweep',
            'considered'  => $stats['checked'],
            'touched'     => $stats['users_touched'],
            'joined'      => $stats['joins_total'],
            'errored'     => $stats['errored'],
            'overlap'     => $stats['skipped_overlap'] ? 1 : 0,
            'nolock'      => $stats['lock_unavailable'] ? 1 : 0,
        ], admin_url('admin.php')));
        exit;
    }

    public static function handle_reconcile_user(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized.'));
        }
        // admin-post.php dispatches admin_post_{action} from $_REQUEST, and
        // check_admin_referer() reads it from there too — so without this a
        // GET carrying a valid nonce reached the mutation.
        AdminActionSupport::requirePost();
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

        $service = OnchainPlugin::instance()->nftGroupGateService();
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
        self::renderValidatorGroups();
        self::renderHalls();

        echo '</div>';
    }

    private static function renderResultNotices(): void
    {
        $op = isset($_GET['op']) ? sanitize_key((string) $_GET['op']) : '';
        if ($op === '') {
            return;
        }

        if ($op === 'sweep') {
            if (isset($_GET['failed']) && (int) $_GET['failed'] === 1) {
                echo '<div class="notice notice-error is-dismissible"><p>'
                    . '<strong>Sweep failed.</strong> The opt-in user list could not be read, so nothing ran. '
                    . 'The error is in the log. No user was skipped; the next run starts where the last one stopped.</p></div>';
                return;
            }
            if (isset($_GET['nolock']) && (int) $_GET['nolock'] === 1) {
                echo '<div class="notice notice-error is-dismissible"><p>'
                    . '<strong>Sweep did not run.</strong> The sweep lock (bcc-core AdvisoryLock) is unavailable, '
                    . 'and the sweep refuses to run without it. Check that bcc-core is active and up to date; '
                    . 'the error is in the log.</p></div>';
                return;
            }
            if (isset($_GET['overlap']) && (int) $_GET['overlap'] === 1) {
                echo '<div class="notice notice-warning is-dismissible"><p>'
                    . '<strong>Sweep skipped.</strong> Another reconcile sweep was already running, '
                    . 'so this one did nothing. Try again in a minute.</p></div>';
                return;
            }
            $errored    = isset($_GET['errored'])    ? (int) $_GET['errored']    : 0;
            $considered = isset($_GET['considered']) ? (int) $_GET['considered'] : 0;
            $touched    = isset($_GET['touched'])    ? (int) $_GET['touched']    : 0;
            $joined     = isset($_GET['joined'])     ? (int) $_GET['joined']     : 0;

            printf(
                '<div class="notice notice-success is-dismissible"><p>'
                . '<strong>Sweep complete.</strong> Considered %d opt-in user(s), '
                . 'touched %d, joined %d holder-group membership(s), %d failed (logged; retried on the next pass).</p></div>',
                (int) $considered,
                (int) $touched,
                (int) $joined,
                (int) $errored
            );
            return;
        }

        if ($op === 'hall') {
            $created = isset($_GET['created']) ? (int) $_GET['created'] : 0;
            $skipped = isset($_GET['skipped']) ? (int) $_GET['skipped'] : 0;
            $errors  = isset($_GET['errors'])  ? (int) $_GET['errors']  : 0;

            printf(
                '<div class="notice notice-%1$s is-dismissible"><p>'
                . '<strong>Hall provisioning complete.</strong> '
                . 'Created %2$d, skipped %3$d, %4$d error(s).%5$s</p></div>',
                $errors > 0 ? 'warning' : 'success',
                $created,
                $skipped,
                $errors,
                $errors > 0 ? ' See the bcc-trust log for details.' : ''
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
                ? AdminUi::badge('NOT SCHEDULED', AdminUi::BAD)
                : '<code>' . esc_html(gmdate('Y-m-d H:i:s', (int) $nextRun)) . ' UTC</code> ' . AdminUi::badge(AdminUi::relative((int) $nextRun), AdminUi::OK)
        );

        echo '</tbody></table>';

        echo '<p style="color:var(--bcc-text-secondary);margin-top:8px;">'
            . 'The cron runs <strong>twicedaily</strong>, processing up to ' . esc_html((string) self::SWEEP_BATCH_SIZE)
            . ' opt-in users per tick, resuming where the last tick stopped. The manual sweep below runs one of those ticks.</p>';
    }

    private static function renderRunSweep(): void
    {
        echo '<h2 style="margin-top:24px;">Run reconcile sweep now</h2>';
        echo '<p style="color:var(--bcc-text-secondary);">Processes up to ' . esc_html((string) self::SWEEP_BATCH_SIZE)
            . ' opt-in users: the next batch of the cron\'s own rotation. Safe to repeat — already-joined memberships are skipped, and it never runs beside a cron tick.</p>';

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
        echo '<p style="color:var(--bcc-text-secondary);">Useful when a user reports "I hold the NFT but I\'m not in the group." '
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

    private static function renderValidatorGroups(): void
    {
        $groupCount = count(
            \BCC\Trust\Onchain\Repositories\ValidatorGroupRepository::listAllValidatorGroupIds()
        );

        echo '<hr style="margin:32px 0;">';
        echo '<h2>Delegator communities</h2>';
        echo '<p style="color:var(--bcc-text-secondary);">A closed community a validator page\'s <strong>operator chooses to create</strong> '
            . 'from that page, where they also set the minimum delegation. Nothing here or on a schedule creates '
            . 'one. Membership is gated on a <strong>live delegation check</strong>.</p>';

        echo '<table class="widefat striped" style="max-width:560px;"><tbody>';
        printf(
            '<tr><th style="width:280px;">Delegator communities</th><td>%s</td></tr>',
            esc_html(number_format($groupCount))
        );
        echo '</tbody></table>';

    }

    /**
     * Halls are READ-ONLY here.
     *
     * The bulk "Provision Halls now" control was removed when automatic Hall
     * creation was retired: a Hall is an official, administrator-created
     * space, and creating them en masse is the same implicit mass-creation
     * the daily sweep was retired for. Creation is per-chain and explicit,
     * on Chains ▸ Halls. This section only reports the count and points
     * there — it writes nothing.
     */
    private static function renderHalls(): void
    {
        $hallCount = count(
            \BCC\Trust\Onchain\Repositories\HallRepository::listAllHallIds()
        );

        $hallsUrl = add_query_arg(
            ['page' => ChainsPage::PAGE_SLUG, 'subtab' => 'halls'],
            admin_url('admin.php')
        );

        echo '<hr style="margin:32px 0;">';
        echo '<h2>Halls</h2>';
        echo '<p style="color:var(--bcc-text-secondary);">An <strong>open</strong> Hall connected to exactly one '
            . 'chain (e.g. "Cosmos Hall"). Halls are never created automatically — an '
            . 'administrator creates each one explicitly, per chain. A Hall never adds '
            . 'members: joining is a member action gated by HallsService.</p>';

        echo '<table class="widefat striped" style="max-width:560px;"><tbody>';
        printf(
            '<tr><th style="width:280px;">Halls created</th><td>%s</td></tr>',
            esc_html(number_format($hallCount))
        );
        echo '</tbody></table>';

        printf(
            '<p style="margin-top:12px;"><a class="button" href="%s">Manage Halls on Chains</a></p>',
            esc_url($hallsUrl)
        );
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
}
