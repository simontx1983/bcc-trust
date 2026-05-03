<?php
/**
 * PushCommand — V2 Phase 1 WP-CLI helpers for the web-push subsystem.
 *
 * Currently exposes one sub-command:
 *
 *   wp bcc-trust push generate-vapid
 *
 *     Generates a fresh VAPID key pair and prints copy-paste config
 *     for wp-config.php. Does NOT write to wp-config.php — that's a
 *     deliberate choice; admins must paste explicitly so the keys
 *     don't end up in version control by accident.
 *
 * Why a CLI command (not an admin UI): VAPID keys are persistent
 * server identity. Generating + rotating them is rare, deliberate,
 * and never something a casual admin should do through clicks.
 *
 * @package BCC\Trust\Core\CLI
 * @since V2 Phase 1
 */

declare(strict_types=1);

namespace BCC\Trust\Core\CLI;

use Minishlink\WebPush\VAPID;
use WP_CLI;

if (!defined('ABSPATH')) {
    exit;
}

final class PushCommand
{
    /**
     * Generate a fresh VAPID key pair and print copy-paste config.
     *
     * ## EXAMPLES
     *
     *   wp bcc-trust push generate-vapid
     *
     * @when after_wp_load
     *
     * @param array<int, string>  $args
     * @param array<string, string> $assoc_args
     */
    public function generate_vapid(array $args, array $assoc_args): void
    {
        unset($args, $assoc_args);

        if (!class_exists(VAPID::class)) {
            WP_CLI::error(
                'minishlink/web-push is not installed. '
                . 'Run `composer install` inside wp-content/plugins/bcc-trust first.'
            );
            return;
        }

        try {
            /** @var array{publicKey: string, privateKey: string} $keys */
            $keys = VAPID::createVapidKeys();
        } catch (\Throwable $e) {
            WP_CLI::error('VAPID key generation failed: ' . $e->getMessage());
            return;
        }

        $publicKey  = $keys['publicKey'];
        $privateKey = $keys['privateKey'];

        $siteUrl = (string) home_url();
        $subject = $siteUrl !== '' ? $siteUrl : 'mailto:admin@example.com';

        WP_CLI::line('');
        WP_CLI::line('Generated VAPID keys for BCC web push.');
        WP_CLI::line('Add the following lines to wp-config.php (above the "stop editing" comment):');
        WP_CLI::line('');
        WP_CLI::line(sprintf("define('BCC_PUSH_VAPID_PUBLIC_KEY',  '%s');", $publicKey));
        WP_CLI::line(sprintf("define('BCC_PUSH_VAPID_PRIVATE_KEY', '%s');", $privateKey));
        WP_CLI::line(sprintf("define('BCC_PUSH_VAPID_SUBJECT',     '%s');", $subject));
        WP_CLI::line('');
        WP_CLI::line('Notes:');
        WP_CLI::line('  • The PUBLIC key is exposed to the browser via /me/push-subscriptions/vapid-public-key.');
        WP_CLI::line('  • The PRIVATE key never leaves the server.');
        WP_CLI::line('  • The SUBJECT must be either a site URL (https://...) or a mailto: address.');
        WP_CLI::line('  • Rotating these invalidates ALL existing subscriptions — do not regenerate without intent.');
        WP_CLI::success('VAPID keys generated.');
    }
}
