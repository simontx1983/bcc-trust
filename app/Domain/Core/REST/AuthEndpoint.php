<?php
/**
 * Auth Endpoints — registers /bcc/v1/auth/* routes.
 *
 * THIN COORDINATOR (Phase 11 architecture split #3 of 4). The 17 /auth/*
 * routes and their handlers used to live in this single ~2,650-line class.
 * They have been split, BYTE-FOR-BYTE, into per-method-family controllers
 * under app/Domain/Core/REST/Auth/, with every shared `static` helper and
 * shared constant relocated to AuthSupport. This class now only fans the
 * single `AuthEndpoint::register()` call site (Plugin.php) out to each
 * family controller's register():
 *
 *   - WalletAuthController       — /auth/nonce, /auth/wallet-link,
 *                                  /auth/wallet-nonce, /auth/wallet-login,
 *                                  /auth/wallet-signup
 *   - PasswordAuthController     — /auth/signup, /auth/login,
 *                                  /auth/forgot-password, /auth/reset-password
 *   - EmailVerificationController — /auth/verify-email, /auth/resend-verification
 *   - TwoFactorController        — /auth/2fa/verify, /auth/2fa/resend
 *   - OAuthController            — /auth/oauth, /auth/oauth-complete
 *   - SessionController          — /auth/logout-everywhere, /auth/refresh
 *
 * No route path, method, permission_callback, args schema, handler body,
 * helper body, constant value, or transient/usermeta key string changed in
 * the split — in-flight tokens + the API contract depend on them being
 * identical. See the Auth/ controllers + AuthSupport for the moved code.
 *
 * Auth model + cache + JWT-signing notes that previously lived here now
 * live alongside the relevant handler in its controller / in AuthSupport.
 *
 * @package BCC\Trust\Core\REST
 * @since V1 (2026-04, §B4 + §B6 + JWT bridge); split Phase 11 (2026-06)
 */

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\REST\Auth\EmailVerificationController;
use BCC\Trust\Core\REST\Auth\OAuthController;
use BCC\Trust\Core\REST\Auth\PasswordAuthController;
use BCC\Trust\Core\REST\Auth\SessionController;
use BCC\Trust\Core\REST\Auth\TwoFactorController;
use BCC\Trust\Core\REST\Auth\WalletAuthController;

if (!defined('ABSPATH')) {
    exit;
}

final class AuthEndpoint
{
    /**
     * Register every /auth/* route by delegating to the per-family
     * controllers. Single call site: Plugin.php. Each controller owns
     * its own `register()` (route blocks + handlers); shared statics +
     * constants live in BCC\Trust\Core\REST\Auth\AuthSupport.
     */
    public static function register(): void
    {
        WalletAuthController::register();
        PasswordAuthController::register();
        EmailVerificationController::register();
        TwoFactorController::register();
        OAuthController::register();
        SessionController::register();
    }
}
