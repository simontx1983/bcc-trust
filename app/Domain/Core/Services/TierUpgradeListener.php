<?php
/**
 * Tier Upgrade Listener — closes the §C1 / §N11 / §O1.2 social loop by
 * detecting reputation_tier crossings and emitting the Heavy "your card
 * is now Rare" celebration.
 *
 * Design (last-seen comparison over activity events):
 *   - Subscribes to activity events that could nudge reputation
 *     (no clean `bcc_reputation_changed` exists today).
 *   - Reads the user's last-seen tier from wp_usermeta, compares
 *     against the current tier from ReputationRepository.
 *   - On a strict upward crossing in the tier ladder, stashes a
 *     §O1.2 Heavy celebration via CelebrationStash and fires
 *     `bcc_tier_upgraded`.
 *
 * Tier ladder (order, low → high): risky · caution · neutral · trusted · elite.
 *
 * The celebration label is keyed on the reputation tier label (v1.57 —
 * "Your standing is now Trusted"). It was previously keyed on the retired
 * card rarity ("Your card is now Rare"), which could not render at all for
 * a tier with no rarity slot.
 *
 * Seed-quietly invariant: the very first event with no recorded
 * last-seen tier seeds it WITHOUT a celebration. Existing trusted users
 * shipping into this listener don't get retroactively "promoted."
 *
 * Single-source-of-trust per §A4: this listener does NOT compute tiers.
 * It calls ReputationRepository::getTier() and compares strings. The
 * scoring math stays in the trust services.
 *
 * @package BCC\Trust\Core\Services
 * @since V1 (2026-04, §C1 / §O1.2 tier-upgrade celebration)
 */

namespace BCC\Trust\Core\Services;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Support\CelebrationStash;
use BCC\Trust\Core\Support\ReputationTierMap;

if (!defined('ABSPATH')) {
    exit;
}

final class TierUpgradeListener
{
    /** wp_usermeta key for the last-seen reputation_tier string. */
    private const LAST_SEEN_META_KEY = 'bcc_last_seen_reputation_tier';

    /** Heavy-celebration `kind` — frontend maps to the tier-upgrade preset. */
    private const KIND_TIER_UPGRADE = 'tier_upgrade';

    /** Frontend asset key. Same family as rank-up; toast handles per-kind label. */
    private const ICON_TIER_UPGRADE = 'tier-upgrade';

    /**
     * Tier ladder, low → high. Order is what determines an "upgrade"
     * vs. a "downgrade" — the exact integer values are arbitrary.
     *
     * @var array<string, int>
     */
    private const TIER_ORDER = [
        'risky'   => 0,
        'caution' => 1,
        'neutral' => 2,
        'trusted' => 3,
        'elite'   => 4,
    ];

    public function __construct(
        private readonly ReputationRepository $reputationRepo
    ) {
    }

    /**
     * Single entry point — every subscribed activity event funnels here.
     *
     * Cheap by design: one user-meta read, one tier lookup (cached
     * inside ReputationRepository::getTier), one usermeta write on
     * transition. Safe to call from request-path subscribers.
     */
    public function onActivityEvent(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $current = $this->reputationRepo->getTier($userId);
        $currentOrder = self::TIER_ORDER[$current] ?? null;
        if ($currentOrder === null) {
            // Drift between ReputationRepository and TIER_ORDER —
            // surface in logs but don't break the request path.
            Logger::warning('[TierUpgradeListener] unknown tier from ReputationRepository', [
                'user_id' => $userId,
                'tier'    => $current,
            ]);
            return;
        }

        $lastSeenRaw = get_user_meta($userId, self::LAST_SEEN_META_KEY, true);
        $lastSeen    = is_string($lastSeenRaw) && $lastSeenRaw !== '' ? $lastSeenRaw : null;

        if ($lastSeen === null) {
            // Seed quietly on first encounter — see class docstring.
            update_user_meta($userId, self::LAST_SEEN_META_KEY, $current);
            return;
        }

        if ($lastSeen === $current) {
            return;
        }

        $lastOrder = self::TIER_ORDER[$lastSeen] ?? null;
        // Persist the new tier regardless of direction so future
        // comparisons stay tight.
        update_user_meta($userId, self::LAST_SEEN_META_KEY, $current);

        if ($lastOrder === null || $currentOrder > $lastOrder) {
            $label = self::buildUpgradeLabel($current);
            CelebrationStash::pushHeavy(
                $userId,
                self::KIND_TIER_UPGRADE,
                $label,
                self::ICON_TIER_UPGRADE
            );

            Logger::info('[TierUpgradeListener] tier upgraded', [
                'user_id' => $userId,
                'from'    => $lastSeen,
                'to'      => $current,
            ]);

            // §A3 event bus — single emission per state change.
            do_action('bcc_tier_upgraded', $userId, $current, $lastSeen);
            return;
        }

        // Downgrade — quiet log only. Per §O1.2 negative events do not
        // fire Heavy celebrations.
        Logger::info('[TierUpgradeListener] tier downgraded', [
            'user_id' => $userId,
            'from'    => $lastSeen,
            'to'      => $current,
        ]);
    }

    /**
     * §A2 server-rendered toast headline. Falls back to a generic line
     * if the tier is unrecognized (defense in depth keeps the toast firing).
     */
    private static function buildUpgradeLabel(string $tier): string
    {
        if (!isset(ReputationTierMap::TIER_LABEL[$tier])) {
            return 'Your standing improved.';
        }
        // v1.57: "Your card is now Rare" became "Your standing is now
        // Trusted". The rarity phrasing was retired along with the
        // vocabulary — and it never fired for the bottom tier at all,
        // because risky had no card mapping to render.
        return sprintf('Your standing is now %s.', ReputationTierMap::toReputationTierLabel($tier));
    }
}
