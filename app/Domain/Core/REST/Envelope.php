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
 * Legacy shape (recognized, not produced):
 *   bcc-trust/v1 also accepts the older { success: true, data: ... }
 *   envelope emitted directly via WP_REST_Response by several
 *   trust-era handlers (TrustRestController, XController,
 *   GitHubController, AdminStatsController, UserStatusController).
 *   The frontend's bcc-trust-client.ts isTrustEnvelope() understands
 *   that shape; this wrapper recognizes it in isAlreadyEnveloped()
 *   so the response is not nest-wrapped into
 *   { data: { success: true, data: ... }, _meta: ... } — which would
 *   silently fail every trust client call.
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

        // Phase 4c: surface the request-correlation id as a response header on
        // EVERY BCC response — success, error, and already-enveloped — so a
        // client/proxy can log it even on an error shape that carries no _meta.
        if (class_exists(\BCC\Core\Http\RequestContext::class)) {
            $response->header('X-Request-Id', \BCC\Core\Http\RequestContext::requestId());
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
            '_meta' => self::buildMeta(),
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

        // Canonical success envelope marker: { data, _meta }.
        if (array_key_exists('data', $data) && array_key_exists('_meta', $data)) {
            return true;
        }

        // Canonical error envelope marker: { error: { code, message, status, ... } }.
        if (
            isset($data['error'])
            && is_array($data['error'])
            && isset($data['error']['code'], $data['error']['message'], $data['error']['status'])
        ) {
            return true;
        }

        // Legacy bcc-trust/v1 success envelope: { success: true, data: ... }.
        //
        // Predates the canonical { data, _meta } shape. Several handlers
        // (TrustRestController, XController, GitHubController,
        // AdminStatsController, UserStatusController) historically emit
        // this directly via WP_REST_Response. Recognize so wrap() does
        // not nest-wrap into { data: { success: true, data: ... }, _meta }
        // — which is the live regression this patch fixes (see commit log
        // for 2026-05-13 Phase α + the operational audit's V-07/V-29
        // entry). The frontend's bcc-trust-client.ts isTrustEnvelope()
        // expects this exact shape at the top level.
        //
        // Strictly bounded match: requires the literal boolean `true` on
        // `success`, a sibling `data` key, AND the absence of `_meta`.
        // This avoids accidental recognition of any future endpoint that
        // happens to include a `success` field inside its own payload —
        // the canonical envelope always carries `_meta`, so its presence
        // is the disambiguator.
        //
        // Do NOT loosen this rule. New endpoints should emit the
        // canonical { data, _meta } shape via ApiResponse::ok(); the
        // legacy shape is recognized for backwards compatibility only
        // and is not a contract option for new code.
        if (
            array_key_exists('success', $data)
            && $data['success'] === true
            && array_key_exists('data', $data)
            && !array_key_exists('_meta', $data)
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
    private static function buildMeta(): array
    {
        // The correlation id was bound at the REST boundary by bcc-core's
        // rest_pre_dispatch (from a client X-BCC-Request-Id or minted), so
        // _meta.request_id matches the X-Request-Id header and the server logs.
        // The `request_id` key is contract-stable — only its source changed.
        if (class_exists(\BCC\Core\Http\RequestContext::class)) {
            return ['request_id' => \BCC\Core\Http\RequestContext::requestId()];
        }

        // Defensive fallback only (bcc-core is a hard dependency, so this is
        // unreachable in practice) — keep the contract field populated.
        try {
            $requestId = bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            $requestId = (string) hexdec(uniqid());
        }

        return ['request_id' => $requestId];
    }
}
