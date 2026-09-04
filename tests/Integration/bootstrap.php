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

// WP core time constants. Some production classes use these in CONSTANT
// EXPRESSIONS (e.g. TableRegistry::EXISTS_CACHE_TTL = HOUR_IN_SECONDS), which
// PHP resolves lazily at first class use — so a missing one is not a load
// error, it is a fatal thrown from whichever test first touches that class.
// Same values as wp-includes/default-constants.php.
if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
    define('HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS);
    define('DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS);
    define('WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS);
    define('MONTH_IN_SECONDS', 30 * DAY_IN_SECONDS);
    define('YEAR_IN_SECONDS', 365 * DAY_IN_SECONDS);
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
// RECORDING stub, not a silent one. Several repository behaviours are only
// observable through the log — a guarded read that failed returns the same
// empty shape a successful empty read does, and the ERROR line is the whole
// difference. A no-op Logger makes that untestable.
if (!class_exists('BCC\\Core\\Log\\Logger')) {
    eval('namespace BCC\\Core\\Log; final class Logger {
        /** @var list<array{level: string, message: string, context: array<string,mixed>}> */
        public static array $lines = [];
        public static function reset(): void { self::$lines = []; }
        public static function info(string $m, array $c = []): void { self::$lines[] = ["level" => "info", "message" => $m, "context" => $c]; }
        public static function warning(string $m, array $c = []): void { self::$lines[] = ["level" => "warning", "message" => $m, "context" => $c]; }
        public static function error(string $m, array $c = []): void { self::$lines[] = ["level" => "error", "message" => $m, "context" => $c]; }
        public static function audit(string $m, array $c = []): void { self::$lines[] = ["level" => "audit", "message" => $m, "context" => $c]; }
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
        // ── MODELLING A POISONED PERSISTENT OBJECT CACHE ────────────────
        // When `__bcc_test_options_frozen` is set, the write REPORTS SUCCESS
        // and the subsequent read still returns the old value. That is not a
        // contrived shape: it is exactly what a stale `redis-cache` drop-in
        // does to `update_option`/`get_option`, and it is the one failure
        // mode a "did the write stick?" re-read exists to catch. Without a
        // way to reproduce it, that re-read is untested code.
        if (!empty($GLOBALS['__bcc_test_options_frozen'])) {
            return true;
        }

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
    // ── PR 7.2: the i18n surface an operator-facing service reaches for ──
    //
    // `DiscoveryScanProgress` builds the sentence that tells an operator
    // whether a chain scan is finished, so its wording is under test — and
    // wording goes through WordPress's translation functions. These are
    // identity/format shims, not translations: the integration harness has
    // no text domain and none is wanted, the assertions are about WHICH
    // sentence is chosen.
    if (!function_exists('__')) {
        function __(string $text, string $domain = 'default'): string
        {
            return $text;
        }
    }
    if (!function_exists('_n')) {
        function _n(string $single, string $plural, int $number, string $domain = 'default'): string
        {
            return $number === 1 ? $single : $plural;
        }
    }
    if (!function_exists('number_format_i18n')) {
        function number_format_i18n(float $number, int $decimals = 0): string
        {
            return number_format($number, $decimals);
        }
    }
    if (!function_exists('esc_html')) {
        function esc_html(string $text): string
        {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        }
    }

    function sanitize_text_field(string $s): string
    {
        return trim((string) preg_replace('/[\r\n\t]+/', ' ', $s));
    }
    function sanitize_textarea_field(string $s): string
    {
        return trim($s);
    }
}

// Guarded on its OWN name rather than riding the get_option() block above:
// that block is skipped wholesale once get_option exists, which would leave
// this undefined, and it would redeclare this one if the reverse happened.
if (!function_exists('esc_url_raw')) {
    /**
     * Enough of WP's URL sanitiser for repository writes to run: strip
     * control characters and pass the rest through. CollectionRepository
     * runs image_url through this before persisting.
     */
    function esc_url_raw(string $url): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $url));
    }
}

if (!function_exists('absint')) {
    /** @param mixed $v */
    function absint($v): int
    {
        return abs((int) $v);
    }
}

// ── Admin-view shims ────────────────────────────────────────────────────
//
// Enough of WP's escaping and admin-form helpers for an ADMIN VIEW to be
// rendered under the integration harness. Added for
// DiscoveryScanPanelControlIntegrationTest, which exists because the panel's
// control branch had NO render coverage at all: PR 7.2 verified
// DiscoveryScanProgress::actionLabel() as a pure function, and the label it
// returned never reached the screen. Asserting on a view's real output is
// the only thing that would have caught that.
//
// ⚠ These deliberately mirror WP's ESCAPING BEHAVIOUR, not just its
// signatures. A shim that returned its input unchanged would let an
// escaping regression pass here and fail in production.
if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return htmlspecialchars(esc_url_raw($url), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'https://example.test/wp-admin/' . ltrim($path, '/');
    }
}

if (!function_exists('wp_nonce_field')) {
    /**
     * ⚠ Emits a field whose NAME matches WP's, so a test can assert the
     * form carries a nonce. It is not a real nonce and proves nothing about
     * nonce verification — that is DiscoveryScanActions' own coverage.
     */
    function wp_nonce_field(string $action = '-1', string $name = '_wpnonce', bool $referer = true, bool $display = true): string
    {
        $field = '<input type="hidden" name="' . esc_attr($name) . '" value="test-nonce-' . md5($action) . '">';

        if ($display) {
            echo $field;
        }

        return $field;
    }
}

// Plugin config constants (vote weights, fraud thresholds, cleanup horizons,
// tiers, scoring) some repos read in their constructor. The aggregator is a
// pure set of define()s.
require_once dirname(__DIR__, 2) . '/includes/config.php';

// current_time('mysql') is used by some repositories (e.g. VoteRepository).
//
// The 'Y-m-d' arm is not decoration: ChainCheckpointRepository stamps
// `cu_budget_reset_at` (a DATE column) with current_time('Y-m-d', true) and
// then compares the stored value back against it to decide whether the daily
// CU counter has rolled over. Returning a full datetime here made that
// comparison NEVER match, so every increment looked like a new day.
if (!function_exists('current_time')) {
    /** @return string|int */
    function current_time(string $type, int $gmt = 0)
    {
        if ($type === 'timestamp' || $type === 'U') {
            return time();
        }
        if ($type === 'Y-m-d') {
            return $gmt ? gmdate('Y-m-d') : date('Y-m-d');
        }
        return $gmt ? gmdate('Y-m-d H:i:s') : date('Y-m-d H:i:s');
    }
}

// Used by ChainCheckpointRepository when it re-encodes the bounded block
// progression history.
if (!function_exists('wp_json_encode')) {
    /**
     * @param  mixed $data
     * @return string|false
     */
    function wp_json_encode($data, int $options = 0, int $depth = 512)
    {
        return json_encode($data, $options, $depth);
    }
}

// WP_Error + is_wp_error, added for PR 5b.
//
// bcc-core's SafeHttpClient returns a WP_Error rather than throwing when a
// request is refused — including its SSRF guard, which rejects a private or
// reserved IP before opening any socket. A test that points a fetcher at
// 127.0.0.1 to prove "a provider call WAS attempted" therefore needs this
// class to exist, or it fails with a missing-class error that looks like a
// production bug and is not one.
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        /** @var array<string, list<string>> */
        private array $errors = [];

        /** @param mixed $data */
        public function __construct(string $code = '', string $message = '', $data = null)
        {
            if ($code !== '') {
                $this->errors[$code][] = $message;
            }
        }

        public function get_error_code(): string
        {
            $codes = array_keys($this->errors);

            return $codes === [] ? '' : (string) $codes[0];
        }

        public function get_error_message(string $code = ''): string
        {
            $code = $code !== '' ? $code : $this->get_error_code();

            return $this->errors[$code][0] ?? '';
        }
    }
}

if (!function_exists('is_wp_error')) {
    /** @param mixed $thing */
    function is_wp_error($thing): bool
    {
        return $thing instanceof \WP_Error;
    }
}

// Post-meta shims, added for PR 5b: holder-gate configuration lives
// entirely in post meta, so GatedGroupRepository cannot run without them.
//
// These read and write the REAL `wp_postmeta` table rather than an
// in-memory array. That is the whole point of this suite: an array-backed
// stub would return whatever the fixture queued regardless of what the
// repair actually wrote, so "the gate meta was updated" would be a claim
// about the stub. Here it is a claim about MySQL.
if (!function_exists('get_post_meta')) {
    /**
     * @return mixed Single value when $single, else a list.
     */
    function get_post_meta(int $postId, string $key = '', bool $single = false)
    {
        $wpdb = $GLOBALS['wpdb'];

        $values = $wpdb->get_col($wpdb->prepare(
            "SELECT meta_value FROM `{$wpdb->postmeta}` WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC",
            $postId,
            $key
        ));

        if ($single) {
            // WP returns '' (not null) for a missing key — callers cast to
            // int/string and rely on that, so the shim must match.
            return $values === [] ? '' : (string) $values[0];
        }

        return array_map('strval', $values);
    }
}

if (!function_exists('update_post_meta')) {
    /**
     * @param  mixed $value
     * @return bool
     */
    function update_post_meta(int $postId, string $key, $value): bool
    {
        $wpdb = $GLOBALS['wpdb'];

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_id FROM `{$wpdb->postmeta}` WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1",
            $postId,
            $key
        ));

        if ($existing === null) {
            return $wpdb->query($wpdb->prepare(
                "INSERT INTO `{$wpdb->postmeta}` (post_id, meta_key, meta_value) VALUES (%d, %s, %s)",
                $postId,
                $key,
                (string) $value
            )) !== false;
        }

        return $wpdb->query($wpdb->prepare(
            "UPDATE `{$wpdb->postmeta}` SET meta_value = %s WHERE meta_id = %d",
            (string) $value,
            (int) $existing
        )) !== false;
    }
}

if (!function_exists('update_meta_cache')) {
    /**
     * No-op: `get_post_meta()` above always reads the database, so there is
     * no cache for a batch warm-up to fill. Declared because
     * GatedGroupRepository::findManyByGroupIds() calls it unconditionally.
     *
     * @param  int[] $objectIds
     * @return array<int, mixed>
     */
    function update_meta_cache(string $metaType, array $objectIds): array
    {
        return [];
    }
}

// Filter shim. ChainRepository::ttl() runs its cache TTL through
// `bcc_chains_cache_ttl` on EVERY cached read, so getActive() cannot execute
// at all without this. Pass-through (no filters registered) — which is also
// what a stock WP install does, so the TTL under test is the real default.
if (!function_exists('apply_filters')) {
    /**
     * @param  mixed $value
     * @param  mixed ...$args
     * @return mixed
     */
    function apply_filters(string $hook, $value, ...$args)
    {
        return $value;
    }
}

// Object-cache stubs — repositories bump §5 generation counters via
// wp_cache_* after a write commits (e.g. NftHoldingsRepository::ingestBatch →
// bumpWalletGeneration). A tiny in-memory store keeps those functional so the
// write path doesn't fatal. Read paths under test query MySQL directly, so
// this cache never masks real DB state.
if (!function_exists('wp_cache_get')) {
    $GLOBALS['__bcc_test_object_cache'] = [];

    // NOTE: `$key` is `int|string`, matching WordPress. It is NOT merely
    // `string`: object ids are passed as integers throughout core and by
    // BCC callers (`wp_cache_delete($postId, 'post_meta')`), so a
    // string-only shim is STRICTER than the thing it stands in for — it
    // would TypeError on a call that works perfectly in production, which
    // is a test-harness bug masquerading as a code bug.
    /**
     * @param int|string $key
     * @param bool $found
     * @return mixed
     */
    function wp_cache_get($key, string $group = '', bool $force = false, &$found = null)
    {
        $hit   = isset($GLOBALS['__bcc_test_object_cache'][$group][$key]);
        $found = $hit;
        return $hit ? $GLOBALS['__bcc_test_object_cache'][$group][$key] : false;
    }
    /**
     * @param int|string $key
     * @param mixed      $value
     */
    function wp_cache_set($key, $value, string $group = '', int $expire = 0): bool
    {
        $GLOBALS['__bcc_test_object_cache'][$group][$key] = $value;
        return true;
    }
    /**
     * @param int|string $key
     * @param mixed      $value
     */
    function wp_cache_add($key, $value, string $group = '', int $expire = 0): bool
    {
        if (isset($GLOBALS['__bcc_test_object_cache'][$group][$key])) {
            return false;
        }
        $GLOBALS['__bcc_test_object_cache'][$group][$key] = $value;
        return true;
    }
    /** @param int|string $key */
    function wp_cache_delete($key, string $group = ''): bool
    {
        unset($GLOBALS['__bcc_test_object_cache'][$group][$key]);
        return true;
    }
    /**
     * @param  int|string $key
     * @return int|false
     */
    function wp_cache_incr($key, int $offset = 1, string $group = '')
    {
        if (!isset($GLOBALS['__bcc_test_object_cache'][$group][$key])) {
            return false;
        }
        return $GLOBALS['__bcc_test_object_cache'][$group][$key] += $offset;
    }
    /**
     * Repositories branch on this to choose an invalidation strategy (a real
     * persistent cache gets a generation bump; the built-in array cache is
     * per-request and needs none). The in-memory store above is NOT an
     * external object cache, so answering false is the honest answer and
     * keeps the branch that runs here the same one a plain WP install takes.
     */
    function wp_using_ext_object_cache(): bool
    {
        return false;
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

// WordPress core wp_users. Only the columns the BCC read paths join against:
// VoteRepository::getVoteAggregatesForPage() LEFT JOINs it for user_registered
// to compute the confidence-diversity "mature voter" term.
//
// user_registered is NULLable here where WP core declares
// `NOT NULL DEFAULT '0000-00-00 00:00:00'`: MySQL 8 runs with NO_ZERO_DATE, so
// the core default is rejected and the CREATE fails. The aggregate already
// treats a NULL registration as "not mature", so the semantics match.
$created = $GLOBALS['wpdb']->query(
    "CREATE TABLE `wp_users` (
        ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_login VARCHAR(60) NOT NULL DEFAULT '',
        user_email VARCHAR(100) NOT NULL DEFAULT '',
        user_registered DATETIME NULL DEFAULT NULL,
        display_name VARCHAR(250) NOT NULL DEFAULT '',
        PRIMARY KEY (ID)
    ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
);
if ($created === false) {
    fwrite(STDERR, "FATAL: could not create wp_users: " . $GLOBALS['wpdb']->last_error . "\n");
    exit(1);
}

// WordPress core wp_posts + wp_postmeta. Added for PR 5b: holder-gate
// configuration lives entirely in post meta on `peepso-group` posts, so the
// gate-identity repair cannot be exercised without them.
//
// ENGINE=InnoDB is load-bearing, not decoration. The repair runs inside
// TransactionManager::run() and takes `SELECT … FOR UPDATE` row locks; on
// MyISAM the transaction would silently do nothing and every rollback
// assertion in the suite would pass while proving the opposite of what it
// claims. Only the columns the gate paths actually touch are declared.
// EVERY column carries a default, and `post_title` is VARCHAR rather than
// core's TEXT (MySQL forbids a default on TEXT). That divergence is
// deliberate: this table is now SHARED. HallRepositoryIntegrationTest
// creates its own three-column `wp_posts` with `CREATE TABLE IF NOT
// EXISTS`, which silently becomes a no-op now that the bootstrap gets
// there first — so its inserts, which name only (ID, post_type,
// post_status), must still succeed. With a defaultless NOT NULL column
// they fail under STRICT mode, the insert is rejected, and the test reads
// an empty table as a repository bug. A shared harness table has to accept
// every legitimate subset its users write.
$created = $GLOBALS['wpdb']->query(
    "CREATE TABLE `wp_posts` (
        ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        post_title VARCHAR(255) NOT NULL DEFAULT '',
        post_name VARCHAR(200) NOT NULL DEFAULT '',
        post_type VARCHAR(20) NOT NULL DEFAULT 'post',
        post_status VARCHAR(20) NOT NULL DEFAULT 'publish',
        PRIMARY KEY (ID),
        KEY type_status (post_type, post_status)
    ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
);
if ($created === false) {
    fwrite(STDERR, "FATAL: could not create wp_posts: " . $GLOBALS['wpdb']->last_error . "\n");
    exit(1);
}

$created = $GLOBALS['wpdb']->query(
    "CREATE TABLE `wp_postmeta` (
        meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        meta_key VARCHAR(255) DEFAULT NULL,
        meta_value LONGTEXT,
        PRIMARY KEY (meta_id),
        KEY post_id (post_id),
        KEY meta_key (meta_key(191))
    ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
);
if ($created === false) {
    fwrite(STDERR, "FATAL: could not create wp_postmeta: " . $GLOBALS['wpdb']->last_error . "\n");
    exit(1);
}

// Core tables (votes, scores, endorsements, …) via the real installer; its
// endorsement migration uses get_option/update_option (stubbed above).
require_once dirname(__DIR__, 2) . '/includes/database/schema-core.php';
bcc_trust_create_core_tables();

// user_info via its real installer — the aggregate query LEFT JOINs it for
// fraud_score (the retroactive fraud discount).
require_once dirname(__DIR__, 2) . '/includes/database/schema-user-info.php';
bcc_trust_create_user_info_table();

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

// The schema-version completion gate. Lives in its own file precisely so it
// can be required here — it used to be an anonymous `plugins_loaded` closure
// in bcc-trust.php, where the rule deciding whether a failed migration is
// ever retried could not be reached by a test at all.
require_once dirname(__DIR__, 2) . '/includes/database/schema-completion.php';

require_once dirname(__DIR__, 2) . '/includes/database/schema-wallets.php';
bcc_onchain_create_wallet_links_table();

require_once dirname(__DIR__, 2) . '/includes/database/schema-nft-holdings.php';
bcc_onchain_create_nft_holdings_table();

// CosmWasm CW-721 discovery. These four are what
// CosmwasmRepositoryIntegrationTest runs its REAL SQL against — the unit
// suite's `$wpdb` double returns queued fixtures regardless of the query
// text, so it structurally cannot catch a malformed clause (an `ORDER BY 0`
// shipped past 1,048 green unit tests). Only a real MySQL can.
require_once dirname(__DIR__, 2) . '/includes/database/schema-chain-checkpoints.php';
bcc_onchain_create_chain_checkpoints_table();

require_once dirname(__DIR__, 2) . '/includes/database/schema-nft-spam-contracts.php';
bcc_onchain_create_nft_spam_contracts_table();

require_once dirname(__DIR__, 2) . '/includes/database/schema-cosmwasm-code-families.php';
bcc_onchain_create_cosmwasm_code_families_table();

require_once dirname(__DIR__, 2) . '/includes/database/schema-cosmwasm-contracts.php';

// PR 7A discovery-run ledger. Required here so the integration suite can
// exercise the real table — uq_active, the claim compare-and-swap and the
// lease reaper are all database behaviour that a double cannot prove.
require_once dirname(__DIR__, 2) . '/includes/database/schema-discovery-runs.php';
bcc_onchain_create_discovery_runs_table();
bcc_onchain_create_cosmwasm_contracts_table();

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

// ── the chain registry ──────────────────────────────────────────────────────
//
// `bcc_onchain_collections.chain_id` points here, and EVERY real read of a
// collection (findManyByIds, listVerified, …) INNER JOINs this table for the
// slug + chain_type. Installing collections without chains means those queries
// simply cannot be executed against a real server — which is the gap a
// keyed-vs-list return-shape defect in findManyByIds() slipped through.
//
// Deliberately LAST, not next to schema-collections.php: the installer seeds
// the default chain rows and then calls ChainRepository::clearCache(), which
// needs the delete_transient() stub defined above. Table creation order is
// free — the collections schema declares no FOREIGN KEY, only a chain_id
// column and an index.
require_once dirname(__DIR__, 2) . '/includes/database/schema-chains.php';
bcc_onchain_create_chains_table();

// Per-chain NFT driver overrides. Created AFTER the chains table because
// its rows are keyed by chain_id (no FOREIGN KEY is declared, but the
// capability tests resolve real chain ids, so the registry has to exist).
//
// The ALTER migration runs here too, immediately after the CREATE TABLE that
// already carries both columns — deliberately, because that is exactly the
// no-op an EXISTING install performs on upgrade, and it is the path that
// must not disturb anything. A migration that only ever ran against a table
// missing its columns would never exercise the branch real sites take.
require_once dirname(__DIR__, 2) . '/includes/database/schema-chain-nft-capabilities.php';
bcc_onchain_add_chains_nft_capability_columns();
bcc_onchain_create_chain_nft_capabilities_table();

// PR 5a canonical collection identity. Runs LAST of the collection-related
// installers, and specifically AFTER the chains table exists: the backfill
// joins collections to chains to learn each row's family, so running it
// earlier would silently backfill nothing and look like success.
//
// In production this sits inside bcc_onchain_ensure_schema() right after
// bcc_onchain_create_collections_table(); the ordering constraint is the
// same one, since ensure_schema() creates chains first.
//
// (schema-collections.php is already required above — the migration lives
// alongside the CREATE TABLE it amends.)
bcc_onchain_add_collections_canonical_identifier();

// PR 6 provisioning intent. Runs after the canonical-identity migration for
// the same reason production does: its backfill reasons about live gates, and
// a gate is only meaningful once identity is settled.
//
// Note this runs against a table that has just been created, so the backfill
// marks nothing — which is correct, and is also why the migration's OWN
// integration test re-runs it against seeded gates rather than relying on
// this bootstrap pass to prove the backfill works.
bcc_onchain_add_collections_provisioning_state();

// PR 7 community metadata: four bounded columns plus the empty-image and
// retired-market normalizations. Same note as above — this pass runs against a
// freshly created table, so the normalizations match nothing here. Their OWN
// integration test seeds the production shapes (`image_url = ''`, a hardcoded
// `floor_currency = 'SOL'`) and re-runs them, because a normalization proved
// only against an empty table is proved against nothing.
bcc_onchain_add_collections_community_metadata();
