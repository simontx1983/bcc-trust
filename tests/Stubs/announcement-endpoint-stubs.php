<?php
/**
 * Stubs for the announcement READ routes.
 *
 * ⚠ WordPress core is NOT loadable in this suite, so there is no real
 * `WP_REST_Server` to dispatch through. What this file provides is enough
 * to drive the REAL handlers and the REAL route schema:
 *
 *   - `register_rest_route` captures into `$GLOBALS['__bcc_routes']`, so
 *     the declared paths, methods and `args` can be asserted rather than
 *     assumed;
 *   - a `WP_REST_Request` faithful in the parts the handlers use;
 *   - `get_option` / `get_current_user_id` / `get_post` driven by globals,
 *     so feature-off, anonymous and operator cases are all reachable.
 *
 * The companion test applies each route's OWN `args` schema (enum,
 * minimum/maximum, sanitize_callback, default) before invoking its
 * callback — see `AnnouncementsEndpointTest::dispatch()`. That is a
 * simulation of dispatch driven by the real declaration, not WordPress's
 * dispatcher, and the test says so.
 *
 * ⚠ Guarded with `function_exists` / `class_exists` throughout: several
 * Stubs files declare overlapping doubles and more than one may be
 * required into the same process.
 */

declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', '/tmp/wp/');
    }

    if (!class_exists('WP_REST_Server')) {
        class WP_REST_Server
        {
            public const READABLE  = 'GET';
            public const CREATABLE = 'POST';
            public const EDITABLE  = 'POST, PUT, PATCH';
            public const DELETABLE = 'DELETE';
        }
    }

    if (!class_exists('WP_REST_Request')) {
        class WP_REST_Request
        {
            /** @var array<string, mixed> */
            private array $params;

            /** @param array<string, mixed> $params */
            public function __construct(array $params = [])
            {
                $this->params = $params;
            }

            /** @return mixed */
            public function get_param(string $key)
            {
                return $this->params[$key] ?? null;
            }

            /** @param mixed $value */
            public function set_param(string $key, $value): void
            {
                $this->params[$key] = $value;
            }

            /** @return array<string, mixed> */
            public function get_params(): array
            {
                return $this->params;
            }
        }
    }

    if (!class_exists('WP_REST_Response')) {
        class WP_REST_Response
        {
            /** @var mixed */
            private $data;
            private int $status;
            /** @var array<string, string> */
            private array $headers = [];

            /** @param mixed $data */
            public function __construct($data = null, int $status = 200)
            {
                $this->data   = $data;
                $this->status = $status;
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

            public function header(string $key, string $value): void
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

    if (!function_exists('get_option')) {
        /** @param mixed $default @return mixed */
        function get_option(string $key, $default = false)
        {
            $store = $GLOBALS['__bcc_options'] ?? [];

            return array_key_exists($key, $store) ? $store[$key] : $default;
        }
    }

    if (!function_exists('get_current_user_id')) {
        function get_current_user_id(): int
        {
            return (int) ($GLOBALS['__bcc_current_user'] ?? 0);
        }
    }

    if (!function_exists('get_post')) {
        /** @return object|null */
        function get_post($id = null)
        {
            $map = $GLOBALS['__bcc_posts'] ?? [];
            $id  = (int) $id;

            return $map[$id] ?? null;
        }
    }

    if (!function_exists('sanitize_text_field')) {
        function sanitize_text_field(string $str): string
        {
            return trim(strip_tags($str));
        }
    }

    if (!function_exists('absint')) {
        /** @param mixed $v */
        function absint($v): int
        {
            return abs((int) $v);
        }
    }

    /**
     * A `$wpdb` double whose reads FAIL the way the driver fails.
     *
     * ⚠⚠ THIS EXISTS BECAUSE A MUTATION SURVIVED. The 503 arms were covered
     * only by a source-shape test — it counted `catch (RepositoryException)`
     * ×4, `self::unavailable()` ×4 and one `'bcc_unavailable'`. Inserting an
     * early `return ApiResponse::ok(['items' => []])` INSIDE a catch block
     * leaves all three counts intact, so the mutation made the 503
     * unreachable and the suite stayed green. Counting the pieces is not the
     * same as reaching the answer.
     *
     * It fails the way the real repository detects failure: `last_error`
     * non-empty after the call, which is what `runPage()`/`findOne()` check.
     * So the throw comes from the REAL repository, not from a double of it.
     */
    if (!class_exists('BccFailingWpdb')) {
        class BccFailingWpdb
        {
            public string $posts    = 'wp_posts';
            public string $postmeta = 'wp_postmeta';
            public string $prefix   = 'wp_';
            public string $last_error = '';

            /** @param mixed ...$args */
            public function prepare(string $query, ...$args): string
            {
                return $query;
            }

            /** @return null */
            public function get_results(string $query = '', $output = null)
            {
                $this->last_error = 'injected driver failure';
                return null;
            }

            /** @return null */
            public function get_row(string $query = '', $output = null)
            {
                $this->last_error = 'injected driver failure';
                return null;
            }

            /** @return null */
            public function get_var(string $query = '')
            {
                $this->last_error = 'injected driver failure';
                return null;
            }
        }
    }
}

namespace BCC\Trust\Core\REST {
    // ⚠ The endpoint calls these UNQUALIFIED from inside its namespace, so
    // PHP resolves them here first — which is the seam that lets the suite
    // capture registrations and steer the viewer without WordPress.
    if (!function_exists('BCC\\Trust\\Core\\REST\\register_rest_route')) {
        /** @param array<string, mixed> $args */
        function register_rest_route(string $namespace, string $route, array $args = [], bool $override = false): bool
        {
            $GLOBALS['__bcc_routes'][] = [
                'namespace' => $namespace,
                'route'     => $route,
                'args'      => $args,
            ];

            return true;
        }
    }

    if (!function_exists('BCC\\Trust\\Core\\REST\\get_current_user_id')) {
        function get_current_user_id(): int
        {
            return (int) ($GLOBALS['__bcc_current_user'] ?? 0);
        }
    }

    if (!function_exists('BCC\\Trust\\Core\\REST\\get_post')) {
        /** @return object|null */
        function get_post($id = null)
        {
            $map = $GLOBALS['__bcc_posts'] ?? [];

            return $map[(int) $id] ?? null;
        }
    }
}

namespace BCC\Trust\Core\Announcements {
    if (!function_exists('BCC\\Trust\\Core\\Announcements\\get_option')) {
        /** @param mixed $default @return mixed */
        function get_option(string $key, $default = false)
        {
            $store = $GLOBALS['__bcc_options'] ?? [];

            return array_key_exists($key, $store) ? $store[$key] : $default;
        }
    }
}

// ⚠ NO ReactionTypeRegistry DOUBLE HERE, deliberately.
//
// `ApiResponse::ok()` resolves the reaction-type map through it, and two
// other stub files double it for exactly that reason. This one does not:
// the REAL class only reads `get_option`, which is stubbed above, so it
// returns an empty map on its own — which is a legitimate first-boot
// state and keeps these assertions about `data`, not about reactions.
//
// Declaring a double would SHADOW a real autoloadable class for the whole
// process, since once the name exists the autoloader never loads the real
// file. Avoided where it buys nothing.
