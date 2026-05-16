<?php
/**
 * DormancyDetector — "is this operator currently inactive?"
 *
 * Drives the §J.4 `attestor.is_dormant` flag on roster rows. When true,
 * the row dims and an INACTIVE marker renders — a soft signal to the
 * viewer that the attestor isn't currently active, NOT a judgment on
 * the attestation itself (which still counts at its decayed weight).
 *
 * **Detection rule (plan §12 q9 — display threshold):**
 *
 *   Dormant ↔ last_login > 60 days ago
 *          AND no attestation activity (cast / reaffirm / revoke)
 *              in the last 60 days
 *
 * Both conditions because either alone is noisy:
 *   - A logged-in operator who hasn't attested isn't dormant in any
 *     meaningful sense — they're a lurker, which the design doesn't
 *     punish (§2.9 "absence is not negative").
 *   - An operator who hasn't logged in but reaffirmed via API-only
 *     flow last week is still actively expressing judgment.
 *
 * **Last-login source:** `bcc_trust_last_login` user_meta, written by
 * `UserLifecycleService::onLogin()` on every login. This is the
 * canonical source — NOT the `bcc_trust_user_info.last_login` column
 * (PeepSo-mirrored, may lag) and NOT `usr_last_activity` (PeepSo
 * activity-ping, fires on any page view including anonymous heartbeat
 * — too sensitive for dormancy).
 *
 * **No-data defaults:**
 *   - Missing `bcc_trust_last_login` meta (operator hasn't logged in
 *     since UserLifecycleService deployed) → treat as NOT dormant.
 *     Returning true on missing-data would slap an INACTIVE marker
 *     on legitimate operators just because of a deployment gap —
 *     wrong direction per §2.7 status-anxiety mitigation.
 *   - userId <= 0 → false (anonymous can't be dormant).
 *
 * **Performance note:** invoked once per roster row in
 * `AttestationService::buildAttestorMiniView`. On a roster page of
 * 50 rows, that's 50 user_meta reads + 50 SQL counts. Acceptable for
 * V1 scale; Slice E.5 ships `bcc_attestor_reliability_cache` which
 * memoizes the result on a nightly recompute.
 *
 * @package BCC\Trust\Core\Services
 * @since V2 Trust Attestation Layer PR-6 (2026-05-13)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Repositories\AttestationRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class DormancyDetector
{
    /**
     * §12 q9 display threshold. Tunable; closed-network testing
     * retunes. The 60-day window matches the §1.4 "slot-counting
     * dormancy" baseline; if those diverge later, split into two
     * constants.
     */
    public const DISPLAY_DORMANT_DAYS = 60;

    /** WordPress user_meta key written by UserLifecycleService::onLogin. */
    private const LOGIN_META_KEY = 'bcc_trust_last_login';

    public function __construct(
        private readonly AttestationRepository $repo
    ) {
    }

    /**
     * Is this operator currently dormant by the §J.4 display rule?
     *
     * Returns false on missing data (defensive default — never mark
     * an operator INACTIVE without strong signal).
     */
    public function isDormant(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $thresholdSeconds = self::DISPLAY_DORMANT_DAYS * 86400;
        $nowTs            = time();
        $sinceTs          = $nowTs - $thresholdSeconds;
        $sinceMysqlUtc    = gmdate('Y-m-d H:i:s', $sinceTs);

        // Condition 1: last_login older than threshold (or unknown).
        // Unknown last_login → not dormant (defensive; see header).
        $lastLoginRaw = (string) get_user_meta($userId, self::LOGIN_META_KEY, true);
        if ($lastLoginRaw === '') {
            return false;
        }
        $lastLoginTs = strtotime($lastLoginRaw . ' UTC');
        if ($lastLoginTs === false) {
            return false;
        }
        if ($lastLoginTs >= $sinceTs) {
            // Recent login → not dormant regardless of attestation
            // activity. Lurkers are not dormant per §2.9.
            return false;
        }

        // Condition 2: no attestation activity inside the window.
        // (Counts cast / reaffirm / revoke as activity — any of the
        // three is a sign of life.)
        $recentActivity = $this->repo->countByActorSince($userId, $sinceMysqlUtc);
        if ($recentActivity > 0) {
            return false;
        }

        return true;
    }
}
