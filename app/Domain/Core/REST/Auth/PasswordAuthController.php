<?php
/**
 * PasswordAuthController — /auth email + password routes.
 *
 * Routes:
 *   - POST /auth/signup          — create account with email + handle
 *   - POST /auth/login           — email OR handle + password → 2FA challenge
 *   - POST /auth/forgot-password — anti-enumeration password-reset request
 *   - POST /auth/reset-password  — consume reset key + set new password
 *
 * Split out of AuthEndpoint (Phase 11 architecture split #3 of 4). Route
 * blocks + handler bodies were originally VERBATIM from that split; login()
 * was later extended (identifier may be email or handle) — see its inline
 * comment. Everything else (password check, OTP, key strings) is unchanged.
 *
 * @package BCC\Trust\Core\REST\Auth
 * @since V1 (Phase 11 split — 2026-06)
 */

namespace BCC\Trust\Core\REST\Auth;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Security\IpResolver;
use BCC\Trust\Core\Services\AccountSecurityMailer;
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

        // POST /auth/login — email or handle + password → JWT (same
        // response as signup). `identifier` may be either; sanitize as
        // plain text here, the handler itself detects the shape (email
        // vs. handle) before doing the corresponding sanitize/lookup.
        register_rest_route(
            AuthSupport::ROUTE_NAMESPACE,
            '/auth/login',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'login'],
                'permission_callback' => '__return_true',
                'args' => [
                    'identifier' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
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
        $displayName = AuthSupport::sanitizePublicDisplayName((string) $request->get_param('display_name'));

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

        // F-285: a sign-up that was never confirmed and can never sign in
        // (see isAbandonedEmailSignup) stops holding this handle or email
        // after ABANDONED_SIGNUP_SECONDS, so a mistyped address or an
        // abandoned attempt does not block it forever.
        self::reclaimAbandonedSignups($handle, $email);

        // Fast-path collision check — UX shortcut so 99% of duplicates
        // get a clean 409 without spinning up wp_insert_user. The
        // remaining race window (a winning concurrent signup that
        // claims this handle between here and our wp_insert_user)
        // is closed below by the wp_users.user_login UNIQUE constraint.
        if (!$handleService->isAvailable($handle)) {
            return ApiResponse::error('bcc_conflict', self::TAKEN_HANDLE, 409);
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
            // Core saves meta_input BEFORE firing user_register, so the
            // handle-assignment hook in Plugin.php sees the chosen handle
            // and leaves it alone.
            'meta_input'   => [
                HandleService::META_HANDLE => $handle,
                // F-253: the member chose this password.
                \BCC\Trust\Core\Services\AccountRecoveryService::META_HAS_PASSWORD => '1',
            ],
        ]);

        if (is_wp_error($userId)) {
            $code = $userId->get_error_code();
            if ($code === 'existing_user_login' || AuthSupport::isDuplicateKeyError($userId)) {
                // Race lost on handle uniqueness.
                return ApiResponse::error('bcc_conflict', self::TAKEN_HANDLE, 409);
            }
            if ($code === 'existing_user_email') {
                return ApiResponse::error(
                    'bcc_conflict',
                    self::TAKEN_EMAIL,
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
        // (Wallet signup also starts at '0' — see WalletAuthController.)
        update_user_meta($userIdInt, AuthSupport::META_EMAIL_VERIFIED, '0');

        // OTP (code path) + single-use token (link path), stored, then the
        // verification email. Best-effort: a mail failure records a
        // DegradationMetric but does NOT roll back account creation — the
        // user can resend from /verify-email via /auth/resend-verification.
        AuthSupport::beginEmailVerification($userIdInt, $email);

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
     * F-285: how long a never-confirmed email sign-up keeps its handle and
     * email before another sign-up may take them (user-approved: 7 days).
     */
    public const ABANDONED_SIGNUP_SECONDS = 7 * 86400;

    /** F-285 copy (user-approved) for a sign-up's handle / email 409. */
    private const TAKEN_HANDLE = 'That handle is taken. If it\'s yours from an unfinished sign-up, check your email for the confirmation link; unconfirmed sign-ups free up after 7 days.';
    private const TAKEN_EMAIL  = 'An account with that email already exists. If it\'s yours from an unfinished sign-up, check your email for the confirmation link; unconfirmed sign-ups free up after 7 days.';

    /**
     * A PURE email-signup account still waiting on email confirmation:
     * created by password signup (bcc_has_password '1'), email never proven
     * (_bcc_email_verified exactly '0'), and NO other way in — no OAuth
     * link and no linked wallet. Such an account can never have signed in:
     * the password sign-in gate refuses '0' and there is no other
     * credential. Shared by F-285 (reclaim) and F-287 (a reset confirms it).
     *
     * Fails closed: an unreadable wallet list counts as "has a wallet".
     */
    private static function isPendingEmailSignup(int $userId): bool
    {
        if ($userId <= 0
            || (string) get_user_meta($userId, AuthSupport::META_EMAIL_VERIFIED, true) !== '0'
            || (string) get_user_meta($userId, \BCC\Trust\Core\Services\AccountRecoveryService::META_HAS_PASSWORD, true) !== '1'
        ) {
            return false;
        }
        foreach (array_keys((array) get_user_meta($userId)) as $metaKey) {
            if (str_starts_with((string) $metaKey, AuthSupport::META_OAUTH_PREFIX)) {
                return false;
            }
        }
        try {
            return \BCC\Trust\Onchain\Repositories\WalletRepository::getForUserOrThrow($userId) === [];
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** isPendingEmailSignup(), registered more than ABANDONED_SIGNUP_SECONDS ago (F-285). */
    private static function isAbandonedEmailSignup(int $userId): bool
    {
        $user = get_userdata($userId);
        if (!($user instanceof \WP_User)) {
            return false;
        }
        // user_registered is WordPress core's UTC column.
        $registered = strtotime((string) $user->user_registered . ' UTC');
        if ($registered === false || $registered > time() - self::ABANDONED_SIGNUP_SECONDS) {
            return false;
        }
        return self::isPendingEmailSignup($userId);
    }

    /**
     * F-285: delete the abandoned sign-up (if any) holding this handle — as
     * its current handle or its u_<handle> login — or this email, so the
     * sign-up in progress can take them. Hard delete (user-approved): the
     * account never signed in, so nothing references it. Audited by user id
     * only. A live account is never touched; the 409 below still applies.
     */
    private static function reclaimAbandonedSignups(string $handle, string $email): void
    {
        $candidates = [];
        $byHandle = HandleService::currentOwner($handle);
        if ($byHandle !== null) {
            $candidates[] = $byHandle;
        }
        $byLogin = username_exists(AuthSupport::deriveLogin($handle));
        if (is_int($byLogin) && $byLogin > 0) {
            $candidates[] = $byLogin;
        }
        $byEmail = email_exists($email);
        if (is_int($byEmail) && $byEmail > 0) {
            $candidates[] = $byEmail;
        }

        foreach (array_unique($candidates) as $userId) {
            if (!self::isAbandonedEmailSignup($userId)) {
                continue;
            }
            if (!function_exists('wp_delete_user')) {
                require_once ABSPATH . 'wp-admin/includes/user.php';
            }
            if (wp_delete_user($userId)) {
                AuditLogger::log('abandoned_signup_reclaimed', $userId, [], 'user', $userId);
            }
        }
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

        $identifier = trim((string) $request->get_param('identifier'));
        $password   = (string) $request->get_param('password');

        if ($identifier === '' || $password === '') {
            return ApiResponse::error('bcc_invalid_request', 'Email/handle and password are required.', 422);
        }

        // Accept either an email address or a handle on the same field.
        // Detect which by shape: a real email always contains '@' + a
        // valid domain per is_email(); anything else is treated as a
        // handle (a leading '@' is stripped defensively, since handles are
        // always displayed with one in the UI).
        //
        // F-280: a handle resolves through the member's CURRENT bcc_handle,
        // never through the u_<handle> login. The login is minted from the
        // handle at SIGNUP and never changes, so after a rename it named
        // the OLD handle: the new handle could not sign in, and a member
        // who later took the old handle would resolve to the renamed
        // member's account. A retired handle never signs in, and a handle
        // two members hold resolves to nobody (HandleService::currentOwner).
        if (is_email($identifier)) {
            $user = get_user_by('email', sanitize_email($identifier));
        } else {
            $ownerId = HandleService::currentOwner(ltrim(strtolower($identifier), '@'));
            $user    = $ownerId !== null ? get_userdata($ownerId) : false;
        }

        // Login throttles (F-281), still before the CPU-bound bcrypt compare.
        // See AuthSupport::LOGIN_ACCOUNT_*.
        //  - This network's budget for what was TYPED (normalized), so a
        //    stranger elsewhere can't lock the owner out.
        //  - A ceiling for what was TYPED across all networks, against a
        //    guess spread over many networks; networks the account recently
        //    signed in from skip it, so the owner at home is never locked out.
        //  Both are keyed on the typed text, NOT the resolved account: a
        //  bucket shared by a handle and an email would make a 429 reveal
        //  that the two are one account (see AuthSupport::LOGIN_ACCOUNT_*).
        $typedKey = 'id:' . hash('sha256', is_email($identifier)
            ? strtolower(sanitize_email($identifier))
            : ltrim(strtolower($identifier), '@'));
        $network = AuthSupport::loginNetwork();
        if (!\BCC\Core\Security\Throttle::allow(
            'login_account_net',
            AuthSupport::LOGIN_ACCOUNT_RATE_LIMIT,
            AuthSupport::LOGIN_ACCOUNT_RATE_WINDOW,
            'bcc_throttle_login_account_net_' . hash('sha256', $typedKey . '|' . $network)
        )) {
            return ApiResponse::error('bcc_rate_limited', 'Too many login attempts.', 429);
        }
        $trustedNetwork = $user !== false && AuthSupport::isTrustedLoginNetwork((int) $user->ID, $network);
        if (!$trustedNetwork && !\BCC\Core\Security\Throttle::allow(
            'login_account',
            AuthSupport::LOGIN_ACCOUNT_GLOBAL_RATE_LIMIT,
            AuthSupport::LOGIN_ACCOUNT_RATE_WINDOW,
            'bcc_throttle_login_account_' . hash('sha256', $typedKey)
        )) {
            return ApiResponse::error('bcc_rate_limited', 'Too many login attempts.', 429);
        }

        // Generic error for "not found" and "wrong password" alike —
        // never leak which one failed (account-enumeration resistance).
        // wp_check_password is the canonical path; it handles legacy
        // hash formats + rehash-on-login transparently.
        if ($user === false || !wp_check_password($password, $user->user_pass, $user->ID)) {
            return ApiResponse::error('bcc_invalid_credentials', 'Invalid credentials.', 401);
        }

        $userId = (int) $user->ID;
        $handle = (string) get_user_meta($userId, HandleService::META_HANDLE, true);

        // Block accounts that are explicitly pending email verification.
        // '0' = set at email-signup and cleared by /auth/verify-email.
        // ''  = not present (legacy user, pre-verification-gate) → allow.
        // '1' = verified → allow.
        // Wallet-signup users start at '0' too, but sign in with their wallet;
        // their password is a random 64-char value they never see.
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

        // ── Test-account 2FA bypass (non-production only) ──────────────
        // Lets an allowlisted test account skip the email OTP for manual /
        // automated (Playwright) QA. Guarded three independent ways so it
        // can never weaken a real login:
        //   1. Hard-refuses on production via wp_get_environment_type() even
        //      if the constant is mistakenly defined there.
        //   2. Inert unless BCC_2FA_BYPASS_ACCOUNTS is defined in wp-config
        //      (a comma-separated identifier allowlist). Undefined/empty =
        //      the feature does not exist. That constant is both the on/off
        //      switch AND the allowlist.
        //   3. Scoped to that allowlist — the typed identifier OR the
        //      resolved handle must match; never "any account".
        // The password was already verified above; this skips only the
        // second factor, returning the same JWT payload as /auth/2fa/verify.
        // To yank the feature entirely, delete this block + its helper.
        //
        // Belt-and-suspenders: the env-type check below already refuses the
        // bypass on production, but if the allowlist constant is somehow
        // defined on a prod box (e.g. a staging wp-config copied to prod),
        // surface that misconfiguration loudly so an operator removes it —
        // don't let a fail-safe default hide a config that shouldn't exist.
        if (defined('BCC_2FA_BYPASS_ACCOUNTS') && wp_get_environment_type() === 'production') {
            Logger::warning(
                '[PasswordAuthController] BCC_2FA_BYPASS_ACCOUNTS is defined on a PRODUCTION environment — the 2FA bypass is refused, but remove the constant from wp-config on prod.',
                ['user_id' => $userId]
            );
        }

        if (
            wp_get_environment_type() !== 'production'
            && defined('BCC_2FA_BYPASS_ACCOUNTS')
            && $this->identifierIsBypassAllowed($identifier, $handle)
        ) {
            wp_set_current_user($userId);
            wp_set_auth_cookie($userId, true);

            $token = JwtToken::encodeNewSession($userId, $handle);
            AuthSupport::recordTrustedLoginNetwork($userId); // F-281: a completed sign-in

            Logger::audit('user_login', [
                'user_id' => $userId,
                'handle'  => $handle,
                'via'     => '2fa_bypass',
            ]);

            do_action('bcc_user_login', $userId);

            $response = ApiResponse::ok([
                'user_id'          => $userId,
                'handle'           => $handle,
                'token'            => $token,
                'expires_in'       => AuthSupport::JWT_TTL_SECONDS,
                'token_type'       => 'Bearer',
                'in_good_standing' => AuthSupport::resolveInGoodStanding($userId),
                // F-305: a suspended member signs in READ-ONLY; the client shows
                // the suspension notice from this flag. Every write gate refuses.
                'suspended'        => AuthSupport::resolveSuspended($userId),
            ]);
            $response->header('Cache-Control', 'no-store');

            return $response;
        }

        // 2FA gate — generate a challenge token + OTP, email the code,
        // and return a 2fa_required response. The client must complete
        // /auth/2fa/verify to receive the actual JWT.
        $otpCode        = AuthSupport::generateOtp();
        $challengeToken = bin2hex(random_bytes(32));
        // Challenge FIRST: it becomes the user's one live challenge (and
        // drops any earlier challenge's OTP); then the OTP bound to it.
        AuthSupport::store2faChallenge($challengeToken, $userId);
        AuthSupport::store2faOtp($challengeToken, $otpCode);

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
     * Non-production test-account allowlist check for the 2FA bypass.
     *
     * BCC_2FA_BYPASS_ACCOUNTS is a comma-separated list of identifiers
     * (emails and/or handles; a leading '@' is optional). A login matches
     * if either the typed identifier or the account's resolved handle is on
     * the list. Comparison is case-insensitive and '@'-insensitive so
     * "tester", "@tester", and "Tester" all match a "tester" entry. An
     * empty/whitespace constant matches nothing (feature stays off).
     */
    private function identifierIsBypassAllowed(string $identifier, string $handle): bool
    {
        $raw = (string) constant('BCC_2FA_BYPASS_ACCOUNTS');
        if (trim($raw) === '') {
            return false;
        }

        $needleId     = strtolower(ltrim(trim($identifier), '@'));
        $needleHandle = strtolower(ltrim(trim($handle), '@'));

        foreach (explode(',', $raw) as $entry) {
            $allowed = strtolower(ltrim(trim($entry), '@'));
            if ($allowed === '') {
                continue;
            }
            if ($allowed === $needleId || $allowed === $needleHandle) {
                return true;
            }
        }

        return false;
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

                // Resolve the request IP through the canonical spoof-proof
                // resolver (audit F3) — it honours CF-Connecting-IP only from
                // verified Cloudflare ranges and otherwise ignores the
                // forgeable X-Forwarded-For header. Mirrors AccountSecurityMailer.
                $requestIp = IpResolver::getClientIp();
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

        // F-287: the reset key was mailed to user_email and came back, which
        // proves the inbox — so a PURE email-signup account still waiting
        // on confirmation is confirmed now, instead of the very next sign-in
        // answering "verify your email". Deliberately NOT for an account
        // with a wallet or OAuth link: its email may be one someone else
        // typed (the pre-hijack case), and marking it verified would let the
        // inbox owner's Google sign-in auto-link INTO an account the other
        // person still controls. Sessions are revoked just below anyway.
        if (self::isPendingEmailSignup($userId)) {
            update_user_meta($userId, AuthSupport::META_EMAIL_VERIFIED, '1');
            AuthSupport::burnEmailOtp($userId);
            Logger::audit('email_verified', [
                'user_id' => $userId,
                'via'     => 'password_reset',
            ]);
            do_action('bcc_email_verified', $userId);
        }

        // Revoke every outstanding bearer JWT for this user. reset_password()
        // clears WP session tokens, but BCC JWTs are self-contained and only
        // honour the per-user token-version counter — so without this bump a
        // token stolen BEFORE the reset (the whole reason to reset) stays
        // valid for its full TTL and can be renewed via /auth/refresh. The
        // user is not logged in on this path, so there is no live session to
        // preserve. [audit H-2]
        JwtToken::revokeAllForUser($userId);

        // Side-channel security email — reuses the "password changed"
        // mail since the user-facing effect is identical.
        AccountSecurityMailer::passwordChanged($userId);

        AuditLogger::log('password_reset_completed', $userId, [], 'user', $userId);

        $response = ApiResponse::ok(['ok' => true]);
        $response->header('Cache-Control', 'no-store');

        return $response;
    }
}
