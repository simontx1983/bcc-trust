<?php
/**
 * ContributionScoreService — "Trust Recovery Through Contribution".
 *
 * Computes a user's capped contribution + consistency bonus from
 * sustained positive participation, so a user is never permanently
 * trapped in Risky. The bonus is an INTERNAL input (no public score);
 * the daily evaluator persists it to `bcc_trust_reputation.contribution_bonus`
 * and ReputationCalculatorService blends it into reputation_score under a
 * hard ceiling.
 *
 * Anti-abuse rules (see config/contribution.php):
 *   R1 — reactions feed ONLY this capped/ceiling'd bonus, never genuine trust.
 *   R2 — the ceiling (applied at blend time) keeps contribution below Trusted.
 *   R3 — non-overlapping 30/90/180-day buckets, each capped → consistency over spikes.
 *   R4 — a contribution's usefulness is weighted by the engager's reputation tier,
 *        so reaction farming from new/low-tier accounts is near-worthless.
 *
 * Quality floors: only published content counts (CommentRepository /
 * PeepSoActivityRepository filter post_status), and only UPHELD scam
 * reports count. A recent fraud/violation signal dampens contribution and
 * zeroes consistency (clean-record gate).
 *
 * Dispute participation keeps its existing §D5 read-time credit
 * (UserViewService::resolveAugmentedTrustScore) and is intentionally NOT
 * folded in here, to avoid double-counting; promoting it to a tier-affecting
 * signal is a deliberate follow-up.
 *
 * The arithmetic is split into pure static methods so the four rules are
 * unit-testable without a database.
 *
 * @package BCC\Trust\Core\Services
 * @since V1 (2026-06-22)
 */

namespace BCC\Trust\Core\Services;

use BCC\Core\Repositories\PeepSoActivityRepository;
use BCC\Trust\Core\Repositories\CommentRepository;
use BCC\Trust\Core\Repositories\ContentReportRepository;
use BCC\Trust\Core\Repositories\PeepSoReactionRepository;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Repositories\UserInfoRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class ContributionScoreService
{
    public function __construct(
        private readonly CommentRepository $commentRepo,
        private readonly ContentReportRepository $reportRepo,
        private readonly PeepSoReactionRepository $reactionRepo,
        private readonly ReputationRepository $reputationRepo,
        private readonly UserInfoRepository $userInfoRepo
    ) {
    }

    /**
     * Compute the (uncapped-at-source, component-capped) contribution and
     * consistency bonus for a user. Caller persists the sum to
     * `contribution_bonus`; the blend ceiling is applied downstream.
     *
     * @return array{contribution: float, consistency: float}
     */
    public function computeBonus(int $userId): array
    {
        if ($userId <= 0) {
            return ['contribution' => 0.0, 'consistency' => 0.0];
        }

        $now      = time();
        $since30  = gmdate('Y-m-d H:i:s', $now - BCC_CONTRIB_WINDOW_SHORT_DAYS * DAY_IN_SECONDS);
        $since90  = gmdate('Y-m-d H:i:s', $now - BCC_CONTRIB_WINDOW_MID_DAYS * DAY_IN_SECONDS);
        $since180 = gmdate('Y-m-d H:i:s', $now - BCC_CONTRIB_WINDOW_LONG_DAYS * DAY_IN_SECONDS);

        // Cumulative-since points, then split into non-overlapping buckets
        // so activity spread across time (consistency) beats a single spike.
        $p30  = $this->windowPoints($userId, $since30);
        $p90  = $this->windowPoints($userId, $since90);
        $p180 = $this->windowPoints($userId, $since180);

        $bucketRecent = $p30;
        $bucketMid    = max(0.0, $p90 - $p30);
        $bucketLong   = max(0.0, $p180 - $p90);

        $usefulness   = $this->engagementUsefulness($userId, $since180); // R4 (one bounded heavy query)
        $hasViolation = $this->hasRecentViolation($userId);

        $contribution = self::composeContribution(
            $bucketRecent,
            $bucketMid,
            $bucketLong,
            $usefulness,
            $hasViolation
        );

        $ageDays       = self::accountAgeDays($userId);
        $windowsActive = ($bucketRecent > 0.0 ? 1 : 0)
                       + ($bucketMid > 0.0 ? 1 : 0)
                       + ($bucketLong > 0.0 ? 1 : 0);
        $consistency   = self::composeConsistency($ageDays, $windowsActive, $hasViolation);

        return ['contribution' => $contribution, 'consistency' => $consistency];
    }

    // ── DB orchestration ─────────────────────────────────────────────

    /** Raw contribution points for activity since a boundary (pre-bucket, pre-engagement). */
    private function windowPoints(int $userId, string $since): float
    {
        $modules  = PeepSoActivityRepository::aggregateByModule($userId, $since);
        $posts    = (int) ($modules['blog'] ?? 0);
        $reviews  = (int) ($modules['review'] ?? 0);
        $comments = $this->commentRepo->countByAuthorSince($userId, $since);
        $reports  = $this->reportRepo->countUpheldByReporterSince($userId, $since);

        return self::rawWindowPoints($posts, $reviews, $comments, $reports);
    }

    /** R4: usefulness multiplier from trust-weighted reaction engagement. */
    private function engagementUsefulness(int $userId, string $since): float
    {
        $reactorCounts = $this->reactionRepo->getReactorCountsForOwnerSince(
            $userId,
            $since,
            BCC_CONTRIB_ENGAGEMENT_MAX_CONTENT_ROWS
        );
        if ($reactorCounts === []) {
            return 1.0;
        }

        $tiers    = $this->reputationRepo->getTiersForUsers(array_keys($reactorCounts));
        $weighted = 0.0;
        foreach ($reactorCounts as $reactorId => $count) {
            $tier      = $tiers[$reactorId] ?? 'neutral';
            $weighted += self::tierWeight($tier) * (float) $count;
        }

        return self::usefulnessFromWeightedEngagement($weighted);
    }

    /** Clean-record gate: any non-trivial fraud signal counts as a recent violation. */
    private function hasRecentViolation(int $userId): bool
    {
        $info = $this->userInfoRepo->getByUserId($userId);
        if ($info === null) {
            return false;
        }
        return (int) $info->fraud_score >= BCC_TRUST_FRAUD_LOW;
    }

    // ── Pure math (unit-testable; no DB) ─────────────────────────────

    /** Bare point value of a window's qualifying actions, before engagement weighting. */
    public static function rawWindowPoints(int $posts, int $reviews, int $comments, int $scamReports): float
    {
        return $posts * (float) BCC_CONTRIB_WEIGHT_POST
            + $reviews * (float) BCC_CONTRIB_WEIGHT_REVIEW
            + $comments * (float) BCC_CONTRIB_WEIGHT_COMMENT
            + $scamReports * (float) BCC_CONTRIB_WEIGHT_SCAM_REPORT;
    }

    /** Engager tier → engagement weight (reuses the shared vote-weight constants). */
    public static function tierWeight(string $tier): float
    {
        return match ($tier) {
            'elite'   => (float) BCC_TRUST_WEIGHT_ELITE,
            'trusted' => (float) BCC_TRUST_WEIGHT_TRUSTED,
            'neutral' => (float) BCC_TRUST_WEIGHT_NEUTRAL,
            'caution' => (float) BCC_TRUST_WEIGHT_CAUTION,
            'risky'   => (float) BCC_TRUST_WEIGHT_RISKY,
            default   => (float) BCC_TRUST_WEIGHT_NEUTRAL,
        };
    }

    /** R4: weighted-engagement sum → a [1.0, MAX_MULT] usefulness multiplier. */
    public static function usefulnessFromWeightedEngagement(float $weightedSum): float
    {
        $lift = min(
            (float) BCC_CONTRIB_ENGAGEMENT_MAX_MULT - 1.0,
            max(0.0, $weightedSum) * (float) BCC_CONTRIB_ENGAGEMENT_SCALE
        );
        return 1.0 + $lift;
    }

    /**
     * R3: cap each non-overlapping bucket, weight by engagement usefulness
     * (R4), dampen on a recent violation, and cap the total at BCC_CONTRIB_MAX.
     */
    public static function composeContribution(
        float $bucketRecent,
        float $bucketMid,
        float $bucketLong,
        float $usefulness,
        bool $hasViolation
    ): float {
        $cap    = (float) BCC_CONTRIB_PER_WINDOW_CAP;
        $capped = min($bucketRecent, $cap) + min($bucketMid, $cap) + min($bucketLong, $cap);

        $contribution = $capped * $usefulness;
        if ($hasViolation) {
            $contribution *= (float) BCC_CONTRIB_VIOLATION_DAMPEN;
        }

        return max(0.0, min((float) BCC_CONTRIB_MAX, $contribution));
    }

    /** Consistency from account age + sustained multi-window presence; zeroed by a recent violation. */
    public static function composeConsistency(int $ageDays, int $windowsActive, bool $hasViolation): float
    {
        if ($hasViolation) {
            return 0.0; // clean-record gate
        }

        $age     = min(1.0, max(0, $ageDays) / (float) BCC_CONSIST_AGE_FULL_DAYS);
        $windows = min(1.0, max(0, $windowsActive) / 3.0);
        $signal  = $age * 0.6 + $windows * 0.4;

        return max(0.0, min((float) BCC_CONSIST_MAX, $signal * (float) BCC_CONSIST_MAX));
    }

    /** Account age in whole days since registration. */
    public static function accountAgeDays(int $userId): int
    {
        $user = get_userdata($userId);
        if ($user === false || empty($user->user_registered)) {
            return 0;
        }
        $registeredTs = strtotime($user->user_registered . ' UTC');
        if ($registeredTs === false) {
            return 0;
        }
        $diff = time() - $registeredTs;
        return $diff > 0 ? (int) floor($diff / DAY_IN_SECONDS) : 0;
    }
}
