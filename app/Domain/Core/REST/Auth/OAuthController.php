<?php
/**
 * OAuthController — /auth OAuth SSO bridge routes.
 *
 * Routes:
 *   - POST /auth/oauth          — OAuth SSO bridge (find user → JWT / handle_required)
 *   - POST /auth/oauth-complete — create a BCC account from an OAuth challenge
 *
 * Split out of AuthEndpoint (Phase 11 architecture split #3 of 4). Route
 * blocks + handler bodies are VERBATIM; shared helpers + constants moved
 * to AuthSupport. The OAuth-only server-to-server gate (oauthBridgeGate)
 * stays a private method here. No OAuth state-binding, provider-token
 * storage, or key string changed.
 *
 * @package BCC\Trust\Core\REST\Auth
 * @since V1 (Phase 11 split — 2026-06)
 */

namespace BCC\Trust\Core\REST\Auth;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Services\AuthMailer;
use BCC\Trust\Core\Services\HandleService;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\FrontendRedirect;
use BCC\Trust\Core\Support\JwtToken;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class OAuthController
{
    /**
     * Max clock skew (seconds) tolerated on a signed /auth/oauth bridge
     * request, and the TTL of its single-use nonce. Bounds how long a
     * captured signed request stays replayable.
     */
    private const OAUTH_BRIDGE_MAX_SKEW_SECONDS = 300;

    public static function register(): void
    {
        $instance = new self();

        // POST /auth/oauth — OAuth SSO bridge.
        // Finds an existing BCC user by OAuth provider ID or email, mints a JWT
        // (same AuthTokenResponse shape as /auth/login), and returns it. When no
        // matching user is found, issues a short-lived provider_token and returns
        // {status: "handle_required"} so the frontend can collect a handle before
        // creating the account via /auth/oauth-complete.
        register_rest_route(
            AuthSupport::ROUTE_NAMESPACE,
            '/auth/oauth',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'oauthLogin'],
                'permission_callback' => '__return_true',
                'args' => [
                    'provider' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'provider_id' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'email' => [
                        'required'          => false,
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_email',
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

        // POST /auth/oauth-complete — create a new BCC account from an OAuth
        // handle-required challenge. Consumes the provider_token issued by
        // /auth/oauth, validates and reserves the handle, creates the WP user,
        // and returns a full JWT so the frontend can establish a NextAuth session
        // via the bcc-verified bridge.
        register_rest_route(
            AuthSupport::ROUTE_NAMESPACE,
            '/auth/oauth-complete',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'oauthComplete'],
                'permission_callback' => '__return_true',
                'args' => [
                    'provider_token' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
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
                ],
            ]
        );
    }

    /**
     * Server-to-server gate for the OAuth SSO bridge.
     *
     * `/auth/oauth` trusts the caller's asserted OAuth identity (provider +
     * email) to mint a JWT, so it MUST only be reachable by the trusted
     * NextAuth backend — which performs the actual Google/Twitter token
     * verification before calling here.
     *
     * The caller proves it holds `BCC_OAUTH_BRIDGE_SECRET` by SIGNING each
     * request rather than transmitting the secret (audit HIGH #4). It sends:
     *   - x-bcc-oauth-timestamp — unix seconds, must be within the skew window
     *   - x-bcc-oauth-nonce     — random, single-use within that window
     *   - x-bcc-oauth-signature — hex HMAC-SHA256(secret, "{ts}\n{nonce}\n{rawBody}")
     * The secret is now a signing key that never crosses the wire, so a
     * TLS-terminating proxy or a request log can't capture a replayable
     * bearer value; the timestamp + single-use nonce bound replay of a
     * captured signed request. The old x-bcc-oauth-secret bearer header is no
     * longer accepted (atomic cutover with the NextAuth caller).
     *
     * Without this gate the endpoint is a pre-auth account takeover: an
     * anonymous caller could POST a victim's email and receive a valid session
     * JWT for that account (password + email-2FA both bypassed).
     *
     * Fail-closed: if the secret isn't configured, refuse — OAuth SSO stays
     * disabled until an operator pins the secret rather than running open.
     *
     * @return WP_REST_Response|null  Error response when the gate fails; null when OK.
     */
    private function oauthBridgeGate(WP_REST_Request $request): ?WP_REST_Response
    {
        $secret = defined('BCC_OAUTH_BRIDGE_SECRET')
            ? (string) constant('BCC_OAUTH_BRIDGE_SECRET')
            : '';
        if ($secret === '') {
            Logger::error('[AuthEndpoint] BCC_OAUTH_BRIDGE_SECRET not configured — /auth/oauth disabled');
            return ApiResponse::error('bcc_internal', 'OAuth bridge not configured.', 500);
        }

        $timestamp = (string) $request->get_header('x-bcc-oauth-timestamp');
        $nonce     = (string) $request->get_header('x-bcc-oauth-nonce');
        $signature = (string) $request->get_header('x-bcc-oauth-signature');

        if ($timestamp === '' || $nonce === '' || $signature === '') {
            return ApiResponse::error('bcc_unauthorized', 'OAuth bridge authentication failed.', 401);
        }

        // Timestamp must be a fresh unix time within the skew window.
        if (!ctype_digit($timestamp)
            || abs(time() - (int) $timestamp) > self::OAUTH_BRIDGE_MAX_SKEW_SECONDS
        ) {
            return ApiResponse::error('bcc_unauthorized', 'OAuth bridge authentication failed.', 401);
        }

        // Verify the HMAC over timestamp + nonce + raw body BEFORE spending a
        // nonce, so bad-signature floods can't churn the nonce store.
        $expected = hash_hmac(
            'sha256',
            $timestamp . "\n" . $nonce . "\n" . $request->get_body(),
            $secret
        );
        if (!hash_equals($expected, $signature)) {
            return ApiResponse::error('bcc_unauthorized', 'OAuth bridge authentication failed.', 401);
        }

        // Single-use nonce — rejects replay of a captured, still-in-window
        // signed request.
        if (!AuthSupport::consumeOauthBridgeNonce($nonce, self::OAUTH_BRIDGE_MAX_SKEW_SECONDS)) {
            return ApiResponse::error('bcc_unauthorized', 'OAuth bridge authentication failed.', 401);
        }

        return null;
    }

    /**
     * POST /auth/oauth — OAuth SSO login bridge.
     *
     * Called by the Next.js `signIn` callback after a successful Google/Twitter
     * OAuth redirect. Looks up the BCC user by provider ID (stored in user meta)
     * or by email (first-time link for users who already have an email account).
     *
     * Existing user → mint JWT → return AuthTokenResponse (same as /auth/login).
     * New user → generate provider_token → return {status:"handle_required", provider_token}.
     *
     * The caller checks `status === "handle_required"` and redirects to
     * /signup/complete-profile?pt=<provider_token> to collect a handle before
     * creating the account via /auth/oauth-complete.
     */
    public function oauthLogin(WP_REST_Request $request): WP_REST_Response
    {
        if (!\BCC\Core\Security\Throttle::allow('oauth_login', AuthSupport::OAUTH_LOGIN_RATE_LIMIT, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests. Please wait.', 429);
        }

        // Server-to-server secret — only the NextAuth backend (which already
        // verified the Google/Twitter token) may reach this. Closes the
        // pre-auth account-takeover; see oauthBridgeGate().
        $gate = $this->oauthBridgeGate($request);
        if ($gate !== null) {
            return $gate;
        }

        $provider    = (string) $request->get_param('provider');
        $providerId  = (string) $request->get_param('provider_id');
        $email       = sanitize_email((string) $request->get_param('email'));
        $displayName = sanitize_text_field((string) $request->get_param('display_name'));

        if (!in_array($provider, ['google', 'twitter'], true)) {
            return ApiResponse::error('bcc_invalid_request', 'Unsupported OAuth provider.', 400);
        }
        if ($providerId === '') {
            return ApiResponse::error('bcc_invalid_request', 'provider_id is required.', 400);
        }

        // Lookup order:
        //   1. Exact provider-ID match in user meta (fastest; covers users who
        //      previously signed in or linked via this provider).
        //   2. Email match (covers users who signed up with email/password and
        //      are signing in via OAuth for the first time with the same email).
        //      Only applies when the provider supplies a verified email (Google
        //      always does; Twitter never does) AND the matched account's own
        //      email is not pending verification — see the gate below.
        $userId = AuthSupport::findUserByOauthProvider($provider, $providerId);

        if ($userId === null && $email !== '' && is_email($email)) {
            $wpUser = get_user_by('email', $email);
            if ($wpUser instanceof \WP_User) {
                $candidateId = (int) $wpUser->ID;
                // Anti-pre-registration gate: never link into an account whose
                // email is pending verification ('0'). Such an email is
                // user-typed (email signup, or X signup via oauth-complete) —
                // its owner hasn't proven inbox control, so an email match
                // proves nothing about identity. Without this gate, an
                // attacker could sign up via X with a victim's address and the
                // victim's first Google sign-in would land inside the
                // attacker's account. '' (legacy) and '1' allow, matching the
                // login() convention.
                $emailVerified = (string) get_user_meta($candidateId, AuthSupport::META_EMAIL_VERIFIED, true);
                if ($emailVerified !== '0') {
                    $userId = $candidateId;
                    // Store the OAuth link so the next sign-in skips the email lookup.
                    update_user_meta($userId, AuthSupport::META_OAUTH_PREFIX . $provider, $providerId);
                }
            }
        }

        if ($userId !== null) {
            $handle = (string) get_user_meta($userId, HandleService::META_HANDLE, true);
            if ($handle === '') {
                return ApiResponse::error(
                    'bcc_invalid_state',
                    'Account is missing a handle — contact support.',
                    409
                );
            }

            $token = JwtToken::encode($userId, $handle);

            do_action('bcc_user_login', $userId, $handle);

            Logger::audit('user_login_oauth', [
                'user_id'  => $userId,
                'provider' => $provider,
            ]);

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

        // No matching user — issue a provider_token so the frontend can
        // collect a handle and create the account via /auth/oauth-complete.
        $providerToken = bin2hex(random_bytes(32));
        AuthSupport::storeOauthProviderToken($providerToken, [
            'provider'     => $provider,
            'provider_id'  => $providerId,
            'email'        => $email,
            'display_name' => $displayName,
        ]);

        $response = ApiResponse::ok([
            'status'         => 'handle_required',
            'provider_token' => $providerToken,
            'email'          => $email,
            'display_name'   => $displayName,
        ]);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    /**
     * POST /auth/oauth-complete — finish OAuth signup by choosing a handle.
     *
     * Consumes the provider_token issued by /auth/oauth, creates a BCC account
     * (email pre-verified, random password), links the OAuth provider ID in user
     * meta, and returns a full JWT.
     *
     * If /auth/oauth didn't capture an email for this provider (Twitter), the
     * `email` param is required here — /signup/complete-profile collects it.
     *
     * Validation errors (invalid handle, handle taken, invalid/missing email,
     * email taken) leave the provider_token intact so the user can correct and
     * retry without restarting the OAuth flow. The token is consumed only when
     * user creation succeeds.
     */
    public function oauthComplete(WP_REST_Request $request): WP_REST_Response
    {
        if (!\BCC\Core\Security\Throttle::allow('oauth_complete', AuthSupport::OAUTH_COMPLETE_RATE_LIMIT, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests. Please wait.', 429);
        }

        // No server-to-server secret gate here (unlike /auth/oauth): this
        // endpoint is browser-called from /signup/complete-profile, and its
        // security rests on the provider_token — an unforgeable, single-use,
        // server-issued capability that ONLY the secret-gated /auth/oauth can
        // mint, carrying server-stored (not client-supplied) provider data.
        $providerToken = sanitize_text_field((string) $request->get_param('provider_token'));
        $handle        = strtolower(trim((string) $request->get_param('handle')));
        $displayName   = AuthSupport::sanitizePublicDisplayName(sanitize_text_field((string) $request->get_param('display_name')));

        if ($providerToken === '') {
            return ApiResponse::error('bcc_invalid_request', 'provider_token is required.', 400);
        }

        // Peek without consuming — validation errors below leave the token intact.
        $data = AuthSupport::peekOauthProviderToken($providerToken);
        if ($data === null) {
            return ApiResponse::error(
                'bcc_invalid_oauth_token',
                'Session expired. Please sign in again.',
                400
            );
        }

        $handleService = Plugin::instance()->handleService();
        $err           = $handleService->validate($handle);
        if ($err !== null) {
            return ApiResponse::error($err, AuthSupport::handleErrorMessage($err), 422);
        }
        if (!$handleService->isAvailable($handle)) {
            return ApiResponse::error('bcc_conflict', 'That handle is already taken.', 409);
        }

        $provider   = (string) ($data['provider'] ?? '');
        $providerId = (string) ($data['provider_id'] ?? '');
        $email      = (string) ($data['email'] ?? '');

        if ($displayName === '') {
            $displayName = AuthSupport::sanitizePublicDisplayName((string) ($data['display_name'] ?? ''));
        }
        if ($displayName === '') {
            $displayName = $handle;
        }

        // Google supplies a provider-verified email. Twitter's OAuth2
        // user-context never returns one, so /signup/complete-profile
        // collects it directly — required here whenever the provider didn't
        // supply one. A collected email is USER-TYPED, not provider-verified:
        // it must NOT be trusted for identity (see the email-fallback gate in
        // oauthLogin()), so it starts pending and goes through the standard
        // verify-email flow.
        $emailProviderVerified = $email !== '' && is_email($email);
        if (!$emailProviderVerified) {
            $email = sanitize_email((string) $request->get_param('email'));
            if ($email === '' || !is_email($email)) {
                return ApiResponse::error(
                    'bcc_invalid_email',
                    'A valid email address is required.',
                    422
                );
            }
        }

        $login    = AuthSupport::deriveLogin($handle);
        $password = wp_generate_password(64, true, true);

        $userId = wp_insert_user([
            'user_login'   => $login,
            'user_email'   => $email,
            'user_pass'    => $password,
            'display_name' => $displayName,
            'role'         => 'subscriber',
        ]);

        if (is_wp_error($userId)) {
            $code = $userId->get_error_code();
            if ($code === 'existing_user_login' || AuthSupport::isDuplicateKeyError($userId)) {
                return ApiResponse::error('bcc_conflict', 'That handle is already taken.', 409);
            }
            if ($code === 'existing_user_email') {
                return ApiResponse::error(
                    'bcc_conflict',
                    'An account with that email already exists.',
                    409
                );
            }
            Logger::error('[bcc-trust] oauth-complete wp_insert_user failed', [
                'code'     => $code,
                'error'    => $userId->get_error_message(),
                'provider' => $provider,
            ]);
            return ApiResponse::error('bcc_internal_error', 'Failed to create account.', 500);
        }

        $userIdInt = (int) $userId;
        update_user_meta($userIdInt, HandleService::META_HANDLE, $handle);
        // Provider-verified email (Google) → '1'. User-typed email (Twitter)
        // → '0' until /auth/verify-email succeeds. '0' blocks password login
        // (see login()) and the oauthLogin() email-fallback link, but NOT
        // provider sign-in — oauthLogin matches by provider ID first, so the
        // user keeps signing in with the provider they signed up with.
        update_user_meta($userIdInt, AuthSupport::META_EMAIL_VERIFIED, $emailProviderVerified ? '1' : '0');
        if ($provider !== '' && $providerId !== '') {
            update_user_meta($userIdInt, AuthSupport::META_OAUTH_PREFIX . $provider, $providerId);
        }

        // Consume the provider_token only after the user row is committed.
        AuthSupport::consumeOauthProviderToken($providerToken);

        $token = JwtToken::encode($userIdInt, $handle);

        Logger::audit('user_signup', [
            'user_id'  => $userIdInt,
            'handle'   => $handle,
            'via'      => 'oauth',
            'provider' => $provider,
        ]);

        do_action('bcc_user_signup', $userIdInt, $handle);

        if ($emailProviderVerified) {
            // Welcome email — best-effort, mirrors finalizeVerification().
            // Provider-verified accounts are fully active immediately (no
            // separate verify-email step), so send here.
            AuthMailer::sendWelcomeEmail(
                $email,
                $handle,
                FrontendRedirect::defaultReturn('/')
            );
        } else {
            // User-typed email: send the standard verification email instead
            // (same OTP + link machinery as the email-signup path above).
            // finalizeVerification() sends the welcome email once verified;
            // /auth/resend-verification works for this account because the
            // flag is '0'. Best-effort — a mail failure does not roll back
            // the signup, and the JWT below is issued regardless.
            $otpCode     = AuthSupport::generateOtp();
            $verifyToken = AuthSupport::generateVerifyToken();
            AuthSupport::storeOtpForUser($userIdInt, $otpCode);
            AuthSupport::storeVerifyToken($verifyToken, $userIdInt);

            $verifyPath = '/verify-email?email=' . rawurlencode($email) . '&token=' . rawurlencode($verifyToken);
            AuthMailer::sendVerificationEmail(
                $userIdInt,
                $email,
                $otpCode,
                FrontendRedirect::defaultReturn($verifyPath)
            );
        }

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
