<?php
/**
 * Watching Summary Service — §N9 identity-snapshot composer.
 *
 * Builds the GET /me/watching/summary response. Pre-computes everything
 * the WatchingHeader UI surfaces so the frontend renders without
 * deriving any business value (per §A2 / §L5):
 *
 *   {
 *     total: int,                           // collection size
 *     tier_distribution: {                  // reputation_tier rollup
 *       legendary: { count: int, percent: int },
 *       rare:      { count: int, percent: int },
 *       uncommon:  { count: int, percent: int },
 *       common:    { count: int, percent: int },
 *       unknown:   { count: int, percent: int }   // legacy follows, no sidecar
 *     },
 *     monthly_activity: {                   // rolling 30 days
 *       reviews: int,
 *       solids_received: int,
 *       disputes_signed: int
 *     }
 *   }
 *
 * Composition pattern: this service ONLY composes the §N9 block.
 * WatchingService stays focused on the read/watch/unwatch pipeline;
 * this service borrows from the same activity repos as LivingService
 * for the monthly rollup. Keeping both narrow makes each independently
 * testable and avoids growing WatchingService into a god-service.
 *
 * Stale-but-stable contract: when ReactionTypeRegistry can't resolve
 * the Solid reaction (fresh install, seeder hasn't run yet), the
 * solids_received count returns 0 — never a fatal error. Same posture
 * as LivingService.today.
 *
 * @package BCC\Trust\Core\Services
 * @since V1 (2026-04, §N9 watchlist summary; renamed from BinderSummaryService 2026-05-13)
 */

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Repositories\WatchingRepository;
use BCC\Trust\Core\Repositories\PeepSoReactionRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Disputes\Repositories\DisputeRepository;
use BCC\Trust\Core\Support\ReactionTypeRegistry;

if (!defined('ABSPATH')) {
    exit;
}

final class WatchingSummaryService
{
    /**
     * The five slots we surface in `tier_distribution`. Order is
     * stable + frontend-meaningful (rare → common cascade plus the
     * unknown bucket for legacy follows). Includes-zero buckets so
     * the frontend can render a complete bar/donut without resampling.
     *
     * @var list<string>
     */
    private const TIER_SLOTS = ['legendary', 'rare', 'uncommon', 'common', 'unknown'];

    private const MONTHLY_DAYS = 30;

    public function __construct(
        private readonly WatchingRepository $watchingRepo,
        private readonly VoteRepository $voteRepo,
        private readonly PeepSoReactionRepository $reactionRepo
    ) {
    }

    /**
     * Compose the §N9 summary for a viewer.
     *
     * @return array{
     *   total: int,
     *   tier_distribution: array<string, array{count: int, percent: int}>,
     *   monthly_activity: array{reviews: int, solids_received: int, disputes_signed: int}
     * }
     */
    public function compose(int $userId): array
    {
        $total            = $this->watchingRepo->countItemsForUser($userId);
        $tierCounts       = $this->watchingRepo->countByTierForUser($userId);
        $tierDistribution = self::projectDistribution($tierCounts, $total);

        return [
            'total'             => $total,
            'tier_distribution' => $tierDistribution,
            'monthly_activity'  => $this->monthlyActivity($userId),
        ];
    }

    /**
     * Project the raw `tier_at_watch` counts onto the fixed slot list,
     * computing per-slot percentages. Empty slots render as zero
     * (count=0, percent=0) so the frontend's bar/donut has every
     * segment present.
     *
     * Percentages are integer-rounded; rounding error means slots
     * may not sum to 100 exactly. Acceptable for V1 — the frontend
     * renders the donut/bar visually, not as a forced-sum total.
     *
     * @param array<string, int> $counts Raw tier-bucket counts from the repo.
     * @return array<string, array{count: int, percent: int}>
     */
    private static function projectDistribution(array $counts, int $total): array
    {
        $out = [];
        foreach (self::TIER_SLOTS as $slot) {
            $count = $counts[$slot] ?? 0;
            $pct   = $total > 0
                ? (int) round(($count / $total) * 100.0)
                : 0;
            $out[$slot] = [
                'count'   => $count,
                'percent' => $pct,
            ];
        }
        return $out;
    }

    /**
     * Rolling-30-day activity counts: reviews written, solids received,
     * disputes signed. Same data sources as LivingService.today() with
     * a wider window.
     *
     * Solid resolution: when ReactionTypeRegistry::solidId() returns
     * null (reactions not seeded yet), surface 0 rather than fail —
     * the watchlist summary is supplementary chrome, not load-bearing.
     *
     * @return array{reviews: int, solids_received: int, disputes_signed: int}
     */
    private function monthlyActivity(int $userId): array
    {
        $sinceMysql = gmdate('Y-m-d 00:00:00', time() - (self::MONTHLY_DAYS * 86400));

        $solidId         = ReactionTypeRegistry::solidId();
        $solidsReceived  = $solidId === null
            ? 0
            : $this->reactionRepo->countReceivedByUserSince($userId, $solidId, $sinceMysql);

        return [
            'reviews'         => $this->voteRepo->countByVoterSince($userId, $sinceMysql),
            'solids_received' => $solidsReceived,
            'disputes_signed' => DisputeRepository::countByReporterSince($userId, $sinceMysql),
        ];
    }
}
