<?php
/**
 * Integration-test bootstrap.
 *
 * Connects to a real MySQL and (re)creates a THROWAWAY `bcc_test` database from
 * scratch on every run — it never touches the dev `local` DB. Installs the BCC
 * schema via the real installer functions (with a thin dbDelta + Logger stub)
 * and exposes a mysqli-backed $wpdb shim as the global $wpdb, so repositories
 * run their genuine SQL against a genuine database.
 *
 * Connection is env-driven (CI sets these to a service container); the defaults
 * point at Local-by-Flywheel's MySQL.
 *
 *   BCC_TEST_DB_HOST  (default 127.0.0.1)
 *   BCC_TEST_DB_PORT  (default 10005)
 *   BCC_TEST_DB_USER  (default root)
 *   BCC_TEST_DB_PASS  (default root)
 *   BCC_TEST_DB_NAME  (default bcc_test)
 */

declare(strict_types=1);

use BCC\Trust\Tests\Integration\MysqliWpdb;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/MysqliWpdb.php';

// Point ABSPATH at a tiny stub tree so the schema installers'
// `require_once ABSPATH . 'wp-admin/includes/upgrade.php'` resolves to an empty
// file (dbDelta is stubbed below) instead of pulling in WordPress core.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/wp-stubs/');
}

// ── Connect + recreate the throwaway DB ─────────────────────────────────────

$host = getenv('BCC_TEST_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('BCC_TEST_DB_PORT') ?: 10005);
$user = getenv('BCC_TEST_DB_USER') ?: 'root';
$pass = getenv('BCC_TEST_DB_PASS') ?: 'root';
$name = getenv('BCC_TEST_DB_NAME') ?: 'bcc_test';

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @mysqli_connect($host, $user, $pass, '', $port);
if (!$conn) {
    fwrite(STDERR, "\n[integration] Cannot reach MySQL at {$host}:{$port} — is the DB running?\n");
    fwrite(STDERR, '[integration] ' . mysqli_connect_error() . "\n");
    exit(1);
}

// Guard: refuse to run against an obviously-real DB name.
if (in_array(strtolower($name), ['local', 'wordpress', 'production', 'prod'], true)) {
    fwrite(STDERR, "\n[integration] Refusing to use database '{$name}' — set BCC_TEST_DB_NAME to a throwaway.\n");
    exit(1);
}

$conn->query("DROP DATABASE IF EXISTS `{$name}`");
if (!$conn->query("CREATE DATABASE `{$name}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
    fwrite(STDERR, "\n[integration] Cannot create test DB: " . $conn->error . "\n");
    exit(1);
}
$conn->select_db($name);

// ── $wpdb shim + minimal WP stubs the schema installers need ────────────────

$GLOBALS['wpdb'] = new MysqliWpdb($conn, 'wp_');

// Real schema installers call dbDelta() + Logger. Stub both: dbDelta just runs
// the CREATE TABLE through the shim; Logger no-ops.
if (!function_exists('dbDelta')) {
    /** @param string|array<int,string> $queries */
    function dbDelta($queries): array
    {
        global $wpdb;
        foreach ((array) $queries as $sql) {
            $wpdb->query($sql);
            if ($wpdb->last_error !== '') {
                fwrite(STDERR, "[integration] schema error: {$wpdb->last_error}\n");
            }
        }
        return [];
    }
}
if (!class_exists('BCC\\Core\\Log\\Logger')) {
    eval('namespace BCC\\Core\\Log; final class Logger {
        public static function info(string $m, array $c = []): void {}
        public static function warning(string $m, array $c = []): void {}
        public static function error(string $m, array $c = []): void {}
        public static function audit(string $m, array $c = []): void {}
    }');
}

// ── Install the schema(s) the integration tests touch ───────────────────────

require_once dirname(__DIR__, 2) . '/includes/database/schema-content-reports.php';
bcc_trust_create_content_reports_table();
