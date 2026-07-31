<?php
/**
 * RankPromotionEngine — automatic, deterministic, idempotent rank
 * transitions from canonical evidence (Rank Phase 5; §6/§7/§23).
 *
 * assess() computes one member's full requirement picture (score,
 * categories, diversity, recognizers, outcomes, trust windows) from
 * the ledger + login/tier planes; evaluate() persists the resulting
 * state and fires transitions:
 *
 *   - PROMOTE when a higher rung's every requirement holds (never
 *     during recovery grace — §14.1.4);
 *   - hold current rung and CLEAR grace when its requirements are
 *     restored (§14.1.6);
 *   - START the 90-day grace when the current rung's requirements are
 *     lost (§14.1.3 — never an instant demotion);
 *   - DEMOTE to the highest currently-satisfied rung only when the
 *     grace deadline passes (§23.3 — never assume one rung down).
 *
 * Apprentice is never awarded here (that's the readiness/confirmation
 * path); it is the floor a ranked member can be demoted to — Phase 5
 * ships no apprentice-revocation rule.
 *
 * Transitions are audited and emit the existing `bcc_rank_awarded`
 * action (bell + celebration chain) or `bcc_rank_demoted`.
 *
 * @package BCC\Trust\Rank\Services
 * @since Rank redesign Phase 5 (2026-07-31)
 */

declare(strict_types=1);

namespace BCC\Trust\Rank\Services;

use BCC\Trust\Rank\Repositories\LoginDaysRepository;
use BCC\Trust\Rank\Repositories\RankEventsRepository;
use BCC\Trust\Rank\Repositories\RankStateRepository;
use BCC\Trust\Rank\Repositories\TierDaysRepository;
use BCC\Trust\Rank\Support\RankScoringConfig;

if (!defined('ABSPATH')) {
    exit;
}

class RankPromotionEngine
{
    /** Users per daily-evaluate batch. */
    private const BATCH_SIZE = 100;

    /** Wall-clock budget for one daily sweep, seconds. */
    private const TIME_BUDGET_SECONDS = 30;

    public function __construct(
        private readonly RankStateRepository $rankState,
        private readonly RankEventsRepository $events,
        private readonly LoginDaysRepository $loginDays,
        private readonly TierDaysRepository $tierDays,
        private readonly IndependenceResolver $independence,
        private readonly RankScoreCalculator $calculator,
        private readonly RankScoringConfig $config
    ) {
    }

    /**
     * Full requirement assessment for one RANKED member. Null for New
     * Members (no state row). Pure reads; the progression surface and
     * evaluate() share this single implementation.
     *
     * @return array{
     *     row: object{rank_slug: string, apprentice_awarded_at: string, recovery_status: string, recovery_deadline: string|null},
     *     categories: array<string, float>,
     *     decay: float,
     *     total: float,
     *     contribution_types: int,
     *     recognizers: int,
     *     outcomes: int,
     *     outcome_types: int,
     *     windows: array<string, array{qualifying: int, required: int, window: int, min_tier: string}>,
     *     satisfied: array<string, bool>,
     *     target: string
     * }|null
     */
    public function assess(int $userId): ?array
    {
        $row = $this->rankState->getForUser($userId);
        if ($row === null) {
            return null;
        }

        $activeEvents = $this->events->listActiveForSubject($userId);
        $clusterMap   = $this->independence->activeMap();

        $result = $this->calculator->calculate(
            $activeEvents,
            $this->loginDays->distinctMonthCount($userId),
            $this->loginDays->lastLoginDay($userId),
            $clusterMap
        );

        // Diversity + independent-headcount reads over the same rows.
        $contributionTypes = [];
        $recognizers       = [];
        $outcomeCount      = 0;
        $outcomeTypes      = [];
        foreach ($activeEvents as $event) {
            if ((float) $event->capped_value <= 0.0) {
                continue; // Zero-credited (clustered) evidence counts nowhere.
            }
            switch ((string) $event->category) {
                case 'contribution':
                    $contributionTypes[(string) $event->source_type] = true;
                    break;
                case 'recognition':
                    $rel = (int) $event->relationship_user_id;
                    if ($rel > 0) {
                        $recognizers[$clusterMap[$rel] ?? $rel] = true;
                    }
                    break;
                case 'outcomes':
                    $outcomeCount++;
                    $outcomeTypes[(string) $event->source_type] = true;
                    break;
            }
        }

        $windows = [];
        foreach ($this->config->trustWindows as $rank => $window) {
            $since = gmdate('Y-m-d', time() - ($window['window_days'] * 86400));
            $windows[$rank] = [
                'qualifying' => $this->tierDays->countQualifyingDays(
                    $userId,
                    $since,
                    $this->config->tierOrdFor($window['min_tier'])
                ),
                'required'   => $window['qualifying_days'],
                'window'     => $window['window_days'],
                'min_tier'   => $window['min_tier'],
            ];
        }

        $suspensionClear = \BCC\Core\Permissions\Permissions::is_not_suspended($userId, false);

        $satisfied = [
            'apprentice' => true, // Held rung; Phase 5 has no apprentice-loss rule.
            'journeyman' => $suspensionClear
                && $result['total'] >= $this->config->thresholds['journeyman']
                && $result['categories']['contribution'] >= $this->config->categoryMinimums['journeyman']['contribution']
                && $result['categories']['helping'] >= $this->config->categoryMinimums['journeyman']['helping']
                && $result['categories']['recognition'] >= $this->config->categoryMinimums['journeyman']['recognition']
                && count($contributionTypes) >= $this->config->diversityMinimums['journeyman_categories']
                && count($recognizers) >= $this->config->recognizerMinimums['journeyman']
                && $windows['journeyman']['qualifying'] >= $windows['journeyman']['required'],
            'veteran' => $suspensionClear
                && $result['total'] >= $this->config->thresholds['veteran']
                && $result['categories']['contribution'] >= $this->config->categoryMinimums['veteran']['contribution']
                && $result['categories']['helping'] >= $this->config->categoryMinimums['veteran']['helping']
                && $result['categories']['recognition'] >= $this->config->categoryMinimums['veteran']['recognition']
                && $result['categories']['outcomes'] >= $this->config->categoryMinimums['veteran']['outcomes']
                && count($contributionTypes) >= $this->config->diversityMinimums['veteran_categories']
                && count($recognizers) >= $this->config->recognizerMinimums['veteran']
                && $outcomeCount >= $this->config->outcomeMinimums['veteran_outcomes']
                && count($outcomeTypes) >= $this->config->outcomeMinimums['veteran_outcome_types']
                && $windows['veteran']['qualifying'] >= $windows['veteran']['required'],
        ];

        // Veteran requires the journeyman rung's substance by
        // construction (its thresholds strictly dominate), so "highest
        // satisfied" is a simple descent.
        $target = 'apprentice';
        if ($satisfied['journeyman']) {
            $target = 'journeyman';
        }
        if ($satisfied['journeyman'] && $satisfied['veteran']) {
            $target = 'veteran';
        }

        return [
            'row'                => $row,
            'categories'         => $result['categories'],
            'decay'              => $result['decay'],
            'total'              => $result['total'],
            'contribution_types' => count($contributionTypes),
            'recognizers'        => count($recognizers),
            'outcomes'           => $outcomeCount,
            'outcome_types'      => count($outcomeTypes),
            'windows'            => $windows,
            'satisfied'          => $satisfied,
            'target'             => $target,
        ];
    }

    /**
     * Assess + persist + transition for one member. Deterministic and
     * idempotent — re-running with unchanged evidence is a no-op
     * state write.
     */
    public function evaluate(int $userId): void
    {
        $assessment = $this->assess($userId);
        if ($assessment === null) {
            return;
        }

        $row     = $assessment['row'];
        $current = (string) $row->rank_slug;
        $target  = $assessment['target'];

        $order = ['apprentice' => 1, 'journeyman' => 2, 'veteran' => 3];
        $currentOrder = $order[$current] ?? 1;
        $targetOrder  = $order[$target];

        $inGrace       = (string) $row->recovery_status === 'grace';
        $graceDeadline = $row->recovery_deadline !== null ? (string) $row->recovery_deadline : null;

        $newRank      = $current;
        $newStatus    = '';
        $newDeadline  = null;
        $promoted     = false;
        $demoted      = false;

        if ($targetOrder > $currentOrder && !$inGrace) {
            // §23.1 automatic promotion (blocked during recovery §14.1.4).
            $newRank  = $target;
            $promoted = true;
        } elseif ($targetOrder >= $currentOrder) {
            // Requirements hold (or exceed) — recovery, if any, ends
            // without demotion (§14.1.6).
            $newStatus   = '';
            $newDeadline = null;
        } else {
            // Current rung's requirements lost.
            if (!$inGrace) {
                // §14.1.3 — start the 90-day recovery grace.
                $newStatus   = 'grace';
                $newDeadline = gmdate('Y-m-d H:i:s', time() + ($this->config->recoveryGraceDays * 86400));
            } elseif ($graceDeadline !== null && $graceDeadline <= gmdate('Y-m-d H:i:s')) {
                // §14.1.7 — grace expired: demote to highest satisfied.
                $newRank = $target;
                $demoted = true;
            } else {
                // Grace still running — keep rank + deadline.
                $newStatus   = 'grace';
                $newDeadline = $graceDeadline;
            }
        }

        $this->rankState->persistEvaluation(
            $userId,
            $newRank,
            $assessment['total'],
            $assessment['categories'],
            $newStatus,
            $newDeadline
        );

        if ($promoted) {
            \BCC\Core\Log\Logger::audit('rank_promoted', [
                'user_id' => $userId,
                'from'    => $current,
                'to'      => $newRank,
            ]);
            do_action('bcc_rank_awarded', $userId, $newRank, $current);
        } elseif ($demoted) {
            \BCC\Core\Log\Logger::audit('rank_demoted', [
                'user_id' => $userId,
                'from'    => $current,
                'to'      => $newRank,
            ]);
            do_action('bcc_rank_demoted', $userId, $newRank, $current);
        }
    }

    /**
     * The daily evaluate sweep — every ranked member, cursor-paged
     * within a time budget (missed members caught next day).
     */
    public function runDailyEvaluate(): void
    {
        $deadline = time() + self::TIME_BUDGET_SECONDS;
        $cursor   = 0;

        while (time() < $deadline) {
            $userIds = $this->rankState->listUserIdsAfter($cursor, self::BATCH_SIZE);
            if ($userIds === []) {
                return;
            }
            $cursor = max($userIds);

            foreach ($userIds as $userId) {
                try {
                    $this->evaluate($userId);
                } catch (\Throwable $e) {
                    \BCC\Core\Log\Logger::error('[bcc-trust] rank evaluate failed', [
                        'user_id' => $userId,
                        'error'   => $e->getMessage(),
                    ]);
                }
                if (time() >= $deadline) {
                    break;
                }
            }
        }

        \BCC\Core\Log\Logger::warning('[bcc-trust] rank daily evaluate: time budget hit, partial pass', [
            'cursor' => $cursor,
        ]);
    }
}
