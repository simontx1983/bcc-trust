<?php
/**
 * Stubs for HallsListCacheHeadersTest (§F-27).
 *
 * ⚠⚠ SUBPROCESS-ONLY. This declares `\BCC\Trust\Core\Plugin` and
 * `\WP_REST_Request` / `\WP_REST_Response`, and shadows
 * `get_current_user_id()` inside `BCC\Trust\Core\REST`. A class
 * declaration cannot be undone and the first one wins for the rest of the
 * process — the failure mode that once hid 93 broken sibling tests here.
 * The consuming test is `#[RunTestsInSeparateProcesses]` +
 * `#[PreserveGlobalState(false)]`.
 *
 * The halls service is faked only far enough to return a payload that
 * CARRIES per-viewer state, because that is the premise of the bug: the
 * row shape is what makes a public cache a leak. The real
 * `HallsService::getHalls()` and its SQL are not under test here.
 *
 * Fixture ($GLOBALS['__bcc_halls_cache']):
 *   viewer_id => int   what get_current_user_id() returns
 *   calls     => list<int>  viewer ids the service was asked about
 */

declare(strict_types=1);

namespace {
    /** @return array{viewer_id: int, calls: list<int>} */
    function bcc_halls_cache_fixture(): array
    {
        $f = $GLOBALS['__bcc_halls_cache'] ?? [];

        return [
            'viewer_id' => (int) ($f['viewer_id'] ?? 0),
            'calls'     => is_array($f['calls'] ?? null) ? $f['calls'] : [],
        ];
    }

    if (!class_exists('WP_REST_Request', false)) {
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
        }
    }

    if (!class_exists('WP_REST_Response', false)) {
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
}

namespace BCC\Trust\Core {
    final class Plugin
    {
        private static ?self $instance = null;

        public static function instance(): self
        {
            return self::$instance ??= new self();
        }

        public function hallsService(): object
        {
            return new class {
                /**
                 * Shaped like the real payload in the one way that
                 * matters: each row carries `viewer_membership`.
                 *
                 * @return array<string, mixed>
                 */
                public function getHalls(int $viewerId, int $page, int $pageSize, ?string $chain): array
                {
                    unset($page, $pageSize, $chain);
                    $GLOBALS['__bcc_halls_cache']['calls'][] = $viewerId;

                    return [
                        'items' => [[
                            'id'                => 1138,
                            'slug'              => 'cosmos-hall',
                            'viewer_membership' => $viewerId > 0
                                ? ['is_member' => true, 'is_primary' => true, 'joined_at' => '2026-11-01 10:00:00']
                                : null,
                        ]],
                    ];
                }
            };
        }
    }
}

namespace BCC\Trust\Core\REST {
    if (!\function_exists('BCC\Trust\Core\REST\get_current_user_id')) {
        function get_current_user_id(): int
        {
            return \bcc_halls_cache_fixture()['viewer_id'];
        }
    }
}

namespace BCC\Trust\Core\Support {
    // ApiResponse::resolveReactionTypes() consults the registry through
    // this filter; a pass-through keeps the envelope real without pulling
    // the reaction registry into a cache-header test.
    if (!\function_exists('BCC\Trust\Core\Support\apply_filters')) {
        /**
         * @param mixed $value
         * @param mixed ...$args
         * @return mixed
         */
        function apply_filters(string $hook, $value, ...$args)
        {
            unset($hook, $args);

            return $value;
        }
    }

    // ApiResponse's envelope reaches ReactionTypeRegistry::all(), which
    // reads an option. Empty string = "nothing configured", which is the
    // registry's own documented default path, so the envelope builds
    // without this test pretending to know anything about reactions.
    if (!\function_exists('BCC\Trust\Core\Support\get_option')) {
        /**
         * @param mixed $default
         * @return mixed
         */
        function get_option(string $key, $default = false)
        {
            unset($key);

            return $default;
        }
    }
}
