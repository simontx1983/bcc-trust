<?php
/**
 * Rank Progression Listener — closes the §E2 social loop by detecting
 * auto-derived rank changes and emitting the §O1.2 Heavy celebration.
 *
 * The existing `RankService::autoDerivedRank()` is reactive — it
 * computes the current rank on every read from the user's reputation
 * tier. That's correct for view-models, but it means a user crosses
 * tier boundaries silently: their rank flips Apprentice → Journeyman
 * with no event, no toast, no audit trail. This listener watches
 * activity events that could plausibly nudge reputation, compares
 * the user's last-seen rank against the current auto-derived rank,
 * and on a strict promotion stashes a celebration + emits
 * `bcc_rank_awarded`.
 *
 * Why subscribe to activity events (not a hypothetical
 * `bcc_reputation_changed` event):
 *
 *   - There is no such event today. Reputation is recomputed
 *     synchronously inside the vote/endorsement path or via async
 *     `bcc_trust_async_reputation_recalculate` jobs, neither of
 *     which fire a clean tier-changed signal.
 *   - Activity events (`bcc_post_created`, `bcc_review_published`,
 *     `bcc_card_pulled`, `bcc_trust_vote_cast`) cover every plausible
 *     trigger for tier movement. Subscribing on the activity side
 *     accepts eventual consistency: a promotion that lands on the
 *     async recompute is detected on the user's next activity event.
 *     For a celebration that fires "shortly after you do something
 *     trust-relevant," that's fine — and aligns with §A3's queued-
 *     subscriber expectation.
 *
 * Seed-quietly invariant:
 *   - The very first event a user sees with no `bcc_last_seen_rank`
 *     meta seeds the meta to the current auto-derived rank WITHOUT
 *     firing a celebration. This prevents a user who is already
 *     Journeyman at the moment this listener ships from being
 *     "celebrated" for a promotion that happened weeks ago.
 *
 * Single-source-of-trust per §A4: this listener does NOT compute
 * tiers or scores. It reads `RankService::autoDerivedRank()` and
 * compares strings. The math stays in the trust services.
 *
 * @package BCC\Trust\Core\Services
 * @since V1 (2026-04, §E2 / §O1.2)
 */

namespace BCC\Trust\Core\Services;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Support\CelebrationStash;
use BCC\Trust\Core\Support\RankCatalog;

if (!defined('ABSPATH')) {
    exit;
}

final class RankProgressionListener
{
    /**
     * Where the user's last-seen rank is persisted between events.
     * Single string ('apprentice', 'journeyman', etc.). Updated on
     * every detected transition — promotion OR demotion — so the
     * comparison stays tight.
     */
    private const LAST_SEEN_META_KEY = 'bcc_last_seen_rank';

    /**
     * Heavy-celebration `kind` for rank-up events. Stable identifier
     * the frontend maps to its rank-up animation preset.
     */
    private const KIND_RANK_UP = 'rank_up';

    /**
     * Frontend asset key for the rank-up icon. Kept here (not the
     * Catalog) because the catalog is rank-data; this is celebration
     * presentation.
     */
    private const ICON_RANK_UP = 'rank-up';

    public function __construct(
        private readonly RankService $rankService
    ) {
    }

    /**
     * Single entry point — every subscribed event funnels through here.
     *
     * Cheap by design: one user-meta read, one autoDerivedRank lookup
     * (which is itself a cached reputation read), one usermeta write
     * on transition. Safe to call from request-path event subscribers
     * without violating §L1 (300ms response budget).
     *
     * No-ops for invalid users (anonymous, deleted, system events that
     * pass user_id=0). Logs a warning if RankCatalog rejects the
     * computed rank — that would mean RankService and RankCatalog
     * have drifted, which we want to know about loudly.
     */
    public function onActivityEvent(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $current = $this->rankService->autoDerivedRank($userId);
        $currentOrder = RankCatalog::orderOf($current);
        if ($currentOrder === null) {
            // Drift between RankService and RankCatalog — fail loudly
            // in logs but don't crash the request path.
            Logger::warning('[RankProgressionListener] unknown rank from RankService', [
                'user_id' => $userId,
                'rank'    => $current,
            ]);
            return;
        }

        $lastSeenRaw = get_user_meta($userId, self::LAST_SEEN_META_KEY, true);
        $lastSeen = is_string($lastSeenRaw) && $lastSeenRaw !== '' ? $lastSeenRaw : null;

        if ($lastSeen === null) {
            // First time we've seen this user. Seed quietly — see
            // class docstring for the rationale.
            update_user_meta($userId, self::LAST_SEEN_META_KEY, $current);
            return;
        }

        if ($lastSeen === $current) {
            return;
        }

        $lastOrder = RankCatalog::orderOf($lastSeen);
        // Persist the new last-seen rank regardless of direction so
        // future comparisons stay tight against the current state.
        update_user_meta($userId, self::LAST_SEEN_META_KEY, $current);

        if ($lastOrder === null || $currentOrder > $lastOrder) {
            // Promotion — celebrate.
            $label = self::buildPromotionLabel($current);
            CelebrationStash::pushHeavy(
                $userId,
                self::KIND_RANK_UP,
                $label,
                self::ICON_RANK_UP
            );

            Logger::info('[RankProgressionListener] rank promoted', [
                'user_id' => $userId,
                'from'    => $lastSeen,
                'to'      => $current,
            ]);

            // §A3 event bus — subscribers (audit, future notification
            // dispatch) attach independently. Single emission per
            // state change.
            do_action('bcc_rank_awarded', $userId, $current, $lastSeen);
            return;
        }

        // Demotion — quiet log only. Per §E2 and §O1.2, negative
        // events don't fire Heavy celebrations.
        Logger::info('[RankProgressionListener] rank demoted', [
            'user_id' => $userId,
            'from'    => $lastSeen,
            'to'      => $current,
        ]);
    }

    /**
     * Build the label the frontend renders inside the toast. Server-
     * rendered per §A2 — frontend never derives rank copy.
     *
     * Falls back to a generic line if the catalog is missing the label
     * (shouldn't happen given the orderOf check above, but defense in
     * depth keeps the celebration shipping rather than hanging on a
     * bad catalog state).
     */
    private static function buildPromotionLabel(string $rankKey): string
    {
        $label = RankCatalog::getLabel($rankKey);
        if ($label === null || $label === '') {
            return 'New rank earned.';
        }
        return sprintf("You're now a %s.", $label);
    }
}
