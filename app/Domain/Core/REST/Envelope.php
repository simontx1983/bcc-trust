<?php

namespace BCC\Trust\Core\REST;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Response envelope wrapper for the BCC REST API.
 *
 * Per docs/api-contract-v1.md §1.4 / §1.5, every response under
 * /wp-json/bcc/v1/ and /wp-json/bcc-trust/v1/ MUST be enveloped:
 *
 *   Success: { data: <payload>, _meta: { ... } }
 *   Error:   { error: { code, message, status, data? } }
 *
 * The frontend's bccFetch wrapper (bcc-frontend/src/lib/api/client.ts)
 * relies on this shape for envelope-vs-error discrimination. Without
 * the envelope, the client throws bcc_invalid_envelope on every read.
 *
 * This class hooks rest_post_dispatch to wrap responses uniformly.
 * Endpoints that already return an enveloped shape are detected and
 * passed through unchanged (idempotent).
 */
final class Envelope
{
    private const NAMESPACES = ['/bcc/v1/', '/bcc-trust/v1/'];

    public static function init(): void
    {
        // Priority 999 so this runs AFTER any endpoint-level transformation
        // hooks (which use the default priority 10).
        add_filter('rest_post_dispatch', [self::class, 'wrap'], 999, 3);
    }

    /**
     * @param mixed $response
     * @param \WP_REST_Server $server
     * @param \WP_REST_Request<array<string, mixed>> $request
     * @return mixed
     */
    public static function wrap($response, \WP_REST_Server $server, \WP_REST_Request $request)
    {
        if (!($response instanceof \WP_REST_Response)) {
            return $response;
        }

        $route = $request->get_route();
        if (!self::isOurNamespace($route)) {
            return $response;
        }

        $data   = $response->get_data();
        $status = (int) $response->get_status();

        // Already enveloped — pass through.
        if (self::isAlreadyEnveloped($data)) {
            return $response;
        }

        // Error path: WP-style { code, message, data: { status } } → contract
        // shape { error: { code, message, status, data? } }.
        if ($status >= 400 && self::looksLikeWpError($data)) {
            $response->set_data([
                'error' => [
                    'code'    => (string) $data['code'],
                    'message' => (string) ($data['message'] ?? ''),
                    'status'  => $status,
                    'data'    => $data['data'] ?? null,
                ],
            ]);
            return $response;
        }

        // Success path: wrap.
        $response->set_data([
            'data'  => $data,
            '_meta' => self::buildMeta($request),
        ]);

        return $response;
    }

    private static function isOurNamespace(string $route): bool
    {
        foreach (self::NAMESPACES as $prefix) {
            if (strpos($route, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param mixed $data
     */
    private static function isAlreadyEnveloped($data): bool
    {
        if (!is_array($data)) {
            return false;
        }

        // Success envelope marker
        if (array_key_exists('data', $data) && array_key_exists('_meta', $data)) {
            return true;
        }

        // Error envelope marker
        if (
            isset($data['error'])
            && is_array($data['error'])
            && isset($data['error']['code'], $data['error']['message'], $data['error']['status'])
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param mixed $data
     */
    private static function looksLikeWpError($data): bool
    {
        return is_array($data)
            && isset($data['code'])
            && is_string($data['code']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildMeta(\WP_REST_Request $request): array
    {
        $requestId = $request->get_header('X-BCC-Request-Id');
        if (!is_string($requestId) || $requestId === '') {
            try {
                $requestId = bin2hex(random_bytes(8));
            } catch (\Throwable $e) {
                $requestId = (string) hexdec(uniqid());
            }
        }

        return [
            'request_id' => $requestId,
        ];
    }
}
