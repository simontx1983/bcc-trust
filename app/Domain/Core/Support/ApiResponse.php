<?php
/**
 * ApiResponse — canonical envelope for all /bcc/v1/* responses.
 *
 * Successful responses:
 *   { "data": <payload>, "_meta": { "version": "v1" } }
 *
 * Error responses:
 *   { "error": { "code": "...", "message": "...", "status": <int> } }
 *
 * Every BCC REST endpoint MUST go through these helpers. The envelope
 * is part of the contract — clients rely on it for cross-endpoint
 * version detection and error parsing. Returning a raw WP_REST_Response
 * or WP_Error from any handler is a contract violation.
 *
 * Cache-Control and other response headers are still set by the
 * caller after construction:
 *
 *     $response = ApiResponse::ok($payload);
 *     $response->header('Cache-Control', 'public, max-age=60');
 *     return $response;
 *
 * @package BCC\Trust\Core\Support
 * @since V1 (2026-04)
 */

namespace BCC\Trust\Core\Support;

use WP_REST_Response;

// ReactionTypeRegistry lives in the same namespace; no use needed.

if (!defined('ABSPATH')) {
    exit;
}

final class ApiResponse
{
    public const VERSION = 'v1';

    /**
     * Wrap a successful payload in the canonical envelope.
     *
     * `_meta` carries the contract version + the resolved reaction
     * type IDs (§D5) so the frontend can reference reactions by kind
     * without hardcoding numeric ids. The reaction_types map is
     * emitted on every success response — small (3 ints) and the
     * frontend caches after first read.
     *
     * @param mixed $data
     */
    public static function ok($data, int $status = 200): WP_REST_Response
    {
        return new WP_REST_Response([
            'data'  => $data,
            '_meta' => [
                'version'        => self::VERSION,
                'reaction_types' => self::resolveReactionTypes(),
            ],
        ], $status);
    }

    /**
     * Pull the resolved reaction-id map from the registry, dropping
     * any null entries (kinds the seeder hasn't reached yet — first
     * boot before tables.php fires, or PeepSo not installed).
     *
     * @return array<string, int>
     */
    private static function resolveReactionTypes(): array
    {
        $out = [];
        foreach (ReactionTypeRegistry::all() as $kind => $id) {
            if ($id !== null) {
                $out[$kind] = $id;
            }
        }
        return $out;
    }

    /**
     * Standardized error response. Always emits the
     * `{error: {code, message, status}, _meta: {version}}` shape —
     * the HTTP status code matches the body's status field.
     *
     * The `_meta` envelope rides errors as well as successes so that
     * client-side response parsers can read `_meta.version` uniformly
     * regardless of outcome.
     */
    public static function error(string $code, string $message, int $status): WP_REST_Response
    {
        return new WP_REST_Response([
            'error' => [
                'code'    => $code,
                'message' => $message,
                'status'  => $status,
            ],
            '_meta' => ['version' => self::VERSION],
        ], $status);
    }
}
