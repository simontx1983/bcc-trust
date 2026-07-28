<?php
/**
 * ReputationTierMap — single source of truth for the §C1 reputation
 * tier → display-label mapping.
 *
 * ## The rarity vocabulary is RETIRED (contract v1.56)
 *
 * This class used to carry a second mapping onto collectible-rarity words
 * (elite→Legendary, trusted→Rare, neutral→Uncommon, caution→Common,
 * risky→null). That mapping and its `card_tier` field are gone. Do not
 * reintroduce them. The reasons, in ascending order of severity:
 *
 *  1. The ordinal was inverted. `neutral` is the STARTING tier — every
 *     member begins there, and ReputationRepository::getTier() defaults to
 *     it — so by population it is the common case, yet it read as
 *     "Uncommon". `caution` has to be actively fallen into, making it
 *     rarer, yet it read as "Common".
 *  2. Rarity words describe a denominator we are deliberately trying to
 *     move. If the platform works, most members end up trusted or better,
 *     at which point calling trusted "Rare" is simply false — our own
 *     success would break our vocabulary.
 *  3. Rarity words are positive-coded: rare reads as desirable. Labelling a
 *     `caution` member "Common" told them their card was unremarkable when
 *     the tier means something has gone wrong. Worse, the rarity palette
 *     rendered that warning in NEUTRAL GREY (--bcc-tier-common #6b6e72)
 *     while the trust ramp has caution in warning yellow. The card did not
 *     merely mislabel the signal, it desaturated it.
 *  4. `risky` had no rarity slot at all, so the single most safety-relevant
 *     state in the system rendered as NO TAG on the card and, because
 *     RankChip was keyed on card_tier too, as a neutral grey dot
 *     everywhere else. The most important thing to show was invisible.
 *
 * TIER_LABEL below was always the honest vocabulary — it predates the
 * retirement and already covered all five tiers. It is now the only one.
 *
 * ## Why this class still exists
 *
 * Before it, the mapping was duplicated verbatim across UserViewService,
 * CardViewService, WatchingService, TierUpgradeListener and
 * CardsSearchEndpoint. A drift between any two silently mis-labelled a
 * member on one surface relative to another — a P1 contract break.
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
     * Reputation tier → user-facing label. Pre-rendered server-side per §A2
     * — the frontend never templates a label from the slug.
     *
     * Covers all five tiers, `risky` included: every surface that shows a
     * tier can now show every tier, which was not true under the retired
     * rarity mapping.
     *
     * The top band reads "Elite" (owner decision, 2026-07-28). The machine
     * identifier stays `elite` — label and key are deliberately the same
     * word here, so there is no mapping to drift. "Proven" was considered
     * and rejected; do not reintroduce it as an alternate label.
     * See docs/glossary.md §6.
     *
     * @var array<string, string>
     */
    public const TIER_LABEL = [
        'risky'   => 'Risky',
        'caution' => 'Caution',
        'neutral' => 'Neutral',
        'trusted' => 'Trusted',
        'elite'   => 'Elite',
    ];

    /**
     * The canonical tier ordering, lowest to highest. Exposed so callers
     * that need to compare or sort tiers do not hand-roll an ordinal map.
     *
     * @var list<string>
     */
    public const TIER_ORDER = ['risky', 'caution', 'neutral', 'trusted', 'elite'];

    /**
     * Map a reputation tier to its display label. Unknown tiers fall back
     * to "Neutral" — mirroring ReputationRepository::getTier()'s neutral
     * default, so a brand-new or missing-row member reads as a plain
     * neutral member rather than an unlabelled one.
     */
    public static function toReputationTierLabel(string $tier): string
    {
        return self::TIER_LABEL[$tier] ?? self::TIER_LABEL['neutral'];
    }

    /**
     * Resolve the member identity-chip tier block in one call.
     *
     * Retained (rather than folded into toReputationTierLabel) because
     * view-model builders assemble a block, and keeping one call site per
     * builder is what stopped the mapping drifting across surfaces in the
     * first place.
     *
     * @return array{reputation_tier: string, reputation_tier_label: string}
     */
    public static function resolveReputation(string $tier): array
    {
        $known = isset(self::TIER_LABEL[$tier]) ? $tier : 'neutral';

        return [
            'reputation_tier'       => $known,
            'reputation_tier_label' => self::toReputationTierLabel($known),
        ];
    }
}
