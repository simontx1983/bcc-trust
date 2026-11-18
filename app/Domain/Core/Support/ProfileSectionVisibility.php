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
        if (ProfileAccess::viewerIsExemptFromVisibility()) {
            return false;
        }

        // §K2 storage, read inverted. `flag()` already returns bool.
        if (isset(self::HIDE_FLAG[$section])) {
            return PrivacySettings::flag($ownerId, self::HIDE_FLAG[$section]);
        }

        // Halls: one implementation, F-270's. See SHOW_META's note.
        if ($section === self::SECTION_HALLS) {
            return !ProfileAccess::showsGroupsOnProfile($ownerId);
        }

        if (!isset(self::SHOW_META[$section])) {
            return false;
        }

        return !self::showFlag($ownerId, self::SHOW_META[$section]);
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
     * ⚠ The permissive default is the whole point: these switches preserve
     * today's behaviour until a member opts out, so a missing meta row must
     * never read as "hidden" — that would blank the profile of every
     * account that has never opened the setting.
     *
     * `'1'`/`1`/`'true'` are ON. ABSENT or '' is ON — that is the
     * permissive default, and it is the case that matters, because it is
     * every account that has never opened the setting.
     *
     * ⚠ AN UNRECOGNISED VALUE HIDES, because `filter_var` without
     * NULL_ON_FAILURE answers false for it. That is deliberate rather than
     * ideal: it is EXACTLY what F-270's
     * {@see ProfileAccess::showsGroupsOnProfile()} already does, and the
     * Halls section delegates there — so treating unrecognised values more
     * permissively here would make the five show-switches disagree with
     * each other on corrupt data. Unreachable in practice: the only writer
     * stores "1"/"0". If this is ever revisited, change BOTH or neither.
     */
    private static function showFlag(int $ownerId, string $metaKey): bool
    {
        $raw = get_user_meta($ownerId, $metaKey, true);
        if (!is_scalar($raw) || trim((string) $raw) === '') {
            return true;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }
}
