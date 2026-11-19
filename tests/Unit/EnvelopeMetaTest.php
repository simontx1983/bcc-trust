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

    /** F-337: WordPress routes case-insensitively, so the envelope must too. */
    public function testAMixedCaseRouteIsEnvelopedLikeItsHandler(): void
    {
        foreach (['/BCC/v1/chains', '/Bcc-Trust/V1/health/read-model'] as $route) {
            $r = self::wrap(['status' => 'ok'], 200, $route);
            self::assertSame(['data' => ['status' => 'ok'], '_meta' => self::expectedMeta()], self::body($r), $route);
        }
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
        // This stub records no `retry_after` (an older bcc-core), so the
        // window is the fallback (F-289).
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

    /** Deny through the stub with an F-289 denial, then wrap a plain throttle 429. */
    private static function throttled(int $window, int $retryAfter, int $at): \WP_REST_Response
    {
        $GLOBALS['__bcc_envelope_meta_throttle'] = ['allow' => false, 'retry_after' => $retryAfter, 'at' => $at];
        \BCC\Core\Security\Throttle::allow('me_write', 120, $window);

        $r = Envelope::wrap(
            ApiResponse::error('bcc_rate_limited', 'Too many requests. Please slow down.', 429),
            new \WP_REST_Server(),
            new \WP_REST_Request('/bcc/v1/me', 'PATCH')
        );
        self::assertInstanceOf(\WP_REST_Response::class, $r);
        return $r;
    }

    /**
     * F-289: a throttle 429 advertises the time LEFT on the bucket that
     * refused (bcc-core's `retry_after`), not the bucket's window — in the
     * header, in X-RateLimit-Reset and in retry_after_seconds alike.
     */
    public function testAThrottle429CarriesTheTimeLeftNotTheWindow(): void
    {
        $before = time();
        $r      = self::throttled(60, 17, $before);
        $after  = time();

        $h = $r->get_headers();
        self::assertSame('17', $h['Retry-After'] ?? null);
        self::assertSame(17, self::body($r)['error']['data']['retry_after_seconds'] ?? null);
        self::assertSame('120', $h['X-RateLimit-Limit'] ?? null);
        self::assertSame('0', $h['X-RateLimit-Remaining'] ?? null);
        $reset = (int) ($h['X-RateLimit-Reset'] ?? 0);
        self::assertGreaterThanOrEqual($before + 17, $reset);
        self::assertLessThanOrEqual($after + 17, $reset);
    }

    /** It can be LONGER than the window: the refused bucket still weighs on the next one. */
    public function testTheWaitCanBeLongerThanTheWindow(): void
    {
        $r = self::throttled(60, 95, time());

        self::assertSame('95', $r->get_headers()['Retry-After'] ?? null);
        self::assertSame(95, self::body($r)['error']['data']['retry_after_seconds'] ?? null);
    }

    /** The seconds since the denial come off, so a slow request doesn't overstate the wait. */
    public function testTheSecondsSinceTheDenialComeOff(): void
    {
        $r = self::throttled(60, 17, time() - 5);

        $wait = (int) ($r->get_headers()['Retry-After'] ?? 0);
        self::assertContains($wait, [11, 12], 'the 5 s since the denial come off (±1 s for a tick)');
        self::assertSame($wait, self::body($r)['error']['data']['retry_after_seconds'] ?? null);
    }

    /** Contract §1.4.4: retry_after_seconds is a POSITIVE integer, even once the wait has passed. */
    public function testTheWaitIsNeverBelowOneSecond(): void
    {
        foreach ([[0, time()], [17, time() - 100]] as [$retryAfter, $at]) {
            $r = self::throttled(60, $retryAfter, $at);
            self::assertSame('1', $r->get_headers()['Retry-After'] ?? null);
            self::assertSame(1, self::body($r)['error']['data']['retry_after_seconds'] ?? null);
        }
    }

    /**
     * F-289: a 429 that no throttle caused gets NOTHING injected. The captcha
     * step-up's bcc_captcha_required (bcc-trust #715) answers after every
     * login bucket has let the request through, and bcc-core now records
     * only denials, so lastDenial() is null. The client must retry at once
     * with a token, not wait out an unrelated bucket's window.
     */
    public function testACaptcha429GetsNoInjectedWait(): void
    {
        $captcha = ['captcha' => ['provider' => 'turnstile', 'site_key' => 'site-key']];
        $resp    = ApiResponse::error('bcc_captcha_required', 'Please complete the verification to continue.', 429, $captcha);
        $before  = (string) json_encode($resp->get_data());

        $r = Envelope::wrap($resp, new \WP_REST_Server(), new \WP_REST_Request('/bcc/v1/auth/login', 'POST'));
        self::assertInstanceOf(\WP_REST_Response::class, $r);

        self::assertNull(\BCC\Core\Security\Throttle::lastDenial());
        $h = $r->get_headers();
        foreach (['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'] as $name) {
            self::assertArrayNotHasKey($name, $h, $name);
        }
        self::assertSame($captcha, self::body($r)['error']['data'] ?? null);
        self::assertSame($before, (string) json_encode($r->get_data()));
    }
}
