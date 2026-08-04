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
 * Phase 8 (recovery + misconduct): assess() folds active finding
 * penalties (capped) into the score and applies finding ceilings as
 * satisfied[] AND-terms; evaluate() surfaces recovery-started /
 * recovery-reminder / decay-warning actions; evaluateImmediate() is
 * the severe-finding no-grace demotion path (§15.3 class 4).
 *
 * Transitions are audited and emit the existing `bcc_rank_awarded`
 * action (bell + celebration chain) or `bcc_rank_demoted`.
 *
 * @package BCC\Trust\Rank\Services
 * @since Rank redesign Phase 5 (2026-07-31)
 */

declare(strict_types=1);

namespace BCC\Trust\Rank\Services;

use BCC\Trust\Rank\Repositories\FindingsRepository;
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

    /** Recovery-reminder marks: fire when exactly this many whole days remain. */
    private const REMINDER_DAYS = [30, 7];

    /** usermeta stamp gating the §12.3 decay warning to one per 30 days. */
    private const DECAY_WARNED_META = 'bcc_rank_decay_warned_at';

    /** Seconds in the decay-warning re-notify gate. */
    private const DECAY_WARN_GATE_SECONDS = 30 * 86400;

    public function __construct(
        private readonly RankStateRepository $rankState,
        private readonly RankEventsRepository $events,
        private readonly LoginDaysRepository $loginDays,
        private readonly TierDaysRepository $tierDays,
        private readonly FindingsRepository $findings,
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
     *     finding_penalty: float,
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

        // §15.3 (Phase 8): active-unexpired finding penalties, capped at
        // the total-active cap (each is already ≤ the per-finding cap by
        // config snapshot). Ceilings: the LOWEST active unexpired
        // ceiling bounds the highest satisfiable rung. Expiry is
        // evaluated here in PHP (UTC) — the repository read is
        // status-only by design.
        $nowUtc        = gmdate('Y-m-d H:i:s');
        $penaltySum    = 0.0;
        $rankOrder     = ['apprentice' => 1, 'journeyman' => 2, 'veteran' => 3];
        $ceilingOrd    = $rankOrder['veteran']; // no restriction by default
        foreach ($this->findings->getActiveForUser($userId) as $finding) {
            if ((string) $finding->penalty_expires_at > $nowUtc) {
                $penaltySum += (float) $finding->score_penalty;
            }
            $ceilingSlug = $finding->ceiling_rank_slug !== null ? (string) $finding->ceiling_rank_slug : '';
            if ($ceilingSlug !== ''
                && isset($rankOrder[$ceilingSlug])
                && $finding->ceiling_expires_at !== null
                && (string) $finding->ceiling_expires_at > $nowUtc
            ) {
                $ceilingOrd = min($ceilingOrd, $rankOrder[$ceilingSlug]);
            }
        }
        $findingPenalty = min($penaltySum, $this->config->totalActivePenaltyCap);

        $result = $this->calculator->calculate(
            $activeEvents,
            $this->loginDays->distinctMonthCount($userId),
            $this->loginDays->lastLoginDay($userId),
            $clusterMap,
            null,
            $findingPenalty
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

        // §15.3 ceiling AND-term (mirrors $suspensionClear): any rung
        // ABOVE the lowest active finding ceiling is unsatisfiable.
        // Consequence: a class-3 (serious) ceiling on a higher-ranked
        // member flows through the ORDINARY §14.1 grace machinery — the
        // 90-day recovery either outlasts the ceiling or demotes at the
        // deadline. Class-4 (severe) skips grace via evaluateImmediate().
        $satisfied = [
            'apprentice' => true, // Held rung; Phase 5 has no apprentice-loss rule.
            'journeyman' => $suspensionClear
                && $ceilingOrd >= $rankOrder['journeyman']
                && $result['total'] >= $this->config->thresholds['journeyman']
                && $result['categories']['contribution'] >= $this->config->categoryMinimums['journeyman']['contribution']
                && $result['categories']['helping'] >= $this->config->categoryMinimums['journeyman']['helping']
                && $result['categories']['recognition'] >= $this->config->categoryMinimums['journeyman']['recognition']
                && count($contributionTypes) >= $this->config->diversityMinimums['journeyman_categories']
                && count($recognizers) >= $this->config->recognizerMinimums['journeyman']
                && $windows['journeyman']['qualifying'] >= $windows['journeyman']['required'],
            'veteran' => $suspensionClear
                && $ceilingOrd >= $rankOrder['veteran']
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
            'finding_penalty'    => $result['finding_penalty'],
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

        $newRank       = $current;
        $newStatus     = '';
        $newDeadline   = null;
        $promoted      = false;
        $demoted       = false;
        $graceStarted  = false;
        $reminderDays  = null;

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
                $newStatus    = 'grace';
                $newDeadline  = gmdate('Y-m-d H:i:s', time() + ($this->config->recoveryGraceDays * 86400));
                $graceStarted = true;
            } elseif ($graceDeadline !== null && $graceDeadline <= gmdate('Y-m-d H:i:s')) {
                // §14.1.7 — grace expired: demote to highest satisfied.
                $newRank = $target;
                $demoted = true;
            } else {
                // Grace still running — keep rank + deadline. When
                // exactly 30 or 7 whole days remain, surface the
                // deadline-framed reminder (Phase 8). Fired from the
                // daily sweep; an extra same-day evaluate (finding
                // issuance/reversal) can re-fire within that one day —
                // acceptable for an admin-time path.
                $newStatus   = 'grace';
                $newDeadline = $graceDeadline;

                $deadlineTs = $graceDeadline !== null ? strtotime($graceDeadline . ' UTC') : false;
                if ($deadlineTs !== false) {
                    $daysLeft = (int) floor(($deadlineTs - time()) / 86400);
                    if (in_array($daysLeft, self::REMINDER_DAYS, true)) {
                        $reminderDays = $daysLeft;
                    }
                }
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
        } elseif ($graceStarted && $newDeadline !== null) {
            // §14.2 (Phase 8): grace start is no longer silent — the
            // recovery notice states the deadline and the paused
            // privileges (bell copy lives in NotificationDispatcher).
            \BCC\Core\Log\Logger::audit('rank_recovery_started', [
                'user_id'  => $userId,
                'rank'     => $current,
                'deadline' => $newDeadline,
            ]);
            do_action('bcc_rank_recovery_started', $userId, $newDeadline);
        } elseif ($reminderDays !== null) {
            do_action('bcc_rank_recovery_reminder', $userId, $reminderDays);
        }

        // §12.3 decay warning (Phase 8): active decay surfaces a plain
        // statement, at most once per 30 days (usermeta stamp). A login
        // self-heals decay with zero writes, so the stamp only gates
        // notification frequency — never the score math.
        if ($assessment['decay'] > 0.0) {
            $this->maybeWarnDecay($userId);
        }
    }

    /**
     * Immediate-demotion path (§15.3 class 4 / severe — Phase 8).
     * FindingsService calls this instead of evaluate() when the issued
     * finding carries immediate_demotion: when the assessment's target
     * is BELOW the current rung the demotion lands NOW — no §14.1
     * grace; recovery_status clears. When the target holds the current
     * rung (or exceeds it) this delegates to the ordinary evaluate()
     * semantics.
     */
    public function evaluateImmediate(int $userId): void
    {
        $assessment = $this->assess($userId);
        if ($assessment === null) {
            return;
        }

        $row     = $assessment['row'];
        $current = (string) $row->rank_slug;
        $target  = $assessment['target'];

        $order = ['apprentice' => 1, 'journeyman' => 2, 'veteran' => 3];
        if ($order[$target] >= ($order[$current] ?? 1)) {
            $this->evaluate($userId);
            return;
        }

        $this->rankState->persistEvaluation(
            $userId,
            $target,
            $assessment['total'],
            $assessment['categories'],
            '',
            null
        );

        \BCC\Core\Log\Logger::audit('rank_demoted', [
            'user_id' => $userId,
            'from'    => $current,
            'to'      => $target,
            'via'     => 'finding',
        ]);
        do_action('bcc_rank_demoted', $userId, $target, $current);
    }

    /** One decay-warning bell per 30 days, gated by a usermeta stamp. */
    private function maybeWarnDecay(int $userId): void
    {
        $stamp = get_user_meta($userId, self::DECAY_WARNED_META, true);
        $last  = is_numeric($stamp) ? (int) $stamp : 0;
        if ($last > 0 && (time() - $last) < self::DECAY_WARN_GATE_SECONDS) {
            return;
        }

        update_user_meta($userId, self::DECAY_WARNED_META, (string) time());
        do_action('bcc_rank_decay_warning', $userId);
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
