<?php
/**
 * PageOwnerDecision — ONE answer to "may this claimer be made the page's
 * PeepSo owner?" (F-299), shared by the two paths that write the row:
 *
 *   - the LIVE claim path (PeepSoIntegration::ensurePageOwnerMembership,
 *     run when a claim is verified), and
 *   - the post-release BACKFILL (BackfillPageOwnersCommand::plan).
 *
 * Both used to decide separately; the backfill refused two cases the live
 * path would have written. One helper, so they cannot drift:
 *
 *   - the claimer is BANNED on the page → skip (a claim never lifts a ban);
 *   - a DIFFERENT member already holds `member_owner` → skip (never a
 *     second owner; ownership is not transferred by a claim);
 *   - the claimer is already the owner → nothing to do;
 *   - otherwise JOIN (no row yet) or PROMOTE (an existing non-owner row).
 *
 * Pure: the caller reads the two inputs.
 *
 * @package BCC\Trust\Core\Support
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class PageOwnerDecision
{
    public const JOIN             = 'join';
    public const PROMOTE          = 'promote';
    public const SKIP_BANNED      = 'skip_banned';
    public const SKIP_OTHER_OWNER = 'skip_other_owner';
    public const ALREADY_OWNER    = 'already_owner';

    /**
     * @param string|null $claimerStatus the claimer's existing peepso_page_members
     *        status on the page (`member`, `banned`, …), null = no row
     * @param int $otherOwner a DIFFERENT user holding `member_owner` on the page, 0 = none
     * @return self::JOIN|self::PROMOTE|self::SKIP_BANNED|self::SKIP_OTHER_OWNER|self::ALREADY_OWNER
     */
    public static function decide(?string $claimerStatus, int $otherOwner): string
    {
        if ($claimerStatus === 'member_owner') {
            return self::ALREADY_OWNER;
        }
        if ($otherOwner > 0) {
            return self::SKIP_OTHER_OWNER;
        }
        if ($claimerStatus === 'banned') {
            return self::SKIP_BANNED;
        }

        return $claimerStatus === null ? self::JOIN : self::PROMOTE;
    }

    /** True for the two outcomes that must never write (manual review). */
    public static function isSkip(string $decision): bool
    {
        return $decision === self::SKIP_BANNED || $decision === self::SKIP_OTHER_OWNER;
    }
}
