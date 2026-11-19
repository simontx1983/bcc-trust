<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Security;

use BCC\Trust\Core\REST\Auth\AuthSupport;
use BCC\Trust\Core\Services\BreachedPasswordCheck;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * BCC's password rules on WordPress's OWN password paths.
 *
 * BCC's routes (POST /auth/signup, POST /auth/reset-password,
 * PATCH /me/account/password) refuse a password shorter than
 * AuthSupport::SIGNUP_MIN_PASSWORD_LENGTH and one BreachedPasswordCheck
 * finds in a breach. WordPress's own paths skipped both:
 *   - the core reset form on cms.* (wp-login.php?action=lostpassword →
 *     rp/resetpass → reset_password()) → `validate_password_reset`;
 *   - wp-admin profile.php / user-edit.php / user-new.php (edit_user()),
 *     admins included, the highest-value accounts →
 *     `user_profile_update_errors`;
 *   - core REST user writes (admins only since CoreUsersRouteGuard) →
 *     `rest_pre_insert_user`.
 * Each now runs the SAME rules through violation(): the same constant, the
 * same helper, the same copy. The breach check fails open exactly as it
 * does on BCC's routes (an outage allows the password and records
 * password_breach_check / unavailable).
 *
 * Core hands the form paths a SLASHED password (wp_magic_quotes), so it is
 * unslashed before it is checked; the REST path's value is not slashed.
 * An empty password is core's to handle (it has its own "enter a
 * password" errors). Security review, 2026-11-18.
 */
final class CorePasswordPolicy
{
    public static function register(): void
    {
        add_action('validate_password_reset', [self::class, 'onValidatePasswordReset'], 10, 2);
        add_action('user_profile_update_errors', [self::class, 'onProfileUpdateErrors'], 10, 3);
        add_filter('rest_pre_insert_user', [self::class, 'onRestPreInsertUser'], 10, 2);
    }

    /**
     * The rule BCC's own routes apply, or null when the password passes.
     *
     * @return array{code: string, message: string, status: int}|null
     */
    public static function violation(string $password): ?array
    {
        if (strlen($password) < AuthSupport::SIGNUP_MIN_PASSWORD_LENGTH) {
            return [
                'code'    => 'bcc_invalid_request',
                'message' => sprintf('Password must be at least %d characters.', AuthSupport::SIGNUP_MIN_PASSWORD_LENGTH),
                'status'  => 400,
            ];
        }
        if (BreachedPasswordCheck::isBreached($password)) {
            return [
                'code'    => BreachedPasswordCheck::ERROR_CODE,
                'message' => BreachedPasswordCheck::ERROR_MESSAGE,
                'status'  => 422,
            ];
        }

        return null;
    }

    /**
     * wp-login.php?action=resetpass. Core reads the new password from
     * $_POST['pass1'] and resets only when $errors is still empty.
     *
     * @param mixed $errors
     * @param mixed $user
     */
    public static function onValidatePasswordReset($errors, $user): void
    {
        unset($user);
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- core verified rp_key/the cookie before this action.
        $raw = $_POST['pass1'] ?? null;
        if (!$errors instanceof \WP_Error || !is_string($raw)) {
            return;
        }
        self::addTo($errors, trim((string) wp_unslash($raw)));
    }

    /**
     * edit_user() (profile.php, user-edit.php, user-new.php). It sets
     * `$user->user_pass` only when a new password was typed.
     *
     * @param mixed $errors
     * @param mixed $update
     * @param mixed $user
     */
    public static function onProfileUpdateErrors($errors, $update, $user): void
    {
        unset($update);
        if (!$errors instanceof \WP_Error || !is_object($user) || !isset($user->user_pass) || !is_string($user->user_pass)) {
            return;
        }
        self::addTo($errors, (string) wp_unslash($user->user_pass));
    }

    /**
     * Core REST user create / update. The prepared user carries
     * `user_pass` only when the request set a password.
     *
     * @param mixed $prepared
     * @param mixed $request
     * @return mixed
     */
    public static function onRestPreInsertUser($prepared, $request)
    {
        unset($request);
        if (!is_object($prepared) || !isset($prepared->user_pass) || !is_string($prepared->user_pass) || $prepared->user_pass === '') {
            return $prepared;
        }
        $violation = self::violation($prepared->user_pass);
        if ($violation === null) {
            return $prepared;
        }

        return new \WP_Error($violation['code'], $violation['message'], ['status' => $violation['status']]);
    }

    private static function addTo(\WP_Error $errors, string $password): void
    {
        if ($password === '') {
            return;
        }
        $violation = self::violation($password);
        if ($violation !== null) {
            $errors->add($violation['code'], $violation['message'], ['form-field' => 'pass1']);
        }
    }
}
