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

// Minimal WP option + sanitizer stubs used by schema migrations + repositories.
if (!function_exists('get_option')) {
    /** @param mixed $default @return mixed */
    function get_option(string $key, $default = false)
    {
        // Round-trips update_option (an empty store still returns $default,
        // so this stays backward-compatible with tests that never set it).
        return $GLOBALS['__bcc_test_options'][$key] ?? $default;
    }
    /** @param mixed $value */
    function update_option(string $key, $value, $autoload = null): bool
    {
        $GLOBALS['__bcc_test_options'][$key] = $value;
        return true;
    }
    /** @param mixed $value */
    function add_option(string $key, $value = '', $deprecated = '', $autoload = 'yes'): bool
    {
        if (!isset($GLOBALS['__bcc_test_options'][$key])) {
            $GLOBALS['__bcc_test_options'][$key] = $value;
        }
        return true;
    }
    function sanitize_text_field(string $s): string
    {
        return trim((string) preg_replace('/[\r\n\t]+/', ' ', $s));
    }
    function sanitize_textarea_field(string $s): string
    {
        return trim($s);
    }
    /** @param mixed $v */
    function absint($v): int
    {
        return abs((int) $v);
    }
}

// Plugin config constants (vote weights, fraud thresholds, cleanup horizons,
// tiers, scoring) some repos read in their constructor. The aggregator is a
// pure set of define()s.
require_once dirname(__DIR__, 2) . '/includes/config.php';

// current_time('mysql') is used by some repositories (e.g. VoteRepository).
if (!function_exists('current_time')) {
    /** @return string|int */
    function current_time(string $type, int $gmt = 0)
    {
        if ($type === 'timestamp' || $type === 'U') {
            return time();
        }
        return $gmt ? gmdate('Y-m-d H:i:s') : date('Y-m-d H:i:s');
    }
}

// Object-cache stubs — repositories bump §5 generation counters via
// wp_cache_* after a write commits (e.g. NftHoldingsRepository::ingestBatch →
// bumpWalletGeneration). A tiny in-memory store keeps those functional so the
// write path doesn't fatal. Read paths under test query MySQL directly, so
// this cache never masks real DB state.
if (!function_exists('wp_cache_get')) {
    $GLOBALS['__bcc_test_object_cache'] = [];

    /**
     * @param bool $found
     * @return mixed
     */
    function wp_cache_get(string $key, string $group = '', bool $force = false, &$found = null)
    {
        $hit   = isset($GLOBALS['__bcc_test_object_cache'][$group][$key]);
        $found = $hit;
        return $hit ? $GLOBALS['__bcc_test_object_cache'][$group][$key] : false;
    }
    /** @param mixed $value */
    function wp_cache_set(string $key, $value, string $group = '', int $expire = 0): bool
    {
        $GLOBALS['__bcc_test_object_cache'][$group][$key] = $value;
        return true;
    }
    /** @param mixed $value */
    function wp_cache_add(string $key, $value, string $group = '', int $expire = 0): bool
    {
        if (isset($GLOBALS['__bcc_test_object_cache'][$group][$key])) {
            return false;
        }
        $GLOBALS['__bcc_test_object_cache'][$group][$key] = $value;
        return true;
    }
    function wp_cache_delete(string $key, string $group = ''): bool
    {
        unset($GLOBALS['__bcc_test_object_cache'][$group][$key]);
        return true;
    }
    /** @return int|false */
    function wp_cache_incr(string $key, int $offset = 1, string $group = '')
    {
        if (!isset($GLOBALS['__bcc_test_object_cache'][$group][$key])) {
            return false;
        }
        return $GLOBALS['__bcc_test_object_cache'][$group][$key] += $offset;
    }
}

// ── Install the schema(s) the integration tests touch ───────────────────────

require_once dirname(__DIR__, 2) . '/includes/database/schema-content-reports.php';
bcc_trust_create_content_reports_table();

require_once dirname(__DIR__, 2) . '/includes/database/schema-stokes.php';
bcc_trust_create_stokes_table();

// WordPress core wp_options (the rate limiter is option-backed via $wpdb->options).
$GLOBALS['wpdb']->query(
    "CREATE TABLE `wp_options` (
        option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        option_name VARCHAR(191) NOT NULL DEFAULT '',
        option_value LONGTEXT NOT NULL,
        autoload VARCHAR(20) NOT NULL DEFAULT 'yes',
        PRIMARY KEY (option_id),
        UNIQUE KEY option_name (option_name)
    ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
);

// Core tables (votes, scores, endorsements, …) via the real installer; its
// endorsement migration uses get_option/update_option (stubbed above).
require_once dirname(__DIR__, 2) . '/includes/database/schema-core.php';
bcc_trust_create_core_tables();

// On-chain claim-resolution tables (claims / validators / collections /
// wallet_links) — exercised by UserVerifiedClaimOnPageIntegrationTest.
// Their table-name helpers resolve via bcc-core's DB::table(); CI checks
// out bcc-core adjacent to this plugin (see .github/workflows/ci.yml), and
// local dev has it at the same relative path.
// PSR-4 autoload for bcc-core (BCC\Core\*) from the adjacent checkout. The
// harness used to manually require only DB.php; the validator-message queue
// worker/repos pull in more bcc-core classes (DegradationMetrics, AdvisoryLock,
// AsyncDispatcher, PeepSoMessageWriter). Registered AFTER the Logger stub above
// so the stub wins (class_exists short-circuits before this autoloader runs).
spl_autoload_register(static function (string $class): void {
    $prefix = 'BCC\\Core\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $rel  = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = dirname(__DIR__, 2) . '/../bcc-core/src/' . $rel . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

if (!class_exists(\BCC\Core\DB\DB::class)) {
    require_once dirname(__DIR__, 2) . '/../bcc-core/src/DB/DB.php';
}

require_once dirname(__DIR__, 2) . '/includes/database/schema-claims.php';
bcc_onchain_create_claims_table();

require_once dirname(__DIR__, 2) . '/includes/database/schema-validators.php';
bcc_onchain_create_validators_table();

require_once dirname(__DIR__, 2) . '/includes/database/schema-collections.php';
bcc_onchain_create_collections_table();

require_once dirname(__DIR__, 2) . '/includes/database/schema-wallets.php';
bcc_onchain_create_wallet_links_table();

require_once dirname(__DIR__, 2) . '/includes/database/schema-nft-holdings.php';
bcc_onchain_create_nft_holdings_table();

// ── Stubs the validator-message-queue worker/repo need ──────────────────────
if (!function_exists('wp_generate_uuid4')) {
    // Deterministic unique id — tests need uniqueness, not randomness.
    function wp_generate_uuid4(): string
    {
        static $n = 0;
        $n++;
        return sprintf('%08x-0000-4000-8000-%012x', $n, $n);
    }
}
// Transient stubs backing the worker's unsupported-context cooldown. Static
// array with an expiry timestamp so cooldown behaviour is actually exercised.
if (!function_exists('get_transient')) {
    function get_transient(string $key)
    {
        $store = $GLOBALS['__bcc_test_transients'] ?? [];
        if (!isset($store[$key])) {
            return false;
        }
        [$value, $expiresAt] = $store[$key];
        if ($expiresAt !== 0 && $expiresAt < time()) {
            unset($GLOBALS['__bcc_test_transients'][$key]);
            return false;
        }
        return $value;
    }
    function set_transient(string $key, $value, int $ttl = 0): bool
    {
        $GLOBALS['__bcc_test_transients'][$key] = [$value, $ttl > 0 ? time() + $ttl : 0];
        return true;
    }
    function delete_transient(string $key): bool
    {
        unset($GLOBALS['__bcc_test_transients'][$key]);
        return true;
    }
}

// ── validator-message queue + activation tables ─────────────────────────────
require_once dirname(__DIR__, 2) . '/includes/database/schema-validator-msg-queue.php';
bcc_create_validator_msg_queue_table();
require_once dirname(__DIR__, 2) . '/includes/database/schema-validator-msg-activation.php';
bcc_create_validator_msg_activation_table();
