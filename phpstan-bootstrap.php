<?php
/**
 * PHPStan bootstrap — makes runtime constants and optional-plugin stubs
 * available during static analysis.
 *
 * Config files guard with `if (!defined('ABSPATH')) exit;` so ABSPATH is
 * defined here to allow them to execute during analysis.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__, 4) . '/');
}
if (!defined('WPINC')) {
    define('WPINC', 'wp-includes');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', 'phpstan_stub');
}

// ── Plugin-level constants normally set in the main plugin file ──────────

if (!defined('BCC_TRUST_VERSION')) {
    define('BCC_TRUST_VERSION', '1.2.0');
}
if (!defined('BCC_TRUST_PATH')) {
    define('BCC_TRUST_PATH', __DIR__ . '/');
}
if (!defined('BCC_TRUST_URL')) {
    define('BCC_TRUST_URL', '/wp-content/plugins/bcc-trust/');
}

// Onchain signal-score caps + cache ttl + total-bonus cap.
if (!defined('BCC_ONCHAIN_MAX_AGE_SCORE'))      { define('BCC_ONCHAIN_MAX_AGE_SCORE',      8); }
if (!defined('BCC_ONCHAIN_MAX_DEPTH_SCORE'))    { define('BCC_ONCHAIN_MAX_DEPTH_SCORE',    7); }
if (!defined('BCC_ONCHAIN_MAX_CONTRACT_SCORE')) { define('BCC_ONCHAIN_MAX_CONTRACT_SCORE', 4.8); }
if (!defined('BCC_ONCHAIN_CACHE_HOURS'))        { define('BCC_ONCHAIN_CACHE_HOURS',        24); }
if (!defined('BCC_ONCHAIN_MAX_TOTAL_BONUS'))    { define('BCC_ONCHAIN_MAX_TOTAL_BONUS',    20); }

// Disputes domain limits.
if (!defined('BCC_DISPUTES_PANEL_SIZE'))          { define('BCC_DISPUTES_PANEL_SIZE',          5); }
if (!defined('BCC_DISPUTES_TTL_DAYS'))            { define('BCC_DISPUTES_TTL_DAYS',            7); }
if (!defined('BCC_DISPUTES_MAX_PER_PAGE'))        { define('BCC_DISPUTES_MAX_PER_PAGE',        3); }
if (!defined('BCC_DISPUTES_REPORTER_MAX_ACTIVE')) { define('BCC_DISPUTES_REPORTER_MAX_ACTIVE', 5); }
if (!defined('BCC_DISPUTES_MIN_REASON_LENGTH'))   { define('BCC_DISPUTES_MIN_REASON_LENGTH',   20); }
if (!defined('BCC_DISPUTES_MAX_REASON_LENGTH'))   { define('BCC_DISPUTES_MAX_REASON_LENGTH',   1000); }
if (!defined('BCC_DISPUTES_MIN_DETAIL_LENGTH'))   { define('BCC_DISPUTES_MIN_DETAIL_LENGTH',   10); }
if (!defined('BCC_DISPUTES_MAX_REOPEN_ATTEMPTS')) { define('BCC_DISPUTES_MAX_REOPEN_ATTEMPTS', 10); }

// ── Load config files (they define all BCC_TRUST_*, BCC_QUEST_* constants) ──

$configDir = __DIR__ . '/includes/config/';
$configs = [
    'tiers.php',
    'scoring.php',
    'trust-weights.php',
    'fraud-detection.php',
    'limits.php',
    'behavioral.php',
    'quest-rewards.php',
];

foreach ($configs as $file) {
    $path = $configDir . $file;
    if (file_exists($path)) {
        require_once $path;
    }
}

// ── ActionScheduler stub (optional WooCommerce dependency) ──────────────

if (!class_exists('ActionScheduler_Store')) {
    class ActionScheduler_Store {
        const STATUS_PENDING = 'pending';
        const STATUS_RUNNING = 'in-progress';
        const STATUS_COMPLETE = 'complete';
        const STATUS_CANCELED = 'canceled';
        const STATUS_FAILED = 'failed';
    }
}

// ── PeepSo class stubs (optional dependency) ────────────────────────────

if (!class_exists('PeepSo')) {
    class PeepSo {
        public const ACCESS_PUBLIC = 10;
        public const ACCESS_MEMBERS = 20;
        public const ACCESS_PRIVATE = 40;
        public static function get_page(string $page = ''): string { return ''; }
        public static function get_peepso_uri(): string { return ''; }
        public static function get_asset(string $asset): string { return ''; }
        /**
         * @param mixed $default
         * @return mixed
         */
        public static function get_option(string $key, $default = null) { return $default; }
        /** @return mixed */
        public static function get_option_new(string $key) { return null; }
    }
}

if (!class_exists('PeepSoUserSearch')) {
    class PeepSoUserSearch {
        /** @var list<int|numeric-string> */
        public array $results = [];
        public int $total = 0;
        /**
         * @param array<string, mixed> $args
         */
        public function __construct(array $args = [], ?int $user_id = null, string $search = '') {}
    }
}

if (!class_exists('PeepSoProfileFields')) {
    class PeepSoProfileFields {
        /** @var array<string, mixed>|null */
        public $profile_fields_stats = null;
        /** @var array<string, PeepSoField> */
        public $profile_fields = [];
        /**
         * @param array<string, mixed> $args
         * @return array<string, PeepSoField>
         */
        public function load_fields(array $args = [], ?string $context = null): array { return []; }
        /** @return array<string, PeepSoField> */
        public function get_fields(): array { return []; }
    }
}

if (!class_exists('PeepSoField')) {
    class PeepSoField {
        public const USER_META_FIELD_KEY = 'peepso_user_field_';
        public int $id = 0;
        public string $key = '';
        public string $title = '';
        public string $desc = '';
        public int $published = 0;
        /** @var mixed */
        public $value = null;
        public int $acc = 20;
        public int $can_acc = 0;
        public ?string $data_type = null;
        /** @var \stdClass */
        public $meta;
        /** @var array<string, mixed> */
        public array $validation_errors = [];

        public function __construct() {
            $this->meta = new \stdClass();
        }

        /**
         * @param int|string $id
         * @return static|null
         */
        public static function get_field_by_id($id, ?int $user_id = null): ?self { return null; }
        public static function user_meta_key_add(string $key): string { return self::USER_META_FIELD_KEY . $key; }
        public static function user_meta_key_trim(string $key): string { return str_ireplace(self::USER_META_FIELD_KEY, '', $key); }

        /** @param mixed $value */
        public function save($value, bool $validate_only = false): bool { return true; }
        public function save_acc(int $acc): bool { return true; }
        /**
         * @param string|null $key1
         * @param string|null $key2
         * @return mixed
         */
        public function prop(string $key, $key1 = null, $key2 = null) { return null; }
    }
}

if (!class_exists('PeepSoUser')) {
    class PeepSoUser {
        public int $id = 0;
        public PeepSoProfileFields $profile_fields;
        public function __construct(int $id = 0) {
            $this->id = $id;
            $this->profile_fields = new PeepSoProfileFields();
        }
        public static function get_instance(int $id = 0): self { return new self($id); }
        public function get_id(): int { return $this->id; }
        public function get_avatar(string $suffix = 'full'): string { return ''; }
        public function get_fullname(): string { return ''; }
        public function get_profileurl(): string { return ''; }
        public function has_cover(): bool { return false; }
        public function get_cover(int $size = 0): string { return ''; }
        public function move_avatar_file(string $src_file, bool $delete = false): void {}
        public function finalize_move_avatar_file(bool $add_to_stream = true): void {}
        public function delete_avatar(): void {}
        public function move_cover_file(string $src_file, bool $add_to_stream = true): void {}
        public function delete_cover_photo(string $cover_hash = ''): bool { return false; }
        /** @return int|string */
        public function get_profile_accessibility() { return 10; }
        /** @return int|string */
        public function get_profile_post_accessibility() { return 20; }
        /** @return array<string, mixed> */
        public function get_peepso_user(): array { return []; }
        /** @param array<string, mixed> $data */
        public function update_peepso_user(array $data): bool { return true; }
    }
}

if (!class_exists('PeepSoUrlSegments')) {
    class PeepSoUrlSegments {
        public static function get_instance(): self { return new self(); }
        public function get(int $index): ?string { return null; }
        public static function get_view_id(int $userId = 0): int { return 0; }
    }
}

if (!class_exists('PeepSoPagesShortcode')) {
    class PeepSoPagesShortcode {
        /** @var int|false */
        public $page_id = false;
        public static function get_instance(): self { return new self(); }
    }
}

if (!class_exists('PeepSoPage')) {
    class PeepSoPage {
        public int $id = 0;
        public function __construct(string $slug = '') {}
    }
}

if (!class_exists('PeepSoPageCategories')) {
    class PeepSoPageCategories {
        /**
         * @return array<int, object>
         */
        public static function get_page_categories(int $page_id): array { return []; }
    }
}

// ── PeepSo function stubs ───────────────────────────────────────────────

if (!function_exists('peepso_get_option')) {
    /** @param mixed $default @return mixed */
    function peepso_get_option(string $key, $default = null) { return $default; }
}

if (!class_exists('PeepSoProfileShortcode')) {
    class PeepSoProfileShortcode {
        public static function get_instance(): self { return new self(); }
        public function get_view_user_id(): int { return 0; }
    }
}

if (!class_exists('PeepSoTemplate')) {
    class PeepSoTemplate {
        public static function exec_template(string $template, mixed ...$args): void {}
    }
}

// ── ActionScheduler function stubs ──────────────────────────────────────

if (!function_exists('as_has_scheduled_action')) {
    /**
     * @param array<mixed>|null $args
     */
    function as_has_scheduled_action(string $hook, ?array $args = null, string $group = ''): bool { return false; }
}

if (!function_exists('as_enqueue_async_action')) {
    /**
     * @param array<mixed> $args
     */
    function as_enqueue_async_action(string $hook, array $args = [], string $group = ''): int { return 0; }
}