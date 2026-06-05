<?php
/**
 * Auth Endpoints — handles /bcc/v1/auth/* routes.
 *
 * Routes registered:
 *   - GET  /auth/nonce       — request a wallet-signature challenge
 *   - POST /auth/wallet-link — verify a wallet signature + link to the user
 *   - POST /auth/signup      — create a new account (email + handle, §B4/§B6)
 *   - POST /auth/token       — mint an HS256 JWT for the authenticated user
 *
 * REST equivalents of the legacy AJAX handlers in
 * BCC\Trust\Onchain\Controllers\WalletController, intended for the
 * cross-origin Next.js frontend (NextAuth + Bearer-token auth).
 *
 * Auth model:
 *   - /auth/nonce, /auth/wallet-link, /auth/token  → require an existing
 *     WP session (cookie or Application Password). 401 otherwise.
 *   - /auth/signup → unauthenticated; on success, `wp_set_auth_cookie()`
 *     sets the WP session for same-origin clients. Headless clients can
 *     follow up with /auth/token via a separate session bridge (cookie
 *     or Application Password) to mint a JWT.
 *
 * Auth is checked inside each handler (not via permission_callback) so
 * unauthenticated requests can return the canonical
 * {error: {code, message, status}} envelope per contract.
 *
 * All wallet logic delegates to BCC\Core\Wallet\WalletIdentityService —
 * the single source of truth for challenge generation, signature
 * verification, and wallet linking. No business logic here.
 *
 * Cache policy (all): `no-store`. Every route either mutates state
 * (signup, wallet-link, nonce) or mints a per-user secret (token).
 *
 * JWT signing: HS256 keyed off `wp_salt('auth')`. NextAuth reads the
 * same secret via env var on the JS side. No JWT plugin dependency —
 * pure PHP HMAC + URL-safe base64 (~30 LoC, see `mintJwt`).
 *
 * @package BCC\Trust\Core\REST
 * @since V1 (2026-04, §B4 + §B6 + JWT bridge)
 */

namespace BCC\Trust\Core\REST;

use BCC\Core\Crypto\WalletVerifier;
use BCC\Core\Log\Logger;
use BCC\Core\ServiceLocator;
use BCC\Core\Wallet\WalletIdentityService;
use BCC\Core\Wallet\WalletVerificationRequest;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Services\AccountSecurityMailer;
use BCC\Trust\Core\Services\AuthMailer;
use BCC\Trust\Core\Services\HandleService;
use BCC\Trust\Core\Services\UserViewService;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\FrontendRedirect;
use BCC\Trust\Core\Support\JwtToken;
use BCC\Trust\Core\Support\WalletAddressValidator;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\WalletRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class AuthEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    /** Per-user-per-minute throttle (matches AJAX `wallet_challenge` budget). */
    private const NONCE_RATE_LIMIT = 10;
    /** Per-user-per-minute throttle (matches AJAX `wallet_verify` budget). */
    private const VERIFY_RATE_LIMIT = 5;
    /** Anon-IP-keyed throttle for signup. Throttle::allow buckets by IP for unauthed requests. */
    private const SIGNUP_RATE_LIMIT = 5;
    /** Anon-IP-keyed throttle for login. Same budget as signup — login is the obvious brute-force target. */
    private const LOGIN_RATE_LIMIT = 5;
    /** Anon-IP-keyed throttle for the public wallet-nonce route. Same budget as the authed nonce. */
    private const WALLET_NONCE_RATE_LIMIT = 10;
    /** Anon-IP-keyed throttle for wallet-login. Sibling of /auth/login. */
    private const WALLET_LOGIN_RATE_LIMIT = 5;
    /** Anon-IP-keyed throttle for wallet-signup. Sibling of /auth/signup. */
    private const WALLET_SIGNUP_RATE_LIMIT = 5;
    /** Per-user-per-minute throttle for /auth/token mints. */
    private const TOKEN_RATE_LIMIT = 30;
    /**
     * IP-keyed (unauthed) + per-user (authed) throttle for /auth/refresh.
     * Refresh is the mobile-survivability silent-retry path on 401; a
     * misbehaving client can hammer it with stale tokens. Keep the
     * ceiling low enough that brute-force replay attempts against
     * an expired-token + grace window are throttled.
     */
    private const REFRESH_RATE_LIMIT = 30;
    /**
     * Anon-IP-keyed (hourly) throttle for /auth/forgot-password. Caps
     * email-bomb spam against any single account from one source.
     * 3/hour covers the legitimate retry pattern (typo, mail didn't
     * arrive, try once more) without giving a harasser a useful weapon.
     */
    private const FORGOT_PASSWORD_RATE_LIMIT = 3;
    /**
     * Anon-IP-keyed (hourly) throttle for /auth/reset-password. Defense
     * in depth against key brute force — WP reset keys are ~20 random
     * chars so this is bug-or-misuse insurance, not an active budget.
     */
    private const RESET_PASSWORD_RATE_LIMIT = 10;
    /**
     * Anon-IP-keyed (hourly) throttle for /auth/verify-email. Caps OTP
     * brute-force attempts — 10/hour against a 6-digit code is negligible
     * attack surface given the 15-minute OTP TTL.
     */
    private const VERIFY_EMAIL_RATE_LIMIT = 10;
    /**
     * Anon-IP-keyed (hourly) throttle for /auth/resend-verification.
     * Caps email-bomb abuse from a single source: 3/hour covers the
     * "I didn't get it" retry pattern without enabling harassment.
     */
    private const RESEND_VERIFICATION_RATE_LIMIT = 3;

    /**
     * User meta key for email-verification state.
     *   '0' = pending verification (set at email-signup).
     *   '1' = verified (set by /auth/verify-email or at wallet-signup).
     *   ''  = not present (legacy user, grandfathered through login gate).
     */
    private const META_EMAIL_VERIFIED = '_bcc_email_verified';

    /** OTP transient TTL: 15 minutes. */
    private const OTP_TTL = 900;
    /** Verify-token transient TTL: 24 hours. */
    private const TOKEN_TTL = 86400;

    /** Minimum password length at signup. WP itself accepts shorter, we don't. */
    private const SIGNUP_MIN_PASSWORD_LENGTH = 8;

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
    private const JWT_TTL_SECONDS = JwtToken::TTL_SECONDS;

    public static function register(): void
    {
        $instance = new self();

        // GET /auth/nonce — generate a wallet-signature challenge.
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/auth/nonce',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'nonce'],
                // Auth is checked inside the handler so unauthenticated
                // requests can return the canonical error envelope.
                'permission_callback' => '__return_true',
                'args' => [
                    'chain_slug' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'wallet_address' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );

        // POST /auth/wallet-link — verify signature + link wallet.
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/auth/wallet-link',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'walletLink'],
                'permission_callback' => '__return_true',
                'args' => [
                    'wallet_address' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'signature' => [
                        'required' => true,
                        'type'     => 'string',
                    ],
                    'wallet_type' => [
                        'required'          => false,
                        'type'              => 'string',
                        'default'           => 'user',
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'label' => [
                        'required'          => false,
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'extra' => [
                        'required' => false,
                        'type'     => 'object',
                        'default'  => [],
                    ],
                ],
            ]
        );

        // POST /auth/signup — create account with email + handle.
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/auth/signup',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'signup'],
                'permission_callback' => '__return_true',
                'args' => [
                    'email' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_email',
                    ],
                    'password' => [
                        'required' => true,
                        'type'     => 'string',
                    ],
                    'handle' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'display_name' => [
                        'required'          => false,
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );

        // POST /auth/token — mint a JWT for the authenticated user.
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/auth/token',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'token'],
                'permission_callback' => '__return_true',
            ]
        );

        // POST /auth/logout-everywhere — destructive: invalidate every
        // outstanding token for the current user (including the one
        // making this request). Closes the §J / Track-F stolen-session
        // threat-model loop: the user can respond to a suspected
        // hijack by nuking all sessions, then sign back in fresh.
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/auth/logout-everywhere',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'logoutEverywhere'],
                'permission_callback' => '__return_true',
            ]
        );

        // POST /auth/refresh — exchange a (recently-expired-OK) JWT for
        // a fresh one. Survivability seam for mobile clients whose
        // backgrounded app exits the 7-day TTL window. Auth is checked
        // inside the handler via JwtToken::decodeForRefresh on the
        // Bearer token; no session cookie required.
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/auth/refresh',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'refresh'],
                'permission_callback' => '__return_true',
            ]
        );

        // POST /auth/login — email + password → JWT (same response as signup).
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/auth/login',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'login'],
                'permission_callback' => '__return_true',
                'args' => [
                    'email' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_email',
                    ],
                    'password' => [
                        'required' => true,
                        'type'     => 'string',
                    ],
                ],
            ]
        );

        // GET /auth/wallet-nonce — anonymous wallet-signature challenge.
        // Public sibling of /auth/nonce: same payload shape, but no
        // existing-user requirement. Drives both /auth/wallet-login and
        // /auth/wallet-signup. Stored in a separate transient keyspace
        // so an anon nonce can never be replayed against /auth/wallet-link
        // (the authed link path) or vice versa.
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/auth/wallet-nonce',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'walletNonce'],
                'permission_callback' => '__return_true',
                'args' => [
                    'chain_slug' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'wallet_address' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );

        // POST /auth/wallet-login — verify a signature, look up the user
        // the wallet is linked to, mint + return a JWT. The
        // wallet-as-credential equivalent of /auth/login.
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/auth/wallet-login',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'walletLogin'],
                'permission_callback' => '__return_true',
                'args' => [
                    'wallet_address' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'signature' => [
                        'required' => true,
                        'type'     => 'string',
                    ],
                    'extra' => [
                        'required' => false,
                        'type'     => 'object',
                        'default'  => [],
                    ],
                ],
            ]
        );

        // POST /auth/wallet-signup — verify a signature, create a new
        // user (with a placeholder email if none supplied), link the
        // wallet, mint + return a JWT. The wallet-as-credential
        // equivalent of /auth/signup.
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/auth/wallet-signup',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'walletSignup'],
                'permission_callback' => '__return_true',
                'args' => [
                    'wallet_address' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'signature' => [
                        'required' => true,
                        'type'     => 'string',
                    ],
                    'handle' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'display_name' => [
                        'required'          => false,
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'email' => [
                        'required'          => false,
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_email',
                    ],
                    'extra' => [
                        'required' => false,
                        'type'     => 'object',
                        'default'  => [],
                    ],
                ],
            ]
        );

        // POST /auth/forgot-password — request a password-reset link.
        // Always returns ok=true regardless of whether the email matches
        // a user (anti-enumeration). When a match exists, generates a
        // WP-native reset key + emails the reset link.
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/auth/forgot-password',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'forgotPassword'],
                'permission_callback' => '__return_true',
                'args' => [
                    'email' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_email',
                    ],
                ],
            ]
        );

        // POST /auth/reset-password — consume a reset key + set a new
        // password. Validates the (key, login) pair against WP's native
        // user_activation_key store; on success, rotates the password
        // hash and fires the `password_reset` action which invalidates
        // outstanding sessions.
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/auth/reset-password',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'resetPassword'],
                'permission_callback' => '__return_true',
                'args' => [
                    'key' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'login' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_user',
                    ],
                    'password' => [
                        'required' => true,
                        'type'     => 'string',
                    ],
                ],
            ]
        );

        // POST /auth/verify-email — consume an OTP code or a one-shot
        // token to confirm email ownership and complete signup. On
        // success, marks the account verified and returns a JWT so the
        // user is immediately signed in without a separate login step.
        // Accepts either:
        //   { email, code }  — OTP typed by the user (15-min TTL)
        //   { token }        — one-shot link token from the email (24-h TTL)
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/auth/verify-email',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'verifyEmail'],
                'permission_callback' => '__return_true',
                'args' => [
                    'email' => [
                        'required'          => false,
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_email',
                    ],
                    'code' => [
                        'required'          => false,
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'token' => [
                        'required'          => false,
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );

        // POST /auth/resend-verification — send a fresh OTP + verify
        // link to the given email address. Always returns ok=true
        // (anti-enumeration). Rate-limited per IP to prevent email-bomb
        // abuse against any one inbox.
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/auth/resend-verification',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'resendVerification'],
                'permission_callback' => '__return_true',
                'args' => [
                    'email' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_email',
                    ],
                ],
            ]
        );
    }

    public function nonce(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        if (!\BCC\Core\Security\Throttle::allow('wallet_challenge', self::NONCE_RATE_LIMIT, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests. Please wait.', 429);
        }

        $chainSlug     = (string) $request->get_param('chain_slug');
        $walletAddress = (string) $request->get_param('wallet_address');

        if ($chainSlug === '' || $walletAddress === '') {
            return ApiResponse::error('bcc_invalid_request', 'chain_slug and wallet_address are required.', 400);
        }

        $chainId = ChainRepository::resolveId($chainSlug);
        if ($chainId === null) {
            return ApiResponse::error('bcc_invalid_request', 'Unsupported chain.', 400);
        }

        $chain = ChainRepository::getById($chainId);
        if ($chain === null) {
            return ApiResponse::error('bcc_invalid_request', 'Chain not found.', 400);
        }

        if (!WalletAddressValidator::validate($walletAddress, (string) $chain->chain_type)) {
            return ApiResponse::error('bcc_invalid_request', 'Invalid wallet address format.', 400);
        }

        $challenge = WalletIdentityService::generateChallenge(
            $userId,
            $chainSlug,
            $chainId,
            $walletAddress
        );

        $response = ApiResponse::ok([
            'nonce'          => $challenge['nonce'],
            'message'        => $challenge['message'],
            'chain_slug'     => $challenge['chain_slug'],
            'chain_id'       => $challenge['chain_id'],
            'wallet_address' => $challenge['wallet_address'],
            'expires_at'     => self::toIso8601($challenge['expires_at']),
        ]);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    public function walletLink(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        if (!\BCC\Core\Security\Throttle::allow('wallet_verify', self::VERIFY_RATE_LIMIT, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests.', 429);
        }

        $walletAddress = (string) $request->get_param('wallet_address');
        $signature     = wp_strip_all_tags(wp_unslash((string) $request->get_param('signature')));
        $walletType    = (string) $request->get_param('wallet_type');
        $label         = (string) $request->get_param('label');
        $extraParam    = $request->get_param('extra');
        $extra         = is_array($extraParam) ? $extraParam : [];

        if ($walletAddress === '' || $signature === '') {
            return ApiResponse::error('bcc_invalid_request', 'wallet_address and signature are required.', 400);
        }

        // Peek (non-destructive) to resolve chain. The atomic consume
        // happens inside verifyAndLink — peek+verify is race-safe per
        // WalletIdentityService::peekChallenge's contract.
        $challenge = WalletIdentityService::peekChallenge($userId, $walletAddress);
        if ($challenge === null) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'Challenge not found or expired. Please request a new nonce.',
                400
            );
        }

        $chain = ChainRepository::getById((int) ($challenge['chain_id'] ?? 0));
        if ($chain === null) {
            return ApiResponse::error('bcc_invalid_request', 'Chain not found.', 400);
        }

        if (WalletRepository::exists($userId, (int) $chain->id, $walletAddress)) {
            return ApiResponse::error('bcc_conflict', 'This wallet is already linked to your account.', 409);
        }
        if (WalletRepository::existsForOtherUser($userId, (int) $chain->id, $walletAddress)) {
            return ApiResponse::error('bcc_conflict', 'This wallet is already linked to another account.', 409);
        }

        $result = WalletIdentityService::verifyAndLink(
            WalletVerificationRequest::fromArray([
                'userId'        => $userId,
                'chainSlug'     => (string) $chain->slug,
                'chainType'     => (string) $chain->chain_type,
                'chainId'       => (int) $chain->id,
                'walletAddress' => $walletAddress,
                'signature'     => $signature,
                'extra'         => $extra,
                'walletType'    => $walletType !== '' ? $walletType : 'user',
                'label'         => $label,
            ])
        );

        if (!$result['success']) {
            return ApiResponse::error('bcc_forbidden', $result['message'], 403);
        }

        // For Polkadot, swap to canonical prefix-0 SS58 (set by the
        // verifier inside verifyAndLink) so audit log, security email,
        // and the response all reflect the address that was actually
        // stored. Other chains pass through.
        if ((string) $chain->chain_type === 'polkadot' && \BCC\Core\Crypto\PolkadotSignatureVerifier::$lastCanonicalAddress !== null) {
            $walletAddress = \BCC\Core\Crypto\PolkadotSignatureVerifier::$lastCanonicalAddress;
        }

        Logger::audit('wallet_connected', [
            'user_id' => $userId,
            'chain'   => $chain->slug,
            'address' => $walletAddress,
            'via'     => 'rest',
        ]);

        // DB audit trail (separate from the bcc-core filesystem Logger above).
        // The filesystem log is for ops grep; the DB row is for admin queries
        // and incident review. We persist both so a missing filesystem rotation
        // does not lose the record, and the DB row drives admin tooling.
        AuditLogger::log('wallet_linked', $result['wallet_link_id'], [
            'chain'   => (string) $chain->slug,
            'via'     => 'rest',
        ], 'wallet', $userId);

        // Side-channel security notification: linking a wallet broadens
        // the auth surface (the wallet can now be used to sign in). Tell
        // the user out-of-band so a session-hijack-then-link attack is
        // detectable. Best-effort; never throws.
        AccountSecurityMailer::walletLinked(
            $userId,
            (string) $chain->slug,
            $walletAddress
        );

        $response = ApiResponse::ok([
            'wallet_link_id' => $result['wallet_link_id'],
            'chain_slug'     => (string) $chain->slug,
            'chain_name'     => (string) $chain->name,
            'address'        => $walletAddress,
            'address_short'  => WalletAddressValidator::shorten($walletAddress),
            'wallet_type'    => $walletType !== '' ? $walletType : 'user',
            'verified'       => true,
        ]);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    public function signup(WP_REST_Request $request): WP_REST_Response
    {
        // Throttle::allow buckets unauthenticated callers by client IP
        // (proxy-aware) — see Throttle::allow doc. Five signups / minute
        // per IP is generous for humans, narrow for scripted abuse.
        if (!\BCC\Core\Security\Throttle::allow('signup', self::SIGNUP_RATE_LIMIT, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many signups. Please wait.', 429);
        }

        $email       = sanitize_email((string) $request->get_param('email'));
        $password    = (string) $request->get_param('password');
        $handle      = strtolower(trim((string) $request->get_param('handle')));
        $displayName = (string) $request->get_param('display_name');

        if ($email === '' || !is_email($email)) {
            return ApiResponse::error('bcc_invalid_request', 'A valid email is required.', 400);
        }
        if (strlen($password) < self::SIGNUP_MIN_PASSWORD_LENGTH) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'Password must be at least ' . self::SIGNUP_MIN_PASSWORD_LENGTH . ' characters.',
                400
            );
        }

        $handleService = Plugin::instance()->handleService();

        $err = $handleService->validate($handle);
        if ($err !== null) {
            return ApiResponse::error($err, self::handleErrorMessage($err), 422);
        }

        // Fast-path collision check — UX shortcut so 99% of duplicates
        // get a clean 409 without spinning up wp_insert_user. The
        // remaining race window (a winning concurrent signup that
        // claims this handle between here and our wp_insert_user)
        // is closed below by the wp_users.user_login UNIQUE constraint.
        if (!$handleService->isAvailable($handle)) {
            return ApiResponse::error('bcc_conflict', 'That handle is already taken.', 409);
        }

        // wp_insert_user requires a unique user_login. We mint an internal
        // login from the handle (`u_<handle>`) so wp_user_login stays
        // disjoint from bcc_handle (per §B3 — handle is the public
        // identity; login is internal).
        //
        // RACE CONDITION (locked): two parallel signups on the same handle
        // can both pass `handleService->isAvailable()` above. The atomic
        // serializer is wp_users.user_login UNIQUE — exactly one
        // wp_insert_user wins; the loser gets WP_Error('existing_user_login'
        // or 'db_insert_error' with a duplicate-key message). We translate
        // both to bcc_conflict so the API contract stays clean. No
        // pre-check on username_exists() / email_exists() — those are
        // TOCTOU traps that lie to the client when they pass and the DB
        // serializer subsequently rejects.
        $login  = self::deriveLogin($handle);
        $userId = wp_insert_user([
            'user_login'   => $login,
            'user_email'   => $email,
            'user_pass'    => $password,
            'display_name' => $displayName !== '' ? $displayName : $handle,
            'role'         => 'subscriber',
        ]);

        if (is_wp_error($userId)) {
            $code = $userId->get_error_code();
            if ($code === 'existing_user_login' || self::isDuplicateKeyError($userId)) {
                // Race lost on handle uniqueness.
                return ApiResponse::error('bcc_conflict', 'That handle is already taken.', 409);
            }
            if ($code === 'existing_user_email') {
                return ApiResponse::error(
                    'bcc_conflict',
                    'An account with that email already exists.',
                    409
                );
            }
            Logger::error('[bcc-trust] signup failed', [
                'code'  => $code,
                'error' => $userId->get_error_message(),
                'email' => $email,
            ]);
            return ApiResponse::error('bcc_internal_error', 'Failed to create account.', 500);
        }

        $userIdInt = (int) $userId;
        update_user_meta($userIdInt, HandleService::META_HANDLE, $handle);

        // Mark the account as pending email verification. The login gate
        // in login() checks this meta and blocks '0' values, so the user
        // cannot sign in until /auth/verify-email succeeds.
        // Wallet-signup users skip this path entirely and get '1' directly.
        update_user_meta($userIdInt, self::META_EMAIL_VERIFIED, '0');

        // Generate a 6-digit OTP (OTP path) and a 32-byte hex token
        // (link path). Store both so the user can verify via either route.
        $otpCode     = self::generateOtp();
        $verifyToken = self::generateVerifyToken();
        self::storeOtpForUser($userIdInt, $otpCode);
        self::storeVerifyToken($verifyToken, $userIdInt);

        // Build the one-shot verify URL and dispatch the email.
        // Best-effort: a mail failure records a DegradationMetric but
        // does NOT roll back account creation — the user can resend from
        // /verify-email via /auth/resend-verification.
        $verifyPath = '/verify-email?email=' . rawurlencode($email) . '&token=' . rawurlencode($verifyToken);
        $verifyUrl  = FrontendRedirect::defaultReturn($verifyPath);
        AuthMailer::sendVerificationEmail($userIdInt, $email, $otpCode, $verifyUrl);

        Logger::audit('user_signup', [
            'user_id' => $userIdInt,
            'handle'  => $handle,
            'via'     => 'rest',
        ]);

        do_action('bcc_user_signup', $userIdInt, $handle);

        // Return ok=true only — no JWT. The user must verify their email
        // before /auth/login will mint a token. The frontend routes them
        // to /verify-email?email=... on this response.
        $response = ApiResponse::ok(['ok' => true, 'email' => $email], 201);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

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
    private static function resolveInGoodStanding(int $userId): bool
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
    private static function isDuplicateKeyError(\WP_Error $error): bool
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

    public function login(WP_REST_Request $request): WP_REST_Response
    {
        // Throttle::allow buckets unauthenticated requests by client IP
        // (proxy-aware) — see Throttle::allow doc. Brute-force resistance
        // depends on this firing BEFORE wp_check_password's CPU-bound
        // bcrypt compare; otherwise an attacker can pin a CPU at the
        // password-check rate.
        if (!\BCC\Core\Security\Throttle::allow('login', self::LOGIN_RATE_LIMIT, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many login attempts.', 429);
        }

        $email    = sanitize_email((string) $request->get_param('email'));
        $password = (string) $request->get_param('password');

        if ($email === '' || !is_email($email) || $password === '') {
            return ApiResponse::error('bcc_invalid_request', 'Email and password are required.', 422);
        }

        $user = get_user_by('email', $email);

        // Generic error for both "user not found" and "wrong password" —
        // never leak which one failed (account-enumeration resistance).
        // wp_check_password is the canonical path; it handles legacy
        // hash formats + rehash-on-login transparently.
        if ($user === false || !wp_check_password($password, $user->user_pass, $user->ID)) {
            return ApiResponse::error('bcc_invalid_credentials', 'Invalid email or password.', 401);
        }

        $userId = (int) $user->ID;
        $handle = (string) get_user_meta($userId, HandleService::META_HANDLE, true);

        // Block accounts that are explicitly pending email verification.
        // '0' = set at email-signup and cleared by /auth/verify-email.
        // ''  = not present (legacy user, pre-verification-gate) → allow.
        // '1' = verified → allow.
        // Wallet-signup users have '1' set at wallet-signup time → allow.
        $emailVerified = (string) get_user_meta($userId, self::META_EMAIL_VERIFIED, true);
        if ($emailVerified === '0') {
            return ApiResponse::error(
                'bcc_email_not_verified',
                'Please verify your email address before logging in.',
                403
            );
        }

        // Legacy users created outside the BCC signup flow (wp-admin,
        // imports, social-login plugin) may lack a handle. Fail loud
        // so the frontend can route them through a handle-claim
        // surface. Per §B6 the JWT contract requires a handle.
        if ($handle === '') {
            return ApiResponse::error(
                'bcc_invalid_state',
                'Account is missing a handle — set one before logging in.',
                409
            );
        }

        // Same auth-cookie behavior as signup: cookie for same-origin
        // admin tooling, JWT in the response for headless clients.
        wp_set_current_user($userId);
        wp_set_auth_cookie($userId, true);

        $token = JwtToken::encode($userId, $handle);

        Logger::audit('user_login', [
            'user_id' => $userId,
            'handle'  => $handle,
            'via'     => 'rest',
        ]);

        do_action('bcc_user_login', $userId);

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
     * POST /auth/forgot-password — anti-enumeration password-reset request.
     *
     * Always returns ok=true. Only when the email matches a real user do
     * we generate a reset key (WP-native, written to user_activation_key
     * with a 24h default expiry) and dispatch the email — but the
     * response is identical either way so a caller can't enumerate which
     * addresses are registered.
     */
    public function forgotPassword(WP_REST_Request $request): WP_REST_Response
    {
        // Throttle first — IP-bucketed, before any DB lookup or mail
        // dispatch. Caps email-bomb spam against any one inbox from a
        // single source.
        if (!\BCC\Core\Security\Throttle::allow('forgot_password', self::FORGOT_PASSWORD_RATE_LIMIT, 3600)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many password-reset requests. Try again later.', 429);
        }

        $email = sanitize_email((string) $request->get_param('email'));
        if ($email === '' || !is_email($email)) {
            return ApiResponse::error('bcc_invalid_request', 'A valid email is required.', 422);
        }

        $user = get_user_by('email', $email);

        // Always-ok response below. We branch ONLY for the side effects
        // (key generation + email + audit) — never for the response.
        if ($user instanceof \WP_User) {
            $userId = (int) $user->ID;

            // get_password_reset_key writes a hash + timestamp to the
            // user_activation_key column. WP's own /wp-login.php?action=rp
            // flow reads from the same column — our path coexists with it.
            $key = get_password_reset_key($user);
            if (!is_wp_error($key) && is_string($key) && $key !== '') {
                $login = (string) $user->user_login;
                $path  = '/reset-password?key=' . rawurlencode($key) . '&login=' . rawurlencode($login);
                $resetUrl = FrontendRedirect::defaultReturn($path);

                $parts     = explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''));
                $requestIp = sanitize_text_field(trim($parts[0] ?? ''));
                AuthMailer::sendPasswordResetEmail($userId, $email, $resetUrl, $requestIp);

                AuditLogger::log('password_reset_requested', $userId, [
                    'email_hash' => sha1($email),
                ], 'user', $userId);
            } else {
                // Key generation failed (e.g. user is admin and a filter
                // blocks resets). Still respond ok=true — same anti-
                // enumeration discipline. Log so ops can see why no mail
                // went out.
                Logger::warning('[bcc-trust] get_password_reset_key returned WP_Error / empty', [
                    'user_id' => $userId,
                ]);
            }
        }

        $response = ApiResponse::ok(['ok' => true]);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * POST /auth/reset-password — consume a reset key + set a new password.
     *
     * Validates (key, login) against WP's user_activation_key store. On
     * success: rotates the password hash via reset_password() (which
     * fires the `password_reset` action — other plugins listening to that
     * hook will invalidate sessions for this user) and emails a security
     * confirmation. The user is NOT auto-logged-in; they must sign in
     * fresh with the new password.
     */
    public function resetPassword(WP_REST_Request $request): WP_REST_Response
    {
        if (!\BCC\Core\Security\Throttle::allow('reset_password_attempt', self::RESET_PASSWORD_RATE_LIMIT, 3600)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many attempts. Try again later.', 429);
        }

        $key      = (string) $request->get_param('key');
        $login    = (string) $request->get_param('login');
        $password = (string) $request->get_param('password');

        if ($key === '' || $login === '' || $password === '') {
            return ApiResponse::error('bcc_invalid_request', 'key, login, and password are required.', 422);
        }

        if (strlen($password) < self::SIGNUP_MIN_PASSWORD_LENGTH) {
            return ApiResponse::error(
                'bcc_weak_password',
                sprintf('Password must be at least %d characters.', self::SIGNUP_MIN_PASSWORD_LENGTH),
                422
            );
        }

        $user = check_password_reset_key($key, $login);
        if (is_wp_error($user) || !($user instanceof \WP_User)) {
            // Same generic code for "expired" and "wrong key" — never
            // leak which one. The frontend surfaces "expired or invalid".
            return ApiResponse::error('bcc_invalid_reset_token', 'This reset link is expired or invalid.', 400);
        }

        $userId = (int) $user->ID;

        // reset_password() hashes + stores the new password, clears the
        // user_activation_key (single-use), and fires the `password_reset`
        // action hook. Returns void.
        reset_password($user, $password);

        // Side-channel security email — reuses the "password changed"
        // mail since the user-facing effect is identical.
        AccountSecurityMailer::passwordChanged($userId);

        AuditLogger::log('password_reset_completed', $userId, [], 'user', $userId);

        $response = ApiResponse::ok(['ok' => true]);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    public function walletNonce(WP_REST_Request $request): WP_REST_Response
    {
        // IP-bucketed throttle (Throttle::allow buckets unauthenticated
        // callers by client IP). The authed sibling (/auth/nonce) uses a
        // user-keyed bucket; the two routes therefore never starve each
        // other under a partial DoS.
        if (!\BCC\Core\Security\Throttle::allow('wallet_nonce_anon', self::WALLET_NONCE_RATE_LIMIT, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests. Please wait.', 429);
        }

        $chainSlug     = (string) $request->get_param('chain_slug');
        $walletAddress = (string) $request->get_param('wallet_address');

        if ($chainSlug === '' || $walletAddress === '') {
            return ApiResponse::error('bcc_invalid_request', 'chain_slug and wallet_address are required.', 400);
        }

        $chainId = ChainRepository::resolveId($chainSlug);
        if ($chainId === null) {
            return ApiResponse::error('bcc_invalid_request', 'Unsupported chain.', 400);
        }

        $chain = ChainRepository::getById($chainId);
        if ($chain === null) {
            return ApiResponse::error('bcc_invalid_request', 'Chain not found.', 400);
        }

        if (!WalletAddressValidator::validate($walletAddress, (string) $chain->chain_type)) {
            return ApiResponse::error('bcc_invalid_request', 'Invalid wallet address format.', 400);
        }

        $challenge = WalletIdentityService::generateAnonymousChallenge(
            $chainSlug,
            $chainId,
            $walletAddress
        );

        $response = ApiResponse::ok([
            'nonce'          => $challenge['nonce'],
            'message'        => $challenge['message'],
            'chain_slug'     => $challenge['chain_slug'],
            'chain_id'       => $challenge['chain_id'],
            'wallet_address' => $challenge['wallet_address'],
            'expires_at'     => self::toIso8601($challenge['expires_at']),
        ]);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    public function walletLogin(WP_REST_Request $request): WP_REST_Response
    {
        // Same brute-force-resistance argument as /auth/login: throttle
        // BEFORE the CPU-bound signature verification, otherwise an
        // attacker can pin a CPU at the verify rate.
        if (!\BCC\Core\Security\Throttle::allow('wallet_login', self::WALLET_LOGIN_RATE_LIMIT, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many login attempts.', 429);
        }

        $walletAddress = (string) $request->get_param('wallet_address');
        $signature     = wp_strip_all_tags(wp_unslash((string) $request->get_param('signature')));
        $extraParam    = $request->get_param('extra');
        $extra         = is_array($extraParam) ? $extraParam : [];

        if ($walletAddress === '' || $signature === '') {
            return ApiResponse::error('bcc_invalid_request', 'wallet_address and signature are required.', 400);
        }

        // Atomically consume the challenge. Chain metadata comes from
        // the stored payload (server-known), never caller input — this
        // is the same nonce-replay guard that verifyAndLink uses.
        $challenge = WalletIdentityService::consumeAnonymousChallenge($walletAddress);
        if ($challenge === null) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'Challenge not found or expired. Please request a new nonce.',
                400
            );
        }

        $chain = ChainRepository::getById((int) ($challenge['chain_id'] ?? 0));
        if ($chain === null) {
            return ApiResponse::error('bcc_invalid_request', 'Chain not found.', 400);
        }

        $challengeMessage = (string) ($challenge['message'] ?? '');
        if ($challengeMessage === '') {
            Logger::error('[bcc-trust] wallet-login challenge malformed', [
                'chain'   => (string) $chain->slug,
                'address' => $walletAddress,
            ]);
            return ApiResponse::error('bcc_internal_error', 'Challenge malformed.', 500);
        }

        $valid = WalletVerifier::verify(
            (string) $chain->chain_type,
            $challengeMessage,
            $signature,
            $walletAddress,
            $extra
        );

        if (!$valid) {
            Logger::warning('[bcc-trust] wallet-login signature invalid', [
                'chain'   => (string) $chain->slug,
                'address' => $walletAddress,
            ]);
            return ApiResponse::error('bcc_signature_invalid', 'Signature verification failed.', 401);
        }

        // For Polkadot, swap to the canonical prefix-0 SS58 form. The
        // row was stored under the canonical at signup time, so a user
        // logging in with their wallet rendering in prefix 42 (Polkadot.js
        // "Substrate" default) still finds it. Other chains pass through.
        if ((string) $chain->chain_type === 'polkadot' && \BCC\Core\Crypto\PolkadotSignatureVerifier::$lastCanonicalAddress !== null) {
            $walletAddress = \BCC\Core\Crypto\PolkadotSignatureVerifier::$lastCanonicalAddress;
        }

        // Resolve the BCC user this wallet is bound to. The
        // (chain, address) pair is constrained to one user by the
        // application layer (every link path checks existsForOtherUser).
        $userId = WalletRepository::findUserIdByAddress((int) $chain->id, $walletAddress);
        if ($userId === 0) {
            // No account is linked — frontend should route the user to
            // /signup (or the wallet-signup flow) with the wallet
            // pre-attached. Per Decision B(a) we surface this as a
            // distinct, recoverable code rather than auto-promoting.
            return ApiResponse::error(
                'bcc_wallet_not_linked',
                'No account is linked to this wallet.',
                404
            );
        }

        $handle = (string) get_user_meta($userId, HandleService::META_HANDLE, true);
        if ($handle === '') {
            // Mirrors /auth/login: a missing handle means the account
            // was created outside the BCC signup flow. Per §B6 the JWT
            // contract requires a handle — fail loud so the frontend
            // can route through a handle-claim surface.
            return ApiResponse::error(
                'bcc_invalid_state',
                'Account is missing a handle — set one before logging in.',
                409
            );
        }

        // Cookie + JWT, identical to /auth/login.
        wp_set_current_user($userId);
        wp_set_auth_cookie($userId, true);

        $token = JwtToken::encode($userId, $handle);

        Logger::audit('user_login', [
            'user_id' => $userId,
            'handle'  => $handle,
            'via'     => 'wallet',
            'chain'   => (string) $chain->slug,
        ]);

        do_action('bcc_user_login', $userId);

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

    public function walletSignup(WP_REST_Request $request): WP_REST_Response
    {
        if (!\BCC\Core\Security\Throttle::allow('wallet_signup', self::WALLET_SIGNUP_RATE_LIMIT, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many signups. Please wait.', 429);
        }

        $walletAddress = (string) $request->get_param('wallet_address');
        $signature     = wp_strip_all_tags(wp_unslash((string) $request->get_param('signature')));
        $handle        = strtolower(trim((string) $request->get_param('handle')));
        $displayName   = (string) $request->get_param('display_name');
        $emailRaw      = trim((string) $request->get_param('email'));
        $extraParam    = $request->get_param('extra');
        $extra         = is_array($extraParam) ? $extraParam : [];

        if ($walletAddress === '' || $signature === '') {
            return ApiResponse::error('bcc_invalid_request', 'wallet_address and signature are required.', 400);
        }

        // Validate handle BEFORE consuming the challenge so a cheap
        // reject doesn't burn the user's nonce. Same rules as /auth/signup.
        $handleService = Plugin::instance()->handleService();
        $err = $handleService->validate($handle);
        if ($err !== null) {
            return ApiResponse::error($err, self::handleErrorMessage($err), 422);
        }
        if (!$handleService->isAvailable($handle)) {
            return ApiResponse::error('bcc_conflict', 'That handle is already taken.', 409);
        }

        // Peek (non-destructive) to resolve chain + validate address
        // format before the atomic consume. Same race-safety contract as
        // /auth/wallet-link's peekChallenge → verifyAndLink pair.
        $challengePeek = WalletIdentityService::peekAnonymousChallenge($walletAddress);
        if ($challengePeek === null) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'Challenge not found or expired. Please request a new nonce.',
                400
            );
        }

        $chain = ChainRepository::getById((int) ($challengePeek['chain_id'] ?? 0));
        if ($chain === null) {
            return ApiResponse::error('bcc_invalid_request', 'Chain not found.', 400);
        }

        if (!WalletAddressValidator::validate($walletAddress, (string) $chain->chain_type)) {
            return ApiResponse::error('bcc_invalid_request', 'Invalid wallet address format.', 400);
        }

        // Fast-path collision check: if the wallet is already linked to
        // anyone, fail before we mint a WP user. The atomic serializer
        // is still the wallet_links UNIQUE — see the rollback path
        // below for the lost-race case.
        if (WalletRepository::existsForOtherUser(0, (int) $chain->id, $walletAddress)) {
            return ApiResponse::error(
                'bcc_wallet_already_linked',
                'This wallet is already linked to an account. Sign in with your wallet instead.',
                409
            );
        }

        // Email handling per Decision A(a): caller-supplied email if
        // present (validated), otherwise a deterministic placeholder
        // keyed by the wallet address. Deterministic so retries don't
        // sprinkle unique placeholder addresses across wp_users on
        // partial-failure reattempts (the only collision class then is
        // an orphaned WP user from a prior signup whose wallet-link
        // step failed — handled below by rolling back wp_insert_user
        // on link failure).
        if ($emailRaw !== '') {
            $email = sanitize_email($emailRaw);
            if ($email === '' || !is_email($email)) {
                return ApiResponse::error('bcc_invalid_request', 'Email is not a valid format.', 400);
            }
        } else {
            $email = self::placeholderEmailForWallet($walletAddress);
        }

        // Point of no return for the nonce: atomic consume + verify.
        $challenge = WalletIdentityService::consumeAnonymousChallenge($walletAddress);
        if ($challenge === null) {
            // Lost the consume race against another request — same
            // nonce can't be replayed.
            return ApiResponse::error(
                'bcc_invalid_request',
                'Challenge not found or expired. Please request a new nonce.',
                400
            );
        }

        $challengeMessage = (string) ($challenge['message'] ?? '');
        if ($challengeMessage === '') {
            Logger::error('[bcc-trust] wallet-signup challenge malformed', [
                'chain'   => (string) $chain->slug,
                'address' => $walletAddress,
            ]);
            return ApiResponse::error('bcc_internal_error', 'Challenge malformed.', 500);
        }

        $valid = WalletVerifier::verify(
            (string) $chain->chain_type,
            $challengeMessage,
            $signature,
            $walletAddress,
            $extra
        );

        if (!$valid) {
            Logger::warning('[bcc-trust] wallet-signup signature invalid', [
                'chain'   => (string) $chain->slug,
                'address' => $walletAddress,
            ]);
            return ApiResponse::error('bcc_signature_invalid', 'Signature verification failed.', 401);
        }

        // For Polkadot, swap to the canonical prefix-0 SS58 form so the
        // wallet_links INSERT below dedups by underlying public key.
        // Same key signed up via prefix-42 (Polkadot.js "Substrate"
        // default) and prefix-0 (Polkadot mainnet) lands on the same
        // row. Other chains pass through unchanged.
        if ((string) $chain->chain_type === 'polkadot' && \BCC\Core\Crypto\PolkadotSignatureVerifier::$lastCanonicalAddress !== null) {
            $walletAddress = \BCC\Core\Crypto\PolkadotSignatureVerifier::$lastCanonicalAddress;
        }

        // Mint the WP user. Random unguessable password — caller
        // authenticates via wallet from now on; the standard "lost
        // your password" flow is the recovery path if they later want
        // an email/password fallback.
        $login    = self::deriveLogin($handle);
        $password = wp_generate_password(64, true, true);

        $userId = wp_insert_user([
            'user_login'   => $login,
            'user_email'   => $email,
            'user_pass'    => $password,
            'display_name' => $displayName !== '' ? $displayName : $handle,
            'role'         => 'subscriber',
        ]);

        if (is_wp_error($userId)) {
            $code = $userId->get_error_code();
            if ($code === 'existing_user_login' || self::isDuplicateKeyError($userId)) {
                // Race lost on handle uniqueness.
                return ApiResponse::error('bcc_conflict', 'That handle is already taken.', 409);
            }
            if ($code === 'existing_user_email') {
                $msg = $emailRaw !== ''
                    ? 'An account with that email already exists.'
                    : 'Account creation failed; contact support.';
                return ApiResponse::error('bcc_conflict', $msg, 409);
            }
            Logger::error('[bcc-trust] wallet-signup wp_insert_user failed', [
                'code'  => $code,
                'error' => $userId->get_error_message(),
            ]);
            return ApiResponse::error('bcc_internal_error', 'Failed to create account.', 500);
        }

        $userIdInt = (int) $userId;
        update_user_meta($userIdInt, HandleService::META_HANDLE, $handle);

        // Wallet-signup users authenticate via cryptographic signature —
        // email is a placeholder or optional. Mark as verified so they
        // are never blocked by the email-verification gate in login().
        update_user_meta($userIdInt, self::META_EMAIL_VERIFIED, '1');

        // Link the wallet via the canonical write contract. If this
        // fails (concurrent signup race claimed the same wallet between
        // our existsForOtherUser check and here), roll back the
        // wp_insert_user so we don't leave an orphan blocking retries.
        $walletLinkId = ServiceLocator::resolveWalletLinkWrite()->linkWallet(
            $userIdInt,
            (string) $chain->slug,
            $walletAddress,
            0,
            'user',
            ''
        );

        if (!$walletLinkId) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($userIdInt);

            Logger::warning('[bcc-trust] wallet-signup link race lost; rolled back user', [
                'chain'   => (string) $chain->slug,
                'address' => $walletAddress,
            ]);

            return ApiResponse::error(
                'bcc_wallet_already_linked',
                'This wallet is already linked to an account. Sign in with your wallet instead.',
                409
            );
        }

        // Fire the canonical domain event so trust-engine and
        // onchain-signals seed scoring/holdings rows. Same action as
        // verifyAndLink, so listeners don't need to care which path the
        // link came from.
        do_action('bcc_wallet_verified', $userIdInt, (string) $chain->slug, $walletAddress);

        wp_set_current_user($userIdInt);
        wp_set_auth_cookie($userIdInt, true);

        $token = JwtToken::encode($userIdInt, $handle);

        Logger::audit('user_signup', [
            'user_id' => $userIdInt,
            'handle'  => $handle,
            'via'     => 'wallet',
            'chain'   => (string) $chain->slug,
        ]);

        do_action('bcc_user_signup', $userIdInt, $handle);

        $response = ApiResponse::ok([
            'user_id'          => $userIdInt,
            'handle'           => $handle,
            'token'            => $token,
            'expires_in'       => self::JWT_TTL_SECONDS,
            'token_type'       => 'Bearer',
            'in_good_standing' => self::resolveInGoodStanding($userIdInt),
        ], 201);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * POST /auth/verify-email — confirm email ownership and complete signup.
     *
     * Accepts two verification paths in the same endpoint:
     *
     *   OTP path   { email, code }  — user types the 6-digit code from the
     *                                  email into the /verify-email page.
     *                                  TTL: 15 minutes. Rate-limited.
     *
     *   Token path { token }        — user clicks the link/button in the
     *                                  email. Self-identifying (token encodes
     *                                  the userId). TTL: 24 hours. Single-use.
     *
     * On success via either path: sets _bcc_email_verified = '1', deletes
     * the OTP transient, sets the WP auth cookie, and mints a JWT so the
     * user is signed in without a separate /auth/login roundtrip.
     *
     * Already-verified accounts return bcc_already_verified (409) on the
     * OTP path; the token path silently succeeds (idempotent re-verify is
     * harmless and avoids a confusing error when a user double-clicks).
     */
    public function verifyEmail(WP_REST_Request $request): WP_REST_Response
    {
        if (!\BCC\Core\Security\Throttle::allow('verify_email', self::VERIFY_EMAIL_RATE_LIMIT, 3600)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many attempts. Try again later.', 429);
        }

        $email = sanitize_email((string) $request->get_param('email'));
        $code  = (string) $request->get_param('code');
        $token = (string) $request->get_param('token');

        if ($token === '' && ($email === '' || $code === '')) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'Supply a verification token or an email and code.',
                422
            );
        }

        // ── Token path ────────────────────────────────────────────────
        if ($token !== '') {
            $userId = self::consumeVerifyToken($token);
            if ($userId === null) {
                return ApiResponse::error(
                    'bcc_invalid_verify_token',
                    'This link has expired or has already been used.',
                    400
                );
            }
            return self::finalizeVerification($userId);
        }

        // ── OTP path ──────────────────────────────────────────────────
        $user = get_user_by('email', $email);
        if (!($user instanceof \WP_User)) {
            // Generic error — don't reveal whether the address is registered.
            return ApiResponse::error('bcc_invalid_otp', 'Invalid or expired code.', 400);
        }

        $userId = (int) $user->ID;

        $emailVerified = (string) get_user_meta($userId, self::META_EMAIL_VERIFIED, true);
        if ($emailVerified === '1') {
            return ApiResponse::error('bcc_already_verified', 'This email is already verified.', 409);
        }

        if (!self::consumeOtpForUser($userId, $code)) {
            return ApiResponse::error('bcc_invalid_otp', 'Invalid or expired code.', 400);
        }

        return self::finalizeVerification($userId);
    }

    /**
     * POST /auth/resend-verification — send a fresh OTP + verify link.
     *
     * Always returns ok=true regardless of whether the email matches a
     * registered unverified account (anti-enumeration; mirrors forgotPassword).
     * Side effects fire only when a real account with _bcc_email_verified = '0'
     * is found.
     *
     * On match: invalidates the existing OTP transient (overwrite), generates
     * a fresh OTP + token, stores both, and dispatches a new email. Old
     * verify-token transients are left to expire naturally (they're keyed by
     * the token string, not by userId, so they can't be bulk-deleted without
     * a full scan; their 24-hour TTL is short enough that this is safe).
     */
    public function resendVerification(WP_REST_Request $request): WP_REST_Response
    {
        if (!\BCC\Core\Security\Throttle::allow('resend_verification', self::RESEND_VERIFICATION_RATE_LIMIT, 3600)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests. Try again later.', 429);
        }

        $email = sanitize_email((string) $request->get_param('email'));
        if ($email === '' || !is_email($email)) {
            return ApiResponse::error('bcc_invalid_request', 'A valid email is required.', 422);
        }

        $user = get_user_by('email', $email);

        if ($user instanceof \WP_User) {
            $userId        = (int) $user->ID;
            $emailVerified = (string) get_user_meta($userId, self::META_EMAIL_VERIFIED, true);

            // Only resend for accounts that are explicitly pending
            // verification. Already-verified and legacy accounts are
            // silently skipped — the anti-enumeration response hides which.
            if ($emailVerified === '0') {
                // Overwrite the existing OTP (if any) so the previous code
                // immediately stops working — only the new email is valid.
                $otpCode     = self::generateOtp();
                $verifyToken = self::generateVerifyToken();
                self::storeOtpForUser($userId, $otpCode);
                self::storeVerifyToken($verifyToken, $userId);

                $verifyPath = '/verify-email?email=' . rawurlencode($email) . '&token=' . rawurlencode($verifyToken);
                $verifyUrl  = FrontendRedirect::defaultReturn($verifyPath);
                AuthMailer::sendVerificationEmail($userId, $email, $otpCode, $verifyUrl);
            }
        }

        $response = ApiResponse::ok(['ok' => true]);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    // ── Verification helpers ───────────────────────────────────────────

    /**
     * Shared finalization path for both OTP and token verification.
     *
     * Sets _bcc_email_verified = '1', cleans up any remaining OTP
     * transient, sets the WP session cookie, and mints a JWT. The
     * response shape matches /auth/login so the frontend can treat
     * both identically.
     */
    private static function finalizeVerification(int $userId): WP_REST_Response
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

    // ── OTP / token generation and storage ────────────────────────────

    /**
     * Generate a 6-digit OTP string, zero-padded.
     * Uses random_int() (CSPRNG). The string is returned in plain text
     * so it can be shown to the user and emailed; storage is via
     * storeOtpForUser() which HMAC-hashes it before writing.
     */
    private static function generateOtp(): string
    {
        return sprintf('%06d', random_int(0, 999999));
    }

    /**
     * Generate a single-use verify token (64 hex chars = 256 bits).
     * Stored in a transient keyed by the token string itself; the userId
     * is the value. Consuming deletes the transient (single-use).
     */
    private static function generateVerifyToken(): string
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
    private static function storeOtpForUser(int $userId, string $otpCode): void
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
    private static function storeVerifyToken(string $token, int $userId): void
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
    private static function consumeOtpForUser(int $userId, string $otpCode): bool
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
    private static function consumeVerifyToken(string $token): ?int
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

    public function token(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        if (!\BCC\Core\Security\Throttle::allow('auth_token', self::TOKEN_RATE_LIMIT, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many token requests.', 429);
        }

        $handle = (string) get_user_meta($userId, HandleService::META_HANDLE, true);
        if ($handle === '') {
            // Per §B6 every account has a handle from signup. A missing
            // handle means somebody created the user outside the BCC
            // signup flow (wp-admin user create, legacy import, etc.).
            // Fail loud — the JWT contract requires a handle.
            return ApiResponse::error(
                'bcc_invalid_state',
                'Account is missing a handle — set one before requesting a token.',
                409
            );
        }

        $token = JwtToken::encode((int) $userId, $handle);

        $response = ApiResponse::ok([
            'token'      => $token,
            'expires_in' => self::JWT_TTL_SECONDS,
            'token_type' => 'Bearer',
        ]);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * POST /auth/logout-everywhere — destructive credential-class
     * mutation. Bumps the user's token-version counter so every
     * outstanding JWT (including the request's own bearer) fails the
     * version check on its next use. Writes a `sessions_revoked_all`
     * audit row + fires the AccountSecurityMailer confirmation email.
     *
     * The current token IS revoked; the response is the last
     * authenticated reply this session will get. The client MUST call
     * NextAuth `signOut()` immediately after a 200 so the local
     * session state matches the server.
     *
     * Throttle: 5/60/user (`logout_everywhere` bucket). Low cardinality —
     * a user has no reason to fire this more than a few times per
     * minute, and the destructive blast-radius justifies a tight cap.
     */
    public function logoutEverywhere(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        // Throttle BEFORE the destructive mutation — per
        // project_destructive_mutation_hardening Tier 1 doctrine:
        // Throttle::allow runs BEFORE credential gates.
        if (!\BCC\Core\Security\Throttle::allow('logout_everywhere', 5, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests.', 429);
        }

        // Audit row FIRST so the in-app timeline records the action
        // even if the mailer fails. AuditLogger never throws.
        AuditLogger::log('sessions_revoked_all', $userId, [], 'user', $userId);

        // Out-of-band email — Track-F redundancy. Never throws; failure
        // records a DegradationMetric and is silently swallowed.
        AccountSecurityMailer::sessionsRevokedAll($userId);

        // Mutation: bump the token-version meta. Every outstanding JWT
        // for this user fails ERR_REVOKED on next use, including the
        // bearer that authenticated THIS request.
        JwtToken::revokeAllForUser($userId);

        $response = ApiResponse::ok(['ok' => true]);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * POST /auth/refresh — silent-refresh path for clients whose
     * Bearer JWT has expired within the REFRESH_GRACE_SECONDS window.
     * Mobile-survivability seam.
     *
     * Contract:
     *   Input:  Authorization: Bearer <expired-or-near-expiry JWT>
     *   Output: { token, expires_in, token_type }      — canonical envelope
     *   Errors: bcc_unauthorized (missing/malformed bearer, JWT decode failed,
     *                             grace exceeded, user suspended)
     *           bcc_rate_limited (Throttle)
     *           bcc_invalid_state (handle missing — mirrors token())
     *
     * Security posture:
     *   - Every JWT check OTHER than `exp` is identical to the canonical
     *     decode path: signature, issuer, audience, version, revocation
     *     (tv claim) all enforced. A token whose signing-key rotated, or
     *     whose user was revoked via revokeAllForUser, can NEVER refresh.
     *   - Suspended users cannot refresh (Permissions::is_not_suspended).
     *     A user account flagged compromised mid-session loses refresh
     *     capability on the next attempt.
     *   - Per-user Throttle limits replay attempts on the grace window.
     *   - Grace window is bounded (REFRESH_GRACE_SECONDS = 86400). After
     *     that the path 401s and the user re-authenticates fully.
     *
     * Not in scope:
     *   - Rotating refresh tokens (single-use, separate from access JWT).
     *     V1 design accepts a stolen JWT being refreshable within grace;
     *     revokeAllForUser is the kill switch for compromised accounts.
     */
    public function refresh(WP_REST_Request $request): WP_REST_Response
    {
        // IP-keyed throttle (the caller is not yet authenticated by the
        // standard means — we're INSIDE the auth-recovery path).
        if (!\BCC\Core\Security\Throttle::allow('auth_refresh', self::REFRESH_RATE_LIMIT, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many refresh attempts.', 429);
        }

        $authHeader = (string) ($request->get_header('Authorization') ?? '');
        if (stripos($authHeader, 'Bearer ') !== 0) {
            return ApiResponse::error('bcc_unauthorized', 'Bearer token required.', 401);
        }
        $token = trim(substr($authHeader, 7));
        if ($token === '') {
            return ApiResponse::error('bcc_unauthorized', 'Bearer token required.', 401);
        }

        $decoded = JwtToken::decodeForRefresh($token);
        if ($decoded['ok'] !== true) {
            // Don't leak the specific JWT failure code — every failure
            // is bcc_unauthorized to the client. The PHP-side audit log
            // is the place to inspect the underlying error if needed.
            return ApiResponse::error('bcc_unauthorized', 'Token cannot be refreshed.', 401);
        }

        $payload = $decoded['payload'];
        $userId  = isset($payload['user_id']) ? (int) $payload['user_id'] : 0;
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Token cannot be refreshed.', 401);
        }

        // Mid-session compromise mitigation: a user flagged suspended
        // can no longer refresh, forcing them through the canonical
        // signOut → re-login path where the suspension is surfaced.
        if (!\BCC\Core\Permissions\Permissions::is_not_suspended($userId)) {
            return ApiResponse::error('bcc_forbidden', 'Account is not in good standing.', 403);
        }

        // Re-read the handle from authoritative storage rather than
        // trusting the JWT payload — a handle change since the original
        // mint should be reflected in the fresh token.
        $handle = (string) get_user_meta($userId, HandleService::META_HANDLE, true);
        if ($handle === '') {
            return ApiResponse::error(
                'bcc_invalid_state',
                'Account is missing a handle — re-login required.',
                409
            );
        }

        $fresh = JwtToken::encode($userId, $handle);

        Logger::audit('token_refreshed', [
            'user_id' => $userId,
        ]);

        $response = ApiResponse::ok([
            'token'      => $fresh,
            'expires_in' => self::JWT_TTL_SECONDS,
            'token_type' => 'Bearer',
        ]);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    private static function toIso8601(int $epochSeconds): string
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
    private static function handleErrorMessage(string $code): string
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
    private static function deriveLogin(string $handle): string
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
     * Domain is `noreply.bcc.local` — `noreply` makes the no-mail
     * intent explicit; `.local` keeps it firmly out of any real-MX
     * collision class.
     */
    private static function placeholderEmailForWallet(string $walletAddress): string
    {
        return 'wallet-' . substr(md5(strtolower($walletAddress)), 0, 16) . '@noreply.bcc.local';
    }

    // JWT mint/verify lives in BCC\Trust\Core\Support\JwtToken — single
    // source of truth for secret + payload shape + claim contract.
    // /auth/signup and /auth/token both call JwtToken::encode; the
    // BearerAuth middleware verifies via JwtToken::decode.
}
