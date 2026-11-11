<?php
/**
 * Review Visibility → Score Sync
 *
 * User decision: a moderation-hidden review stops counting toward the
 * reviewed page's trust score and vote tallies while it is hidden, and
 * counts again once restored.
 *
 * The counting side lives in VoteRepository (the page aggregate and tally
 * reads exclude hidden reviews via hiddenReviewExclusion(), the F-55
 * predicate). This class is the trigger side: when an activity's
 * moderation visibility changes (HiddenActivityRepository fires
 * `bcc_trust_activity_visibility_changed` at its single write point — so
 * auto-hide, admin hide, admin restore and undo-of-restore are all
 * covered), and that activity is a REVIEW, recalculate the page it counts
 * toward. Non-review activities are ignored.
 *
 * Never throws into the hiding request: a failed recalculation is logged
 * and handed back to the recalculation cron (recalculateScoreOrRequeue).
 *
 * @package BCC\Trust\Core\Services\Vote
 */

namespace BCC\Trust\Core\Services\Vote;

use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Services\VoteService;
use BCC\Trust\Core\Support\CacheManager;

if (!defined('ABSPATH')) {
    exit;
}

final class ReviewVisibilityScoreSync
{
    public function __construct(
        private readonly VoteRepository $voteRepo,
        private readonly VoteService    $voteService,
    ) {}

    /**
     * @return bool true when a review's page was recalculated; false when
     *              the activity is not a review, or the recalc was queued.
     */
    public function onVisibilityChanged(int $actId): bool
    {
        try {
            $pageId = $this->voteRepo->findReviewPageIdByActivity($actId);
        } catch (\Throwable $e) {
            \BCC\Core\Log\Logger::error('[bcc-trust] review visibility: page lookup failed', [
                'act_id' => $actId,
                'error'  => $e->getMessage(),
            ]);
            return false;
        }

        if ($pageId === null || $pageId <= 0) {
            return false; // not a review activity
        }

        CacheManager::invalidatePageCaches($pageId, null, 'review_visibility_changed');

        if (!$this->voteService->recalculateScoreOrRequeue($pageId)) {
            return false; // logged + re-flagged for the cron
        }

        // Same signal the recalculation cron fires — drives the read model.
        do_action('bcc_trust_score_recalculated', $pageId);
        return true;
    }
}
