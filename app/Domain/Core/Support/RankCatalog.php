<?php
/**
 * Canonical rank catalog — the earned **Rank** axis (one of three
 * orthogonal identity axes; see docs/glossary.md §1 and api-contract §4.8).
 *
 * Rank mirrors the feature-access **level** (§2.6) and is fully
 * auto-derived from activity by RankService::rankForLevel():
 *   Apprentice = level New · Journeyman = level Active · Master = level Veteran.
 *
 * This class is the single source of rank labels and metadata. Every
 * other code path — the /ranks endpoint, admin tools — reads from
 * here. NEVER inline rank lists elsewhere.
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
     * The full catalog, shaped for the /ranks endpoint response.
     *
     * @return list<array{key: string, label: string, description: string, auto_assigned: bool, order: int}>
     */
    public static function all(): array
    {
        return self::CATALOG;
    }

    /**
     * Whether a key is a valid earned rank on the ladder.
     */
    public static function isValid(string $key): bool
    {
        foreach (self::CATALOG as $rank) {
            if ($rank['key'] === $key) {
                return true;
            }
        }
        return false;
    }

    /**
     * Display label for an earned rank, or null if the key is unknown.
     */
    public static function getLabel(string $key): ?string
    {
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
     * is the highest-ordered rank (currently Master). Unknown keys
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
