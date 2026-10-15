<?php

/**
 * A5b — the viewer-standing context §4.32.11's capability resolver requires.
 *
 * ── ⚠⚠ WHY THIS CLASS EXISTS: A GAP, NOT A PREFERENCE ──────────────────────
 * `AnnouncementCapabilityResolver::forRow()` documents its own contract as:
 *
 *   "`$viewer` is the viewer's standing, computed by the caller from the
 *    existing services — this class performs no standing check:
 *      authenticated · suspended · fraud_locked · good_standing"
 *
 * The only caller shipped so far — `AnnouncementsEndpoint::viewerStanding()` —
 * returned `['authenticated' => $viewerId > 0]` and nothing else. Every other
 * key was therefore ABSENT, and the resolver fails closed on an absent key, so
 * `good_standing` read as FALSE for everyone. The measured consequence:
 * `can_comment` could never be `allowed` through any live route, for any
 * viewer, because the gate fell through to `suspended`.
 *
 * That was invisible from either side. The resolver was correct, the endpoint
 * was correct about the one key it supplied, and nothing compared the two.
 *
 * ── THE ESTABLISHED SOURCES OF TRUTH, NOT NEW ONES ────────────────────────
 * Every flag below is resolved through a service that already decides it for
 * another surface. Nothing here invents a standing rule:
 *
 *   suspended      `Permissions::is_not_suspended($id, false)` — the
 *                  cross-plugin seam, with the admin bypass DISABLED. That is
 *                  the exact call `CommentService::create()` makes for an
 *                  ORDINARY comment and `MessagesService::senderRestricted()`
 *                  makes for a DM: "a suspended member, admin included, cannot
 *                  comment". An announcement comment is a comment, so it gets
 *                  the same bar.
 *
 *   fraud_locked   PeepSo `usr_role === 'ban'`, which is
 *                  `MessagesService::senderRestricted()`'s second check and
 *                  mirrors "the state PeepSo's own writers refuse".
 *
 *   good_standing  `UserViewService::isInGoodStanding($tier)` over the tier
 *                  from `reputationRepository()->getTier()` — the path
 *                  `AuthSupport::resolveInGoodStanding()` already uses. That
 *                  method's docblock is explicit: "Single source of truth for
 *                  the tier-to-standing mapping — do NOT duplicate the
 *                  in_array list elsewhere." So the list is not restated here.
 *
 * ⚠⚠ GOOD STANDING MEANS TIER >= NEUTRAL, and that has a visible consequence:
 * a signed-in member below `neutral` CANNOT comment. That is the established
 * meaning of the phrase in this codebase (`GOOD_STANDING_TIERS` is
 * `['neutral','trusted','elite']`) and it is what §4.32.7's "signed-in members
 * in good standing only" resolves to. It is called out because it is a real
 * product consequence that a reader should not have to derive.
 *
 * ⚠ FAIL CLOSED, DELIBERATELY DIVERGING FROM `AuthSupport`.
 * `AuthSupport::resolveInGoodStanding()` wraps the same lookup and fails OPEN
 * on error, with a stated reason: "The auth response should never block on a
 * downstream tier-lookup failure — worst-case the chrome stamp shows on a user
 * who's technically not in good standing, which is recoverable on next login."
 *
 * That reasoning does not transfer. Here the flag decides whether a member may
 * WRITE, and an unreadable tier is not evidence of eligibility. A failed lookup
 * therefore denies, and the divergence is recorded rather than left to look
 * like an inconsistency.
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Announcements;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Services\UserViewService;

if (!defined('ABSPATH')) {
    exit;
}

final class AnnouncementViewerStanding
{
    /**
     * The four keys `AnnouncementCapabilityResolver::forRow()` reads.
     *
     * ⚠ ALL FOUR ARE ALWAYS PRESENT. The resolver fails closed on an absent
     * key, which is correct of it, but it means a partial array silently
     * denies — exactly the defect this class replaces. Returning a complete
     * array is the whole point, so there is no early return that omits keys.
     *
     * @return array{authenticated: bool, suspended: bool, fraud_locked: bool, good_standing: bool}
     */
    public static function forViewer(int $viewerId): array
    {
        if ($viewerId <= 0) {
            // ⚠ An anonymous viewer is reported as not-authenticated and
            // otherwise NEUTRAL, not as suspended or fraud-locked. The
            // resolver's order checks `authenticated` before standing, so the
            // reason a visitor sees is `auth_required` — "sign in" — rather
            // than an alarming `suspended` for an account that does not exist.
            return [
                'authenticated' => false,
                'suspended'     => false,
                'fraud_locked'  => false,
                'good_standing' => false,
            ];
        }

        return [
            'authenticated' => true,
            'suspended'     => self::isSuspended($viewerId),
            'fraud_locked'  => self::isFraudLocked($viewerId),
            'good_standing' => self::isInGoodStanding($viewerId),
        ];
    }

    /**
     * ⚠ `allowAdminBypass = false`. An administrator who is suspended is still
     * suspended for the purpose of writing content — the same choice
     * `CommentService` and `MessagesService` make, and the reason
     * `Permissions::is_not_suspended()` takes the parameter at all.
     */
    private static function isSuspended(int $viewerId): bool
    {
        try {
            return !\BCC\Core\Permissions\Permissions::is_not_suspended($viewerId, false);
        } catch (\Throwable $e) {
            // Fail closed: an unreadable suspension state is treated as
            // suspended, because the alternative is granting a write on a
            // lookup that did not answer.
            Logger::warning('[AnnouncementViewerStanding] suspension lookup failed; failing closed', [
                'user_id' => $viewerId,
                'error'   => $e->getMessage(),
            ]);

            return true;
        }
    }

    /**
     * PeepSo's own ban state, mirroring `MessagesService::senderRestricted()`.
     *
     * ⚠ `class_exists()` guarded: PeepSo is a hard dependency of the running
     * site but not of this class's unit coverage, and an absent PeepSo must not
     * fatal a capability read. Absent PeepSo reads as NOT banned, which is the
     * same interpretation `MessagesService` takes — the ban is an extra
     * restriction on top of suspension, not the primary gate.
     */
    private static function isFraudLocked(int $viewerId): bool
    {
        if (!class_exists('PeepSoUser')) {
            return false;
        }

        try {
            // ⚠ No `is_string()` guard: PeepSo's stub types `get_user_role()`
            // as returning string, so PHPStan reads the guard as always true.
            // The strict comparison is the whole check — and if the real
            // return were ever something else, `=== 'ban'` is false, which is
            // the safe direction.
            return \PeepSoUser::get_instance($viewerId)->get_user_role() === 'ban';
        } catch (\Throwable $e) {
            Logger::warning('[AnnouncementViewerStanding] PeepSo role lookup failed; failing closed', [
                'user_id' => $viewerId,
                'error'   => $e->getMessage(),
            ]);

            return true;
        }
    }

    /**
     * Tier >= neutral, through the canonical mapping.
     *
     * ⚠ The tier list is NOT restated here. `UserViewService::isInGoodStanding()`
     * owns it and says so in its own docblock; duplicating the `in_array` would
     * be the §11 violation that docblock exists to prevent.
     */
    private static function isInGoodStanding(int $viewerId): bool
    {
        try {
            $tier = Plugin::instance()->reputationRepository()->getTier($viewerId);

            return UserViewService::isInGoodStanding((string) $tier);
        } catch (\Throwable $e) {
            // ⚠ FAIL CLOSED — the deliberate divergence from
            // AuthSupport::resolveInGoodStanding(), which fails OPEN because it
            // is decorating a login response rather than gating a write.
            Logger::warning('[AnnouncementViewerStanding] tier lookup failed; failing closed', [
                'user_id' => $viewerId,
                'error'   => $e->getMessage(),
            ]);

            return false;
        }
    }
}
