<?php
/**
 * Which SECTIONS of a member's profile may a given viewer see (F-341).
 *
 * ⭐ ONE owner for every section switch, across THREE storage conventions
 * that already existed and are deliberately not unified in storage:
 *
 *   - four NEW `bcc_show_*_on_profile` flags (about, verifications,
 *     operator, blogs) — show semantics, ABSENT MEANS ON;
 *   - `bcc_show_groups_on_profile`, shipped by F-270, which is the HALLS
 *     switch. ⚠ Its wire field keeps that name rather than becoming
 *     `show_halls_on_profile`: renaming a field that is already live
 *     breaks clients for no behavioural gain. A deliberate exception to
 *     the naming pattern, noted in the contract (decision, 2026-11-12);
 *   - `reviews_hidden` and `disputes_hidden`, the §K2 flags, which stay
 *     the ONLY storage for those two and are exposed on the wire INVERTED
 *     as `show_reviews_on_profile` / `show_disputes_on_profile`. One
 *     source of truth, no migration, and §K2 stays authoritative.
 *
 * Why one class rather than a flag read at each surface: F-283 was exactly
 * that mistake — a rule with three partial implementations and one surface
 * with none, so a count was hidden on the profile and printed on the card.
 * A new surface that renders a profile section calls this.
 *
 * ⚠ SCOPE OF PR 1. This answers the question; the PAYLOAD applies it. The
 * per-user TAB ROUTES (`/users/{handle}/blog`, `/reviews`, …) are PR 2 —
 * until then a hidden section is still reachable by calling its route
 * directly, so this is not yet a complete privacy boundary and must not be
 * described as one. Per-Hall hiding (`bcc_hidden_hall_ids`) is PR 3.
 *
 * ⚠ `reviews_hidden` / `disputes_hidden` ALREADY gate their own routes and
 * counts (§K2, F-283). F-341 does not change what those routes answer —
 * only the new sections follow the documented-empty rule (decision,
 * 2026-11-12). So for those two this class is a READ of an existing
 * decision, not a new control.
 *
 * @package BCC\Trust\Core\Support
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

use BCC\Core\Repositories\UserMetaRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class ProfileSectionVisibility
{
    public const SECTION_ABOUT         = 'about';
    public const SECTION_VERIFICATIONS = 'verifications';
    public const SECTION_HALLS         = 'halls';
    public const SECTION_OPERATOR      = 'operator';
    public const SECTION_BLOGS         = 'blogs';
    public const SECTION_REVIEWS       = 'reviews';
    public const SECTION_DISPUTES      = 'disputes';

    /** Every section this class governs, in profile order. */
    public const SECTIONS = [
        self::SECTION_ABOUT,
        self::SECTION_VERIFICATIONS,
        self::SECTION_HALLS,
        self::SECTION_OPERATOR,
        self::SECTION_BLOGS,
        self::SECTION_REVIEWS,
        self::SECTION_DISPUTES,
    ];

    /**
     * Sections stored as their own NEW `bcc_show_*` user_meta, show
     * semantics, absent-means-ON.
     *
     * ⚠ HALLS IS DELIBERATELY ABSENT from this map. Its flag shipped with
     * F-270 and is already read by {@see ProfileAccess::showsGroupsOnProfile()},
     * so this class DELEGATES to that rather than re-reading the key —
     * two readers of one flag is how defaults drift apart, and it is the
     * duplication F-283 was. The wire field also keeps F-270's name
     * (`show_groups_on_profile`) rather than becoming
     * `show_halls_on_profile`: renaming a live field breaks clients for no
     * behavioural gain. A deliberate exception, noted in the contract.
     *
     * @var array<string, string>
     */
    private const SHOW_META = [
        self::SECTION_ABOUT         => 'bcc_show_about_on_profile',
        self::SECTION_VERIFICATIONS => 'bcc_show_verifications_on_profile',
        self::SECTION_OPERATOR      => 'bcc_show_operator_on_profile',
        self::SECTION_BLOGS         => 'bcc_show_blogs_on_profile',
    ];

    /**
     * Sections whose storage is a §K2 HIDE flag, read inverted.
     *
     * @var array<string, string>
     */
    private const HIDE_FLAG = [
        self::SECTION_REVIEWS  => 'reviews_hidden',
        self::SECTION_DISPUTES => 'disputes_hidden',
    ];

    /**
     * Read every show-flag for a LIST of members in one statement.
     *
     * ⚠⚠ WITHOUT THIS, A LIST IS N+1. {@see hides()} reads through
     * `UserMetaRepository::valuesOrUnknown()`, which memoizes per
     * (user, key) — but nothing primed these particular keys:
     * `PrivacySettings::prime()` reads its own `ALL_KEYS`, and the
     * `bcc_show_*_on_profile` keys are not among them. So each member
     * card issued its own read, which on a directory or watchers page
     * is one statement per member.
     *
     * This is a prime, not a cache of its own: the call populates
     * `UserMetaRepository`'s memo and the return value is deliberately
     * ignored. Every later `hides()` for a primed member is a memo
     * hit.
     *
     * ⚠ All four show-flags are primed, not only the one a given
     * surface needs, because `read()` takes them in ONE statement
     * (`MAX_KEYS` is 10) — so a narrower prime would cost the same
     * query and serve fewer callers.
     *
     * ⚠ An UNTRUSTED read memoizes nothing, by design: each member
     * then reads for itself and {@see hides()} answers "hidden" for
     * each. Slower and private, rather than fast and wrong.
     *
     * ⚠ `valuesOrUnknown()` REFUSES more than `BATCH_MAX` (1000)
     * members rather than truncating, which would silently leave the
     * tail unprimed. Card lists are bounded far below that; a caller
     * that is not must chunk.
     *
     * @param list<int> $ownerIds
     */
    public static function prime(array $ownerIds): void
    {
        $ids = [];
        foreach ($ownerIds as $id) {
            $intId = (int) $id;
            if ($intId > 0) {
                $ids[$intId] = true;
            }
        }
        if ($ids === []) {
            return;
        }

        UserMetaRepository::valuesOrUnknown(
            array_keys($ids),
            array_merge(array_values(self::SHOW_META), [ProfileAccess::META_SHOW_GROUPS])
        );
    }

    /**
     * Is `$section` hidden from this viewer on `$ownerId`'s profile?
     *
     * The owner always sees their own sections, and an admin keeps the
     * §F-02 exemption — the same one the rest of profile privacy uses, so
     * an admin's view cannot differ between surfaces.
     *
     * ⚠ An UNKNOWN section name returns false (visible) rather than
     * throwing or hiding. A typo must not blank a section silently, and it
     * must not take a profile down either; {@see isKnownSection()} is how
     * a caller checks deliberately.
     */
    public static function hides(string $section, int $ownerId, int $viewerId): bool
    {
        if ($ownerId <= 0) {
            return false;
        }
        if ($viewerId > 0 && $viewerId === $ownerId) {
            return false;
        }
        // The viewer is not always the request's user: a card list can
        // be composed for one member while another is signed in, and an
        // async or fan-out build has no current user at all. So the
        // exemption is resolved for the VIEWER being served.
        if (ProfileAccess::exemptFor($viewerId)) {
            return false;
        }

        // §K2 storage, read inverted. `flag()` already returns bool.
        if (isset(self::HIDE_FLAG[$section])) {
            return PrivacySettings::flag($ownerId, self::HIDE_FLAG[$section]);
        }

        // Halls: one implementation, F-270's. See SHOW_META's note.
        //
        // ⚠ The viewer goes WITH it. That gate resolves the owner and
        // the exemption itself, which the two short-circuits above have
        // already settled for this call — harmless, and it is what lets
        // the gate be correct for a caller that has not.
        if ($section === self::SECTION_HALLS) {
            return !ProfileAccess::showsGroupsOnProfile($ownerId, $viewerId);
        }

        if (!isset(self::SHOW_META[$section])) {
            return false;
        }

        $shown = self::showFlagOrUnknown($ownerId, self::SHOW_META[$section]);

        // A read that could not be trusted HIDES. The owner and the
        // exempt already returned above, so this is only ever the
        // answer given to a viewer the setting applies to.
        return $shown === null ? true : !$shown;
    }

    /** The inverse of {@see hides()}, for readability at call sites. */
    public static function shows(string $section, int $ownerId, int $viewerId): bool
    {
        return !self::hides($section, $ownerId, $viewerId);
    }

    /**
     * The `sections` block for the profile payload: section name => bool.
     *
     * Additive and deliberately explicit. Without it a client cannot tell
     * a HIDDEN section from an EMPTY one, and would render an empty tab on
     * someone's profile rather than omitting the tab.
     *
     * @return array<string, bool>
     */
    public static function visibilityMap(int $ownerId, int $viewerId): array
    {
        $map = [];
        foreach (self::SECTIONS as $section) {
            $map[$section] = self::shows($section, $ownerId, $viewerId);
        }

        return $map;
    }

    public static function isKnownSection(string $section): bool
    {
        return in_array($section, self::SECTIONS, true);
    }

    /**
     * Read one `bcc_show_*` flag. ABSENT MEANS ON.
     *
     * ⭐ DELEGATED, not implemented here. This reader and its
     * strictest-row rule now live on {@see ProfileAccess::showFlagOrUnknown()},
     * beside the meta keys, because F-270's groups switch needs the SAME
     * reader and its own copy was the one still failing open. The
     * docblock this replaces had to say "it is EXACTLY what F-270's
     * showsGroupsOnProfile() already does … change BOTH or neither" — a
     * comment standing in for a mechanism. There is now one reader, so
     * the five switches cannot disagree.
     *
     * @return bool|null true shown, false hidden, null NOT READ
     */
    private static function showFlagOrUnknown(int $ownerId, string $metaKey): ?bool
    {
        return ProfileAccess::showFlagOrUnknown($ownerId, $metaKey);
    }
}
