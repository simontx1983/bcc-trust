<?php
/**
 * MyPrivacyEndpoint — handles /bcc/v1/me/privacy routes.
 *
 * Routes registered:
 *   - GET   /me/privacy   — read all 8 §K2 + discovery flags
 *   - PATCH /me/privacy   — partial-update; only listed keys change
 *
 * The seven §K2 flags also surface inside the user view-model
 * (`/users/:handle.privacy`) so non-self viewers see hidden tabs render
 * as private. The eighth flag — `discovery_optout` — is owner-only and
 * lives ONLY behind /me/privacy; it controls whether the user appears
 * in PeepSo's user search (hooked via PrivacySettings::registerSearchFilter).
 *
 * Storage: `wp_usermeta.bcc_privacy_*` (boolean-truthy "1"/"0"). All
 * read/write goes through PrivacySettings; the endpoint is pure glue.
 *
 * Auth: required. Self-only — there is no admin-overriding-someone-else
 * surface in V1. Errors return the canonical envelope.
 *
 * Cache: `no-store`. Both directions mutate or reflect a per-viewer
 * state that has no shared-cache value.
 *
 * @package BCC\Trust\Core\REST
 * @since V1 (2026-04, §K2 privacy UI)
 */

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\PrivacySettings;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class MyPrivacyEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/privacy',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'get'],
                'permission_callback' => '__return_true',
            ]
        );

        // PATCH/POST/PUT all map to EDITABLE. The body is a partial
        // bag: every key is optional, missing keys leave that flag
        // untouched. Per-arg booleans are validated below.
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/privacy',
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$instance, 'patch'],
                'permission_callback' => '__return_true',
                'args'                => self::patchArgsSchema(),
            ]
        );
    }

    public function get(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $resp = ApiResponse::ok(PrivacySettings::readAll($userId));
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }

    public function patch(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        // Filter to known keys + cast to bool. WP's REST schema already
        // type-coerces (rest_sanitize_value_from_schema) but we re-check
        // here so a malformed direct call still produces a clean result.
        $partial = [];
        foreach (PrivacySettings::ALL_KEYS as $key) {
            $raw = $request->get_param($key);
            if ($raw === null) {
                continue;
            }
            $partial[$key] = filter_var($raw, FILTER_VALIDATE_BOOLEAN);
        }

        if ($partial === []) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'No privacy keys provided.',
                422
            );
        }

        PrivacySettings::writePartial($userId, $partial);

        $resp = ApiResponse::ok(PrivacySettings::readAll($userId));
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }

    /**
     * REST args schema — every key optional boolean. Lets WP coerce
     * "true"/"false" string bodies cleanly.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function patchArgsSchema(): array
    {
        $schema = [];
        foreach (PrivacySettings::ALL_KEYS as $key) {
            $schema[$key] = [
                'required' => false,
                'type'     => 'boolean',
            ];
        }
        return $schema;
    }
}
