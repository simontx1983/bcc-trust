<?php
/**
 * AuthSupport — shared statics + constants for the /auth/* controllers.
 *
 * Split out of the monolithic AuthEndpoint (Phase 11 architecture split
 * #3 of 4). This is a PURELY STRUCTURAL home for the helpers and
 * constants that the per-family Auth controllers
 * (WalletAuthController, PasswordAuthController, EmailVerificationController,
 * TwoFactorController, OAuthController, SessionController) share.
 *
 * Every method here is the VERBATIM body of a former `private static`
 * helper on AuthEndpoint, converted to `public static` so the controllers
 * can call it. Every constant here is a former AuthEndpoint constant,
 * promoted to `public const`. NO logic, key string, TTL, or namespace was
 * changed in the move — in-flight tokens + the API contract depend on the
 * transient/usermeta key strings being byte-identical.
 *
 * @package BCC\Trust\Core\REST\Auth
 * @since V1 (Phase 11 split — 2026-06)
 */

namespace BCC\Trust\Core\REST\Auth;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Services\AuthMailer;
use BCC\Trust\Core\Services\HandleService;
use BCC\Trust\Core\Services\UserViewService;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\FrontendRedirect;
use BCC\Trust\Core\Support\JwtToken;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

final class AuthSupport
{
    public const ROUTE_NAMESPACE = 'bcc/v1';

    /** Per-user-per-minute throttle (matches AJAX `wallet_challenge` budget). */
    public const NONCE_RATE_LIMIT = 10;
    /** Per-user-per-minute throttle (matches AJAX `wallet_verify` budget). */
    public const VERIFY_RATE_LIMIT = 5;
    /** Anon-IP-keyed throttle for signup. Throttle::allow buckets by IP for unauthed requests. */
    public const SIGNUP_RATE_LIMIT = 5;
    /** Anon-IP-keyed throttle for login. Same budget as signup — login is the obvious brute-force target. */
    public const LOGIN_RATE_LIMIT = 5;
    /**
     * Per-identifier login throttle (defense-in-depth vs distributed
     * brute-force from many IPs against ONE account, which the IP bucket
     * can't see). Keyed by a hash of the submitted identifier — so it fires
     * the same whether or not the account exists (no enumeration oracle).
     * Deliberately generous over a longer window: a legitimate user never
     * makes 20 attempts in 15 minutes, while sustained probing of a single
     * identifier is capped. Tradeoff: a determined attacker can trip this to
     * briefly (one window) lock a targeted identifier — accepted because
     * password login also requires the mandatory email OTP, so this only
     * bounds password-probing rate, not account access.
     */
    public const LOGIN_ACCOUNT_RATE_LIMIT  = 20;
    public const LOGIN_ACCOUNT_RATE_WINDOW = 900;
    /** Anon-IP-keyed throttle for the public wallet-nonce route. Same budget as the authed nonce. */
    public const WALLET_NONCE_RATE_LIMIT = 10;
    /** Anon-IP-keyed throttle for wallet-login. Sibling of /auth/login. */
    public const WALLET_LOGIN_RATE_LIMIT = 5;
    /** Anon-IP-keyed throttle for wallet-signup. Sibling of /auth/signup. */
    public const WALLET_SIGNUP_RATE_LIMIT = 5;
    /**
     * IP-keyed (unauthed) + per-user (authed) throttle for /auth/refresh.
     * Refresh is the mobile-survivability silent-retry path on 401; a
     * misbehaving client can hammer it with stale tokens. Keep the
     * ceiling low enough that brute-force replay attempts against
     * an expired-token + grace window are throttled.
     */
    public const REFRESH_RATE_LIMIT = 30;
    /**
     * Anon-IP-keyed (hourly) throttle for /auth/forgot-password. Caps
     * email-bomb spam against any single account from one source.
     * 3/hour covers the legitimate retry pattern (typo, mail didn't
     * arrive, try once more) without giving a harasser a useful weapon.
     */
    public const FORGOT_PASSWORD_RATE_LIMIT = 3;
    /**
     * Anon-IP-keyed (hourly) throttle for /auth/reset-password. Defense
     * in depth against key brute force — WP reset keys are ~20 random
     * chars so this is bug-or-misuse insurance, not an active budget.
     */
    public const RESET_PASSWORD_RATE_LIMIT = 10;
    /**
     * Anon-IP-keyed (hourly) throttle for /auth/verify-email. Caps OTP
     * brute-force attempts — 10/hour against a 6-digit code is negligible
     * attack surface given the 15-minute OTP TTL.
     */
    public const VERIFY_EMAIL_RATE_LIMIT = 10;
    /**
     * Anon-IP-keyed (hourly) throttle for /auth/resend-verification.
     * Caps email-bomb abuse from a single source: 3/hour covers the
     * "I didn't get it" retry pattern without enabling harassment.
     */
    public const RESEND_VERIFICATION_RATE_LIMIT = 3;
    /** Anon-IP-keyed (per-minute) throttle for /auth/2fa/verify. */
    public const TWO_FA_VERIFY_RATE_LIMIT = 10;
    /** Anon-IP-keyed (per-minute) throttle for /auth/2fa/resend. */
    public const TWO_FA_RESEND_RATE_LIMIT = 3;
    /** 2FA OTP transient TTL: 5 minutes. */
    public const TWO_FA_OTP_TTL = 300;
    /** 2FA challenge-token transient TTL: 10 minutes. */
    public const TWO_FA_CHALLENGE_TTL = 600;
    /** Anon-IP-keyed (per-minute) throttle for /auth/oauth. */
    public const OAUTH_LOGIN_RATE_LIMIT = 10;
    /** Anon-IP-keyed (per-minute) throttle for /auth/oauth-complete. */
    public const OAUTH_COMPLETE_RATE_LIMIT = 5;
    /** OAuth provider-token transient TTL: 15 minutes. */
    public const OAUTH_PROVIDER_TOKEN_TTL = 900;
    /** User meta key prefix for stored OAuth provider IDs, e.g. _bcc_oauth_google. */
    public const META_OAUTH_PREFIX = '_bcc_oauth_';

    /**
     * User meta key for email-verification state.
     *   '0' = pending verification (set at email-signup).
     *   '1' = verified (set by /auth/verify-email or at wallet-signup).
     *   ''  = not present (legacy user, grandfathered through login gate).
     */
    public const META_EMAIL_VERIFIED = '_bcc_email_verified';

    /** OTP transient TTL: 15 minutes. */
    public const OTP_TTL = 900;
    /** Verify-token transient TTL: 24 hours. */
    public const TOKEN_TTL = 86400;

    /** Minimum password length at signup. WP itself accepts shorter, we don't. */
    public const SIGNUP_MIN_PASSWORD_LENGTH = 8;

    /**
     * JWT lifetime — 7 days for V1.
     *
     * Trade-off (locked 2026-04): a longer-lived token means we can ship
     * V1 without a refresh-token flow. The cost is a wider blast radius
     * if a token is exfiltrated (no proactive expiry → sole revocation
     * path is a `wp_salt('auth')` rotation, which invalidates every
     * outstanding token at once).
     *
     * Tightening to 3600 = 1 hour requires:
     *   - a refresh endpoint (POST /auth/refresh)
     *   - frontend silent-refresh handling in NextAuth
     *   - a rotating-refresh-token store (httpOnly cookie or DB row)
     *
     * Doing that is a one-constant flip in JwtToken::TTL_SECONDS plus a
     * refresh route — but until those land, 7 days is the right
     * ergonomic choice for V1. Single source of truth lives in
     * JwtToken; we expose it here for response payloads.
     */
    public const JWT_TTL_SECONDS = JwtToken::TTL_SECONDS;

    /**
     * Resolve the in_good_standing boolean for the auth response. Reads
     * the user's reputation tier (single repo call) and routes through
     * the canonical UserViewService::isInGoodStanding tier-mapping so
     * the source of truth stays in one place.
     *
     * Wrapped to fail-open: any error returns true (the more permissive
     * default). The auth response should never block on a downstream
     * tier-lookup failure — worst-case the chrome stamp shows on a user
     * who's technically not in good standing, which is recoverable on
     * next login.
     */
    public static function resolveInGoodStanding(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        try {
            $tier = Plugin::instance()->reputationRepository()->getTier($userId);
            return UserViewService::isInGoodStanding($tier);
        } catch (\Throwable $e) {
            Logger::warning('[AuthEndpoint] in_good_standing lookup failed; fail-open', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return true;
        }
    }

    /**
     * True when a WP_Error from wp_insert_user reflects a MySQL
     * duplicate-key error — the actual atomic serializer firing for a
     * race-winner / race-loser pair on the user_login UNIQUE constraint.
     *
     * wp_insert_user wraps the $wpdb->insert failure as
     * WP_Error('db_insert_error') with the MySQL error string in the
     * data slot. Detection is by substring on a stable MySQL phrase.
     */
    public static function isDuplicateKeyError(\WP_Error $error): bool
    {
        if ($error->get_error_code() !== 'db_insert_error') {
            return false;
        }
        $data = $error->get_error_data();
        if (!is_string($data)) {
            return false;
        }
        return str_contains($data, 'Duplicate entry');
    }

    /**
     * Shared finalization path for both OTP and token verification.
     *
     * Sets _bcc_email_verified = '1', cleans up any remaining OTP
     * transient, sets the WP session cookie, and mints a JWT. The
     * response shape matches /auth/login so the frontend can treat
     * both identically.
     */
    public static function finalizeVerification(int $userId): WP_REST_Response
    {
        $user = get_userdata($userId);
        if (!($user instanceof \WP_User)) {
            return ApiResponse::error('bcc_internal_error', 'User not found.', 500);
        }

        update_user_meta($userId, self::META_EMAIL_VERIFIED, '1');

        // Clean up the OTP transient regardless of which path was used.
        // Token-path verification bypasses consumeOtpForUser but the OTP
        // transient should not linger past a successful verification.
        delete_transient('bcc_otp_' . (string) $userId);

        $handle = (string) get_user_meta($userId, HandleService::META_HANDLE, true);
        if ($handle === '') {
            return ApiResponse::error(
                'bcc_invalid_state',
                'Account is missing a handle.',
                409
            );
        }

        wp_set_current_user($userId);
        wp_set_auth_cookie($userId, true);

        $token = JwtToken::encode($userId, $handle);

        Logger::audit('email_verified', [
            'user_id' => $userId,
            'via'     => 'rest',
        ]);

        do_action('bcc_email_verified', $userId);

        // Welcome email — best-effort, never throws. Sent once at the moment
        // the account transitions from pending → active. FrontendRedirect
        // resolves the floor URL from the same env var used everywhere else.
        AuthMailer::sendWelcomeEmail(
            $user->user_email,
            $handle,
            FrontendRedirect::defaultReturn('/')
        );

        $response = ApiResponse::ok([
            'user_id'          => $userId,
            'handle'           => $handle,
            'token'            => $token,
            'expires_in'       => self::JWT_TTL_SECONDS,
            'token_type'       => 'Bearer',
            'in_good_standing' => self::resolveInGoodStanding($userId),
        ]);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * Generate a 6-digit OTP string, zero-padded.
     * Uses random_int() (CSPRNG). The string is returned in plain text
     * so it can be shown to the user and emailed; storage is via
     * storeOtpForUser() which HMAC-hashes it before writing.
     */
    public static function generateOtp(): string
    {
        return sprintf('%06d', random_int(0, 999999));
    }

    /**
     * Generate a single-use verify token (64 hex chars = 256 bits).
     * Stored in a transient keyed by the token string itself; the userId
     * is the value. Consuming deletes the transient (single-use).
     */
    public static function generateVerifyToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Store an HMAC-SHA256 hash of the OTP for $userId.
     *
     * Keyed by `bcc_otp_{userId}` so any concurrent call (e.g. from
     * resendVerification) overwrites the previous OTP atomically.
     * TTL: OTP_TTL seconds (15 minutes).
     */
    public static function storeOtpForUser(int $userId, string $otpCode): void
    {
        $key  = 'bcc_otp_' . (string) $userId;
        $hash = hash_hmac('sha256', $otpCode, (string) wp_salt('auth'));
        set_transient($key, $hash, self::OTP_TTL);
    }

    /**
     * Store a verify token mapped to $userId.
     *
     * Key: `bcc_vt_{token}`, value: string userId, TTL: TOKEN_TTL (24 h).
     * Multiple tokens can be live at once (one per resend call); they
     * expire naturally since we can't bulk-invalidate by userId.
     */
    public static function storeVerifyToken(string $token, int $userId): void
    {
        set_transient('bcc_vt_' . $token, (string) $userId, self::TOKEN_TTL);
    }

    /**
     * Verify the submitted OTP code against the stored HMAC hash and,
     * on match, delete the transient (single-use). Returns true on match,
     * false on mismatch or expired/missing transient.
     *
     * Uses hash_equals() for timing-safe comparison — the HMAC prevents
     * length-extension attacks; hash_equals() prevents timing oracles.
     */
    public static function consumeOtpForUser(int $userId, string $otpCode): bool
    {
        $key    = 'bcc_otp_' . (string) $userId;
        $stored = get_transient($key);
        if ($stored === false || !is_string($stored) || $stored === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $otpCode, (string) wp_salt('auth'));
        if (!hash_equals($expected, $stored)) {
            return false;
        }

        delete_transient($key);
        return true;
    }

    /**
     * Consume a verify token and return the associated userId, or null
     * when the token is missing, malformed, or already used. Consuming
     * deletes the transient immediately so the link is single-use.
     */
    public static function consumeVerifyToken(string $token): ?int
    {
        if ($token === '') {
            return null;
        }

        $stored = get_transient('bcc_vt_' . $token);
        if ($stored === false || !is_string($stored) || $stored === '') {
            return null;
        }

        $userId = (int) $stored;
        if ($userId <= 0) {
            return null;
        }

        delete_transient('bcc_vt_' . $token);
        return $userId;
    }

    public static function toIso8601(int $epochSeconds): string
    {
        if ($epochSeconds <= 0) {
            return '';
        }
        return gmdate('Y-m-d\TH:i:s\Z', $epochSeconds);
    }

    /**
     * Map a HandleService::ERR_* code to a user-facing message. Kept
     * inline here (not in HandleService) so the service stays free of
     * presentation concerns — error codes are the contract, messages
     * are the caller's responsibility.
     */
    public static function handleErrorMessage(string $code): string
    {
        return match ($code) {
            HandleService::ERR_RESERVED => 'That handle is reserved.',
            default                     => 'Handle must be 3–20 chars, lowercase letters, digits, or hyphens, '
                                            . 'with no leading, trailing, or consecutive hyphens.',
        };
    }

    /**
     * Derive an internal wp_user_login from the BCC handle. The 'u_'
     * prefix keeps logins disjoint from handles so a future admin
     * looking up wp_users by login sees the BCC identity at a glance,
     * and avoids a collision class where someone signs up before BCC
     * existed and reserved a username we'd otherwise want to assign.
     */
    public static function deriveLogin(string $handle): string
    {
        return 'u_' . $handle;
    }

    /**
     * Deterministic placeholder email for a wallet-signup with no
     * caller-supplied email. WP requires a unique user_email; the hash
     * suffix keeps the address stable per wallet so a retry of the
     * same wallet collides with itself rather than leaking unique
     * placeholders across the wp_users table.
     *
     * Domain is AccountRecoveryService::PLACEHOLDER_EMAIL_DOMAIN
     * (`noreply.bcc.local`) — the single source of truth that
     * AccountRecoveryService::isPlaceholderEmail() reads back to tell a
     * real recovery email from a synthetic one. `noreply` makes the
     * no-mail intent explicit; `.local` keeps it firmly out of any
     * real-MX collision class.
     */
    public static function placeholderEmailForWallet(string $walletAddress): string
    {
        return 'wallet-' . substr(md5(strtolower($walletAddress)), 0, 16)
            . '@' . \BCC\Trust\Core\Services\AccountRecoveryService::PLACEHOLDER_EMAIL_DOMAIN;
    }

    // ── 2FA OTP / challenge-token helpers ─────────────────────────────

    public static function store2faOtp(int $userId, string $otpCode): void
    {
        $hash = hash_hmac('sha256', $otpCode, (string) wp_salt('auth'));
        set_transient('bcc_2fa_otp_' . (string) $userId, $hash, self::TWO_FA_OTP_TTL);
    }

    public static function consume2faOtp(int $userId, string $code): bool
    {
        $key    = 'bcc_2fa_otp_' . (string) $userId;
        $stored = get_transient($key);
        if ($stored === false || !is_string($stored) || $stored === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $code, (string) wp_salt('auth'));
        if (!hash_equals($expected, $stored)) {
            return false;
        }
        delete_transient($key);
        return true;
    }

    public static function store2faChallenge(string $token, int $userId): void
    {
        set_transient('bcc_2fa_ct_' . $token, (string) $userId, self::TWO_FA_CHALLENGE_TTL);
    }

    /** Validate a challenge token without consuming it. Returns userId or null. */
    public static function peek2faChallenge(string $token): ?int
    {
        if ($token === '') {
            return null;
        }
        $stored = get_transient('bcc_2fa_ct_' . $token);
        if ($stored === false || !is_string($stored) || $stored === '') {
            return null;
        }
        $id = (int) $stored;
        return $id > 0 ? $id : null;
    }

    /** Delete the challenge token transient (call after successful OTP verify). */
    public static function consume2faChallenge(string $token): void
    {
        if ($token !== '') {
            delete_transient('bcc_2fa_ct_' . $token);
        }
    }

    // ── OAuth provider-token helpers ──────────────────────────────────

    /**
     * @param array{provider: string, provider_id: string, email: string, display_name: string} $data
     */
    public static function storeOauthProviderToken(string $token, array $data): void
    {
        set_transient('bcc_oauth_pt_' . $token, wp_json_encode($data), self::OAUTH_PROVIDER_TOKEN_TTL);
    }

    /**
     * Read the provider token without deleting it (safe for validation passes).
     *
     * @return array<array-key, mixed>|null
     */
    public static function peekOauthProviderToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        $raw = get_transient('bcc_oauth_pt_' . $token);
        if ($raw === false || !is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /** Delete the provider token (call after successful user creation). */
    public static function consumeOauthProviderToken(string $token): void
    {
        if ($token !== '') {
            delete_transient('bcc_oauth_pt_' . $token);
        }
    }

    /**
     * Find a WP user by OAuth provider ID stored in user meta.
     * Returns the user ID or null if not found.
     */
    public static function findUserByOauthProvider(string $provider, string $providerId): ?int
    {
        if ($provider === '' || $providerId === '') {
            return null;
        }
        $users = get_users([
            'meta_key'   => self::META_OAUTH_PREFIX . $provider,
            'meta_value' => $providerId,
            'number'     => 1,
            'fields'     => 'ID',
        ]);
        if (!empty($users)) {
            $id = (int) reset($users);
            return $id > 0 ? $id : null;
        }
        return null;
    }
}
