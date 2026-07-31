<?php
/**
 * RankStateService — the single read facade every rank-displaying
 * surface consumes after the Phase 5 cutover.
 *
 * Replaces RankService/FeatureAccessService-level reads everywhere:
 * `member_state` distinguishes the New Member account state (missing
 * rank_state row) from ranked members (C8 — the frontend renders a
 * distinct NewMemberChip, and an API failure is never mistaken for
 * "new member" because this field is always explicitly present in
 * data). Labels come from RankCatalog — the one slug→label home.
 *
 * List surfaces MUST use memberStatesFor (one bounded query via
 * RankStateRepository::getRanksForUsers) — the MemberSummaryPrefetcher
 * seam; per-row reads regress lists to N+1.
 *
 * @package BCC\Trust\Rank\Services
 * @since Rank redesign Phase 5 (2026-07-31)
 */

declare(strict_types=1);

namespace BCC\Trust\Rank\Services;

use BCC\Trust\Core\Support\RankCatalog;
use BCC\Trust\Rank\Repositories\RankStateRepository;

if (!defined('ABSPATH')) {
    exit;
}

class RankStateService
{
    public function __construct(
        private readonly RankStateRepository $rankState,
        private readonly RankPromotionEngine $engine,
        private readonly ApprenticeReadinessService $readiness,
        private readonly \BCC\Trust\Rank\Support\RankScoringConfig $config
    ) {
    }

    /**
     * @return array{member_state: string, rank: string|null, rank_label: string|null}
     */
    public function memberState(int $userId): array
    {
        $row = $userId > 0 ? $this->rankState->getForUser($userId) : null;

        return $this->shape($row !== null ? (string) $row->rank_slug : null);
    }

    /**
     * Batched form for list surfaces (prefetcher seam).
     *
     * @param list<int> $userIds
     * @return array<int, array{member_state: string, rank: string|null, rank_label: string|null}>
     */
    public function memberStatesFor(array $userIds): array
    {
        $ranks = $this->rankState->getRanksForUsers($userIds);

        $out = [];
        foreach ($userIds as $id) {
            $iid = (int) $id;
            if ($iid > 0) {
                $out[$iid] = $this->shape($ranks[$iid] ?? null);
            }
        }
        return $out;
    }

    /**
     * The §2.5 / GET /me/progression block — canonical, backend-built
     * (the frontend derives NO thresholds, labels, or permissions from
     * this; it renders). §22.2's do-not-expose list is honored by
     * construction: no per-action point formulas, no fraud/cluster
     * signals, no other member's data.
     *
     * @return array<string, mixed>
     */
    public function progressionFor(int $userId): array
    {
        $assessment = $this->engine->assess($userId);

        if ($assessment === null) {
            // New Member — readiness view (§5.1) instead of rank math.
            return [
                'member_state' => 'new_member',
                'rank'         => null,
                'rank_label'   => null,
                'readiness'    => $this->readiness->readinessFor($userId),
            ];
        }

        $row     = $assessment['row'];
        $current = (string) $row->rank_slug;
        $next    = RankCatalog::getNextRank($current);

        $categories = [];
        foreach ($this->config->categoryMax as $category => $max) {
            $categories[$category] = [
                'score' => round((float) ($assessment['categories'][$category] ?? 0.0), 2),
                'max'   => $max,
            ];
        }

        // §16.3 Apprentice vesting from the maturity epoch (survives
        // promotion — owner correction 3).
        $awardedTs = strtotime((string) $row->apprentice_awarded_at . ' UTC');
        $elapsed   = $awardedTs !== false
            ? max(0, min($this->config->maturitySpanDays, (int) floor((time() - $awardedTs) / 86400)))
            : $this->config->maturitySpanDays;
        $maturity = $this->config->maturityFloor
            + ($elapsed / $this->config->maturitySpanDays) * (1.0 - $this->config->maturityFloor);

        return [
            'member_state'       => 'ranked',
            'rank'               => $current,
            'rank_label'         => RankCatalog::getLabel($current),
            'next_rank'          => $next,
            'next_rank_label'    => $next !== null ? RankCatalog::getLabel($next) : null,
            'rank_score'         => round($assessment['total'], 2),
            'next_threshold'     => $next !== null ? ($this->config->thresholds[$next] ?? null) : null,
            'categories'         => $categories,
            'diversity'          => [
                'contribution_types' => $assessment['contribution_types'],
                'journeyman_required' => $this->config->diversityMinimums['journeyman_categories'],
                'veteran_required'    => $this->config->diversityMinimums['veteran_categories'],
            ],
            'recognizers'        => [
                'independent'         => $assessment['recognizers'],
                'journeyman_required' => $this->config->recognizerMinimums['journeyman'],
                'veteran_required'    => $this->config->recognizerMinimums['veteran'],
            ],
            'outcomes'           => [
                'count'                  => $assessment['outcomes'],
                'types'                  => $assessment['outcome_types'],
                'veteran_required'       => $this->config->outcomeMinimums['veteran_outcomes'],
                'veteran_types_required' => $this->config->outcomeMinimums['veteran_outcome_types'],
            ],
            'trust_windows'      => $assessment['windows'],
            'vesting'            => [
                'maturity'     => round($maturity, 4),
                'days_elapsed' => $elapsed,
                'span_days'    => $this->config->maturitySpanDays,
            ],
            'decay'              => [
                'active' => $assessment['decay'] > 0.0,
                'points' => $assessment['decay'],
            ],
            'recovery'           => (string) $row->recovery_status === 'grace'
                ? ['deadline' => (string) $row->recovery_deadline]
                : null,
            'quests'             => $this->questsBlock($userId),
        ];
    }

    /**
     * D-1: quests stay as onboarding guidance / achievements — the
     * POWER fields the legacy block carried (per-item weight_bonus,
     * the vote-weight multiplier) are stripped; quests grant no Rank,
     * Trust, or voting power.
     *
     * @return array{completed_count: int, total_count: int, pct: int, items: list<array{slug: string, label: string, hint: string, done: bool, category: string}>}
     */
    private function questsBlock(int $userId): array
    {
        $quests = \BCC\Trust\Core\Plugin::instance()->questProgressService();

        // Backfill quests completed before their emitter was wired
        // (throttled internally; own-view only — progression is self-only).
        $quests->reconcile($userId);
        $progress = $quests->getProgress($userId);

        $items = [];
        foreach ($progress['quests'] as $slug => $quest) {
            $items[] = [
                'slug'     => (string) $slug,
                'label'    => (string) $quest['label'],
                'hint'     => (string) $quest['hint'],
                'done'     => (bool) $quest['done'],
                'category' => (string) $quest['category'],
            ];
        }

        return [
            'completed_count' => (int) $progress['completed_count'],
            'total_count'     => (int) $progress['total_count'],
            'pct'             => (int) $progress['pct'],
            'items'           => $items,
        ];
    }

    /**
     * The §2.6 CapabilitiesBlock (replaces the retired feature_access
     * block at the Phase 5 cutover) — general write-action capability
     * per key for the profile owner. Target-specific conditions
     * (self-target, slot limits, fraud) still apply at action time;
     * this block answers "is this member CLASS of action available",
     * which is what the FE renders. Final policy: Apprentice+ AND
     * Neutral+ for all three; Journeyman never required.
     *
     * @return array<string, array{allowed: bool, reason: string|null}>
     */
    public function capabilitiesBlock(int $userId): array
    {
        $ranked  = $userId > 0 && $this->rankState->getForUser($userId) !== null;
        $tierOk  = false;
        if ($ranked) {
            $tier   = \BCC\Trust\Core\Plugin::instance()->reputationRepository()->getTier($userId);
            $tierOk = $this->config->tierOrdFor($tier) >= $this->config->tierOrdFor('neutral');
        }

        $verdict = [
            'allowed' => $ranked && $tierOk,
            'reason'  => $ranked
                ? ($tierOk ? null : 'below_neutral')
                : 'new_member',
        ];

        return [
            'write_review' => $verdict,
            'vouch'        => $verdict,
            'stand_behind' => $verdict,
        ];
    }

    /**
     * @return array{member_state: string, rank: string|null, rank_label: string|null}
     */
    private function shape(?string $rankSlug): array
    {
        if ($rankSlug === null) {
            return ['member_state' => 'new_member', 'rank' => null, 'rank_label' => null];
        }

        return [
            'member_state' => 'ranked',
            'rank'         => $rankSlug,
            'rank_label'   => RankCatalog::getLabel($rankSlug),
        ];
    }
}
