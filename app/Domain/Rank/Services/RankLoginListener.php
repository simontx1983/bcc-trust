<?php
/**
 * RankLoginListener — the canonical explicit-login normalizer.
 *
 * Subscribes (via Plugin::registerAsyncJobs) to the four verified
 * deliberate-authentication events and folds them into one idempotent
 * Rank login event — a `bcc_trust_login_days` day row:
 *
 *   - `bcc_user_login`     — password / 2FA / wallet / OAuth login
 *   - `bcc_user_signup`    — wallet / OAuth / password signup (mints a session)
 *   - `bcc_email_verified` — email-signup activation (mints a session)
 *   - `wp_login`           — classic wp-admin login
 *
 * Refresh (`SessionController`) and re-mint (`MyAccountEndpoint`) paths
 * fire NO event and are excluded by construction — background API
 * requests never count as a login (owner correction 2). An explicitly
 * initiated re-authentication re-fires one of the hooks above and counts.
 *
 * Also refreshes the display-only `bcc_trust_user_info.last_login`
 * column (admin Users tab) — previously written by nothing, always NULL.
 * The retired `bcc_trust_last_login` usermeta writer lived in
 * UserLifecycleService::onLogin; this listener replaces it, and
 * DormancyDetector reads LoginDaysRepository::lastLoginDay() instead.
 *
 * @package BCC\Trust\Rank\Services
 * @since Rank redesign Phase 1 (2026-07-31)
 */

declare(strict_types=1);

namespace BCC\Trust\Rank\Services;

use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Rank\Repositories\LoginDaysRepository;

if (!defined('ABSPATH')) {
    exit;
}

class RankLoginListener
{
    public function __construct(
        private readonly LoginDaysRepository $loginDays,
        private readonly UserInfoRepository $userInfo
    ) {
    }

    /**
     * Handle one deliberate authentication event. $source is
     * hook-granular ('login' | 'signup' | 'email_verify' | 'wp_login').
     * Throws propagate to the registerAsyncJobs try/catch, which logs
     * and records the `rank_scoring.login_write_failed` DegradationMetric.
     */
    public function onAuthEvent(int $userId, string $source): void
    {
        if ($userId <= 0) {
            return;
        }

        $day = gmdate('Y-m-d');

        if (!$this->loginDays->recordLogin($userId, $source, $day)) {
            throw new \RuntimeException("login_days write failed for user {$userId}");
        }

        // Display-only mirror refresh — never authoritative (§24.2).
        $this->userInfo->touchLastLogin($userId);
    }
}
