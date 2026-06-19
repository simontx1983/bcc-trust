<?php

declare(strict_types=1);

// ── WP-function stubs ───────────────────────────────────────────────────────
// JwtToken calls a handful of WordPress globals unqualified, so PHP resolves
// them against its own namespace FIRST. Defining them here (same namespace,
// no WordPress loaded) shadows the globals and keeps the test pure — no DB,
// no WP bootstrap. The revocation counter is backed by a test-controlled store.
namespace BCC\Trust\Core\Support {

    if (!function_exists(__NAMESPACE__ . '\\wp_salt')) {
        function wp_salt(string $scheme = 'auth'): string
        {
            return 'unit-test-fixed-secret-key-0123456789abcdef';
        }
        function home_url(string $path = '/'): string
        {
            return 'https://bcc.test/' . ltrim($path, '/');
        }
        /** @param mixed $data */
        function wp_json_encode($data, int $options = 0, int $depth = 512): string
        {
            return (string) json_encode($data, $options, $depth);
        }
        function wp_generate_uuid4(): string
        {
            return '00000000-0000-4000-8000-000000000000';
        }
        /** @return int */
        function get_user_meta(int $userId, string $key, bool $single = false)
        {
            return \BCC\Trust\Core\Support\Tests\MetaStore::$tv[$userId] ?? 0;
        }
        /** @param mixed $value */
        function update_user_meta(int $userId, string $key, $value): bool
        {
            \BCC\Trust\Core\Support\Tests\MetaStore::$tv[$userId] = (int) $value;
            return true;
        }
    }
}

namespace BCC\Trust\Core\Support\Tests {

    use BCC\Trust\Core\Support\JwtToken;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\TestCase;

    /** Test-controlled backing store for the per-user revocation counter (`tv`). */
    final class MetaStore
    {
        /** @var array<int, int> */
        public static array $tv = [];
    }

    /**
     * Invariants for the HS256 token codec. These are the checks an attacker
     * would probe: alg-confusion (none / RS / HS512), signature tampering,
     * expiry, not-yet-valid, issuer, version, and per-user revocation.
     */
    #[CoversClass(JwtToken::class)]
    final class JwtTokenTest extends TestCase
    {
        private const UID = 42;

        protected function setUp(): void
        {
            MetaStore::$tv = [];
        }

        // ── base64url + forging helpers ─────────────────────────────────────

        private static function b64(string $raw): string
        {
            return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        }

        /**
         * @param array<string, mixed> $header
         * @param array<string, mixed> $payload
         */
        private static function forge(array $header, array $payload, string $sig = 'x'): string
        {
            return self::b64((string) json_encode($header))
                . '.' . self::b64((string) json_encode($payload))
                . '.' . $sig;
        }

        /** A structurally valid payload an attacker would start from. */
        private static function basePayload(int $expOffset = 600): array
        {
            $now = time();
            return [
                'iss'     => 'https://bcc.test/',
                'sub'     => (string) self::UID,
                'iat'     => $now,
                'exp'     => $now + $expOffset,
                'ver'     => JwtToken::TOKEN_VERSION,
                'tv'      => 0,
                'user_id' => self::UID,
                'handle'  => 'tester',
            ];
        }

        // ── Happy path ──────────────────────────────────────────────────────

        public function testValidTokenRoundTrips(): void
        {
            $token  = JwtToken::encode(self::UID, 'tester');
            $result = JwtToken::decode($token);

            self::assertTrue($result['ok']);
            self::assertSame(self::UID, (int) $result['payload']['user_id']);
            self::assertSame('tester', $result['payload']['handle']);
        }

        // ── Algorithm confusion ─────────────────────────────────────────────

        public function testAlgNoneRejected(): void
        {
            $token  = self::forge(['alg' => 'none', 'typ' => 'JWT'], self::basePayload(), '');
            $result = JwtToken::decode($token);

            self::assertFalse($result['ok']);
            self::assertSame(JwtToken::ERR_BAD_ALGORITHM, $result['error']);
        }

        public function testAlgHs512Rejected(): void
        {
            $token  = self::forge(['alg' => 'HS512', 'typ' => 'JWT'], self::basePayload());
            $result = JwtToken::decode($token);

            self::assertFalse($result['ok']);
            self::assertSame(JwtToken::ERR_BAD_ALGORITHM, $result['error']);
        }

        // ── Signature integrity ─────────────────────────────────────────────

        public function testTamperedSignatureRejected(): void
        {
            $token = JwtToken::encode(self::UID, 'tester');
            [$h, $p] = explode('.', $token);
            $forged = $h . '.' . $p . '.' . self::b64('not-the-real-signature');

            $result = JwtToken::decode($forged);
            self::assertFalse($result['ok']);
            self::assertSame(JwtToken::ERR_BAD_SIGNATURE, $result['error']);
        }

        public function testTamperedPayloadRejected(): void
        {
            // Keep the original (valid) signature but swap the payload to
            // escalate user_id — the HMAC no longer matches.
            $token = JwtToken::encode(self::UID, 'tester');
            [$h, , $sig] = explode('.', $token);
            $evil = self::b64((string) json_encode(self::basePayload() + ['user_id' => 1]));

            $result = JwtToken::decode($h . '.' . $evil . '.' . $sig);
            self::assertFalse($result['ok']);
            self::assertSame(JwtToken::ERR_BAD_SIGNATURE, $result['error']);
        }

        // ── Temporal ────────────────────────────────────────────────────────

        public function testExpiredRejected(): void
        {
            // encode() merges extraClaims last, so this overrides exp into the past.
            $token  = JwtToken::encode(self::UID, 'tester', ['exp' => time() - 10_000]);
            $result = JwtToken::decode($token);

            self::assertFalse($result['ok']);
            self::assertSame(JwtToken::ERR_EXPIRED, $result['error']);
        }

        public function testNotYetValidRejected(): void
        {
            $token  = JwtToken::encode(self::UID, 'tester', ['iat' => time() + 10_000]);
            $result = JwtToken::decode($token);

            self::assertFalse($result['ok']);
            self::assertSame(JwtToken::ERR_NOT_YET_VALID, $result['error']);
        }

        public function testRefreshGraceAcceptsRecentlyExpired(): void
        {
            // Expired 1 hour ago — past canonical decode, but inside the
            // refresh grace window (1 day + skew).
            $token = JwtToken::encode(self::UID, 'tester', ['exp' => time() - 3_600]);

            self::assertSame(JwtToken::ERR_EXPIRED, JwtToken::decode($token)['error']);
            self::assertTrue(JwtToken::decodeForRefresh($token)['ok']);
        }

        public function testRefreshGraceRejectsBeyondWindow(): void
        {
            // Expired 2 days ago — beyond even the refresh grace window.
            $token  = JwtToken::encode(self::UID, 'tester', ['exp' => time() - 172_800]);
            $result = JwtToken::decodeForRefresh($token);

            self::assertFalse($result['ok']);
            self::assertSame(JwtToken::ERR_EXPIRED, $result['error']);
        }

        // ── Issuer / version / claims ───────────────────────────────────────

        public function testWrongIssuerRejected(): void
        {
            $token  = JwtToken::encode(self::UID, 'tester', ['iss' => 'https://evil.example/']);
            $result = JwtToken::decode($token);

            self::assertFalse($result['ok']);
            self::assertSame(JwtToken::ERR_BAD_ISSUER, $result['error']);
        }

        public function testWrongVersionRejected(): void
        {
            $token  = JwtToken::encode(self::UID, 'tester', ['ver' => JwtToken::TOKEN_VERSION + 1]);
            $result = JwtToken::decode($token);

            self::assertFalse($result['ok']);
            self::assertSame(JwtToken::ERR_BAD_VERSION, $result['error']);
        }

        public function testMissingUserIdRejected(): void
        {
            $token  = JwtToken::encode(self::UID, 'tester', ['user_id' => 0]);
            $result = JwtToken::decode($token);

            self::assertFalse($result['ok']);
            self::assertSame(JwtToken::ERR_MISSING_CLAIM, $result['error']);
        }

        public function testMalformedRejected(): void
        {
            self::assertSame(JwtToken::ERR_MALFORMED, JwtToken::decode('not-a-jwt')['error']);
            self::assertSame(JwtToken::ERR_MALFORMED, JwtToken::decode('only.two')['error']);
        }

        // ── Per-user revocation (tv) ────────────────────────────────────────

        public function testRevocationInvalidatesOutstandingTokens(): void
        {
            $token = JwtToken::encode(self::UID, 'tester');
            self::assertTrue(JwtToken::decode($token)['ok'], 'fresh token valid before revoke');

            JwtToken::revokeAllForUser(self::UID); // bumps tv 0 -> 1

            $afterRevoke = JwtToken::decode($token);
            self::assertFalse($afterRevoke['ok']);
            self::assertSame(JwtToken::ERR_REVOKED, $afterRevoke['error']);

            // A token minted AFTER the bump carries tv=1 and verifies again.
            self::assertTrue(JwtToken::decode(JwtToken::encode(self::UID, 'tester'))['ok']);
        }
    }
}
