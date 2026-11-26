<?php

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Services\AdminValidatorReadService as Reads;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\BearerAuth;
use BCC\Trust\Core\Security\RateLimiter;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/** Staff workspace read routes. All reads are private and uncached. */
final class AdminValidatorReadEndpoint
{
    public static function register(): void
    {
        $instance = new self();

        register_rest_route('bcc/v1', '/admin/validator-pages', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$instance, 'directory'],
            'permission_callback' => [BearerAuth::class, 'requireAdmin'],
        ]);

        register_rest_route('bcc/v1', '/admin/validator-pages/(?P<pageId>\d+)/relationships', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$instance, 'relationships'],
            'permission_callback' => [BearerAuth::class, 'requireAdmin'],
        ]);

        register_rest_route('bcc/v1', '/admin/validator-pages/(?P<pageId>\d+)/history', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$instance, 'history'],
            'permission_callback' => [BearerAuth::class, 'requireAdmin'],
        ]);

        register_rest_route('bcc/v1', '/admin/validator-relationships/history', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$instance, 'history'],
            'permission_callback' => [BearerAuth::class, 'requireAdmin'],
        ]);

        register_rest_route('bcc/v1', '/admin/validator-relationships/health', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$instance, 'health'],
            'permission_callback' => [BearerAuth::class, 'requireAdmin'],
        ]);

        register_rest_route('bcc/v1', '/admin/users/search', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$instance, 'searchUsers'],
            'permission_callback' => [BearerAuth::class, 'requireAdmin'],
        ]);
    }

    public function directory(WP_REST_Request $request): WP_REST_Response
    {
        if (($gate = self::adminGate()) !== null) {
            return $gate;
        }
        $params = $request->get_query_params();
        $limit = self::positive($params['limit'] ?? 25);
        $after = isset($params['cursor']) && $params['cursor'] !== '' ? self::positive($params['cursor']) : 0;
        $chain = $params['chain'] ?? '';
        $state = $params['state'] ?? '';
        $query = $params['q'] ?? '';
        if ($limit === null || $limit > 100 || $after === null || !is_string($chain) || !is_string($state) || !is_string($query)
            || ($state !== '' && !in_array($state, Reads::STATES, true)) || mb_strlen($query) > 200 || !mb_check_encoding($query, 'UTF-8')
            || ($chain !== '' && preg_match('/^[a-z0-9-]{1,100}$/D', $chain) !== 1)) {
            return self::invalid();
        }
        try {
            return self::respond(ApiResponse::ok(Reads::directory($chain, $state, trim($query), $after, $limit)));
        } catch (RepositoryReadFailure) {
            return self::unavailable();
        }
    }

    public function relationships(WP_REST_Request $request): WP_REST_Response
    {
        if (($gate = self::adminGate()) !== null) {
            return $gate;
        }
        $id = self::pageId($request);
        if ($id === null) {
            return self::invalid();
        }
        try {
            $result = Reads::relationships($id);
            return self::respond($result === null ? ApiResponse::error('bcc_not_found', 'Validator page not found.', 404) : ApiResponse::ok($result));
        } catch (RepositoryReadFailure) {
            return self::unavailable();
        }
    }

    public function history(WP_REST_Request $request): WP_REST_Response
    {
        if (($gate = self::adminGate()) !== null) {
            return $gate;
        }
        $params = $request->get_query_params();
        $url = $request->get_url_params();
        $rawPage = $url['pageId'] ?? $params['page_id'] ?? null;
        $page = $rawPage === null || $rawPage === '' ? null : self::positive($rawPage);
        $rawUser = $params['user_id'] ?? null;
        $user = $rawUser === null || $rawUser === '' ? null : self::positive($rawUser);
        $rawCursor = $params['cursor'] ?? null;
        $before = $rawCursor === null || $rawCursor === '' ? null : self::positive($rawCursor);
        $limit = self::positive($params['limit'] ?? 25);
        if (($rawPage !== null && $rawPage !== '' && $page === null) || ($rawUser !== null && $rawUser !== '' && $user === null)
            || ($rawCursor !== null && $rawCursor !== '' && $before === null) || $limit === null || $limit > 100) {
            return self::invalid();
        }
        try {
            if (isset($url['pageId']) && $page !== null && Reads::relationships($page) === null) {
                return self::respond(ApiResponse::error('bcc_not_found', 'Validator page not found.', 404));
            }
            return self::respond(ApiResponse::ok(Reads::history($page, $user, $before, $limit)));
        } catch (RepositoryReadFailure) {
            return self::unavailable();
        }
    }

    public function health(WP_REST_Request $request): WP_REST_Response
    {
        if (($gate = self::adminGate()) !== null) {
            return $gate;
        }
        try {
            $result = Reads::health();
            return $result === null ? self::unavailable() : self::respond(ApiResponse::ok($result));
        } catch (RepositoryReadFailure) {
            return self::unavailable();
        }
    }

    public function searchUsers(WP_REST_Request $request): WP_REST_Response
    {
        if (($gate = self::adminGate()) !== null) {
            return $gate;
        }
        // A member's email/login search belongs in JSON, never an access-log URL.
        $body = $request->get_json_params();
        $query = is_array($body) ? ($body['q'] ?? null) : null;
        if (!is_string($query) || !mb_check_encoding($query, 'UTF-8') || mb_strlen(trim($query)) < 1 || mb_strlen($query) > 200) {
            return self::invalid();
        }
        if (!RateLimiter::allowByKey('validator_admin_user_search:' . get_current_user_id(), 30, 60)) {
            $response = self::respond(ApiResponse::error('bcc_rate_limited', 'Wait before searching again.', 429, ['retry_after' => 60]));
            $response->header('Retry-After', '60');
            return $response;
        }
        try {
            return self::respond(ApiResponse::ok(Reads::searchUsers(trim($query))));
        } catch (RepositoryReadFailure) {
            return self::unavailable();
        }
    }

    private static function pageId(WP_REST_Request $request): ?int
    {
        return self::positive($request->get_url_params()['pageId'] ?? null);
    }

    private static function positive(mixed $raw): ?int
    {
        if (is_int($raw)) {
            return $raw > 0 ? $raw : null;
        }
        if (!is_string($raw) || preg_match('/^[1-9][0-9]*$/D', $raw) !== 1) {
            return null;
        }
        $value = (int) $raw;
        return $value > 0 && (string) $value === $raw ? $value : null;
    }

    private static function adminGate(): ?WP_REST_Response
    {
        if (get_current_user_id() <= 0) {
            return self::respond(ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401));
        }
        return BearerAuth::requireAdmin() ? null : self::respond(ApiResponse::error('bcc_forbidden', 'Admin access required.', 403));
    }

    private static function respond(WP_REST_Response $response): WP_REST_Response
    {
        $response->header('Cache-Control', 'private, no-store');
        return $response;
    }

    private static function invalid(): WP_REST_Response
    {
        return self::respond(ApiResponse::error('bcc_invalid_request', 'Check the request fields.', 422));
    }

    private static function unavailable(): WP_REST_Response
    {
        return self::respond(ApiResponse::error('bcc_unavailable', 'The requested information could not be read. Try again.', 503));
    }
}
