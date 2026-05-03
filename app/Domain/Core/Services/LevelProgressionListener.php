<?php
/**
 * Level Progression Listener — closes the §O5 / §O5.1 / §O1.2 social
 * loop by detecting feature-access level crossings (1 New → 2 Active →
 * 3 Veteran) and emitting the Heavy "level up" celebration.
 *
 * Mirrors RankProgressionListener's design:
 *   - Subscribes to activity events that could plausibly nudge the
 *     level inputs (pulls, posts, reviews, votes).
 *   - Reads the user's last-seen level from wp_usermeta, compares
 *     against the current level from FeatureAccessService.
 *   - On a strict upward crossing, stashes a §O1.2 Heavy celebration
 *     and fires `bcc_feature_level_unlocked`.
 *
 * Level ladder (§O5):
 *   1 — New      (default for all signups)
 *   2 — Active   (5+ pulls AND 3+ Floor visits)
 *   3 — Veteran  (Active reqs AND 3+ reviews AND 30+ days active)
 *
 * Seed-quietly invariant: the very first event with no recorded
 * last-seen level seeds it WITHOUT a celebration. Existing Veteran
 * users on the day this listener ships don't get retroactive toasts.
 *
 * Single-source-of-trust per §A4: this listener does NOT compute
 * level. It calls FeatureAccessService::getLevel() and compares ints.
 *
 * @package BCC\Trust\Core\Services
 * @since V1 (2026-04, §O5 / §O1.2 level-up celebration)
 */

namespace BCC\Trust\Core\Services;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Support\CelebrationStash;

if (!defined('ABSPATH')) {
    exit;
}

final class LevelProgressionListener
{
    /** wp_usermeta key for the last-seen level int. */
    private const LAST_SEEN_META_KEY = 'bcc_last_seen_level';

    /** Heavy-celebration `kind` — frontend maps to the level-up preset. */
    private const KIND_LEVEL_UP = 'level_up';

    /** Frontend asset key. */
    private const ICON_LEVEL_UP = 'level-up';

    /**
     * Display labels for each level. Matches §O5 vocabulary.
     *
     * @var array<int, string>
     */
    private const LEVEL_LABEL = [
        FeatureAccessService::LEVEL_NEW     => 'New',
        FeatureAccessService::LEVEL_ACTIVE  => 'Active',
        FeatureAccessService::LEVEL_VETERAN => 'Veteran',
    ];

    public function __construct(
        private readonly FeatureAccessService $featureAccess
    ) {
    }

    /**
     * Single entry point — every subscribed activity event funnels here.
     *
     * Cheap by design: one user-meta read, one getLevel call (which
     * itself is a few cached counts), one usermeta write on transition.
     */
    public function onActivityEvent(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $current = $this->featureAccess->getLevel($userId);
        if (!isset(self::LEVEL_LABEL[$current])) {
            // Drift between FeatureAccessService and LEVEL_LABEL —
            // surface in logs, don't break the request.
            Logger::warning('[LevelProgressionListener] unknown level from FeatureAccessService', [
                'user_id' => $userId,
                'level'   => $current,
            ]);
            return;
        }

        $lastSeenRaw = get_user_meta($userId, self::LAST_SEEN_META_KEY, true);
        // wp_usermeta stores as string — empty when never set; numeric
        // string after first write.
        $lastSeen = is_numeric($lastSeenRaw) ? (int) $lastSeenRaw : null;

        if ($lastSeen === null) {
            // Seed quietly on first encounter — see class docstring.
            update_user_meta($userId, self::LAST_SEEN_META_KEY, (string) $current);
            return;
        }

        if ($lastSeen === $current) {
            return;
        }

        // Persist the new level regardless of direction (FeatureAccess
        // is monotonic in practice — Veteran cannot demote to Active —
        // but the comparison is one-line either way).
        update_user_meta($userId, self::LAST_SEEN_META_KEY, (string) $current);

        if ($current > $lastSeen) {
            $label = self::buildUpgradeLabel($current);
            CelebrationStash::pushHeavy(
                $userId,
                self::KIND_LEVEL_UP,
                $label,
                self::ICON_LEVEL_UP
            );

            Logger::info('[LevelProgressionListener] level unlocked', [
                'user_id' => $userId,
                'from'    => $lastSeen,
                'to'      => $current,
            ]);

            // §A3 event bus — `bcc_feature_level_unlocked` is the name
            // §O5 reserves for this. Subscribers (audit, future
            // notification copy) attach independently.
            do_action('bcc_feature_level_unlocked', $userId, $current, $lastSeen);
            return;
        }

        // Downgrade — quiet log. Shouldn't happen in V1 (no demotion
        // path on FeatureAccessService thresholds), but log + persist
        // keeps the comparison honest if the rules change.
        Logger::info('[LevelProgressionListener] level dropped (unexpected)', [
            'user_id' => $userId,
            'from'    => $lastSeen,
            'to'      => $current,
        ]);
    }

    /**
     * §A2 server-rendered toast headline.
     */
    private static function buildUpgradeLabel(int $level): string
    {
        $label = self::LEVEL_LABEL[$level] ?? null;
        if ($label === null) {
            return 'New level unlocked.';
        }
        return sprintf("You're now %s.", $label);
    }
}
