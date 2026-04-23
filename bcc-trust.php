<?php
/**
 * Plugin Name: Blue Collar Crypto – Trust
 * Description: Unified reputation, dispute, and on-chain signal plugin. Merges bcc-trust-engine, bcc-disputes, and bcc-onchain-signals into a single bounded-context codebase.
 * Version: 1.0.0-m1.0
 * Author: Blue Collar Labs LLC
 * Text Domain: bcc-trust
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 8.1
 * Requires Plugins: bcc-core
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * M1.0 — skeleton only.
 *
 * This plugin is intentionally inert at load time. Domain code is
 * moved in across M1.1 (Core), M1.2 (Onchain), and M1.3 (Disputes).
 * Until then the three predecessor plugins (bcc-trust-engine,
 * bcc-disputes, bcc-onchain-signals) remain the live implementations.
 *
 * Activating this plugin in M1.0 is a no-op: no tables are installed,
 * no hooks are registered, no REST routes are exposed. The directory
 * exists so the migration work has a stable target to commit into.
 */

define('BCC_TRUST_VERSION', '1.0.0-m1.0');
define('BCC_TRUST_PATH', plugin_dir_path(__FILE__));
define('BCC_TRUST_URL', plugin_dir_url(__FILE__));
define('BCC_TRUST_FILE', __FILE__);

/**
 * Autoloader guard — absent until composer install is run. Kept silent
 * during M1.0 so the skeleton doesn't block the admin on a fresh
 * checkout; the notice pattern from the predecessor plugins will be
 * re-introduced when real domain code lands in M1.1.
 */
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}