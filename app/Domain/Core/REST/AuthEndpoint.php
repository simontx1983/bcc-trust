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
use BCC\Trust\Core\Services\HandleService;
use BCC\Trust\Core\Services\UserViewService;
use BCC\Trust\Core\Support\ApiResponse;
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

        // Auto-login for same-origin clients (admin tooling, server-rendered
        // pages on the WP origin). For cross-origin headless clients
        // (Next.js on a separate domain) the cookie won't be usable —
        // those clients consume the `token` field in the response below
        // and authenticate via Bearer header from there. The cookie call
        // is harmless either way.
        wp_set_current_user($userIdInt);
        wp_set_auth_cookie($userIdInt, true);

        // Mint the JWT inline so headless clients don't need a second
        // roundtrip to /auth/token (which would require the WP cookie
        // they don't have on a cross-origin response). Same secret +
        // payload shape as /auth/token — that endpoint stays for token
        // refresh / re-mint after a session is established.
        $token = JwtToken::encode($userIdInt, $handle);

        Logger::audit('user_signup', [
            'user_id' => $userIdInt,
            'handle'  => $handle,
            'via'     => 'rest',
        ]);

        do_action('bcc_user_signup', $userIdInt, $handle);

        $response = ApiResponse::ok([
            'user_id'          => $userIdInt,
            'handle'           => $handle,
            'token'            => $token,
            'expires_in'       => self::JWT_TTL_SECONDS,
            'token_type'       => 'Bearer',
            // §I1 chrome signal — bounded-staleness boolean carried
            // through the NextAuth JWT until next login. Fresh signups
            // default to 'neutral' tier → true.
            'in_good_standing' => self::resolveInGoodStanding($userIdInt),
        ], 201);
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
