<?php
/**
 * PasswordAuthController — /auth email + password routes.
 *
 * Routes:
 *   - POST /auth/signup          — create account with email + handle
 *   - POST /auth/login           — email + password → 2FA challenge
 *   - POST /auth/forgot-password — anti-enumeration password-reset request
 *   - POST /auth/reset-password  — consume reset key + set new password
 *
 * Split out of AuthEndpoint (Phase 11 architecture split #3 of 4). Route
 * blocks + handler bodies are VERBATIM; shared helpers + constants moved
 * to AuthSupport. No auth logic, password check, OTP, or key string
 * changed.
 *
 * @package BCC\Trust\Core\REST\Auth
 * @since V1 (Phase 11 split — 2026-06)
 */

namespace BCC\Trust\Core\REST\Auth;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Services\AccountSecurityMailer;
use BCC\Trust\Core\Services\AuthMailer;
use BCC\Trust\Core\Services\HandleService;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\FrontendRedirect;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class PasswordAuthController
{
    public static function register(): void
    {
        $instance = new self();

        // POST /auth/signup — create account with email + handle.
        register_rest_route(
            AuthSupport::ROUTE_NAMESPACE,
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

        // POST /auth/login — email + password → JWT (same response as signup).
        register_rest_route(
            AuthSupport::ROUTE_NAMESPACE,
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

        // POST /auth/forgot-password — request a password-reset link.
        // Always returns ok=true regardless of whether the email matches
        // a user (anti-enumeration). When a match exists, generates a
        // WP-native reset key + emails the reset link.
        register_rest_route(
            AuthSupport::ROUTE_NAMESPACE,
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
            AuthSupport::ROUTE_NAMESPACE,
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
    }

    public function signup(WP_REST_Request $request): WP_REST_Response
    {
        // Throttle::allow buckets unauthenticated callers by client IP
        // (proxy-aware) — see Throttle::allow doc. Five signups / minute
        // per IP is generous for humans, narrow for scripted abuse.
        if (!\BCC\Core\Security\Throttle::allow('signup', AuthSupport::SIGNUP_RATE_LIMIT, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many signups. Please wait.', 429);
        }

        $email       = sanitize_email((string) $request->get_param('email'));
        $password    = (string) $request->get_param('password');
        $handle      = strtolower(trim((string) $request->get_param('handle')));
        $displayName = (string) $request->get_param('display_name');

        if ($email === '' || !is_email($email)) {
            return ApiResponse::error('bcc_invalid_request', 'A valid email is required.', 400);
        }
        if (strlen($password) < AuthSupport::SIGNUP_MIN_PASSWORD_LENGTH) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'Password must be at least ' . AuthSupport::SIGNUP_MIN_PASSWORD_LENGTH . ' characters.',
                400
            );
        }

        $handleService = Plugin::instance()->handleService();

        $err = $handleService->validate($handle);
        if ($err !== null) {
            return ApiResponse::error($err, AuthSupport::handleErrorMessage($err), 422);
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
        $login  = AuthSupport::deriveLogin($handle);
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
        update_user_meta($userIdInt, AuthSupport::META_EMAIL_VERIFIED, '0');

        // Generate a 6-digit OTP (OTP path) and a 32-byte hex token
        // (link path). Store both so the user can verify via either route.
        $otpCode     = AuthSupport::generateOtp();
        $verifyToken = AuthSupport::generateVerifyToken();
        AuthSupport::storeOtpForUser($userIdInt, $otpCode);
        AuthSupport::storeVerifyToken($verifyToken, $userIdInt);

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

    public function login(WP_REST_Request $request): WP_REST_Response
    {
        // Throttle::allow buckets unauthenticated requests by client IP
        // (proxy-aware) — see Throttle::allow doc. Brute-force resistance
        // depends on this firing BEFORE wp_check_password's CPU-bound
        // bcrypt compare; otherwise an attacker can pin a CPU at the
        // password-check rate.
        if (!\BCC\Core\Security\Throttle::allow('login', AuthSupport::LOGIN_RATE_LIMIT, 60)) {
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
        $emailVerified = (string) get_user_meta($userId, AuthSupport::META_EMAIL_VERIFIED, true);
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

        // 2FA gate — generate a challenge token + OTP, email the code,
        // and return a 2fa_required response. The client must complete
        // /auth/2fa/verify to receive the actual JWT.
        $otpCode        = AuthSupport::generateOtp();
        $challengeToken = bin2hex(random_bytes(32));
        AuthSupport::store2faOtp($userId, $otpCode);
        AuthSupport::store2faChallenge($challengeToken, $userId);

        AuthMailer::send2faCode($userId, (string) $user->user_email, $otpCode);

        Logger::audit('user_login_2fa_initiated', [
            'user_id' => $userId,
            'handle'  => $handle,
        ]);

        $response = ApiResponse::ok([
            'status'          => '2fa_required',
            'method'          => 'email',
            'challenge_token' => $challengeToken,
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
        if (!\BCC\Core\Security\Throttle::allow('forgot_password', AuthSupport::FORGOT_PASSWORD_RATE_LIMIT, 3600)) {
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
        if (!\BCC\Core\Security\Throttle::allow('reset_password_attempt', AuthSupport::RESET_PASSWORD_RATE_LIMIT, 3600)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many attempts. Try again later.', 429);
        }

        $key      = (string) $request->get_param('key');
        $login    = (string) $request->get_param('login');
        $password = (string) $request->get_param('password');

        if ($key === '' || $login === '' || $password === '') {
            return ApiResponse::error('bcc_invalid_request', 'key, login, and password are required.', 422);
        }

        if (strlen($password) < AuthSupport::SIGNUP_MIN_PASSWORD_LENGTH) {
            return ApiResponse::error(
                'bcc_weak_password',
                sprintf('Password must be at least %d characters.', AuthSupport::SIGNUP_MIN_PASSWORD_LENGTH),
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
}
