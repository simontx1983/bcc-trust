<?php
/**
 * Stubs for SecretPageSingleCardGateTest and SecretPageEntityReadsTest ONLY.
 *
 * ⚠ Required from the test's setUp(), i.e. inside its separate process —
 * NEVER at file level. File-level code in a test class file runs in the
 * PARENT process during discovery, so a stub defined there becomes THE
 * class for the whole unit run.
 *
 * The page post, its meta and the admin flag come from
 * `$GLOBALS['__bcc_spc']`. Every get_post_meta() read is recorded, which is
 * how the test tells "the secret-page gate stopped the card" apart from
 * "the card went on and stopped later".
 */

declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', sys_get_temp_dir() . '/');
    }

    if (!class_exists('WP_Post')) {
        final class WP_Post
        {
            public int $ID = 0;
            public string $post_type = '';
            public string $post_status = '';
            public string $post_name = '';
            public int $post_author = 0;
        }
    }
    if (!function_exists('get_post')) {
        function get_post($id = null)
        {
            return $GLOBALS['__bcc_spc']['posts'][(int) $id] ?? null;
        }
    }
    if (!function_exists('get_post_meta')) {
        function get_post_meta(int $id, string $key = '', bool $single = false)
        {
            $GLOBALS['__bcc_spc']['meta_reads'][] = [$id, $key];
            return $GLOBALS['__bcc_spc']['meta'][$id][$key] ?? '';
        }
    }

    /**
     * Answers PeepSoPagePrivacy's two reads from `secret` / `statuses` (for
     * every page: a test has one page), and can fail the privacy read. Any
     * other statement (votes, attestations, SHOW TABLES) finds nothing.
     * Every statement is recorded with its arguments filled in.
     */
    final class BccSecretPageCardFakeWpdb
    {
        public string $prefix   = 'wp_';
        public string $posts    = 'wp_posts';
        public string $postmeta = 'wp_postmeta';
        public string $last_error = '';
        /** @var mixed */
        public $last_result = [];

        public bool $secret = false;
        /** @var list<string> */
        public array $statuses = [];
        public bool $failPrivacy = false;

        /** @var list<string> */
        public array $queries = [];

        /** @param mixed ...$args */
        public function prepare(string $sql, ...$args): string
        {
            if (count($args) === 1 && is_array($args[0])) {
                $args = $args[0];
            }
            $sql = str_replace(['%d', '%s', '%f'], ['%d', "'%s'", '%F'], $sql);
            return vsprintf($sql, $args);
        }

        /** @return int|false */
        public function query(string $sql)
        {
            $this->queries[] = $sql;
            $this->last_error = '';

            if (str_contains($sql, 'peepso_page_members')) {
                $this->last_result = array_map(static fn (string $s): object => (object) ['pm_user_status' => $s], $this->statuses);
            } elseif (str_contains($sql, 'bcc_page_priv')) {
                if ($this->failPrivacy) {
                    $this->last_error = 'MySQL server has gone away';
                    return false;
                }
                $this->last_result = $this->secret ? [(object) ['1' => '1']] : [];
            } else {
                $this->last_result = [];
            }
            return count($this->last_result);
        }

        public function get_var(?string $sql = null): ?string
        {
            if ($sql !== null) {
                $this->queries[] = $sql;
                return null;
            }
            $row = is_array($this->last_result) ? ($this->last_result[0] ?? null) : null;
            if (!is_object($row)) {
                return null;
            }
            $values = array_values(get_object_vars($row));
            return isset($values[0]) ? (string) $values[0] : null;
        }

        /** @return list<string> */
        public function get_col(?string $sql = null): array
        {
            if ($sql !== null) {
                $this->queries[] = $sql;
                return [];
            }
            $out = [];
            foreach (is_array($this->last_result) ? $this->last_result : [] as $row) {
                $values = array_values(get_object_vars($row));
                $out[]  = (string) ($values[0] ?? '');
            }
            return $out;
        }

        /** @return array<int, object> */
        public function get_results(string $sql, string $output = 'OBJECT'): array
        {
            $this->queries[] = $sql;
            return [];
        }
    }

    // ── for the reviews / vouches endpoints ─────────────────────────────

    // TableRegistry::exists() (VoteRepository's hidden-review clause).
    if (!defined('HOUR_IN_SECONDS')) {
        define('HOUR_IN_SECONDS', 3600);
    }
    if (!function_exists('wp_cache_get')) {
        /** @return mixed */
        function wp_cache_get(string $key, string $group = '')
        {
            return false;
        }
    }
    if (!function_exists('wp_cache_set')) {
        /** @param mixed $value */
        function wp_cache_set(string $key, $value, string $group = '', int $ttl = 0): bool
        {
            return true;
        }
    }

    // ProfileAccess::canViewProfile() for the user_profile cases: a public
    // profile, so the request reaches the code under test.
    if (!class_exists('PeepSoUser')) {
        final class PeepSoUser
        {
            public static function get_instance(int $id = 0): self
            {
                return new self();
            }

            public function get_profile_accessibility(): int
            {
                return 10;
            }

            public static function is_accessible_static(int $level, int $ownerId): bool
            {
                return true;
            }
        }
    }

    if (!function_exists('get_current_user_id')) {
        function get_current_user_id(): int
        {
            return (int) ($GLOBALS['__bcc_spc']['viewer'] ?? 0);
        }
    }

    if (!class_exists('WP_REST_Request')) {
        class WP_REST_Request
        {
            /** @param array<string, mixed> $params */
            public function __construct(private array $params = [])
            {
            }

            /** @return mixed */
            public function get_param(string $key)
            {
                return $this->params[$key] ?? null;
            }
        }
    }

    if (!class_exists('WP_REST_Response')) {
        class WP_REST_Response
        {
            /** @var array<string, string> */
            private array $headers = [];

            /** @param mixed $data */
            public function __construct(private $data = null, private int $status = 200)
            {
            }

            /** @return mixed */
            public function get_data()
            {
                return $this->data;
            }

            public function get_status(): int
            {
                return $this->status;
            }

            public function header(string $key, string $value, bool $replace = true): void
            {
                $this->headers[$key] = $value;
            }

            /** @return array<string, string> */
            public function get_headers(): array
            {
                return $this->headers;
            }
        }
    }
}

namespace BCC\Trust\Core\Support {
    // ReactionTypeRegistry (inside ApiResponse::ok) reads one option.
    if (!function_exists(__NAMESPACE__ . '\\get_option')) {
        /**
         * @param mixed $default
         * @return mixed
         */
        function get_option(string $key, $default = false)
        {
            return $default;
        }
    }
}

namespace BCC\Core\Permissions {
    // bcc-core is not autoloaded in unit; ProfileAccess::viewerIsExemptFromVisibility()
    // asks this class whether the current user is a site admin.
    if (!class_exists(__NAMESPACE__ . '\\Permissions', false)) {
        final class Permissions
        {
            public static function is_admin(): bool
            {
                return (bool) ($GLOBALS['__bcc_spc']['admin'] ?? false);
            }

            /** ProfileAccess's suspension check: nobody is suspended here. */
            public static function suspensionState(int $userId): ?bool
            {
                return false;
            }
        }
    }
}
