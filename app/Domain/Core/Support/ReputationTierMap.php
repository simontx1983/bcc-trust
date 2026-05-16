<?php
/**
 * ReputationTierMap — single source of truth for the §C1 reputation
 * tier → card_tier → display-label chain.
 *
 * §C1 mapping (locked):
 *   elite   → legendary
 *   trusted → rare
 *   neutral → uncommon
 *   caution → common
 *   risky   → null   (entity hidden from card UI per §C1)
 *
 * Before this class existed, the same mapping was duplicated verbatim
 * across UserViewService, CardViewService, WatchingService,
 * TierUpgradeListener, and CardsSearchEndpoint. A drift between any two
 * would silently mis-tier a card on one surface relative to another —
 * a P1 contract break. All five now resolve through here.
 *
 * @package BCC\Trust\Core\Support
 * @since V1
 */

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class ReputationTierMap
{
    /**
     * Reputation tier → card_tier. Null for `risky` (entity hidden
     * from card UI per §C1).
     *
     * @var array<string, string|null>
     */
    public const TIER_TO_CARD = [
        'elite'   => 'legendary',
        'trusted' => 'rare',
        'neutral' => 'uncommon',
        'caution' => 'common',
        'risky'   => null,
    ];

    /**
     * card_tier → user-facing label. Pre-rendered server-side per §A2 —
     * frontend never templates a tier label from the slug.
     *
     * @var array<string, string>
     */
    public const CARD_TIER_LABEL = [
        'legendary' => 'Legendary',
        'rare'      => 'Rare',
        'uncommon'  => 'Uncommon',
        'common'    => 'Common',
    ];

    /**
     * Map a reputation tier to its card_tier slug. Returns null for
     * `risky` and for any unknown tier value.
     */
    public static function toCardTier(string $tier): ?string
    {
        return self::TIER_TO_CARD[$tier] ?? null;
    }

    /**
     * Map a card_tier slug to its display label. Accepts null
     * (returns null) so callers with optional card_tier values can
     * pipe through without a guard.
     */
    public static function toCardTierLabel(?string $cardTier): ?string
    {
        if ($cardTier === null) {
            return null;
        }
        return self::CARD_TIER_LABEL[$cardTier] ?? null;
    }

    /**
     * Resolve both card_tier slug and label from a reputation tier in
     * one call. Convenience for view-model builders that need both.
     *
     * @return array{key: string|null, label: string|null}
     */
    public static function resolve(string $tier): array
    {
        $key   = self::toCardTier($tier);
        $label = self::toCardTierLabel($key);
        return ['key' => $key, 'label' => $label];
    }
}
