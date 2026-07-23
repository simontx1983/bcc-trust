<?php
/**
 * Stubs for MyGroupsPostPolicyRouteTest — exercises the real
 * MyGroupsEndpoint::postPostPolicy handler (auth → throttle → service →
 * envelope) end to end, WordPress-free.
 *
 * Builds on public-all-stubs.php (fixture + GroupsService collaborators)
 * and adds: get_current_user_id (REST namespace), Throttle, a Plugin
 * double returning the real GroupsService, WP_REST_Request/Response, and a
 * ReactionTypeRegistry double (ApiResponse::ok reads it).
 *
 * Subprocess-only; guarded.
 */

declare(strict_types=1);

namespace {
    require_once __DIR__ . '/public-all-stubs.php';

    if (!class_exists('WP_REST_Request', false)) {
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

    if (!class_exists('WP_REST_Response', false)) {
        class WP_REST_Response
        {
            /** @var mixed */
            public $data;
            public int $status;

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

            public function header(string $key, string $value, bool $replace = true): void
            {
            }
        }
    }
}

namespace BCC\Trust\Core\REST {
    if (!function_exists('BCC\\Trust\\Core\\REST\\get_current_user_id')) {
        function get_current_user_id(): int
        {
            return (int) ($GLOBALS['__bcc_puball_fixture']['current_user'] ?? 0);
        }
    }
}

namespace BCC\Trust\Core {
    if (!class_exists(Plugin::class, false)) {
        final class Plugin
        {
            private static ?Plugin $inst = null;
            private ?\BCC\Trust\Core\Services\GroupsService $gs = null;

            public static function instance(): self
            {
                return self::$inst ??= new self();
            }

            public function groupsService(): \BCC\Trust\Core\Services\GroupsService
            {
                return $this->gs ??= new \BCC\Trust\Core\Services\GroupsService(
                    new \BCC\Trust\Core\Repositories\ReputationRepository(),
                    new \BCC\Trust\Core\Services\GroupContextResolver()
                );
            }
        }
    }
}

namespace BCC\Trust\Core\Support {
    // ApiResponse::ok() reads this; keep it DB-free.
    if (!class_exists(ReactionTypeRegistry::class, false)) {
        class ReactionTypeRegistry
        {
            /** @return array<string, int|null> */
            public static function all(): array
            {
                return [];
            }
        }
    }
}

namespace BCC\Core\Security {
    if (!class_exists(Throttle::class, false)) {
        class Throttle
        {
            public static function allow(string $key, int $limit, int $window, ?string $bucketKey = null): bool
            {
                return empty($GLOBALS['__bcc_puball_fixture']['throttled']);
            }
        }
    }
}
