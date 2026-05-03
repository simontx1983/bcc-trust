<?php
/**
 * Living Service — §O3 living header composer.
 *
 * Builds the per-user living block surfaced on the user's own
 * profile + any feature that wants the "alive" indicators:
 *
 *   {
 *     streak_days   : int,            // consecutive UTC days with activity ending today
 *     today         : { reviews, solids_received, disputes_signed },
 *     recent_impact : string|null,    // pre-rendered headline ("Wrote 2 reviews today")
 *     rank_progress : { current_rank, next_rank, percent, remaining_label },
 *     comparison    : ...|null        // §O3.1 — V1.0 stub (network-percentile aggregator deferred)
 *   }
 *
 * Composition pattern: this service ONLY computes the living block.
 * UserViewService delegates to it on the isSelf path. Keeping the
 * service narrow makes the streak / today / progress logic easy to
 * test in isolation and avoids growing UserViewService further.
 *
 * V1.0 stubs documented inline:
 *   - comparison → null (§O3.1 network percentile + local-peer
 *                        aggregators are V1.5 work)
 *
 * @package BCC\Trust\Core\Services
 * @since V1 (2026-04, §O3)
 */

namespace BCC\Trust\Core\Services;

use BCC\Core\Repositories\PeepSoActivityRepository;
use BCC\Trust\Core\Repositories\FlagsRepository;
use BCC\Trust\Core\Repositories\PeepSoReactionRepository;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Support\RankCatalog;
use BCC\Trust\Core\Support\ReactionTypeRegistry;

if (!defined('ABSPATH')) {
    exit;
}

final class LivingService
{
    /**
     * Streak walker window. The user's last 365 days are bulk-loaded
     * once; the walker stops at the first gap. Bounding the window
     * keeps the underlying GROUP BY DATE bounded (matches the
     * existing aggregateByDay default).
     */
    private const STREAK_WINDOW_DAYS = 365;

    public function __construct(
        private readonly VoteRepository $voteRepo,
        private readonly FlagsRepository $flagsRepo,
        private readonly ReputationRepository $reputationRepo,
        private readonly PeepSoReactionRepository $reactionRepo
    ) {
    }

    /**
     * Comparison-line window per §O3.1 — rolling 7 days. Smaller than
     * the streak walker's window because comparison is "this week",
     * not all-time, and a tighter window highlights recent activity.
     */
    private const COMPARISON_WINDOW_DAYS = 7;

    /**
     * Compose the §O3 living block for a user.
     *
     * @return array{
     *   streak_days: int,
     *   today: array{reviews: int, solids_received: int, disputes_signed: int},
     *   recent_impact: string|null,
     *   rank_progress: array{
     *     current_rank: string,
     *     next_rank: string|null,
     *     percent: int,
     *     remaining_label: string
     *   },
     *   comparison: array{headline: string, kind: string, as_of: string}|null
     * }
     */
    public function compose(int $userId, string $rankKey): array
    {
        $today = $this->today($userId);
        $streak = $this->streakDays($userId);

        return [
            'streak_days'   => $streak,
            'today'         => $today,
            'recent_impact' => self::recentImpactHeadline($today),
            'rank_progress' => $this->rankProgress($userId, $rankKey),
            'comparison'    => self::networkComparison($userId),
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // Network-percentile comparison (§O3.1)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Pre-rendered "Top X% this week" line for the §O3.1 comparison
     * slot in LivingHeader.
     *
     * Soft-phrasing rule: only surfaces for users in the top half.
     * Bottom-half percentiles intentionally return null — the spec
     * says "no leaderboards, no 'you're behind' framing." Better to
     * show no comparison than to surface a discouraging signal.
     *
     * Buckets in 5% steps so users below the very top still feel a
     * meaningful difference between "Top 5%" and "Top 25%". Anything
     * worse than top 50% renders no headline.
     *
     * @return array{headline: string, kind: string, as_of: string}|null
     */
    private static function networkComparison(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $sinceMysql = gmdate('Y-m-d 00:00:00', time() - (self::COMPARISON_WINDOW_DAYS * 86400));

        $rank = PeepSoActivityRepository::getNetworkPercentile($userId, $sinceMysql);
        if ($rank === null) {
            return null;
        }

        $pct = $rank['percentile_from_top'];
        if ($pct > 50) {
            // Bottom half — soft-phrasing rule. Hide rather than scold.
            return null;
        }

        // Round to nearest 5% bucket; floor at 5 so the very best
        // doesn't display "Top 0% this week".
        $bucket = max(5, (int) round($pct / 5) * 5);

        return [
            'headline' => sprintf('Top %d%% this week', $bucket),
            'kind'     => 'network_percentile',
            'as_of'    => gmdate('Y-m-d'),
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // Streak walker — consecutive UTC days ending today with ≥1 activity
    // ──────────────────────────────────────────────────────────────────

    /**
     * Walks day-by-day backward from today (UTC) counting consecutive
     * days that have at least one activity. Stops at the first gap.
     *
     * Returns 0 when today itself has no activity — a streak ALWAYS
     * includes today; if you missed today, you're at zero.
     */
    private function streakDays(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $today      = gmdate('Y-m-d');
        $sinceEpoch = time() - (self::STREAK_WINDOW_DAYS * 86400);
        $sinceMysql = gmdate('Y-m-d 00:00:00', $sinceEpoch);

        $days = PeepSoActivityRepository::aggregateByDay($userId, $sinceMysql, self::STREAK_WINDOW_DAYS);
        if (!isset($days[$today])) {
            return 0;
        }

        $streak = 0;
        $cursor = $today;
        $cursorEpoch = strtotime($cursor . ' UTC');
        if ($cursorEpoch === false) {
            return 0;
        }

        while (isset($days[$cursor])) {
            $streak++;
            // Defensive cap — shouldn't fire given STREAK_WINDOW_DAYS,
            // but bounds the loop iterations under any pathological data.
            if ($streak >= self::STREAK_WINDOW_DAYS) {
                break;
            }
            $cursorEpoch -= 86400;
            $cursor = gmdate('Y-m-d', $cursorEpoch);
        }
        return $streak;
    }

    // ──────────────────────────────────────────────────────────────────
    // Today aggregator — reviews + disputes (solids deferred)
    // ──────────────────────────────────────────────────────────────────

    /**
     * @return array{reviews: int, solids_received: int, disputes_signed: int}
     */
    private function today(int $userId): array
    {
        $todayStartMysql = gmdate('Y-m-d 00:00:00');

        return [
            'reviews'         => $this->voteRepo->countByVoterSince($userId, $todayStartMysql),
            'solids_received' => $this->countSolidsReceivedSince($userId, $todayStartMysql),
            'disputes_signed' => $this->flagsRepo->countByFlaggerSince($userId, $todayStartMysql),
        ];
    }

    /**
     * Solid reactions received on the user's content since a MySQL
     * DATETIME boundary. Returns 0 when the §D5 reactions haven't
     * been seeded yet (ReactionSeeder runs at install, but the IDs
     * may legitimately be null on a fresh install before bootstrap).
     */
    private function countSolidsReceivedSince(int $userId, string $sinceMysql): int
    {
        $solidId = ReactionTypeRegistry::solidId();
        if ($solidId === null) {
            return 0;
        }
        return $this->reactionRepo->countReceivedByUserSince($userId, $solidId, $sinceMysql);
    }

    // ──────────────────────────────────────────────────────────────────
    // Recent-impact headline — pre-rendered, derived from today counts
    // ──────────────────────────────────────────────────────────────────

    /**
     * Pre-rendered single-line summary of the user's day. Returned as
     * a server-formatted string so the frontend renders without
     * deriving tense / pluralisation / priority order (per §A2).
     *
     * Priority: writing > signing > receiving. Writing a review is the
     * highest-effort action of the day, so it leads when present;
     * signing a dispute is the next; receiving solids is the most
     * passive signal and surfaces only when nothing more active happened.
     *
     * Returns null when nothing of note happened — the frontend falls
     * back to a "Quiet shift" placeholder.
     *
     * @param array{reviews: int, solids_received: int, disputes_signed: int} $today
     */
    private static function recentImpactHeadline(array $today): ?string
    {
        if ($today['reviews'] > 0) {
            return $today['reviews'] === 1
                ? 'Wrote a review today.'
                : sprintf('Wrote %d reviews today.', $today['reviews']);
        }
        if ($today['disputes_signed'] > 0) {
            return $today['disputes_signed'] === 1
                ? 'Signed a dispute today.'
                : sprintf('Signed %d disputes today.', $today['disputes_signed']);
        }
        if ($today['solids_received'] > 0) {
            return $today['solids_received'] === 1
                ? '1 solid received today.'
                : sprintf('%d solids received today.', $today['solids_received']);
        }
        return null;
    }

    // ──────────────────────────────────────────────────────────────────
    // Rank progress — current/next labels + percent + remaining hint
    // ──────────────────────────────────────────────────────────────────

    /**
     * Build the §N11 progression block.
     *
     * V1 rank derivation rule (per RankService::autoDerivedRank):
     *   - Apprentice = trust_score < BCC_TRUST_NEUTRAL_SCORE (50)
     *   - Journeyman = trust_score >= BCC_TRUST_NEUTRAL_SCORE
     *   - Foreman+   = admin-conferred (no further auto-promotion)
     *
     * Cases:
     *   - Apprentice → linear progress toward 50; capped at 99 because
     *                  hitting 100 would mean already promoted.
     *   - Journeyman → next is Foreman (admin-conferred); progress=100;
     *                  remaining hint clarifies it's admin-awarded.
     *   - Foreman+   → no next; progress=100; remaining hint says so.
     *
     * @return array{current_rank: string, next_rank: string|null, percent: int, remaining_label: string}
     */
    private function rankProgress(int $userId, string $rankKey): array
    {
        $nextRankKey = RankCatalog::getNextRank($rankKey);

        if ($rankKey === RankCatalog::RANK_APPRENTICE) {
            $score     = $this->reputationRepo->getScore($userId);
            $threshold = (float) BCC_TRUST_NEUTRAL_SCORE;

            $pct = ($score / $threshold) * 100.0;
            // Clamp to [0, 99] for Apprentice — 100 implies promoted,
            // which the rank-derivation service should already have done.
            $pctInt   = (int) max(0, min(99, (int) round($pct)));
            $remaining = max(0, (int) ceil($threshold - $score));
            $label = $remaining === 0
                ? 'Almost there — promotion lands shortly.'
                : sprintf('%d trust point%s to Journeyman.', $remaining, $remaining === 1 ? '' : 's');

            return [
                'current_rank'    => $rankKey,
                'next_rank'       => $nextRankKey,
                'percent'         => $pctInt,
                'remaining_label' => $label,
            ];
        }

        if ($nextRankKey !== null && !RankCatalog::isAutoAssigned($nextRankKey)) {
            // Journeyman → Foreman: next exists in catalog but is
            // admin-conferred. Progress is "complete" for the
            // auto-ladder; the remaining hint explains the gate.
            return [
                'current_rank'    => $rankKey,
                'next_rank'       => $nextRankKey,
                'percent'         => 100,
                'remaining_label' => sprintf(
                    '%s is conferred, not earned.',
                    (string) RankCatalog::getLabel($nextRankKey)
                ),
            ];
        }

        // Top of the ladder (Foreman or any unknown-but-validated rank
        // with no next slot).
        return [
            'current_rank'    => $rankKey,
            'next_rank'       => null,
            'percent'         => 100,
            'remaining_label' => 'Top of the ladder.',
        ];
    }
}
