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
     *
     * @param array{
     *   level: int,
     *   next_level_thresholds: list<array{metric: string, label: string, current: int, required: int}>
     * } $featureAccess  The viewer's feature_access block (§2.6), passed
     *                   in so the rank-progress bar reuses the canonical
     *                   level thresholds rather than re-deriving them.
     */
    public function compose(int $userId, string $rankKey, array $featureAccess): array
    {
        $today = $this->today($userId);
        $streak = $this->streakDays($userId);

        return [
            'streak_days'   => $streak,
            'today'         => $today,
            'recent_impact' => self::recentImpactHeadline($today),
            'rank_progress' => self::rankProgress($rankKey, $featureAccess),
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
     * Build the §N11 progression block — honestly, from the **real**
     * capability gates.
     *
     * Rank now mirrors the feature-access level (Apprentice=New,
     * Journeyman=Active, Master=Veteran). The bar therefore reflects the
     * actual level thresholds the user is working toward (pulls /
     * reviews / days active — supplied verbatim in `next_level_thresholds`),
     * NOT a fabricated trust-score percentage. `percent` is the limiting
     * (closest-to-blocking) gate's ratio; `remaining_label` names what's
     * left. At Master (top of ladder, no thresholds) it reads complete.
     *
     * Pure: derives entirely from the passed-in feature_access block, so
     * it's trivially testable and adds no queries.
     *
     * @param array{
     *   level: int,
     *   next_level_thresholds: list<array{metric: string, label: string, current: int, required: int}>
     * } $featureAccess
     * @return array{current_rank: string, next_rank: string|null, percent: int, remaining_label: string}
     */
    private static function rankProgress(string $rankKey, array $featureAccess): array
    {
        $nextRankKey = RankCatalog::getNextRank($rankKey);
        $thresholds  = $featureAccess['next_level_thresholds'];

        // Top of the ladder (Master) or no remaining gates.
        if ($nextRankKey === null || $thresholds === []) {
            return [
                'current_rank'    => $rankKey,
                'next_rank'       => $nextRankKey,
                'percent'         => 100,
                'remaining_label' => 'Top of the trade.',
            ];
        }

        // percent = the limiting gate (smallest current/required ratio),
        // clamped to [0, 99] — 100 implies already promoted, which the
        // level resolver would have done.
        $ratios   = [];
        $unmet    = [];
        foreach ($thresholds as $t) {
            $required = $t['required'];
            $current  = $t['current'];
            $ratios[] = $required > 0 ? min(1.0, $current / $required) : 1.0;
            $remaining = $required - $current;
            if ($remaining > 0) {
                $unmet[] = sprintf('%d more %s', $remaining, strtolower($t['label']));
            }
        }
        $percent = (int) max(0, min(99, (int) round(min($ratios) * 100)));

        $nextLabel = (string) RankCatalog::getLabel($nextRankKey);
        $label = $unmet === []
            ? 'Almost there — promotion lands shortly.'
            : sprintf('%s to reach %s.', self::joinPhrases($unmet), $nextLabel);

        return [
            'current_rank'    => $rankKey,
            'next_rank'       => $nextRankKey,
            'percent'         => $percent,
            'remaining_label' => $label,
        ];
    }

    /**
     * Join short clauses into a natural-language list:
     * ["a"] → "a"; ["a","b"] → "a and b"; ["a","b","c"] → "a, b and c".
     *
     * @param list<string> $parts
     */
    private static function joinPhrases(array $parts): string
    {
        $count = count($parts);
        if ($count === 1) {
            return $parts[0];
        }
        $last = array_pop($parts);
        return implode(', ', $parts) . ' and ' . $last;
    }
}
