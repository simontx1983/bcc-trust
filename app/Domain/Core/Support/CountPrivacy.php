<?php
/**
 * Which of a member's COUNTS may a given viewer see (F-283).
 *
 * ⭐ ONE owner for a rule that had three partial implementations and one
 * surface with none — which is why F-283 exists. Before this:
 *
 *   - `UserViewService::resolveCounts()` zeroed the four counts inline,
 *     correctly, for the full profile payload;
 *   - `MemberProfileComposer` had its own `$hideFor()` closure emitting
 *     `hidden` flags for the tab strip;
 *   - `UserReviewsService` / `UserDisputesService` gated their LISTS;
 *   - `CardViewService::buildMemberStats()` consulted NONE of them, so a
 *     member card published the very numbers the profile zeroed;
 *   - `UserFollowsService` gated on `watching_hidden` only, so a member
 *     who hid their follower COUNT still had the true total served as
 *     `pagination.total`.
 *
 * A count is a small disclosure, but it is still the disclosure the member
 * switched off, and "hidden on one surface, printed on the next" is worse
 * than either answer consistently. New surfaces that emit these counts
 * call this rather than re-reading the flags.
 *
 * ⚠ SCOPE: this owns the COUNTS only. Whether a LIST is served
 * (`/followers`, the reviews tab, the disputes tab) is a separate decision
 * those services already make, and a 403 there is a different product
 * answer from a zeroed number here. Do not fold them together.
 *
 * ⚠ `reviews_received` is deliberately NOT governed. Product decision
 * 2026-07-22: reviews written ABOUT a member are public, and
 * `reviews_hidden` covers only the ones they wrote. Zeroing it here would
 * silently reverse that.
 *
 * ⚠ The owner always sees their own counts, so every entry point takes
 * `$isSelf` rather than deriving it — the two call sites that have a
 * session viewer and the one that has a caller-supplied id must not
 * disagree about who "self" is.
 *
 * @package BCC\Trust\Core\Support
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class CountPrivacy
{
    /** Flag names as stored in the §K2 privacy block. */
    public const FLAG_FOLLOWERS = 'follower_count_hidden';
    public const FLAG_WATCHING  = 'watching_hidden';
    public const FLAG_REVIEWS   = 'reviews_hidden';
    public const FLAG_DISPUTES  = 'disputes_hidden';

    /**
     * Does `$privacy` hide `$flag` from this viewer?
     *
     * Absent or non-true means visible: the block is read from user_meta
     * and a missing key must not be mistaken for "hidden", which would
     * blank counts for every member who never opened the setting.
     *
     * @param array<string, mixed> $privacy
     */
    public static function hides(array $privacy, string $flag, bool $isSelf): bool
    {
        if ($isSelf) {
            return false;
        }

        return ($privacy[$flag] ?? false) === true;
    }

    /**
     * Apply the four rules to a §3.1 `counts` block.
     *
     * Takes the FULLY BUILT block and narrows it, rather than zeroing the
     * inputs before assembly: the mapping from flag to count key then
     * lives in one readable place, and a new count added to the block is
     * visibly either governed here or deliberately not.
     *
     * ⚠ SHAPE-PRESERVING, and typed to say so. It only ever reassigns
     * existing keys to 0 — it never adds or removes one — so the caller's
     * precise array shape survives. Without the template the return type
     * would collapse to `array<string, mixed>` and
     * `UserViewService::resolveCounts()` could no longer declare the exact
     * `counts` block the §3.1 contract pins.
     *
     * @param array{followers: int, following: int, watching_size: int, reviews_written: int, disputes_signed: int, solids_given: int, solids_received: int, ...} $counts
     * @param array<string, mixed> $privacy
     * @return array{followers: int, following: int, watching_size: int, reviews_written: int, disputes_signed: int, solids_given: int, solids_received: int, ...}
     */
    public static function applyToCounts(array $counts, array $privacy, bool $isSelf): array
    {
        if ($isSelf) {
            return $counts;
        }

        if (self::hides($privacy, self::FLAG_FOLLOWERS, $isSelf)) {
            $counts['followers'] = 0;
        }
        if (self::hides($privacy, self::FLAG_WATCHING, $isSelf)) {
            // §C2 single-graph rule: the watchlist IS the follow set, so
            // `watching_size` and `following` are the same disclosure and
            // one flag governs both.
            $counts['watching_size'] = 0;
            $counts['following']     = 0;
        }
        if (self::hides($privacy, self::FLAG_REVIEWS, $isSelf)) {
            $counts['reviews_written'] = 0;
        }
        if (self::hides($privacy, self::FLAG_DISPUTES, $isSelf)) {
            $counts['disputes_signed'] = 0;
        }

        return $counts;
    }
}
