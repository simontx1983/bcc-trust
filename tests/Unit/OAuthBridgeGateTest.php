<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\Auth\OAuthController;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Audit HIGH #4 — the /auth/oauth bridge gate must require a fresh, single-use,
 * HMAC-signed request rather than a transmitted shared secret.
 *
 * oauthBridgeGate() returns null to accept and a 401 WP_REST_Response to
 * reject. A correctly-signed, in-window, unused request is accepted; replay
 * (reused nonce), stale timestamp, tampered signature/body, the legacy
 * x-bcc-oauth-secret header, and missing signing headers are all rejected.
 *
 * ## Isolation
 * Own subprocess; setUp() pulls in tests/Stubs/oauth-bridge-stubs.php (request/
 * response doubles, transient-backed nonce store, the bridge secret constant),
 * so the real gate + AuthSupport::consumeOauthBridgeNonce run without WordPress.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class OAuthBridgeGateTest extends TestCase
{
    private const SECRET = 'test-bridge-secret-abc123';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/oauth-bridge-stubs.php';
        $GLOBALS['__bcc_oauth_transients'] = [];
    }

    private function gate(WP_REST_Request $request): ?WP_REST_Response
    {
        $method = new ReflectionMethod(OAuthController::class, 'oauthBridgeGate');
        $method->setAccessible(true);
        /** @var WP_REST_Response|null $result */
        $result = $method->invoke(new OAuthController(), $request);
        return $result;
    }

    private function signature(int $ts, string $nonce, string $body): string
    {
        return hash_hmac('sha256', $ts . "\n" . $nonce . "\n" . $body, self::SECRET);
    }

    private function signedRequest(
        string $body = '{"provider":"google","provider_id":"g-1","email":"a@b.co"}',
        ?int $ts = null,
        string $nonce = 'nonce-fixed-1',
        ?string $signatureOverride = null
    ): WP_REST_Request {
        $ts  = $ts ?? time();
        $sig = $signatureOverride ?? $this->signature($ts, $nonce, $body);

        $request = new WP_REST_Request();
        $request->set_body($body);
        $request->set_header('x-bcc-oauth-timestamp', (string) $ts);
        $request->set_header('x-bcc-oauth-nonce', $nonce);
        $request->set_header('x-bcc-oauth-signature', $sig);
        return $request;
    }

    public function testValidSignedRequestPasses(): void
    {
        self::assertNull($this->gate($this->signedRequest()), 'a fresh, correctly-signed request must pass');
    }

    public function testReplayedNonceIsRejected(): void
    {
        $request = $this->signedRequest();
        self::assertNull($this->gate($request), 'first use passes');

        // Same request (same nonce) again — replay.
        $result = $this->gate($request);
        self::assertInstanceOf(WP_REST_Response::class, $result);
        self::assertSame(401, $result->get_status());
    }

    public function testStaleTimestampIsRejected(): void
    {
        $result = $this->gate($this->signedRequest(ts: time() - 301));
        self::assertInstanceOf(WP_REST_Response::class, $result);
        self::assertSame(401, $result->get_status());
    }

    public function testFutureTimestampBeyondSkewIsRejected(): void
    {
        $result = $this->gate($this->signedRequest(ts: time() + 400));
        self::assertInstanceOf(WP_REST_Response::class, $result);
        self::assertSame(401, $result->get_status());
    }

    public function testTamperedSignatureIsRejected(): void
    {
        $result = $this->gate($this->signedRequest(signatureOverride: 'deadbeef'));
        self::assertInstanceOf(WP_REST_Response::class, $result);
        self::assertSame(401, $result->get_status());
    }

    public function testTamperedBodyIsRejected(): void
    {
        // Sign one body, then swap the body the server sees.
        $ts      = time();
        $nonce   = 'nonce-body-1';
        $signed  = $this->signature($ts, $nonce, '{"email":"victim@b.co"}');
        $request = new WP_REST_Request();
        $request->set_body('{"email":"attacker@b.co"}'); // different from what was signed
        $request->set_header('x-bcc-oauth-timestamp', (string) $ts);
        $request->set_header('x-bcc-oauth-nonce', $nonce);
        $request->set_header('x-bcc-oauth-signature', $signed);

        $result = $this->gate($request);
        self::assertInstanceOf(WP_REST_Response::class, $result);
        self::assertSame(401, $result->get_status());
    }

    public function testLegacySecretHeaderIsRejected(): void
    {
        // Old scheme: bearer secret in x-bcc-oauth-secret, no signing headers.
        $request = new WP_REST_Request();
        $request->set_body('{}');
        $request->set_header('x-bcc-oauth-secret', self::SECRET);

        $result = $this->gate($request);
        self::assertInstanceOf(WP_REST_Response::class, $result);
        self::assertSame(401, $result->get_status());
    }

    public function testMissingSigningHeadersRejected(): void
    {
        $result = $this->gate(new WP_REST_Request());
        self::assertInstanceOf(WP_REST_Response::class, $result);
        self::assertSame(401, $result->get_status());
    }
}
