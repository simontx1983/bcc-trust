<?php
/**
 * JwtToken — HS256 codec for the BCC API.
 *
 * Single source of truth for:
 *   - signing secret  → wp_salt('auth')
 *   - issuer (iss)    → home_url('/')
 *   - audience (aud)  → BCC_FRONTEND_ORIGIN constant (optional)
 *   - version (ver)   → TOKEN_VERSION (this file)
 *   - clock skew      → SKEW_SECONDS (60)
 *   - lifetime (exp)  → TTL_SECONDS (7 days, V1)
 *
 * Mint and verify go through the same constants — there is exactly one
 * place to change the contract. Both /auth/signup and /auth/token mint
 * via `encode()`; the BearerAuth middleware verifies via `decode()`.
 *
 * NextAuth integration:
 *   - algorithm: HS256
 *   - secret:    wp_salt('auth') value (export to env once; rotation
 *                invalidates every outstanding token, by design)
 *   - issuer:    site home URL
 *   - audience:  BCC_FRONTEND_ORIGIN value (when configured)
 *   - clock skew tolerance: 60 seconds (matches our verify path)
 *
 * Rejection on decode (returns ['ok' => false, 'error' => ...]):
 *   - structurally malformed JWT (≠ 3 parts / non-decodable b64 / non-JSON)
 *   - alg ≠ HS256 (resists "alg=none" / RSA-key-confusion attacks)
 *   - bad signature (constant-time hash_equals)
 *   - missing/invalid exp, exp + skew already past
 *   - iat in the future beyond skew
 *   - iss ≠ this site's home URL
 *   - aud ≠ configured audience (only when BCC_FRONTEND_ORIGIN is set)
 *   - ver ≠ TOKEN_VERSION
 *   - missing/zero user_id claim
 *
 * @package BCC\Trust\Core\Support
 * @since V1 (2026-04, Phase 2 hardening)
 */

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class JwtToken
{
    /** Bumped on a breaking payload-schema change so old clients get rejected loudly. */
    public const TOKEN_VERSION = 1;

    public const ALGORITHM = 'HS256';

    /** Clock-skew tolerance applied to both exp and iat. */
    public const SKEW_SECONDS = 60;

    /** §V1 token lifetime — 7 days. See AuthEndpoint::JWT_TTL_SECONDS comment for the trade-off. */
    public const TTL_SECONDS = 604800;

    /**
     * Refresh grace window — how long PAST exp `decodeForRefresh()` will
     * still accept a token for re-mint. 86400s = 1 day. Bounded so that
     * a stolen post-expiry token can't be perpetually re-minted; the
     * user must re-authenticate after the grace window closes.
     *
     * The grace stacks on SKEW_SECONDS (60s). Effective survivability of
     * one "session" before forced re-login: TTL_SECONDS (7d) + this (1d)
     * = 8 days. Per-user revocation via revokeAllForUser() invalidates
     * every outstanding token regardless of grace.
     */
    public const REFRESH_GRACE_SECONDS = 86400;

    /**
     * Per-user revocation counter. Stored as wp_usermeta integer; a
     * mint embeds the current value as the `tv` claim, decode rejects
     * mismatches. Bumping the counter (via revokeAllForUser) invalidates
     * every outstanding token for that user without affecting anyone else.
     *
     * Meta missing / 0 → equivalent (cast to int) — old tokens minted
     * before this claim shipped have an absent / zero `tv` and still
     * verify cleanly until a revocation bump arms the gate.
     */
    public const TOKEN_VERSION_META_KEY = 'bcc_token_version';

    public const ERR_MALFORMED     = 'jwt_malformed';
    public const ERR_BAD_ALGORITHM = 'jwt_bad_algorithm';
    public const ERR_BAD_SIGNATURE = 'jwt_bad_signature';
    public const ERR_BAD_ISSUER    = 'jwt_bad_issuer';
    public const ERR_BAD_AUDIENCE  = 'jwt_bad_audience';
    public const ERR_BAD_VERSION   = 'jwt_bad_version';
    public const ERR_EXPIRED       = 'jwt_expired';
    public const ERR_NOT_YET_VALID = 'jwt_not_yet_valid';
    public const ERR_MISSING_CLAIM = 'jwt_missing_claim';
    public const ERR_REVOKED       = 'jwt_revoked';

    /**
     * Encode a token for the given user + handle.
     *
     * @param array<string, mixed> $extraClaims optional additional payload claims.
     */
    public static function encode(int $userId, string $handle, array $extraClaims = []): string
    {
        $now = time();

        $header = [
            'alg' => self::ALGORITHM,
            'typ' => 'JWT',
        ];

        $payload = array_merge([
            'iss'     => self::issuer(),
            'sub'     => (string) $userId,
            'iat'     => $now,
            'exp'     => $now + self::TTL_SECONDS,
            'ver'     => self::TOKEN_VERSION,
            'jti'     => wp_generate_uuid4(),
            'tv'      => self::currentTokenVersion($userId),
            'user_id' => $userId,
            'handle'  => $handle,
        ], $extraClaims);

        // Audience is optional — only emit when BCC_FRONTEND_ORIGIN is
        // set. The first entry in the allowlist is the canonical mint
        // value (decode accepts ANY allowlisted entry, see decode()).
        $allowlist = self::audienceAllowlist();
        if ($allowlist !== []) {
            $payload['aud'] = $allowlist[0];
        }

        $headerEnc  = self::b64urlEncode((string) wp_json_encode($header));
        $payloadEnc = self::b64urlEncode((string) wp_json_encode($payload));
        $signature  = hash_hmac('sha256', $headerEnc . '.' . $payloadEnc, self::secret(), true);
        $sigEnc     = self::b64urlEncode($signature);

        return $headerEnc . '.' . $payloadEnc . '.' . $sigEnc;
    }

    /**
     * Invalidate every outstanding token for a user. Increments the
     * stored revocation counter; subsequent decodes of any pre-bump
     * token return ERR_REVOKED.
     *
     * Use cases: admin "kill all sessions" action, password change,
     * account compromise response. Cheap (single update_user_meta).
     *
     * Returns the new version number (post-increment).
     */
    public static function revokeAllForUser(int $userId): int
    {
        $current = self::currentTokenVersion($userId);
        $next    = $current + 1;
        update_user_meta($userId, self::TOKEN_VERSION_META_KEY, $next);
        return $next;
    }

    /**
     * Decode + verify a token (canonical path; rejects expired tokens
     * with the standard 60-second skew window).
     *
     * Returns a tagged result so the caller branches on a single
     * predicate. PHPStan-friendly tagged-union shape:
     *
     *   success → ['ok' => true,  'payload' => array<string, mixed>]
     *   failure → ['ok' => false, 'error'   => one of ERR_*]
     *
     * @return array{ok: true, payload: array<string, mixed>}|array{ok: false, error: string}
     */
    public static function decode(string $token): array
    {
        return self::decodeWithExpiryWindow($token, self::SKEW_SECONDS);
    }

    /**
     * Decode + verify a token, allowing expiration up to REFRESH_GRACE_SECONDS
     * past `exp`. Every OTHER check (signature, issuer, audience, version,
     * revocation, user_id) is still enforced canonically.
     *
     * Used exclusively by /auth/refresh to mint a fresh token from a
     * recently-expired one without forcing a full re-login. The caller
     * (AuthEndpoint::refresh) is responsible for the additional
     * "not suspended" check before re-minting.
     *
     * @return array{ok: true, payload: array<string, mixed>}|array{ok: false, error: string}
     */
    public static function decodeForRefresh(string $token): array
    {
        return self::decodeWithExpiryWindow($token, self::SKEW_SECONDS + self::REFRESH_GRACE_SECONDS);
    }

    /**
     * Shared decode core. The only difference between decode() and
     * decodeForRefresh() is how far past `exp` we still accept a token.
     * Every security-critical check (algorithm, signature, issuer,
     * audience, version, revocation) is identical across both callers.
     *
     * @return array{ok: true, payload: array<string, mixed>}|array{ok: false, error: string}
     */
    private static function decodeWithExpiryWindow(string $token, int $maxSecondsPastExp): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return ['ok' => false, 'error' => self::ERR_MALFORMED];
        }
        [$headerEnc, $payloadEnc, $sigEnc] = $parts;

        $headerJson  = self::b64urlDecode($headerEnc);
        $payloadJson = self::b64urlDecode($payloadEnc);
        if ($headerJson === null || $payloadJson === null) {
            return ['ok' => false, 'error' => self::ERR_MALFORMED];
        }

        $header  = json_decode($headerJson, true);
        $payload = json_decode($payloadJson, true);
        if (!is_array($header) || !is_array($payload)) {
            return ['ok' => false, 'error' => self::ERR_MALFORMED];
        }

        // Algorithm check FIRST — defends against "alg=none" attacks
        // and forces all tokens through the HMAC verify below.
        if (($header['alg'] ?? '') !== self::ALGORITHM) {
            return ['ok' => false, 'error' => self::ERR_BAD_ALGORITHM];
        }

        $expectedSig = hash_hmac('sha256', $headerEnc . '.' . $payloadEnc, self::secret(), true);
        $actualSig   = self::b64urlDecode($sigEnc);
        if ($actualSig === null || !hash_equals($expectedSig, $actualSig)) {
            return ['ok' => false, 'error' => self::ERR_BAD_SIGNATURE];
        }

        $now = time();

        $exp = isset($payload['exp']) ? (int) $payload['exp'] : 0;
        if ($exp <= 0) {
            return ['ok' => false, 'error' => self::ERR_MISSING_CLAIM];
        }
        // $maxSecondsPastExp is SKEW_SECONDS on the canonical decode path,
        // larger (SKEW + REFRESH_GRACE_SECONDS) when called via
        // decodeForRefresh() — the ONLY semantic difference between the
        // two public entry points.
        if ($now > $exp + $maxSecondsPastExp) {
            return ['ok' => false, 'error' => self::ERR_EXPIRED];
        }

        $iat = isset($payload['iat']) ? (int) $payload['iat'] : 0;
        if ($iat > $now + self::SKEW_SECONDS) {
            return ['ok' => false, 'error' => self::ERR_NOT_YET_VALID];
        }

        $iss = (string) ($payload['iss'] ?? '');
        if ($iss !== self::issuer()) {
            return ['ok' => false, 'error' => self::ERR_BAD_ISSUER];
        }

        // Audience: only check when this site has one configured.
        // Multi-origin allowlist support — the token's `aud` must
        // EXACTLY match one of the configured entries (no substring,
        // no wildcard). Tokens minted before BCC_FRONTEND_ORIGIN was
        // set lack the aud claim and remain valid until then.
        $allowlist = self::audienceAllowlist();
        if ($allowlist !== []) {
            $tokenAud = (string) ($payload['aud'] ?? '');
            if (!in_array($tokenAud, $allowlist, true)) {
                return ['ok' => false, 'error' => self::ERR_BAD_AUDIENCE];
            }
        }

        $ver = isset($payload['ver']) ? (int) $payload['ver'] : 0;
        if ($ver !== self::TOKEN_VERSION) {
            return ['ok' => false, 'error' => self::ERR_BAD_VERSION];
        }

        $userId = isset($payload['user_id']) ? (int) $payload['user_id'] : 0;
        if ($userId <= 0) {
            return ['ok' => false, 'error' => self::ERR_MISSING_CLAIM];
        }

        // Per-user revocation gate. A bumped bcc_token_version on the
        // user's wp_usermeta invalidates every token minted before the
        // bump. Default 0 on both sides (token claim absent, meta unset)
        // means existing tokens remain valid until first revocation.
        $tokenTv   = isset($payload['tv']) ? (int) $payload['tv'] : 0;
        $currentTv = self::currentTokenVersion($userId);
        if ($tokenTv !== $currentTv) {
            return ['ok' => false, 'error' => self::ERR_REVOKED];
        }

        return ['ok' => true, 'payload' => $payload];
    }

    /**
     * Read the user's revocation counter as int. Missing meta → 0.
     * Cached transparently by WP's user-meta cache; no extra plumbing.
     */
    private static function currentTokenVersion(int $userId): int
    {
        return (int) get_user_meta($userId, self::TOKEN_VERSION_META_KEY, true);
    }

    private static function secret(): string
    {
        return wp_salt('auth');
    }

    private static function issuer(): string
    {
        return home_url('/');
    }

    /**
     * Audience allowlist resolved from BCC_FRONTEND_ORIGIN.
     *
     * - undefined / empty → returns []; aud is omitted from minted
     *   tokens and not checked on decode.
     * - single origin     → returns [origin].
     * - comma-separated   → returns the full list, in order.
     *
     * Encode uses the FIRST entry as the canonical mint value.
     * Decode accepts any entry via in_array (exact-string match — no
     * substring, no wildcard). This handles the common multi-tenant
     * case (one backend serves both prod-frontend AND staging) without
     * weakening the equality check.
     *
     * @return list<string>
     */
    private static function audienceAllowlist(): array
    {
        if (!defined('BCC_FRONTEND_ORIGIN') || BCC_FRONTEND_ORIGIN === '') {
            return [];
        }
        $entries = array_map('trim', explode(',', (string) BCC_FRONTEND_ORIGIN));
        return array_values(array_filter($entries, static fn(string $e): bool => $e !== ''));
    }

    /** RFC 7515 §2 base64url (no padding). */
    private static function b64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** RFC 7515 §2 base64url decode. Returns null when input isn't valid base64url. */
    private static function b64urlDecode(string $data): ?string
    {
        $b64 = strtr($data, '-_', '+/');
        $remainder = strlen($b64) % 4;
        if ($remainder !== 0) {
            $b64 .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode($b64, true);
        return $decoded === false ? null : $decoded;
    }
}
