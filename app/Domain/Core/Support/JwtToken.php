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
    /** This one token was signed out via POST /auth/logout (per-jti denylist). */
    public const ERR_LOGGED_OUT    = 'jwt_logged_out';

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
     * @return array{ok: true, payload: array<string, mixed>}|array{ok: false, error: string, user_id?: int}
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
     * @return array{ok: true, payload: array<string, mixed>}|array{ok: false, error: string, user_id?: int}
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
     * @return array{ok: true, payload: array<string, mixed>}|array{ok: false, error: string, user_id?: int}
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
            // The user id rides along (signature, issuer, audience and
            // version are already verified at this point) so /auth/refresh
            // can say WHY: a suspension revokes every token (F-119), and
            // the member must be told to sign in again read-only (F-305).
            return ['ok' => false, 'error' => self::ERR_REVOKED, 'user_id' => $userId];
        }

        // Single-token logout (F-120): a token signed out via POST
        // /auth/logout is denylisted by its jti until it could no longer be
        // used anyway (exp + skew + refresh grace). Checked here — the path
        // both decode() and decodeForRefresh() share — so a logged-out token
        // can neither authenticate nor be refreshed.
        // The denylist lives in the user's own meta, which currentTokenVersion()
        // above has just loaded into WP's per-request meta cache — so this
        // check costs no extra query on the hot path (staging/prod run with
        // no persistent object cache, where a transient would be one DB query
        // per authenticated request).
        $jti = (string) ($payload['jti'] ?? '');
        if ($jti !== '' && self::isLoggedOut($userId, $jti)) {
            return ['ok' => false, 'error' => self::ERR_LOGGED_OUT];
        }

        return ['ok' => true, 'payload' => $payload];
    }

    /** User-meta key holding the user's single-token-logout denylist. */
    public const DENYLIST_META_KEY = 'bcc_jwt_denylist';

    /** Most entries kept per user (oldest-expiring dropped beyond it). */
    public const DENYLIST_MAX_ENTRIES = 100;

    /**
     * Sign out ONE token (F-120): denylist its jti for as long as the token
     * could otherwise still be used — until exp, plus the decode skew, plus
     * the refresh grace window (decodeForRefresh accepts a token that long
     * past exp). Other tokens of the same user are unaffected; for "every
     * device" use revokeAllForUser(). No-op without a jti or user id.
     *
     * Stored as user meta `{ sha256(jti)[0..40] => until_ts }`, pruned of
     * expired entries on every write and capped at DENYLIST_MAX_ENTRIES.
     *
     * @param array<string, mixed> $payload a payload returned by decode()/decodeForRefresh()
     */
    public static function logOut(array $payload): void
    {
        $jti    = (string) ($payload['jti'] ?? '');
        $userId = (int) ($payload['user_id'] ?? 0);
        if ($jti === '' || $userId <= 0) {
            return;
        }

        // Read-modify-write: serialise per user so two concurrent logouts
        // cannot each read the old list and drop the other's entry. If the
        // lock cannot be had (contended past the wait, or the driver errored)
        // the write still happens — logout is best-effort and must never fail
        // — and only that narrow lost-update race remains.
        $lockKey = self::DENYLIST_LOCK_PREFIX . $userId;
        $locked  = \BCC\Core\DB\AdvisoryLock::acquire($lockKey, self::DENYLIST_LOCK_WAIT);
        if (!$locked) {
            \BCC\Core\Log\Logger::warning('[bcc-trust] jwt denylist written without lock', ['user_id' => $userId]);
        }

        try {
            // Re-read under the lock: this request's meta cache may predate a
            // concurrent writer's update.
            wp_cache_delete($userId, 'user_meta');

            $now  = time();
            $list = self::denylist($userId);
            foreach ($list as $hash => $until) {
                if ($until < $now) {
                    unset($list[$hash]);
                }
            }
            $list[self::denylistHash($jti)] = $now + self::denylistTtl((int) ($payload['exp'] ?? 0));
            if (count($list) > self::DENYLIST_MAX_ENTRIES) {
                asort($list);
                $list = array_slice($list, -self::DENYLIST_MAX_ENTRIES, null, true);
            }
            update_user_meta($userId, self::DENYLIST_META_KEY, $list);
        } finally {
            if ($locked) {
                \BCC\Core\DB\AdvisoryLock::release($lockKey);
            }
        }
    }

    private const DENYLIST_LOCK_PREFIX = 'bcc_jwt_deny_';

    /** Seconds logOut() waits for the per-user denylist lock. */
    private const DENYLIST_LOCK_WAIT = 2;

    /** Seconds a logged-out jti must stay denylisted. Always ≥ 1. */
    public static function denylistTtl(int $exp): int
    {
        return max(1, $exp - time() + self::SKEW_SECONDS + self::REFRESH_GRACE_SECONDS);
    }

    private static function isLoggedOut(int $userId, string $jti): bool
    {
        $until = self::denylist($userId)[self::denylistHash($jti)] ?? null;
        return $until !== null && $until >= time();
    }

    /** @return array<string, int> */
    private static function denylist(int $userId): array
    {
        $raw = get_user_meta($userId, self::DENYLIST_META_KEY, true);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $hash => $until) {
            $out[(string) $hash] = (int) $until;
        }
        return $out;
    }

    private static function denylistHash(string $jti): string
    {
        return substr(hash('sha256', $jti), 0, 40);
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
     * - comma-separated   → returns every EXACT origin, in order.
     *
     * Encode uses the FIRST entry as the canonical mint value.
     * Decode accepts any entry via in_array (exact-string match — no
     * substring, no wildcard). This handles the common multi-tenant
     * case (one backend serves both prod-frontend AND staging) without
     * weakening the equality check.
     *
     * `regex:` preview patterns are excluded by FrontendOrigin: an
     * audience must be a concrete origin, and a raw pattern minted into
     * `aud` would validate only until the pattern is removed from the
     * allowlist — then every outstanding token fails at once, long after
     * the change that caused it.
     *
     * @return list<string>
     */
    private static function audienceAllowlist(): array
    {
        return FrontendOrigin::exactOrigins();
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
