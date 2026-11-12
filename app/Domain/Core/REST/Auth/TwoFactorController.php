<?php
/**
 * TwoFactorController — /auth/2fa routes.
 *
 * Routes:
 *   - POST /auth/2fa/verify — consume challenge token + OTP → JWT
 *   - POST /auth/2fa/resend — send a fresh 2FA OTP for a challenge
 *
 * Split out of AuthEndpoint (Phase 11 architecture split #3 of 4). Route
 * blocks + handler bodies are VERBATIM; shared helpers + constants moved
 * to AuthSupport. No OTP generation/storage or key string changed.
 *
 * @package BCC\Trust\Core\REST\Auth
 * @since V1 (Phase 11 split — 2026-06)
 */

namespace BCC\Trust\Core\REST\Auth;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Services\AuthMailer;
use BCC\Trust\Core\Services\HandleService;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\JwtToken;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class TwoFactorController
{
    public static function register(): void
    {
        $instance = new self();

        // POST /auth/2fa/verify — consume a challenge token + 6-digit OTP
        // to complete login and receive a full JWT. Challenge token is
        // issued by /auth/login when 2FA is required.
        register_rest_route(
            AuthSupport::ROUTE_NAMESPACE,
            '/auth/2fa/verify',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'twoFaVerify'],
                'permission_callback' => '__return_true',
                'args' => [
                    'challenge_token' => ['required' => true, 'type' => 'string'],
                    'code'            => ['required' => true, 'type' => 'string'],
                ],
            ]
        );

        // POST /auth/2fa/resend — generate a fresh 2FA OTP for an
        // in-progress challenge. Validates the challenge token without
        // consuming it. Always returns ok=true (anti-enumeration).
        register_rest_route(
            AuthSupport::ROUTE_NAMESPACE,
            '/auth/2fa/resend',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'twoFaResend'],
                'permission_callback' => '__return_true',
                'args' => [
                    'challenge_token' => ['required' => true, 'type' => 'string'],
                ],
            ]
        );
    }

    /**
     * POST /auth/2fa/verify — second factor: consume challenge + OTP → JWT.
     *
     * Accepts the challenge_token issued by /auth/login and the 6-digit OTP
     * sent to the user's email. On success, behaves identically to a direct
     * /auth/login response (same JWT payload shape).
     *
     * On wrong code: challenge token is preserved so the user can retry
     * without restarting the login flow — up to TWO_FA_MAX_FAILURES wrong
     * codes per (user, challenge); the last one burns the OTP + challenge
     * (401 bcc_invalid_2fa_token, "sign in again"). The per-IP rate limiter
     * is a second fence; the challenge TTL (10 min) is the outer bound.
     */
    public function twoFaVerify(WP_REST_Request $request): WP_REST_Response
    {
        if (!\BCC\Core\Security\Throttle::allow('2fa_verify', AuthSupport::TWO_FA_VERIFY_RATE_LIMIT, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many attempts. Wait a moment and try again.', 429);
        }

        $challengeToken = sanitize_text_field((string) $request->get_param('challenge_token'));
        $code           = sanitize_text_field((string) $request->get_param('code'));

        if ($challengeToken === '' || $code === '') {
            return ApiResponse::error('bcc_invalid_request', 'challenge_token and code are required.', 422);
        }

        $userId = AuthSupport::peek2faChallenge($challengeToken);
        if ($userId === null) {
            return ApiResponse::error('bcc_invalid_2fa_token', 'This session has expired. Please sign in again.', 401);
        }

        // Count EVERY attempt BEFORE comparing (L2): the wrong-code bucket
        // below only counts after a failed compare, so N concurrent guesses
        // racing past it could all be compared before any was counted. This
        // bucket caps COMPARISONS per (user, challenge) at TWO_FA_MAX_FAILURES
        // — correct attempts included, so a legitimate user sequence (up to
        // MAX-1 wrong, then right) is unaffected — and any attempt beyond it
        // burns the code and the challenge without comparing. A degraded
        // limiter fails closed (burns).
        $challengeKey = substr(hash('sha256', $challengeToken), 0, 32);
        if (!\BCC\Core\Security\Throttle::allow(
            '2fa_attempt',
            AuthSupport::TWO_FA_MAX_FAILURES,
            AuthSupport::TWO_FA_ATTEMPT_WINDOW,
            '2fa_attempt:' . $userId . ':' . $challengeKey
        )) {
            AuthSupport::burn2faOtp($challengeToken);
            AuthSupport::consume2faChallenge($challengeToken);
            Logger::audit('2fa_attempts_exhausted', ['user_id' => $userId]);
            return ApiResponse::error('bcc_invalid_2fa_token', 'Too many incorrect codes. Please sign in again.', 401);
        }

        if (!AuthSupport::consume2faOtp($challengeToken, $code)) {
            // Attempt cap (F-124), per (user, challenge): a bounded Throttle
            // bucket with an EXPLICIT key (so it is not split by client IP)
            // counts wrong codes over the challenge's lifetime. Failures 1..
            // MAX-1 answer "incorrect"; the MAX-th burns the OTP and the
            // challenge, so the code can no longer be guessed and the user
            // must sign in again (the existing bcc_invalid_2fa_token answer,
            // which the frontend already routes back to sign-in). Keyed on
            // the challenge too, so a stale challenge's failures can never
            // burn a fresh login. A degraded limiter fails closed (burns).
            $failKey = '2fa_fail:' . $userId . ':' . $challengeKey;
            if (!\BCC\Core\Security\Throttle::allow(
                '2fa_fail',
                AuthSupport::TWO_FA_MAX_FAILURES - 1,
                AuthSupport::TWO_FA_ATTEMPT_WINDOW,
                $failKey
            )) {
                AuthSupport::burn2faOtp($challengeToken);
                AuthSupport::consume2faChallenge($challengeToken);
                Logger::audit('2fa_attempts_exhausted', ['user_id' => $userId]);
                return ApiResponse::error(
                    'bcc_invalid_2fa_token',
                    'Too many incorrect codes. Please sign in again.',
                    401
                );
            }
            return ApiResponse::error('bcc_invalid_2fa_code', 'Incorrect or expired code.', 401);
        }

        // OTP matched — consume the challenge token so it can't be reused.
        AuthSupport::consume2faChallenge($challengeToken);

        $user = get_userdata($userId);
        if (!($user instanceof \WP_User)) {
            return ApiResponse::error('bcc_invalid_state', 'Account not found.', 404);
        }

        $handle = (string) get_user_meta($userId, HandleService::META_HANDLE, true);
        if ($handle === '') {
            return ApiResponse::error('bcc_invalid_state', 'Account is missing a handle.', 409);
        }

        wp_set_current_user($userId);
        wp_set_auth_cookie($userId, true);

        $token = JwtToken::encodeNewSession($userId, $handle);

        Logger::audit('user_login', [
            'user_id' => $userId,
            'handle'  => $handle,
            'via'     => '2fa',
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

    /**
     * POST /auth/2fa/resend — send a fresh 2FA OTP for an in-progress challenge.
     *
     * Validates the challenge token without consuming it. Rate-limited per
     * IP (3/min) to prevent email-bomb abuse; the limit runs first.
     *
     * F-292: an ENDED challenge (expired, superseded by a newer login, burned
     * by too many wrong codes, or older than TWO_FA_CHALLENGE_MAX_AGE) answers
     * the same `bcc_invalid_2fa_token` 401 as verify, which the client routes
     * back to sign-in. It used to answer ok=true and send nothing. There is
     * nothing to enumerate: the token is a 256-bit bearer secret. A live
     * challenge is re-armed for one code lifetime so the new code can be
     * used (a late resend used to mail a code that then failed).
     */
    public function twoFaResend(WP_REST_Request $request): WP_REST_Response
    {
        if (!\BCC\Core\Security\Throttle::allow('2fa_resend', AuthSupport::TWO_FA_RESEND_RATE_LIMIT, 60)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many resend requests. Wait a moment.', 429);
        }

        $challengeToken = sanitize_text_field((string) $request->get_param('challenge_token'));

        $userId = $challengeToken !== '' ? AuthSupport::peek2faChallenge($challengeToken) : null;
        $user   = $userId !== null ? get_userdata($userId) : false;
        if ($userId === null
            || !($user instanceof \WP_User)
            || !AuthSupport::rearm2faChallenge($challengeToken, $userId)
        ) {
            return ApiResponse::error('bcc_invalid_2fa_token', 'This session has expired. Please sign in again.', 401);
        }

        $otpCode = AuthSupport::generateOtp();
        // Re-binds a fresh code to the SAME (still-current) challenge. The
        // wrong-code counters are per challenge and outlive it, so a resend
        // does not grant extra guesses.
        AuthSupport::store2faOtp($challengeToken, $otpCode);
        AuthMailer::send2faCode($userId, (string) $user->user_email, $otpCode);

        $response = ApiResponse::ok(['ok' => true]);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }
}
