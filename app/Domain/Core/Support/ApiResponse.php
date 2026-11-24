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
     * `{error: {code, message, status, data?}, _meta: {version}}` shape —
     * the HTTP status code matches the body's status field.
     *
     * The `_meta` envelope rides errors as well as successes so that
     * client-side response parsers can read `_meta.version` uniformly
     * regardless of outcome.
     *
     * `$data` is OPTIONAL structured context for the error. Only
     * emitted when non-null AND non-empty (so the typical error
     * response stays unchanged). v1.5 §3.3.12 uses this for the
     * `bcc_invalid_mention_target` `{user_id}` and
     * `bcc_too_many_mentions` `{max}` payloads. Future error codes
     * MUST document any keys they place here in the contract.
     *
     * @param array<string, mixed>|null $data
     */
    public static function error(
        string $code,
        string $message,
        int $status,
        ?array $data = null
    ): WP_REST_Response {
        $error = [
            'code'    => $code,
            'message' => $message,
            'status'  => $status,
        ];
        if (is_array($data) && $data !== []) {
            $error['data'] = $data;
        }
        return new WP_REST_Response([
            'error' => $error,
            '_meta' => ['version' => self::VERSION],
        ], $status);
    }

    /**
     * Forward a SERVICE's error array as an envelope, mapping its code to an
     * HTTP status.
     *
     * The domain services answer `['error' => 'bcc_*', 'message' => '...']`
     * with no status, because a status is a transport concern. Every REST
     * caller therefore needs this translation, and before this it was a
     * PRIVATE copy inside each endpoint that needed it.
     *
     * ⚠ The map is PostsEndpoint's, which is the SUPERSET of the two that
     * existed. It is deliberately NOT applied to the other two copies in
     * this PR: `BlogCoverImageEndpoint::statusForCode()` is a strict subset,
     * so routing it here would change its answer for
     * `bcc_permission_denied` and `bcc_feature_disabled` from 400 to 403 —
     * a real behaviour change to an endpoint this PR has no business
     * touching. Migrating those two is a follow-up of its own.
     *
     * @param array<string, mixed> $result a service result carrying 'error'
     */
    public static function fromServiceError(array $result): WP_REST_Response
    {
        $code    = (string) ($result['error'] ?? 'bcc_unavailable');
        $message = (string) ($result['message'] ?? '');
        /** @var array<string, mixed>|null $data */
        $data = isset($result['data']) && is_array($result['data']) ? $result['data'] : null;

        return self::error($code, $message, self::statusForServiceCode($code), $data);
    }

    /**
     * Code → HTTP status for a domain-service error.
     *
     * `default => 400` is deliberate and matches what the endpoints already
     * did: an unrecognised code is a caller problem until proven otherwise,
     * and answering 500 for one would turn a new service error code into a
     * paged alert.
     */
    public static function statusForServiceCode(string $code): int
    {
        return match ($code) {
            'bcc_unauthorized'           => 401,
            'bcc_forbidden'              => 403,
            'bcc_permission_denied'      => 403,
            'bcc_not_found'              => 404,
            'bcc_invalid_request'        => 400,
            'bcc_invalid_mention_target' => 400,
            'bcc_too_many_mentions'      => 400,
            'bcc_rate_limited'           => 429,
            'bcc_unavailable'            => 503,
            'bcc_feature_disabled'       => 403,
            default                      => 400,
        };
    }
}
