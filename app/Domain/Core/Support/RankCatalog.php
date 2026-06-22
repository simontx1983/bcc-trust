<?php
/**
 * Canonical rank catalog — the earned **Rank** axis (one of three
 * orthogonal identity axes; see docs/glossary.md §1 and api-contract §4.8).
 *
 * Rank mirrors the feature-access **level** (§2.6) and is fully
 * auto-derived from activity by RankService::rankForLevel():
 *   Apprentice = level New · Journeyman = level Active · Master = level Veteran.
 *
 * **Foreman is NOT on this ladder.** It is a conferred *Role*
 * (moderator authority), orthogonal to Rank — see self::ROLES /
 * self::isRole(). Reaching Master does not confer it; it is surfaced
 * via a separate `foreman_insignia` flag, never as a rank label.
 *
 * This class is the single source of rank labels and metadata. Every
 * other code path — the /ranks endpoint, UserRankRepository validation,
 * admin tools — reads from here. NEVER inline rank lists elsewhere.
 *
 * @package BCC\Trust\Core\Support
 * @since V1 (2026-04)
 */

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class RankCatalog
{
    public const RANK_APPRENTICE = 'apprentice';
    public const RANK_JOURNEYMAN = 'journeyman';
    public const RANK_MASTER     = 'master';

    /** Conferred Role key — orthogonal to the earned ladder. */
    public const RANK_FOREMAN = 'foreman';

    /**
     * Canonical earned ladder. Order matches the user-facing
     * progression (Apprentice → Journeyman → Master). All three are
     * auto-assigned (derived from feature-access level); there is no
     * auto-conferred rank above Master.
     *
     * @var list<array{key: string, label: string, description: string, auto_assigned: bool, order: int}>
     */
    private const CATALOG = [
        [
            'key'           => self::RANK_APPRENTICE,
            'label'         => 'Apprentice',
            'description'   => 'New on the floor.',
            'auto_assigned' => true,
            'order'         => 1,
        ],
        [
            'key'           => self::RANK_JOURNEYMAN,
            'label'         => 'Journeyman',
            'description'   => 'Earned the basics.',
            'auto_assigned' => true,
            'order'         => 2,
        ],
        [
            'key'           => self::RANK_MASTER,
            'label'         => 'Master',
            'description'   => 'Master of the trade.',
            'auto_assigned' => true,
            'order'         => 3,
        ],
    ];

    /**
     * Conferred **Roles** — assigned, never auto-earned, orthogonal to
     * the Rank ladder. A user can hold a role at any rank (a Journeyman
     * Foreman is valid). Stored as rows in `bcc_user_ranks` alongside
     * earned ranks, but resolved separately: a role row sets the
     * `foreman_insignia` flag and never changes the user's earned rank.
     *
     * Today: Foreman only. Future roles register here.
     *
     * @var array<string, string> role key → display label
     */
    private const ROLES = [
        self::RANK_FOREMAN => 'Foreman',
    ];

    /**
     * Whether a key is a conferred Role (vs an earned rank). Used by
     * RankService::getViewerBlock to surface `foreman_insignia` without
     * letting the role bleed into the earned-rank fields.
     */
    public static function isRole(string $key): bool
    {
        return isset(self::ROLES[$key]);
    }

    /**
     * The full catalog, shaped for the /ranks endpoint response.
     *
     * @return list<array{key: string, label: string, description: string, auto_assigned: bool, order: int}>
     */
    public static function all(): array
    {
        return self::CATALOG;
    }

    /**
     * Whether a key is a valid `rank_key` for `bcc_user_ranks` — an
     * earned rank OR a conferred role. Roles are valid stored keys even
     * though they're off the earned ladder.
     */
    public static function isValid(string $key): bool
    {
        if (self::isRole($key)) {
            return true;
        }
        foreach (self::CATALOG as $rank) {
            if ($rank['key'] === $key) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a rank can be assigned automatically (vs admin-only).
     * Foreman+ return false; Apprentice/Journeyman return true.
     * Unknown keys return false (fail-closed for admin gates).
     */
    public static function isAutoAssigned(string $key): bool
    {
        foreach (self::CATALOG as $rank) {
            if ($rank['key'] === $key) {
                return $rank['auto_assigned'];
            }
        }
        return false;
    }

    /**
     * Display label for an earned rank OR a conferred role, or null if
     * the key is unknown.
     */
    public static function getLabel(string $key): ?string
    {
        if (isset(self::ROLES[$key])) {
            return self::ROLES[$key];
        }
        foreach (self::CATALOG as $rank) {
            if ($rank['key'] === $key) {
                return $rank['label'];
            }
        }
        return null;
    }

    /**
     * Catalog order for a rank key, or null if unknown. Used by the
     * promotion listener to compare "did the auto-derived rank just
     * climb?" — strictly greater order = celebrate, strictly less =
     * silent demote, equal = no-op. Lookup is O(N) over a 3-row table;
     * not worth caching.
     */
    public static function orderOf(string $key): ?int
    {
        foreach (self::CATALOG as $rank) {
            if ($rank['key'] === $key) {
                return $rank['order'];
            }
        }
        return null;
    }

    /**
     * The catalog order key immediately after $key, or null when $key
     * is the highest-ordered rank (currently Foreman). Unknown keys
     * also return null. Used by LivingService to render the §O3
     * progression strip's "current → next" label.
     */
    public static function getNextRank(string $key): ?string
    {
        $currentOrder = null;
        foreach (self::CATALOG as $rank) {
            if ($rank['key'] === $key) {
                $currentOrder = $rank['order'];
                break;
            }
        }
        if ($currentOrder === null) {
            return null;
        }

        $nextKey   = null;
        $nextOrder = PHP_INT_MAX;
        foreach (self::CATALOG as $rank) {
            if ($rank['order'] > $currentOrder && $rank['order'] < $nextOrder) {
                $nextOrder = $rank['order'];
                $nextKey   = $rank['key'];
            }
        }
        return $nextKey;
    }
}
