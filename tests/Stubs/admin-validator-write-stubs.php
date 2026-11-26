<?php

namespace {
    if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
    final class WP_REST_Request
    {
        public array $url = [];
        public array $query = [];
        public mixed $json = [];
        public function __construct(string $method = 'POST') {}
        public function get_url_params(): array { return $this->url; }
        public function get_json_params(): mixed { return $this->json; }
        public function get_param(string $key): mixed { return $this->query[$key] ?? $this->json[$key] ?? $this->url[$key] ?? null; }
        public function set_header(string $key, string $value): void {}
        public function set_body(string $body): void { $this->json = json_decode($body, true, 512, JSON_THROW_ON_ERROR); }
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
    final class WP_REST_Server { public const CREATABLE = 'POST'; }
    function get_current_user_id(): int { return $GLOBALS['validator_write']['actor']; }
    function register_rest_route(string $namespace, string $route, array $options): void { $GLOBALS['validator_write']['routes'][$namespace . $route] = $options; }
}

namespace BCC\Trust\Core\Support {
    final class BearerAuth
    {
        public static function requireAdmin(): bool { return $GLOBALS['validator_write']['admin']; }
    }
    final class ApiResponse
    {
        public static function ok(array $data, int $status = 200): \WP_REST_Response { return new \WP_REST_Response(['success' => true, 'data' => $data], $status); }
        public static function error(string $code, string $message, int $status, array $data = []): \WP_REST_Response { return new \WP_REST_Response(['success' => false, 'error' => compact('code', 'message', 'data')], $status); }
    }
}

namespace BCC\Trust\Core\Services {
    final class AdminValidatorReadService
    {
        public static function relationships(int $id): ?array
        {
            $state = &$GLOBALS['validator_write'];
            $state['read_calls'][] = $id;
            if ($state['read_failure'] || ($state['fail_after_write'] && count($state['writes']) > 0)) {
                throw new \BCC\Trust\Onchain\Repositories\RepositoryReadFailure(self::class, __FUNCTION__, 'private repository detail');
            }
            return $state['projection'];
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {
    final class ValidatorStepUpRepository
    {
        public const MAX_ATTEMPTS = 5;
        public static function find(string $id, int $actor): ?object { return $GLOBALS['validator_write']['step']; }
    }
}

namespace BCC\Trust\Onchain\Services {
    final class ValidatorStepUpService
    {
        public static function start(\WP_REST_Request $request, int $actor, string $action, int $page, int $target, string $role): array
        {
            $GLOBALS['validator_write']['starts'][] = [$request->get_param('current_password'), $request->get_param('wallet_address'), $actor, $action, $page, $target, $role];
            return $GLOBALS['validator_write']['step_result'];
        }
        public static function resend(int $actor, string $id): array
        {
            $GLOBALS['validator_write']['resends'][] = [$actor, $id];
            return $GLOBALS['validator_write']['step_result'];
        }
    }
    final class ValidatorRelationshipService
    {
        public static function linkOwnerByStaff(int $actor, int $page, int $target, string $type, string $note, ?string $ref, string $id, ?string $code): array
        {
            $GLOBALS['validator_write']['writes'][] = ['link', $actor, $page, $target, $type, $note, $ref, $id, $code];
            return $GLOBALS['validator_write']['write_result'];
        }
        public static function revokeOwnerByStaff(int $actor, int $page, int $link, string $reason, string $note, string $id, ?string $code): array
        {
            $GLOBALS['validator_write']['writes'][] = ['revoke', $actor, $page, $link, $reason, $note, $id, $code];
            return $GLOBALS['validator_write']['write_result'];
        }
        public static function cancelLinkByStaff(int $actor, int $page, int $link, string $note, string $id, ?string $code): array
        {
            $GLOBALS['validator_write']['writes'][] = ['cancel', $actor, $page, $link, $note, $id, $code];
            return $GLOBALS['validator_write']['write_result'];
        }
    }
}
