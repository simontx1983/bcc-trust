<?php
/**
 * Admin "Verify Collections" page.
 *
 * Submenu under bcc-trust-dashboard. Lists collections from
 * wp_bcc_onchain_collections (paginated, ordered by holder count) with
 * an `is_verified` checkbox per row. Submitting the form persists the
 * flag changes, then the daily provisioning sweep auto-creates a
 * closed PeepSo group for each newly-verified collection.
 *
 * "Provision now" button triggers GatedGroupProvisioningService::provisionAll
 * immediately for admin testing without waiting for cron.
 *
 * @package BCC\Trust\Onchain\Admin
 */

namespace BCC\Trust\Onchain\Admin;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Onchain\Repositories\CollectionRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class VerifyCollectionsPage
{
    public const PAGE_SLUG  = 'bcc-verify-collections';
    public const NONCE_KEY  = 'bcc_verify_collections_nonce';
    public const NONCE_NAME = '_bcc_vc_nonce';

    public static function register_page(): void
    {
        add_submenu_page(
            'bcc-trust-dashboard',
            'Verify Collections',
            'Verify Collections',
            'manage_options',
            self::PAGE_SLUG,
            [__CLASS__, 'render_page']
        );
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        $notices = self::handlePost();

        $page    = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $listing = CollectionRepository::listForAdminVerification($page, 50);
        ?>
        <div class="wrap">
            <h1>Verify Collections</h1>

            <p>Mark a collection as <strong>On-Chain Verified</strong> to auto-create
            a closed PeepSo group for its holders. Holders see "you qualify" suggestions;
            joining is explicit (suggest-don't-auto-join). The provisioning sweep runs
            daily — use <em>Provision now</em> to trigger it immediately.</p>

            <?php foreach ($notices as $notice): ?>
                <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible">
                    <p><?php echo esc_html($notice['message']); ?></p>
                </div>
            <?php endforeach; ?>

            <form method="post" action="">
                <?php wp_nonce_field(self::NONCE_KEY, self::NONCE_NAME); ?>
                <input type="hidden" name="bcc_vc_action" value="save">
                <input type="hidden" name="paged" value="<?php echo (int) $page; ?>">

                <p class="submit" style="margin:0 0 12px 0;">
                    <button type="submit" class="button button-primary">Save Verification Changes</button>
                    <button type="submit" name="bcc_vc_action" value="provision" class="button">Provision Now</button>
                </p>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th style="width:80px;">Verified</th>
                            <th style="width:60px;"></th>
                            <th>Collection</th>
                            <th>Chain</th>
                            <th>Contract</th>
                            <th style="width:120px;">Holders</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($listing['items'] === []): ?>
                            <tr>
                                <td colspan="6"><em>No collections synced yet. Connect a wallet to populate this list.</em></td>
                            </tr>
                        <?php else: foreach ($listing['items'] as $row): ?>
                            <tr>
                                <td>
                                    <input type="hidden" name="known[]" value="<?php echo (int) $row->id; ?>">
                                    <input type="checkbox"
                                           name="verified[<?php echo (int) $row->id; ?>]"
                                           value="1"
                                           <?php checked((int) $row->is_verified, 1); ?>>
                                </td>
                                <td>
                                    <?php if (!empty($row->image_url)): ?>
                                        <img src="<?php echo esc_url($row->image_url); ?>"
                                             alt=""
                                             style="width:40px;height:40px;border-radius:4px;object-fit:cover;">
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo esc_html($row->collection_name ?? '(no name)'); ?></strong>
                                </td>
                                <td><code><?php echo esc_html($row->chain_slug); ?></code></td>
                                <td>
                                    <code style="font-size:11px;"><?php echo esc_html($row->contract_address); ?></code>
                                </td>
                                <td><?php echo number_format_i18n((int) ($row->unique_holders ?? 0)); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary">Save Verification Changes</button>
                </p>
            </form>

            <?php if ($listing['pages'] > 1): ?>
                <div class="tablenav-pages">
                    <?php
                    echo paginate_links([
                        'base'    => add_query_arg('paged', '%#%'),
                        'format'  => '',
                        'current' => $page,
                        'total'   => $listing['pages'],
                    ]);
                    ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @return list<array{type: string, message: string}>
     */
    private static function handlePost(): array
    {
        if (empty($_POST['bcc_vc_action'])) {
            return [];
        }

        if (!isset($_POST[self::NONCE_NAME]) ||
            !wp_verify_nonce(sanitize_text_field((string) $_POST[self::NONCE_NAME]), self::NONCE_KEY)
        ) {
            return [['type' => 'error', 'message' => 'Security check failed. Please try again.']];
        }

        $action = sanitize_text_field((string) $_POST['bcc_vc_action']);

        if ($action === 'save') {
            return self::handleSave();
        }

        if ($action === 'provision') {
            return self::handleProvision();
        }

        return [];
    }

    /**
     * @return list<array{type: string, message: string}>
     */
    private static function handleSave(): array
    {
        $known = isset($_POST['known']) && is_array($_POST['known'])
            ? array_map('intval', $_POST['known'])
            : [];

        $checkedRaw = isset($_POST['verified']) && is_array($_POST['verified'])
            ? $_POST['verified']
            : [];
        $checked = [];
        foreach ($checkedRaw as $id => $_v) {
            $checked[(int) $id] = true;
        }

        $changed = 0;
        foreach ($known as $collectionId) {
            if ($collectionId <= 0) {
                continue;
            }
            $shouldBeVerified = isset($checked[$collectionId]);
            CollectionRepository::setVerified($collectionId, $shouldBeVerified);
            $changed++;
        }

        return [[
            'type'    => 'success',
            'message' => sprintf('Verification flags saved (%d collections processed).', $changed),
        ]];
    }

    /**
     * @return list<array{type: string, message: string}>
     */
    private static function handleProvision(): array
    {
        // Persist any pending changes first so the operator sees consistent state.
        $saveNotices = self::handleSave();

        $result = Plugin::instance()->gatedGroupProvisioningService()->provisionAll();

        $message = sprintf(
            'Provisioning sweep ran: %d created, %d skipped (already exist or missing metadata).',
            $result['created'],
            $result['skipped']
        );
        $errors = $result['errors'] ?? [];

        $notices = $saveNotices;
        $notices[] = [
            'type'    => empty($errors) ? 'success' : 'warning',
            'message' => $message,
        ];

        foreach ($errors as $err) {
            $notices[] = ['type' => 'error', 'message' => (string) $err];
        }

        return $notices;
    }
}
