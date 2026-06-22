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
     * Reputation tier → **honest member trust-tier label** — the chip a
     * human reads (`reputation_tier_label`), distinct from the entity-card
     * *rarity* words above. A *caution* member must not read as "Common";
     * *neutral* (the start) must not read as "Uncommon". The internal key
     * `elite` is labelled "Proven". Covers all five tiers incl. `risky`
     * (which has no card rarity). See docs/glossary.md §6.
     *
     * @var array<string, string>
     */
    public const TIER_LABEL = [
        'risky'   => 'Risky',
        'caution' => 'Caution',
        'neutral' => 'Neutral',
        'trusted' => 'Trusted',
        'elite'   => 'Proven',
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

    /**
     * Map a reputation tier to its **honest trust-tier label** (the
     * member chip). Unknown tiers fall back to "Neutral" — mirroring
     * ReputationRepository::getTier()'s neutral default, so a brand-new
     * or missing-row member reads as a plain neutral member rather than
     * an unlabelled one.
     */
    public static function toReputationTierLabel(string $tier): string
    {
        return self::TIER_LABEL[$tier] ?? self::TIER_LABEL['neutral'];
    }

    /**
     * Resolve the full member identity-chip tier block in one call:
     * the entity-card rarity (`card_tier`/`tier_label`, may be null for
     * risky) AND the honest member trust label (`reputation_tier_label`).
     * View-model builders for member surfaces use this so the two
     * vocabularies never drift apart.
     *
     * @return array{
     *   card_tier: string|null,
     *   tier_label: string|null,
     *   reputation_tier_label: string
     * }
     */
    public static function resolveReputation(string $tier): array
    {
        $card = self::resolve($tier);
        return [
            'card_tier'             => $card['key'],
            'tier_label'            => $card['label'],
            'reputation_tier_label' => self::toReputationTierLabel($tier),
        ];
    }
}
