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
use BCC\Trust\Core\Services\AccountRecoveryService;
use BCC\Trust\Core\Services\AuthMailer;
use BCC\Trust\Core\Services\HandleService;
use BCC\Trust\Core\Services\UserViewService;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\FrontendRedirect;
use BCC\Trust\Core\Support\JwtToken;
use BCC\Trust\Onchain\Repositories\WalletRepository;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

final class AuthSupport
{
    public const ROUTE_NAMESPACE = 'bcc/v1';

    /**
     * Hygiene gate for a user-supplied PUBLIC display name
     * (owner-directed 2026-08-06). Returns '' — which every signup path
     * treats as "fall back to the handle" — when the candidate would
     * leak non-public identity onto public surfaces:
     *   - email-shaped values (any '@'): the observed leak class came
     *     from WP-native account creation defaulting display_name to an
     *     email login, and OAuth providers can return the email as the
     *     "name";
     *   - internal-login-shaped values ('u_' prefix, §B3): the login is
     *     internal by design and must never render publicly.
     *
     * Pure static (unit-tested). Callers sanitize/trim before or after;
     * this only decides accept-vs-fallback.
     */
    public static function sanitizePublicDisplayName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || str_contains($name, '@') || str_starts_with($name, 'u_')) {
            return '';
        }
        return $name;
    }

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
    /** Per-WALLET (chain + address) throttle for wallet-login, per minute (F-123; was per IP). */
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

    /**
     * Wrong 2FA codes allowed per (user, challenge) before the OTP and the
     * challenge are burned and the user must sign in again (F-124). The
     * per-IP TWO_FA_VERIFY_RATE_LIMIT alone did not bound an attacker who
     * spreads guesses across addresses.
     */
    public const TWO_FA_MAX_FAILURES = 5;

    /**
     * Absolute 2FA challenge lifetime (F-292): a resend may re-arm a live
     * challenge for one more code lifetime (TWO_FA_OTP_TTL), but never once
     * the challenge is older than this. So a challenge lives at most
     * TWO_FA_CHALLENGE_MAX_AGE + TWO_FA_OTP_TTL (35 min), however many
     * resends happen.
     */
    public const TWO_FA_CHALLENGE_MAX_AGE = 1800;

    /**
     * Window for the two per-(user, challenge) wrong-code buckets (F-292).
     *
     * ⚠ Must be MUCH longer than a challenge's whole possible life, not
     * merely equal to it. Throttle is a SLIDING window: a guess's weight
     * decays linearly over the window after its bucket ends, so with a
     * window equal to the lifetime, guesses made early could fade out
     * while resends kept the challenge alive, and the 5-per-challenge cap
     * would slowly refill. With 6 h against a ≤ 35 min life, the most
     * TWO_FA_MAX_FAILURES guesses can fade in total is 5 × 35/360 ≈ 0.49
     * of one guess, so the cap stays exact. The bucket keys include the
     * challenge, so a long window costs nothing elsewhere.
     */
    public const TWO_FA_ATTEMPT_WINDOW = 21600;
    /** Per-PROVIDER-IDENTITY (provider + provider_id) throttle for /auth/oauth, per minute (F-131; was per IP). */
    public const OAUTH_LOGIN_RATE_LIMIT = 10;

    /**
     * Route-wide ceilings (per 60 s) for the two sign-in routes the frontend
     * calls SERVER-SIDE (F-123 / F-131). Their per-identity buckets replace
     * the per-IP ones: every call arrives from the BFF's single egress IP, so
     * a per-IP bucket was one shared bucket — anyone could lock every user
     * out. The ceiling bounds total load; it is set well above peak sign-ins.
     */
    public const WALLET_LOGIN_GLOBAL_LIMIT = 300;
    public const OAUTH_LOGIN_GLOBAL_LIMIT  = 300;

    /**
     * Throttle a sign-in attempt per IDENTITY plus a route-wide ceiling, both
     * with EXPLICIT bucket keys (Throttle otherwise keys an anonymous caller
     * by action + client IP — the shared-BFF-IP problem this replaces).
     *
     * $identity is hashed, so the bucket key never carries the raw wallet
     * address or provider id. Returns false (→ 429) when either bucket is
     * exhausted.
     */
    public static function allowIdentityAttempt(string $action, string $identity, int $perIdentity, int $global): bool
    {
        if (!\BCC\Core\Security\Throttle::allow($action . '_global', $global, 60, $action . '_global')) {
            // The route-wide ceiling tripping means EVERY identity is now
            // refused — a flood, or the ceiling set too low for real traffic.
            // Count it so it is visible, never silent.
            if (class_exists(\BCC\Core\Observability\DegradationMetrics::class)) {
                \BCC\Core\Observability\DegradationMetrics::record('auth_throttle', $action . '_ceiling');
            }
            return false;
        }
        return \BCC\Core\Security\Throttle::allow(
            $action,
            $perIdentity,
            60,
            $action . ':' . substr(hash('sha256', $identity), 0, 32)
        );
    }
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

    /**
     * Per-ACCOUNT limit (per 60 s) on cheap self-scoped writes — profile, prefs,
     * privacy, onboarding, mark-read, highlights, primary hall… (F-134). One
     * shared 'me_write' bucket; generous, because mark-read and celebrations
     * fire from ordinary UI use.
     */
    public const ME_WRITE_RATE_LIMIT = 120;

    /** Per-ACCOUNT handle changes per HOUR (F-134), on top of ME_WRITE_RATE_LIMIT. */
    public const HANDLE_CHANGE_RATE_LIMIT = 10;

    /** OTP transient TTL: 15 minutes. */
    public const OTP_TTL = 900;

    /**
     * Wrong email-verification codes allowed per (user, current code) before
     * that code is burned (F-48). A correct code signs the caller in, so the
     * per-IP VERIFY_EMAIL_RATE_LIMIT alone did not bound an attacker who
     * spreads guesses across addresses. Mirrors TWO_FA_MAX_FAILURES (F-124).
     */
    public const VERIFY_EMAIL_MAX_FAILURES = 5;
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

        // Email ownership just changed hands from "unproven" to "proven".
        // Revoke every Bearer token minted before this point: if someone
        // else created the account with this address (the pre-hijack case),
        // their sessions must not survive the inbox owner's verification.
        // Every account reaching here had a TYPED address (password, X,
        // wallet, or a legacy account via resend) — provider-verified
        // signups never do — so this runs on every verification.
        // Revoke FIRST, then mint: the token below carries the bumped
        // version and is the verifier's working session.
        JwtToken::revokeAllForUser($userId);
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
            // How many wallets are linked to the account the caller just
            // proved the email of. > 0 tells the frontend to show "this
            // account has a wallet linked — is it yours?" (it lists them via
            // the owner-only GET /wallets with the fresh token above, and
            // removes one via DELETE /wallets/{id}). A count only — no wallet
            // detail rides an auth response (wallet-privacy policy).
            'linked_wallet_count' => self::linkedWalletCount($userId),
        ]);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * Number of wallet links on the account, for the post-verification
     * "is this wallet yours?" prompt. Reads through the fail-CLOSED
     * repository path; if the read does not run, logs and returns 0 so the
     * verification itself still completes (the prompt is advisory — the
     * owner can always review wallets in Settings).
     */
    private static function linkedWalletCount(int $userId): int
    {
        try {
            return count(WalletRepository::getForUserOrThrow($userId));
        } catch (\Throwable $e) {
            Logger::warning('[bcc-trust] verify-email: linked wallet count unavailable', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return 0;
        }
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
     * Start (or restart) email verification for $userId at $email.
     *
     * Generates a fresh OTP (overwriting any previous one, so only the newest
     * mail's code works) and a single-use verify token, stores both, and
     * mails the standard verification email with a one-shot link to
     * /verify-email. The user completes it via POST /auth/verify-email
     * (EmailVerificationController), whose finalizeVerification() is the
     * ONLY place that sets META_EMAIL_VERIFIED = '1' for user_email.
     *
     * Does NOT touch META_EMAIL_VERIFIED — callers set '0' themselves where
     * the account is pending. Best-effort: AuthMailer never throws, and a
     * mail failure records a DegradationMetric without rolling anything back
     * (the user can resend via /auth/resend-verification).
     *
     * Shared by password signup, X signup (oauth-complete), wallet signup and
     * resendVerification — one sequence, so the paths cannot drift.
     */
    public static function beginEmailVerification(int $userId, string $email): void
    {
        $otpCode     = self::generateOtp();
        $verifyToken = self::generateVerifyToken();
        self::storeOtpForUser($userId, $otpCode);
        self::storeVerifyToken($verifyToken, $userId);

        $verifyPath = '/verify-email?email=' . rawurlencode($email) . '&token=' . rawurlencode($verifyToken);
        AuthMailer::sendVerificationEmail(
            $userId,
            $email,
            $otpCode,
            FrontendRedirect::defaultReturn($verifyPath)
        );
    }

    /**
     * Email state for a freshly created wallet-signup account.
     *
     * Sets META_EMAIL_VERIFIED = '0' — a wallet signature proves control of
     * the wallet, not of the inbox. A caller-supplied (real) address gets the
     * standard verification email, so its owner can prove it; a wallet
     * placeholder (`wallet-…@noreply.bcc.local`, no inbox) gets no mail and
     * stays '0'. Wallet users sign in with their wallet, so '0' gates nothing
     * for them except OAuth link-by-email (mayLinkOauthByEmail).
     */
    public static function startWalletSignupEmailVerification(int $userId, string $email): void
    {
        update_user_meta($userId, self::META_EMAIL_VERIFIED, '0');

        if ($email === '' || AccountRecoveryService::isPlaceholderEmail($email)) {
            return;
        }

        self::beginEmailVerification($userId, $email);
    }

    /**
     * Whether an OAuth sign-in may be linked into an existing account by
     * EMAIL match (oauthLogin's fallback after the provider-id lookup).
     *
     * Only an account whose user_email ownership was proven — exactly '1',
     * set by finalizeVerification() after an OTP/link verify, or by a
     * provider-verified signup — may be linked. Fail closed on '0'
     * (pending), '' (legacy, never verified) and anything else: an email
     * match against an unproven address proves nothing about identity, and
     * linking would let whoever typed a victim's address first receive the
     * victim's later sign-in (pre-account-takeover).
     */
    public static function mayLinkOauthByEmail(string $emailVerifiedFlag): bool
    {
        return $emailVerifiedFlag === '1';
    }

    /**
     * Whether an email forwarded by the OAuth bridge counts as verified BY
     * THE PROVIDER — the only case in which it may (a) be email-matched to an
     * existing account and (b) start a new account at '1'.
     *
     * Requires the signed bridge payload to say so explicitly
     * (`email_verified` is the JSON boolean `true` — not "1", not 1, not
     * "true") for a provider whose verification we trust (Google), and a
     * well-formed address. Everything else fails closed: an email's mere
     * presence proves nothing, since a provider can return an unverified one.
     *
     * @param mixed $emailVerifiedClaim the bridge's `email_verified` value
     */
    public static function isProviderVerifiedEmail(string $provider, string $email, $emailVerifiedClaim): bool
    {
        return $provider === 'google'
            && $emailVerifiedClaim === true
            && $email !== ''
            && is_email($email) !== false;
    }

    /**
     * Opaque identity of the user's CURRENT email-verification code, or null
     * when none is pending. A hash of the stored HMAC — never the code — so a
     * per-code counter keyed on it starts fresh whenever a new code is sent.
     */
    public static function emailOtpIdentity(int $userId): ?string
    {
        $stored = get_transient('bcc_otp_' . (string) $userId);
        if ($stored === false || !is_string($stored) || $stored === '') {
            return null;
        }
        return substr(hash('sha256', $stored), 0, 32);
    }

    /** Burn the user's pending email-verification code (the emailed link stays valid). */
    public static function burnEmailOtp(int $userId): void
    {
        delete_transient('bcc_otp_' . (string) $userId);
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
     * caller-supplied email. WP requires a unique user_email; the token
     * keeps the address stable per wallet so a retry of the same wallet
     * collides with its own orphaned row rather than sprinkling unique
     * placeholders across wp_users.
     *
     * PRIVACY — the token is an HMAC keyed on `wp_salt('auth')`, NOT a
     * bare hash of the address. The previous form was
     * `substr(md5(strtolower($address)), 0, 16)`, which is a *guessable*
     * function of the wallet: Gravatar publishes `md5(user_email)` on
     * every avatar URL, so an attacker with a candidate address could
     * recompute the placeholder, hash it, and confirm which member owns
     * that wallet — a member↔wallet oracle. Keying on the site salt
     * (which the attacker does not know) closes that oracle while
     * preserving determinism-per-wallet. Existing pre-fix accounts are
     * repaired by includes/database/backfill-wallet-placeholder-emails.php.
     * See docs/wallet-privacy-policy.md.
     *
     * Determinism now depends on salt stability. If the site salt is
     * rotated between a partial-failure signup and its retry, the retry
     * produces a different placeholder and won't collide with the
     * orphaned row — the only cost is a stray subscriber with no wallet
     * link (no security impact; the wallet itself is still single-linked
     * by the wallet_links UNIQUE, and the primary orphan guard is the
     * wp_delete_user rollback in the signup handler, not this email).
     * Salt rotation is rare and manual; the trade against closing the
     * oracle is overwhelmingly worth it.
     *
     * FAILS CLOSED on an empty salt by THROWING — the caller converts it
     * to an internal error before any user row or nonce is consumed.
     * Emitting a random token instead would give the account a
     * nondeterministic identity (breaking retry idempotency) and mask a
     * real misconfiguration; a bare hash of the address would reopen the
     * oracle. Neither is acceptable, so we refuse. `wp_salt()`
     * auto-generates and persists salts, so an empty return is a genuine
     * "salts unreadable/undefined" signal, not a normal condition.
     *
     * Domain is AccountRecoveryService::PLACEHOLDER_EMAIL_DOMAIN
     * (`noreply.bcc.local`) — the single source of truth that
     * AccountRecoveryService::isPlaceholderEmail() reads back to tell a
     * real recovery email from a synthetic one. `noreply` makes the
     * no-mail intent explicit; `.local` keeps it firmly out of any
     * real-MX collision class.
     *
     * @throws \RuntimeException when wp_salt('auth') is empty.
     */
    public static function placeholderEmailForWallet(string $walletAddress): string
    {
        $salt = (string) wp_salt('auth');
        if ($salt === '') {
            throw new \RuntimeException(
                'wp_salt(auth) is empty; refusing to mint a wallet placeholder email.'
            );
        }

        return 'wallet-' . substr(hash_hmac('sha256', strtolower($walletAddress), $salt), 0, 16)
            . '@' . \BCC\Trust\Core\Services\AccountRecoveryService::PLACEHOLDER_EMAIL_DOMAIN;
    }

    // ── 2FA OTP / challenge-token helpers ─────────────────────────────

    /**
     * Transient-key fragment for a challenge token: a hash, so the raw token
     * (a bearer secret for the 2FA step) never appears in an option name.
     */
    public static function twoFaChallengeKey(string $challengeToken): string
    {
        return substr(hash('sha256', $challengeToken), 0, 40);
    }

    /**
     * Store the OTP for ONE challenge (F-124). Bound to the challenge, not
     * the user: a challenge can only ever guess — and burn — its own code.
     */
    public static function store2faOtp(string $challengeToken, string $otpCode): void
    {
        $hash = hash_hmac('sha256', $otpCode, (string) wp_salt('auth'));
        set_transient('bcc_2fa_otp_' . self::twoFaChallengeKey($challengeToken), $hash, self::TWO_FA_OTP_TTL);
    }

    public static function consume2faOtp(string $challengeToken, string $code): bool
    {
        $key    = 'bcc_2fa_otp_' . self::twoFaChallengeKey($challengeToken);
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

    /**
     * Destroy a challenge's pending 2FA OTP (attempt cap reached — the code
     * can no longer be guessed; the user must sign in again for a new one).
     */
    public static function burn2faOtp(string $challengeToken): void
    {
        delete_transient('bcc_2fa_otp_' . self::twoFaChallengeKey($challengeToken));
    }

    /**
     * Register $token as the user's ONE live 2FA challenge (F-124).
     *
     * A per-user pointer names the current challenge; peek2faChallenge()
     * honours only that one, so a new login makes every earlier challenge
     * for the user unusable (for verify AND resend) and its OTP is deleted.
     * Without this, minting N challenges (each needs the password) would buy
     * N × TWO_FA_MAX_FAILURES guesses at a valid code. Consequence: a second
     * concurrent login (another device) cancels the first's pending 2FA.
     */
    public static function store2faChallenge(string $token, int $userId): void
    {
        $pointerKey = 'bcc_2fa_current_' . (string) $userId;
        $previous   = get_transient($pointerKey);
        if (is_string($previous) && $previous !== '') {
            delete_transient('bcc_2fa_otp_' . $previous);
        }
        set_transient('bcc_2fa_ct_' . $token, (string) $userId, self::TWO_FA_CHALLENGE_TTL);
        set_transient($pointerKey, self::twoFaChallengeKey($token), self::TWO_FA_CHALLENGE_TTL);
        // F-292: when this challenge was issued, for the absolute lifetime
        // a resend checks (rearm2faChallenge). Outlives any re-armed life.
        set_transient(
            'bcc_2fa_iat_' . self::twoFaChallengeKey($token),
            (string) time(),
            self::TWO_FA_CHALLENGE_MAX_AGE + self::TWO_FA_OTP_TTL
        );
    }

    /**
     * Validate a challenge token without consuming it. Returns userId, or null
     * when the token is unknown/expired OR has been superseded by a newer
     * login for the same user (only the current challenge is live).
     */
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
        if ($id <= 0) {
            return null;
        }
        $current = get_transient('bcc_2fa_current_' . (string) $id);
        if (!is_string($current) || !hash_equals($current, self::twoFaChallengeKey($token))) {
            return null;
        }
        return $id;
    }

    /**
     * Keep a LIVE challenge alive for one more code lifetime, so a code
     * sent by a resend can actually be used (F-292). Before, a resend late
     * in the challenge's 10 minutes mailed a 5-minute code into a
     * challenge about to expire, and that code then failed with "session
     * expired".
     *
     * Re-arms the challenge AND the user's current-challenge pointer to
     * TWO_FA_OTP_TTL from now, so the code and its challenge end together.
     * Refuses (returns false) once the challenge is older than
     * TWO_FA_CHALLENGE_MAX_AGE, or when its issue time is unknown (a
     * challenge minted before this change): the caller then answers
     * "session expired", the same as for an ended challenge. The caller
     * must have checked peek2faChallenge() first.
     *
     * The wrong-code counters are NOT reset by this (they are keyed on the
     * challenge with a window far longer than its life — see
     * TWO_FA_ATTEMPT_WINDOW), so resends never buy extra guesses.
     */
    public static function rearm2faChallenge(string $token, int $userId): bool
    {
        if ($token === '' || $userId <= 0) {
            return false;
        }
        $key    = self::twoFaChallengeKey($token);
        $issued = get_transient('bcc_2fa_iat_' . $key);
        if (!is_string($issued) || $issued === '' || !ctype_digit($issued)) {
            return false;
        }
        if (time() - (int) $issued >= self::TWO_FA_CHALLENGE_MAX_AGE) {
            return false;
        }
        set_transient('bcc_2fa_ct_' . $token, (string) $userId, self::TWO_FA_OTP_TTL);
        set_transient('bcc_2fa_current_' . (string) $userId, $key, self::TWO_FA_OTP_TTL);
        return true;
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
     * Single-use nonce gate for the signed /auth/oauth bridge request.
     *
     * Returns true the FIRST time a given nonce is seen (and marks it seen
     * for $ttl seconds), false on reuse — so a captured, still-in-window
     * signed request cannot be replayed. The nonce is hashed into the
     * transient key to bound its length and keep arbitrary header bytes out
     * of the key name.
     *
     * Callers MUST verify the request signature BEFORE consuming a nonce, so
     * a flood of bad-signature requests can't churn the transient store.
     * The tiny get→set window is acceptable: within it the only replay is
     * the identical, already-authentic request racing itself, which is not
     * a meaningful attack, and the timestamp-skew check bounds the rest.
     */
    public static function consumeOauthBridgeNonce(string $nonce, int $ttl): bool
    {
        if ($nonce === '') {
            return false;
        }
        $key = 'bcc_oauth_nonce_' . hash('sha256', $nonce);
        if (get_transient($key) !== false) {
            return false;
        }
        set_transient($key, 1, $ttl);
        return true;
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
