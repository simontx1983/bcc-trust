<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Admin;

use BCC\Trust\Core\Services\PeepSoLeftoversService;
use BCC\Trust\Onchain\Admin\AdminActionSupport;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One-off cleanup of data left by removed add-ons (PeepSo Friends, Email
 * Digest, Social Login, User Limits; Nextend Social Login).
 *
 * Hidden page — reachable at admin.php?page=bcc-peepso-leftovers, not in the
 * menu. The page itself IS the dry run: it lists, per add-on, every table
 * (with row count), config key, option and usermeta key that a run would
 * remove. A run is per add-on, POST + nonce + manage_options, and needs the
 * add-on's id typed into a confirm box. Every removed item is audited.
 * Dropping a table cannot be undone — take a backup first.
 */
final class PeepSoLeftoversPage
{
    public const PAGE_SLUG = 'bcc-peepso-leftovers';

    private const ACTION = 'bcc_peepso_leftovers_run';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'registerMenu']);
        add_action('admin_post_' . self::ACTION, [self::class, 'handleRun']);
    }

    public static function registerMenu(): void
    {
        add_submenu_page(
            AdminMenus::parentFor(self::PAGE_SLUG),
            'PeepSo leftovers',
            'PeepSo leftovers',
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render']
        );
    }

    public static function handleRun(): void
    {
        AdminActionSupport::requireCapability();
        AdminActionSupport::requirePost();
        check_admin_referer(self::ACTION);

        $group   = isset($_POST['group']) ? sanitize_key((string) wp_unslash($_POST['group'])) : '';
        $confirm = isset($_POST['confirm_group']) ? trim((string) wp_unslash($_POST['confirm_group'])) : '';

        if (!PeepSoLeftoversService::exists($group) || $confirm !== $group) {
            AdminActionSupport::redirect(['page' => self::PAGE_SLUG, 'leftovers' => 'not_confirmed', 'group' => $group]);
        }

        $result = (new PeepSoLeftoversService())->run(
            $group,
            static function (string $kind, string $name, array $detail) use ($group): void {
                AdminActionSupport::audit('admin_peepso_leftover_removed', 'peepso_leftover', null, [
                    'group' => $group,
                    'kind'  => $kind,
                    'name'  => $name,
                ] + $detail);
            }
        );

        AdminActionSupport::redirect([
            'page'      => self::PAGE_SLUG,
            'leftovers' => isset($result['refused']) ? 'refused' : ($result['ok'] ? 'done' : 'partial'),
            'group'     => $group,
            'removed'   => count($result['removed']),
            'failed'    => count($result['failed']),
        ]);
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo '<div class="wrap"><h1>PeepSo leftovers</h1>';
        echo '<p>Data left behind by add-ons that were removed from this site. This page only reads: '
            . 'nothing is removed until you run one add-on below and type its id to confirm. '
            . '<strong>Dropping a table cannot be undone — take a database backup first.</strong></p>';

        self::renderNotice();

        $service = new PeepSoLeftoversService();
        foreach (PeepSoLeftoversService::groups() as $group => $label) {
            self::renderGroup($group, $service->plan($group));
        }

        echo '</div>';
    }

    private static function renderNotice(): void
    {
        $state = isset($_GET['leftovers']) ? sanitize_key((string) $_GET['leftovers']) : '';
        $group = isset($_GET['group']) ? sanitize_key((string) $_GET['group']) : '';
        $map   = [
            'done'          => ['success', sprintf('Removed %d item(s) for %s.', (int) ($_GET['removed'] ?? 0), $group)],
            'partial'       => ['warning', sprintf('Removed %d item(s) for %s; %d could not be removed (see below).', (int) ($_GET['removed'] ?? 0), $group, (int) ($_GET['failed'] ?? 0))],
            'refused'       => ['error', 'Nothing was removed: that add-on is still installed.'],
            'not_confirmed' => ['error', 'Nothing was removed: type the add-on id exactly to confirm.'],
        ];
        if (isset($map[$state])) {
            printf('<div class="notice notice-%s"><p>%s</p></div>', esc_attr($map[$state][0]), esc_html($map[$state][1]));
        }
    }

    /**
     * @param array{label: string, installed: bool, tables: array<string, int|null>, config_keys: list<string>, options: list<string>, usermeta: array<string, int>, nothing_left: bool} $plan
     */
    private static function renderGroup(string $group, array $plan): void
    {
        printf('<h2>%s <code>%s</code></h2>', esc_html($plan['label']), esc_html($group));

        if ($plan['installed']) {
            echo '<p>' . AdminUiCompat::badge('installed', AdminUiCompat::WARN) . ' This add-on is active, so its data is live. Nothing here will be removed.</p>';
            return;
        }
        if ($plan['nothing_left']) {
            echo '<p>' . AdminUiCompat::badge('clean', AdminUiCompat::OK) . ' Nothing left to remove.</p>';
            return;
        }

        echo '<table class="widefat striped" style="max-width:820px"><thead><tr><th>Kind</th><th>Name</th><th>Rows</th></tr></thead><tbody>';
        foreach ($plan['tables'] as $table => $rows) {
            if ($rows !== null) {
                printf('<tr><td>table (dropped)</td><td><code>%s</code></td><td>%d</td></tr>', esc_html($table), $rows);
            }
        }
        foreach ($plan['config_keys'] as $key) {
            printf('<tr><td>PeepSo setting</td><td><code>peepso_config.%s</code></td><td>—</td></tr>', esc_html($key));
        }
        foreach ($plan['options'] as $option) {
            printf('<tr><td>option</td><td><code>%s</code></td><td>—</td></tr>', esc_html($option));
        }
        foreach ($plan['usermeta'] as $key => $rows) {
            printf('<tr><td>user meta</td><td><code>%s</code></td><td>%d</td></tr>', esc_html($key), $rows);
        }
        echo '</tbody></table>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:8px 0 24px">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
        echo '<input type="hidden" name="group" value="' . esc_attr($group) . '">';
        wp_nonce_field(self::ACTION);
        printf(
            '<label>Type <code>%1$s</code> to confirm: <input type="text" name="confirm_group" autocomplete="off" pattern="%1$s" required></label> ',
            esc_attr($group)
        );
        echo '<button type="submit" class="button button-primary">Remove these</button>';
        echo '</form>';
    }
}
