<?php

namespace {
    if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
    final class WP_REST_Request
    {
        public array $url = [];
        public array $query = [];
        public mixed $json = [];
        public function get_url_params(): array { return $this->url; }
        public function get_query_params(): array { return $this->query; }
        public function get_json_params(): mixed { return $this->json; }
    }
    final class WP_REST_Response
    {
        public array $headers = [];
        public function __construct(public array $data, public int $status = 200) {}
        public function header(string $name, string $value): void { $this->headers[$name] = $value; }
        public function get_data(): array { return $this->data; }
        public function get_status(): int { return $this->status; }
        public function get_headers(): array { return $this->headers; }
    }
    final class WP_REST_Server { public const CREATABLE = 'POST'; public const READABLE = 'GET'; }
    function get_current_user_id(): int { return $GLOBALS['validator_read_endpoint']['actor']; }
    function register_rest_route(string $namespace, string $route, array $options): void { $GLOBALS['validator_read_endpoint']['routes'][$namespace . $route] = $options; }
}

namespace BCC\Trust\Core\Support {
    final class BearerAuth
    {
        public static function requireAdmin(): bool { return $GLOBALS['validator_read_endpoint']['admin']; }
    }
    final class ApiResponse
    {
        public static function ok(array $data, int $status = 200): \WP_REST_Response { return new \WP_REST_Response(['success' => true, 'data' => $data], $status); }
        public static function error(string $code, string $message, int $status, array $data = []): \WP_REST_Response { return new \WP_REST_Response(['success' => false, 'error' => compact('code', 'message', 'data')], $status); }
    }
}

namespace BCC\Trust\Core\Security {
    final class RateLimiter
    {
        public static function allowByKey(string $key, int $limit, int $window): bool
        {
            $GLOBALS['validator_read_endpoint']['rate_calls'][] = [$key, $limit, $window];
            return $GLOBALS['validator_read_endpoint']['rate_allow'];
        }
    }
}

namespace BCC\Trust\Core\Services {
    final class AdminValidatorReadService
    {
        public const STATES = ['unclaimed', 'wallet_verified', 'staff_verified', 'conflict'];
        public static function directory(string $chain, string $state, string $query, int $after, int $limit): array { return self::read('directory', [$chain, $state, $query, $after, $limit]); }
        public static function relationships(int $page): ?array { return self::read('relationships', [$page]); }
        public static function history(?int $page, ?int $user, ?int $before, int $limit): array { return self::read('history', [$page, $user, $before, $limit]); }
        public static function health(): ?array { return self::read('health', []); }
        public static function searchUsers(string $query): array { return self::read('searchUsers', [$query]); }
        private static function read(string $method, array $args): ?array
        {
            $state = &$GLOBALS['validator_read_endpoint'];
            $state['calls'][] = [$method, $args];
            if ($state['fail']) { throw new \BCC\Trust\Onchain\Repositories\RepositoryReadFailure(self::class, $method, 'private read detail'); }
            return $state['results'][$method];
        }
    }
}
