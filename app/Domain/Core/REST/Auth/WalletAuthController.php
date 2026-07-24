<?php
/**
 * WalletAuthController — /auth wallet-signature routes.
 *
 * Routes:
 *   - GET  /auth/nonce         — authed wallet-signature challenge
 *   - POST /auth/wallet-link   — verify signature + link wallet to user
 *   - GET  /auth/wallet-nonce  — anonymous wallet-signature challenge
 *   - POST /auth/wallet-login  — verify signature → resolve user → JWT
 *   - POST /auth/wallet-signup — verify signature → create user + link → JWT
 *
 * Split out of AuthEndpoint (Phase 11 architecture split #3 of 4). Route
 * blocks + handler bodies are VERBATIM; shared helpers + constants moved
 * to AuthSupport. No auth logic, signature-verification, or key string
 * changed.
 *
 * @package BCC\Trust\Core\REST\Auth
 * @since V1 (Phase 11 split — 2026-06)
 */

namespace BCC\Trust\Core\REST\Auth;

use BCC\Core\Crypto\WalletVerifier;
use BCC\Core\Log\Logger;
use BCC\Core\ServiceLocator;
use BCC\Core\Wallet\WalletIdentityService;
use BCC\Core\Wallet\WalletVerificationRequest;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Services\AccountSecurityMailer;
use BCC\Trust\Core\Services\HandleService;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\JwtToken;
use BCC\Trust\Core\Support\WalletAddressValidator;
use BCC\Trust\Onchain\Repositories\WalletRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class WalletAuthController
{
    public static function register(): void
    {
        $instance = new self();

        // GET /auth/nonce — generate a wallet-signature challenge.
        register_rest_route(
            AuthSupport::ROUTE_NAMESPACE,
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
            AuthSupport::ROUTE_NAMESPACE,
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

        // GET /auth/wallet-nonce — anonymous wallet-signature challenge.
        // Public sibling of /auth/nonce: same payload shape, but no
        // existing-user requirement. Drives both /auth/wallet-login and
        // /auth/wallet-signup. Stored in a separate transient keyspace
        // so an anon nonce can never be replayed against /auth/wallet-link
        // (the authed link path) or vice versa.
        register_rest_route(
            AuthSupport::ROUTE_NAMESPACE,
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
            AuthSupport::ROUTE_NAMESPACE,
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
            AuthSupport::ROUTE_NAMESPACE,
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

        if (!\BCC\Core\Security\Throttle::allow('wallet_challenge', AuthSupport::NONCE_RATE_LIMIT, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests. Please wait.', 429);
        }

        $chainSlug     = (string) $request->get_param('chain_slug');
        $walletAddress = (string) $request->get_param('wallet_address');

        if ($chainSlug === '' || $walletAddress === '') {
            return ApiResponse::error('bcc_invalid_request', 'chain_slug and wallet_address are required.', 400);
        }

        $chainId = \BCC\Core\ServiceLocator::resolveChainRead()->resolveId($chainSlug);
        if ($chainId === null) {
            return ApiResponse::error('bcc_invalid_request', 'Unsupported chain.', 400);
        }

        $chain = \BCC\Core\ServiceLocator::resolveChainRead()->getById($chainId);
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
            'expires_at'     => AuthSupport::toIso8601($challenge['expires_at']),
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

        if (!\BCC\Core\Security\Throttle::allow('wallet_verify', AuthSupport::VERIFY_RATE_LIMIT, 60)) {
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

        $chain = \BCC\Core\ServiceLocator::resolveChainRead()->getById((int) ($challenge['chain_id'] ?? 0));
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

    public function walletNonce(WP_REST_Request $request): WP_REST_Response
    {
        // IP-bucketed throttle (Throttle::allow buckets unauthenticated
        // callers by client IP). The authed sibling (/auth/nonce) uses a
        // user-keyed bucket; the two routes therefore never starve each
        // other under a partial DoS.
        if (!\BCC\Core\Security\Throttle::allow('wallet_nonce_anon', AuthSupport::WALLET_NONCE_RATE_LIMIT, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests. Please wait.', 429);
        }

        $chainSlug     = (string) $request->get_param('chain_slug');
        $walletAddress = (string) $request->get_param('wallet_address');

        if ($chainSlug === '' || $walletAddress === '') {
            return ApiResponse::error('bcc_invalid_request', 'chain_slug and wallet_address are required.', 400);
        }

        $chainId = \BCC\Core\ServiceLocator::resolveChainRead()->resolveId($chainSlug);
        if ($chainId === null) {
            return ApiResponse::error('bcc_invalid_request', 'Unsupported chain.', 400);
        }

        $chain = \BCC\Core\ServiceLocator::resolveChainRead()->getById($chainId);
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
            'expires_at'     => AuthSupport::toIso8601($challenge['expires_at']),
        ]);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    public function walletLogin(WP_REST_Request $request): WP_REST_Response
    {
        // Same brute-force-resistance argument as /auth/login: throttle
        // BEFORE the CPU-bound signature verification, otherwise an
        // attacker can pin a CPU at the verify rate.
        if (!\BCC\Core\Security\Throttle::allow('wallet_login', AuthSupport::WALLET_LOGIN_RATE_LIMIT, 60)) {
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

        $chain = \BCC\Core\ServiceLocator::resolveChainRead()->getById((int) ($challenge['chain_id'] ?? 0));
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
            'expires_in'       => AuthSupport::JWT_TTL_SECONDS,
            'token_type'       => 'Bearer',
            'in_good_standing' => AuthSupport::resolveInGoodStanding($userId),
        ]);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    public function walletSignup(WP_REST_Request $request): WP_REST_Response
    {
        if (!\BCC\Core\Security\Throttle::allow('wallet_signup', AuthSupport::WALLET_SIGNUP_RATE_LIMIT, 60)) {
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
            return ApiResponse::error($err, AuthSupport::handleErrorMessage($err), 422);
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

        $chain = \BCC\Core\ServiceLocator::resolveChainRead()->getById((int) ($challengePeek['chain_id'] ?? 0));
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
            // Placeholder derivation keys on wp_salt('auth'). If the salt
            // is unreadable it throws — and we fail closed HERE, which is
            // BEFORE the nonce is consumed (just below) and BEFORE any WP
            // user is minted (further below). So a misconfigured salt can
            // never leave a half-created account or a spent nonce behind.
            // Logs the chain only, never the address.
            try {
                $email = AuthSupport::placeholderEmailForWallet($walletAddress);
            } catch (\RuntimeException $e) {
                Logger::error(
                    '[bcc-trust] wallet-signup: cannot mint placeholder email (auth salt unavailable)',
                    ['chain' => (string) $chain->slug]
                );
                return ApiResponse::error(
                    'bcc_internal_error',
                    'Account creation is temporarily unavailable. Please try again later.',
                    500
                );
            }
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
        $login    = AuthSupport::deriveLogin($handle);
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
            if ($code === 'existing_user_login' || AuthSupport::isDuplicateKeyError($userId)) {
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
        update_user_meta($userIdInt, AuthSupport::META_EMAIL_VERIFIED, '1');

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
            'expires_in'       => AuthSupport::JWT_TTL_SECONDS,
            'token_type'       => 'Bearer',
            'in_good_standing' => AuthSupport::resolveInGoodStanding($userIdInt),
        ], 201);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }
}
