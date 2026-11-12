<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Core\Http\RequestContext;
use BCC\Trust\Core\REST\Envelope;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Onchain\Controllers\WalletController;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * What `_meta` Envelope emits, and what it leaves alone (bcc-trust #377).
 *
 *   - Envelope-built envelopes — the success wrap of an un-enveloped payload
 *     AND the re-shape of a WP-style error — carry _meta { version, request_id },
 *     with version === ApiResponse::VERSION and request_id === X-Request-Id.
 *   - Envelopes a handler already built pass through BYTE-IDENTICAL:
 *     ApiResponse::ok() { version, reaction_types }, ApiResponse::error()
 *     { version }, and the legacy { success, data } shape with no _meta.
 *     Other routes (e.g. the messages endpoints) document the ApiResponse
 *     shape, so this pass-through is a hard requirement, not a detail.
 *   - An error envelope never has a top-level `data` key — the frontend's
 *     isSuccessEnvelope() is `"data" in v && typeof _meta === "object"`, so a
 *     `data` sibling would make an error read as a success.
 *   - GET /bcc/v1/chains throttles with the canonical bcc_rate_limited error.
 *
 * ## Isolation
 * Subprocess-only; setUp() pulls in tests/Stubs/envelope-meta-stubs.php
 * (minimal WP REST classes plus bcc-core's RequestContext and Throttle).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EnvelopeMetaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/envelope-meta-stubs.php';
        $GLOBALS['__bcc_envelope_meta_throttle'] = ['allow' => true];
    }

    /** @param mixed $body */
    private static function wrap($body, int $status = 200, string $route = '/bcc/v1/chains', string $method = 'GET'): \WP_REST_Response
    {
        $out = Envelope::wrap(
            new \WP_REST_Response($body, $status),
            new \WP_REST_Server(),
            new \WP_REST_Request($route, $method)
        );
        self::assertInstanceOf(\WP_REST_Response::class, $out);
        return $out;
    }

    /** @return array<string, mixed> */
    private static function body(\WP_REST_Response $r): array
    {
        $data = $r->get_data();
        self::assertIsArray($data);
        return $data;
    }

    private static function expectedMeta(): array
    {
        return ['version' => ApiResponse::VERSION, 'request_id' => RequestContext::FIXED_ID];
    }

    public function testSuccessWrapCarriesVersionAndRequestId(): void
    {
        $r = self::wrap([['id' => 4, 'slug' => 'cosmos']]);

        self::assertSame(
            ['data' => [['id' => 4, 'slug' => 'cosmos']], '_meta' => self::expectedMeta()],
            self::body($r)
        );
        self::assertSame('v1', ApiResponse::VERSION);
        self::assertSame(RequestContext::FIXED_ID, $r->get_headers()['X-Request-Id'] ?? null);
    }

    public function testSuccessWrapAppliesToTheBccTrustNamespaceToo(): void
    {
        $r = self::wrap(['status' => 'ok'], 200, '/bcc-trust/v1/health/read-model');

        self::assertSame(['data' => ['status' => 'ok'], '_meta' => self::expectedMeta()], self::body($r));
    }

    public function testReshapedWpErrorCarriesTheSameMetaAndNoTopLevelData(): void
    {
        $r = self::wrap(
            ['code' => 'rest_invalid_param', 'message' => 'Invalid parameter(s): page', 'data' => ['status' => 400]],
            400
        );

        self::assertSame(
            [
                'error' => [
                    'code'    => 'rest_invalid_param',
                    'message' => 'Invalid parameter(s): page',
                    'status'  => 400,
                    'data'    => ['status' => 400],
                ],
                '_meta' => self::expectedMeta(),
            ],
            self::body($r)
        );
        self::assertArrayNotHasKey('data', self::body($r), 'an error envelope must not look like a success');
        self::assertSame(400, $r->get_status());
    }

    public function testApiResponseOkShapePassesThroughByteIdentical(): void
    {
        // Literal ApiResponse::ok() shape — ok() itself needs the reaction-type
        // registry (WordPress), and this test is about Envelope, not ok().
        $built = ['data' => ['id' => 7], '_meta' => ['version' => 'v1', 'reaction_types' => ['like' => 1]]];

        $r = self::wrap($built);

        self::assertSame($built, self::body($r));
        self::assertSame(
            (string) json_encode($built),
            (string) json_encode(self::body($r)),
            'ApiResponse::ok envelopes must not gain or lose _meta keys'
        );
    }

    public function testApiResponseErrorPassesThroughByteIdentical(): void
    {
        $built = ApiResponse::error('bcc_not_found', 'Not found.', 404);
        $before = (string) json_encode($built->get_data());

        $r = Envelope::wrap($built, new \WP_REST_Server(), new \WP_REST_Request('/bcc/v1/pages/1', 'GET'));
        self::assertInstanceOf(\WP_REST_Response::class, $r);

        self::assertSame($before, (string) json_encode($r->get_data()));
        self::assertSame(['version' => 'v1'], self::body($r)['_meta']);
    }

    public function testLegacySuccessShapePassesThroughWithNoMeta(): void
    {
        $legacy = ['success' => true, 'data' => ['score' => 42]];

        $r = self::wrap($legacy, 200, '/bcc-trust/v1/score/1');

        self::assertSame($legacy, self::body($r));
        self::assertArrayNotHasKey('_meta', self::body($r));
    }

    public function testOtherNamespacesAreUntouched(): void
    {
        $raw = ['message' => 'core route'];

        $r = self::wrap($raw, 200, '/wp/v2/posts');

        self::assertSame($raw, $r->get_data());
        self::assertArrayNotHasKey('X-Request-Id', $r->get_headers());
    }

    public function testChainsThrottleReturnsCanonicalRateLimitedError(): void
    {
        $GLOBALS['__bcc_envelope_meta_throttle'] = ['allow' => false];

        $handled = WalletController::rest_list_chains(new \WP_REST_Request('/bcc/v1/chains', 'GET'));
        $r = Envelope::wrap($handled, new \WP_REST_Server(), new \WP_REST_Request('/bcc/v1/chains', 'GET'));
        self::assertInstanceOf(\WP_REST_Response::class, $r);

        self::assertSame(429, $r->get_status());
        $body = self::body($r);
        self::assertArrayNotHasKey('data', $body, 'a 429 must not be a success envelope');
        self::assertSame('bcc_rate_limited', $body['error']['code'] ?? null);
        self::assertSame(429, $body['error']['status'] ?? null);
        // Envelope's 429 block now reaches this route: backoff guidance rides along.
        self::assertSame(60, $body['error']['data']['retry_after_seconds'] ?? null);
        self::assertSame('60', $r->get_headers()['Retry-After'] ?? null);
        self::assertSame('30', $r->get_headers()['X-RateLimit-Limit'] ?? null);
    }

    /**
     * F-333 / F-294: a 429 that set its OWN Retry-After (the 7-day handle
     * cooldown) is not a throttle denial. lastDenial() then describes an
     * earlier throttle call in the request, and must not overwrite the
     * real wait or add a contradicting retry_after_seconds.
     */
    public function testA429WithItsOwnRetryAfterKeepsItsOwnWait(): void
    {
        // A recorded denial from some earlier throttle call (60 s window).
        $GLOBALS['__bcc_envelope_meta_throttle'] = ['allow' => false];
        \BCC\Core\Security\Throttle::allow('me_write', 120, 60);

        $resp = ApiResponse::error('bcc_rate_limited', 'Handle can only be changed once every 7 days.', 429, [
            'next_change_at' => '2026-11-19T10:00:00Z',
        ]);
        $resp->header('Retry-After', '561600');

        $r = Envelope::wrap($resp, new \WP_REST_Server(), new \WP_REST_Request('/bcc/v1/me/handle', 'PATCH'));
        self::assertInstanceOf(\WP_REST_Response::class, $r);

        self::assertSame('561600', $r->get_headers()['Retry-After'] ?? null);
        $body = self::body($r);
        self::assertSame(['next_change_at' => '2026-11-19T10:00:00Z'], $body['error']['data'] ?? null);
    }
}
