<?php
/**
 * PollService — the generic meaningful-voting poll engine
 * (Rank redesign Phase 6, Wave 2; approved plan §16–§19).
 *
 * Pure poll MECHANICS: windows, one-active-ballot bookkeeping, the
 * recast/withdraw budget, close-time cluster rules, quorum/majority
 * evaluation, and the C10-safe viewer state. ELIGIBILITY IS THE
 * CALLER'S JOB — Wave 3's DisputeVoteService applies §18 voter
 * eligibility and dispute conflict checks before calling cast(), and
 * computes the §16.6 snapshot via VoteWeightCalculator (this service
 * never re-implements weight arithmetic; it stores the snapshot
 * verbatim and clamps only defensively).
 *
 * Snapshot model (§16.6): the weight-input snapshot is captured at the
 * ORIGINAL cast; every re-entry (recast or post-withdrawal re-cast)
 * COPIES it verbatim — no re-weighting, and every re-entry consumes
 * one of the two recast budget slots. Poll windows NEVER reset.
 *
 * Close sweep (§17/§19, hourly via RankScheduler):
 *   1. binding-window polls (day-7+): confirmed-cluster representative
 *      rule → suspected pro-rata cap (split-proof by construction) →
 *      quorum (post-cap voters AND weight) → majority (winning side
 *      ≥ config majority of counted weight; exactly 60.00% PASSES).
 *      Not met → the poll stays open until expiry.
 *   2. expiry polls (day-90+): evaluated the same way; still short →
 *      closed 'inconclusive'. Audit columns are persisted on EVERY
 *      close so the closed-only tally (§17.3) is always derivable.
 *
 * Poll close + per-ballot audit writes are atomic
 * (TransactionManager::run); the polls row-guard (WHERE status='open')
 * makes the sweep idempotent under concurrency. `bcc_trust_poll_closed`
 * fires AFTER commit — Wave 3's dispute layer subscribes; the engine
 * stays generic ('for'/'against'; dispute semantics map in Wave 3).
 *
 * While a poll is OPEN, viewerState exposes NO counts, totals, or
 * quorum progress (C10) — only the viewer's own ballot facts.
 *
 * @package BCC\Trust\Rank\Services
 * @since Rank redesign Phase 6 (2026-08-03)
 */

declare(strict_types=1);

namespace BCC\Trust\Rank\Services;

use BCC\Trust\Core\Security\TransactionManager;
use BCC\Trust\Rank\Repositories\BallotRepository;
use BCC\Trust\Rank\Repositories\ClusterFindingsRepository;
use BCC\Trust\Rank\Repositories\PollRepository;
use BCC\Trust\Rank\Support\RankScoringConfig;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-import-type PollRow from PollRepository
 * @phpstan-import-type BallotRow from BallotRepository
 * @phpstan-import-type BallotSnapshot from BallotRepository
 * @phpstan-import-type ClusterFindingRow from ClusterFindingsRepository
 *
 * @phpstan-type EvaluatedBallot array{
 *     row: BallotRow,
 *     counted: bool,
 *     counted_weight: float,
 *     suspected_id: int|null,
 *     confirmed_id: int|null
 * }
 * @phpstan-type PollEvaluation array{
 *     ballots: list<EvaluatedBallot>,
 *     counted_voters: int,
 *     counted_weight: float,
 *     weight_for: float,
 *     weight_against: float
 * }
 */
class PollService
{
    /** §17.4: a ballot may be changed at most twice — and every re-entry counts. */
    public const MAX_RECASTS = 2;

    /** §17.4: 24h between ballot actions (recast or withdraw). */
    public const ACTION_COOLDOWN_SECONDS = 86400;

    /** Bounded sweep batch per tick (hourly cadence drains any backlog). */
    private const SWEEP_BATCH = 200;

    /** Float-comparison guard for the quorum/majority thresholds. */
    private const EPSILON = 1e-9;

    private const CHOICES = ['for', 'against'];

    public function __construct(
        private readonly PollRepository $polls,
        private readonly BallotRepository $ballots,
        private readonly ClusterFindingsRepository $clusters,
        private readonly RankScoringConfig $config
    ) {
    }

    // ── lifecycle ────────────────────────────────────────────────────

    /**
     * Open a poll over a subject. Windows come from config
     * (binding_window min/max days) and NEVER reset. A duplicate open —
     * pre-checked AND constraint-backed (uniq_open_subject) — surfaces
     * as a domain exception.
     *
     * @return int New poll id.
     * @throws \InvalidArgumentException On malformed subject input.
     * @throws \RuntimeException When the subject already has an open poll.
     */
    public function open(
        string $pollType,
        string $subjectType,
        int $subjectId,
        ?\DateTimeImmutable $now = null
    ): int {
        $now ??= $this->utcNow();

        if ($pollType === '' || strlen($pollType) > 20) {
            throw new \InvalidArgumentException('poll open: poll_type must be 1-20 characters');
        }
        if ($subjectType === '' || strlen($subjectType) > 20) {
            throw new \InvalidArgumentException('poll open: subject_type must be 1-20 characters');
        }
        if ($subjectId <= 0) {
            throw new \InvalidArgumentException('poll open: subject_id must be positive');
        }

        if ($this->polls->getOpenBySubject($pollType, $subjectType, $subjectId) !== null) {
            throw new \RuntimeException('poll open: subject already has an open poll');
        }

        $id = $this->polls->create(
            $pollType,
            $subjectType,
            $subjectId,
            $now->format('Y-m-d H:i:s'),
            $now->modify('+' . $this->config->bindingMinDays . ' days')->format('Y-m-d H:i:s'),
            $now->modify('+' . $this->config->bindingMaxDays . ' days')->format('Y-m-d H:i:s')
        );

        if ($id === 0) {
            // Lost the uniq_open_subject race — same domain error as the pre-check.
            throw new \RuntimeException('poll open: subject already has an open poll');
        }

        return $id;
    }

    /**
     * Cast a ballot. First cast stores the caller-computed §16.6
     * snapshot (VoteWeightCalculator inputs + effective weight, clamped
     * defensively to [0, vote_ceiling]). A re-cast after withdrawal is
     * a RE-ENTRY: it consumes recast budget and carries the ORIGINAL
     * snapshot verbatim — the caller-provided snapshot is ignored.
     *
     * @param array<string, mixed> $snapshot §16.6 input set — keys
     *        rank_slug (string|null), maturity, rank_multiplier,
     *        trust_multiplier, trust_score, fraud_discount,
     *        effective_weight (all numeric).
     * @return int New ballot id.
     * @throws \InvalidArgumentException On bad choice/snapshot.
     * @throws \RuntimeException On closed/expired poll, existing active
     *         ballot, exhausted recast budget, or insert race.
     */
    public function cast(
        int $pollId,
        int $voterId,
        string $choice,
        array $snapshot,
        ?\DateTimeImmutable $now = null
    ): int {
        $now ??= $this->utcNow();
        $this->assertChoice($choice);
        if ($voterId <= 0) {
            throw new \InvalidArgumentException('poll cast: voter id must be positive');
        }

        $this->requireOpenPoll($pollId, $now);

        if ($this->ballots->getActiveForVoter($pollId, $voterId) !== null) {
            throw new \RuntimeException('poll cast: voter already has an active ballot — use changeBallot()');
        }

        $nowSql = $now->format('Y-m-d H:i:s');
        $latest = $this->ballots->getLatestForVoter($pollId, $voterId);

        if ($latest !== null) {
            // Re-entry after withdrawal — every re-entry is a recast:
            // budget enforced, ORIGINAL snapshot copied verbatim.
            $priorRecasts = (int) $latest->recast_count;
            if ($priorRecasts >= self::MAX_RECASTS) {
                throw new \RuntimeException('poll cast: recast limit reached');
            }
            $id = $this->ballots->insert(
                $pollId,
                $voterId,
                $choice,
                $this->snapshotFromRow($latest),
                $priorRecasts + 1,
                $nowSql,
                $nowSql
            );
        } else {
            $id = $this->ballots->insert(
                $pollId,
                $voterId,
                $choice,
                $this->validateSnapshot($snapshot),
                0,
                $nowSql,
                $nowSql
            );
        }

        if ($id === 0) {
            throw new \RuntimeException('poll cast: ballot insert failed (concurrent cast?)');
        }
        return $id;
    }

    /**
     * The recast path: supersede the active ballot and insert a fresh
     * ACTIVE row with the ORIGINAL snapshot verbatim and
     * recast_count + 1. Max 2 changes, 24h cooldown since the last
     * ballot action; poll windows NEVER reset.
     *
     * @return int Replacement ballot id.
     * @throws \InvalidArgumentException On bad/unchanged choice.
     * @throws \RuntimeException On missing active ballot, exhausted
     *         budget, cooldown, or concurrent mutation.
     */
    public function changeBallot(
        int $pollId,
        int $voterId,
        string $newChoice,
        ?\DateTimeImmutable $now = null
    ): int {
        $now ??= $this->utcNow();
        $this->assertChoice($newChoice);

        $this->requireOpenPoll($pollId, $now);

        $active = $this->ballots->getActiveForVoter($pollId, $voterId);
        if ($active === null) {
            throw new \RuntimeException('poll recast: no active ballot — use cast()');
        }
        if ($active->choice === $newChoice) {
            // Defensive: a same-choice "change" would silently burn one
            // of the two budget slots for nothing.
            throw new \InvalidArgumentException('poll recast: choice unchanged');
        }
        if ((int) $active->recast_count >= self::MAX_RECASTS) {
            throw new \RuntimeException('poll recast: recast limit reached');
        }
        if (!$this->cooldownElapsed($active, $now)) {
            throw new \RuntimeException('poll recast: 24h cooldown since the last ballot action has not elapsed');
        }

        $nowSql   = $now->format('Y-m-d H:i:s');
        $activeId = (int) $active->id;
        $snapshot = $this->snapshotFromRow($active);
        $recasts  = (int) $active->recast_count + 1;

        $newId = $this->withinTransaction(function () use ($pollId, $voterId, $newChoice, $activeId, $snapshot, $recasts, $nowSql): int {
            if (!$this->ballots->supersede($pollId, $activeId, $nowSql)) {
                throw new \RuntimeException('poll recast: ballot changed concurrently — retry');
            }
            $id = $this->ballots->insert($pollId, $voterId, $newChoice, $snapshot, $recasts, $nowSql, $nowSql);
            if ($id === 0) {
                throw new \RuntimeException('poll recast: replacement insert failed');
            }
            return $id;
        });

        return (int) $newId;
    }

    /**
     * Withdraw the active ballot (24h cooldown applies). The voter may
     * re-enter later via cast() while their recast budget lasts.
     *
     * @throws \RuntimeException On missing active ballot or cooldown.
     */
    public function withdraw(int $pollId, int $voterId, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= $this->utcNow();

        $this->requireOpenPoll($pollId, $now);

        $active = $this->ballots->getActiveForVoter($pollId, $voterId);
        if ($active === null) {
            throw new \RuntimeException('poll withdraw: no active ballot');
        }
        if (!$this->cooldownElapsed($active, $now)) {
            throw new \RuntimeException('poll withdraw: 24h cooldown since the last ballot action has not elapsed');
        }

        return $this->ballots->withdraw($pollId, (int) $active->id, $now->format('Y-m-d H:i:s'));
    }

    // ── views ────────────────────────────────────────────────────────

    /**
     * C10-safe poll view. OPEN: windows + the viewer's own ballot facts
     * and NOTHING else — no counts, no totals, no quorum progress.
     * CLOSED: adds outcome, closed_at, and the counted tally (§17.3,
     * closed-only), derived from the persisted closure audit.
     *
     * @return array{
     *     status: string,
     *     opened_at: string,
     *     binding_earliest_at: string,
     *     expires_at: string,
     *     viewer: array{my_choice: string|null, my_effective_weight: float|null, can_change: bool, can_withdraw: bool},
     *     outcome?: string|null,
     *     closed_at?: string|null,
     *     tally?: array{counted_voters: int, weight_for: float, weight_against: float}
     * }
     * @throws \RuntimeException When the poll does not exist.
     */
    public function viewerState(int $pollId, int $viewerId, ?\DateTimeImmutable $now = null): array
    {
        $now ??= $this->utcNow();

        $poll = $this->polls->getById($pollId);
        if ($poll === null) {
            throw new \RuntimeException('poll not found');
        }

        $active  = $viewerId > 0 ? $this->ballots->getActiveForVoter($pollId, $viewerId) : null;
        $isOpen  = $poll->status === 'open';
        $expired = $this->isPast($poll->expires_at, $now);

        $canChange   = false;
        $canWithdraw = false;
        if ($active !== null && $isOpen && !$expired && $this->cooldownElapsed($active, $now)) {
            $canWithdraw = true;
            $canChange   = (int) $active->recast_count < self::MAX_RECASTS;
        }

        $state = [
            'status'              => $poll->status,
            'opened_at'           => $poll->opened_at,
            'binding_earliest_at' => $poll->binding_earliest_at,
            'expires_at'          => $poll->expires_at,
            'viewer'              => [
                'my_choice'           => $active !== null ? $active->choice : null,
                'my_effective_weight' => $active !== null ? (float) $active->effective_weight : null,
                'can_change'          => $canChange,
                'can_withdraw'        => $canWithdraw,
            ],
        ];

        if (!$isOpen) {
            $state['outcome']   = $poll->outcome;
            $state['closed_at'] = $poll->closed_at;
            $state['tally']     = $this->closedTally($pollId);
        }

        return $state;
    }

    // ── close sweep ──────────────────────────────────────────────────

    /**
     * The hourly sweep body (RankScheduler EVENT_POLL_CLOSE_SWEEP):
     * evaluate open polls past their binding window; close open polls
     * past expiry that still fall short as 'inconclusive'.
     *
     * @return array{closed: int, inconclusive: int} closed = decisive
     *         ('passed'/'failed') closures this tick.
     */
    public function closeDuePolls(?\DateTimeImmutable $now = null): array
    {
        $now    = $now ?? $this->utcNow();
        $nowSql = $now->format('Y-m-d H:i:s');

        // §18/§19 cluster findings are GLOBAL and stable across one sweep,
        // so index them ONCE here and thread the maps down into every
        // evaluatePoll(). Previously evaluatePoll() re-read BOTH unscoped
        // lists (listActiveConfirmed + listActiveSuspected) for every poll
        // it closed — up to 2 × (200 binding + 200 expiry) identical reads
        // per hourly tick.
        $confirmedByMember = $this->indexConfirmedByMember();
        $suspectedByMember = $this->indexSuspectedByMember();

        $closed       = 0;
        $inconclusive = 0;

        foreach ($this->polls->listOpenPastBinding($nowSql, self::SWEEP_BATCH) as $poll) {
            $outcome = $this->evaluateAndMaybeClose($poll, $now, false, $confirmedByMember, $suspectedByMember);
            if ($outcome === 'passed' || $outcome === 'failed') {
                $closed++;
            }
        }

        foreach ($this->polls->listOpenPastExpiry($nowSql, self::SWEEP_BATCH) as $poll) {
            if (!$this->isPast($poll->expires_at, $now)) {
                continue; // Service-side guard — never trust the list query alone.
            }
            $outcome = $this->evaluateAndMaybeClose($poll, $now, true, $confirmedByMember, $suspectedByMember);
            if ($outcome === 'passed' || $outcome === 'failed') {
                $closed++;
            } elseif ($outcome === 'inconclusive') {
                $inconclusive++;
            }
        }

        return ['closed' => $closed, 'inconclusive' => $inconclusive];
    }

    /**
     * Evaluate one open poll and close it when warranted. Returns the
     * outcome this call closed it with, or null (left open / lost the
     * close race / not yet binding). The cluster maps are loaded once per
     * sweep and threaded in — never re-read here.
     *
     * @phpstan-param PollRow $poll
     * @phpstan-param array<int, list<ClusterFindingRow>> $confirmedByMember
     * @phpstan-param array<int, ClusterFindingRow> $suspectedByMember
     */
    private function evaluateAndMaybeClose(
        object $poll,
        \DateTimeImmutable $now,
        bool $forceClose,
        array $confirmedByMember,
        array $suspectedByMember
    ): ?string {
        // §17.2 day-7 guard IN THE SERVICE: even if the list query ever
        // drifted, a poll before its binding window is never evaluated.
        if (!$this->isPast($poll->binding_earliest_at, $now)) {
            return null;
        }

        $pollId = (int) $poll->id;
        $eval   = $this->evaluatePoll($pollId, $confirmedByMember, $suspectedByMember);

        $outcome = $this->decideOutcome($eval);
        if ($outcome === null) {
            if (!$forceClose) {
                return null; // Quorum/majority not met — stays open until expiry.
            }
            $outcome = 'inconclusive';
        }

        $nowSql = $now->format('Y-m-d H:i:s');

        // Atomic: the row-guarded poll close arbitrates concurrency
        // FIRST; only the winner writes the per-ballot closure audit.
        $won = $this->withinTransaction(function () use ($pollId, $outcome, $eval, $nowSql): int {
            if (!$this->polls->close($pollId, $outcome, $nowSql)) {
                return 0; // Another sweep tick won — no audit writes.
            }
            foreach ($eval['ballots'] as $evaluated) {
                $this->ballots->recordClosureAudit(
                    $pollId,
                    (int) $evaluated['row']->id,
                    $evaluated['suspected_id'],
                    $evaluated['confirmed_id'],
                    $evaluated['counted_weight'],
                    $evaluated['counted']
                );
            }
            return 1;
        });

        if ((int) $won !== 1) {
            return null;
        }

        // AFTER commit — Wave 3's dispute layer subscribes (positional args).
        do_action(
            'bcc_trust_poll_closed',
            $pollId,
            $outcome,
            (string) $poll->poll_type,
            (string) $poll->subject_type,
            (int) $poll->subject_id
        );

        return $outcome;
    }

    /**
     * Index every active CONFIRMED finding by member id (a member may
     * appear in more than one). Read ONCE per sweep in closeDuePolls();
     * the §18 representative rule then resolves per ballot in memory.
     *
     * @phpstan-return array<int, list<ClusterFindingRow>>
     */
    private function indexConfirmedByMember(): array
    {
        /** @var array<int, list<ClusterFindingRow>> $confirmedByMember */
        $confirmedByMember = [];
        foreach ($this->clusters->listActiveConfirmed() as $finding) {
            foreach ($this->memberIds($finding) as $memberId) {
                $confirmedByMember[$memberId][] = $finding;
            }
        }
        return $confirmedByMember;
    }

    /**
     * Index every active SUSPECTED finding by member id — lowest finding
     * id wins per member (the repository reads id ASC). Read ONCE per
     * sweep; the §19 pro-rata cap then applies per ballot in memory.
     *
     * @phpstan-return array<int, ClusterFindingRow>
     */
    private function indexSuspectedByMember(): array
    {
        /** @var array<int, ClusterFindingRow> $suspectedByMember */
        $suspectedByMember = [];
        foreach ($this->clusters->listActiveSuspected() as $finding) {
            foreach ($this->memberIds($finding) as $memberId) {
                if (!isset($suspectedByMember[$memberId])) {
                    $suspectedByMember[$memberId] = $finding; // Lowest finding id wins (id ASC read).
                }
            }
        }
        return $suspectedByMember;
    }

    /**
     * Close-time evaluation of one poll's active ballots (§18/§19):
     *
     *   1. CONFIRMED clusters: a member ballot counts ONLY when the
     *      voter is the finding's representative; every other member
     *      gets counted=0 / weight 0 (row retained for audit).
     *   2. SUSPECTED cap: W_susp (counted suspected weight) is capped
     *      at ratio × W_nc (counted non-cluster weight) and scaled
     *      pro-rata — C_total depends only on the sums, so splitting
     *      one identity into many changes nothing (split-proof).
     *
     * The member-keyed cluster maps are built once per sweep by
     * indexConfirmedByMember()/indexSuspectedByMember() and injected —
     * this method never re-reads the cluster findings.
     *
     * @phpstan-param array<int, list<ClusterFindingRow>> $confirmedByMember
     * @phpstan-param array<int, ClusterFindingRow> $suspectedByMember
     * @phpstan-return PollEvaluation
     */
    private function evaluatePoll(int $pollId, array $confirmedByMember, array $suspectedByMember): array
    {
        $rows = $this->ballots->listActiveForPoll($pollId);

        /** @var list<EvaluatedBallot> $evaluated */
        $evaluated       = [];
        $suspectedWeight = 0.0;
        $nonClusterWeight = 0.0;

        foreach ($rows as $row) {
            $voter  = (int) $row->voter_user_id;
            $weight = (float) $row->effective_weight;

            $confirmedId = null;
            $counted     = true;
            foreach ($confirmedByMember[$voter] ?? [] as $finding) {
                $confirmedId ??= (int) $finding->id;
                if ((int) $finding->representative_user_id !== $voter) {
                    $counted = false; // Non-representative member: excluded from weight AND headcount.
                }
            }

            $suspectedId = isset($suspectedByMember[$voter])
                ? (int) $suspectedByMember[$voter]->id
                : null;

            if (!$counted) {
                $evaluated[] = [
                    'row'            => $row,
                    'counted'        => false,
                    'counted_weight' => 0.0,
                    'suspected_id'   => $suspectedId,
                    'confirmed_id'   => $confirmedId,
                ];
                continue;
            }

            if ($suspectedId !== null) {
                $suspectedWeight += $weight;
            } else {
                $nonClusterWeight += $weight;
            }

            $evaluated[] = [
                'row'            => $row,
                'counted'        => true,
                'counted_weight' => $weight,
                'suspected_id'   => $suspectedId,
                'confirmed_id'   => $confirmedId,
            ];
        }

        // §19: C_total = min(W_susp, ratio × W_nc); pro-rata when over.
        $capTotal = min($suspectedWeight, $this->config->suspectedClusterCapRatio * $nonClusterWeight);
        if ($suspectedWeight > $capTotal && $suspectedWeight > 0.0) {
            $scale = $capTotal / $suspectedWeight;
            foreach ($evaluated as $i => $entry) {
                if ($entry['counted'] && $entry['suspected_id'] !== null) {
                    $evaluated[$i]['counted_weight'] = round($entry['counted_weight'] * $scale, 4);
                }
            }
        }

        $countedVoters = 0;
        $weightFor     = 0.0;
        $weightAgainst = 0.0;
        foreach ($evaluated as $entry) {
            if (!$entry['counted']) {
                continue;
            }
            $countedVoters++;
            if ($entry['row']->choice === 'for') {
                $weightFor += $entry['counted_weight'];
            } else {
                $weightAgainst += $entry['counted_weight'];
            }
        }

        return [
            'ballots'        => $evaluated,
            'counted_voters' => $countedVoters,
            'counted_weight' => $weightFor + $weightAgainst,
            'weight_for'     => $weightFor,
            'weight_against' => $weightAgainst,
        ];
    }

    /**
     * Quorum + majority over POST-cap numbers. Exactly the configured
     * majority share (60.00%) PASSES (>=, epsilon-guarded).
     *
     * @phpstan-param PollEvaluation $eval
     * @return 'passed'|'failed'|null Null = quorum or majority not met.
     */
    private function decideOutcome(array $eval): ?string
    {
        if ($eval['counted_voters'] < $this->config->quorumVoters) {
            return null;
        }
        if ($eval['counted_weight'] + self::EPSILON < $this->config->quorumWeight) {
            return null;
        }

        $total = $eval['counted_weight'];
        if ($total <= 0.0) {
            return null;
        }

        $threshold = $this->config->majority * $total;
        if ($eval['weight_for'] + self::EPSILON >= $threshold) {
            return 'passed';
        }
        if ($eval['weight_against'] + self::EPSILON >= $threshold) {
            return 'failed';
        }

        return null;
    }

    // ── helpers ──────────────────────────────────────────────────────

    /**
     * Closed-poll tally (§17.3) from the persisted closure audit.
     *
     * @return array{counted_voters: int, weight_for: float, weight_against: float}
     */
    private function closedTally(int $pollId): array
    {
        $voters        = 0;
        $weightFor     = 0.0;
        $weightAgainst = 0.0;

        foreach ($this->ballots->listActiveForPoll($pollId) as $row) {
            if ((int) ($row->counted ?? '0') !== 1) {
                continue;
            }
            $voters++;
            $weight = (float) ($row->counted_weight ?? '0');
            if ($row->choice === 'for') {
                $weightFor += $weight;
            } else {
                $weightAgainst += $weight;
            }
        }

        return [
            'counted_voters' => $voters,
            'weight_for'     => round($weightFor, 4),
            'weight_against' => round($weightAgainst, 4),
        ];
    }

    /**
     * Seam for atomicity: production wraps the callback in
     * TransactionManager::run; unit tests override to invoke directly
     * (no WP/DB in the unit bootstrap).
     *
     * @return mixed
     */
    protected function withinTransaction(callable $callback): mixed
    {
        return TransactionManager::run($callback);
    }

    /**
     * @phpstan-return PollRow
     * @throws \RuntimeException On missing, closed, or expired poll.
     */
    private function requireOpenPoll(int $pollId, \DateTimeImmutable $now): object
    {
        $poll = $this->polls->getById($pollId);
        if ($poll === null) {
            throw new \RuntimeException('poll not found');
        }
        if ($poll->status !== 'open') {
            throw new \RuntimeException('poll is closed');
        }
        if ($this->isPast($poll->expires_at, $now)) {
            // Past day-90 but not yet swept — ballots are frozen.
            throw new \RuntimeException('poll has expired');
        }
        return $poll;
    }

    /**
     * Validate the caller-computed §16.6 snapshot; clamp the effective
     * weight defensively to [0, vote_ceiling] (§16.7). Never computes
     * weight — VoteWeightCalculator is the single source of that math.
     *
     * @param array<string, mixed> $snapshot
     * @phpstan-return BallotSnapshot
     */
    private function validateSnapshot(array $snapshot): array
    {
        $rankSlug = $snapshot['rank_slug'] ?? null;
        if ($rankSlug !== null && !is_string($rankSlug)) {
            throw new \InvalidArgumentException('poll cast: snapshot rank_slug must be a string or null');
        }
        if (is_string($rankSlug) && strlen($rankSlug) > 20) {
            throw new \InvalidArgumentException('poll cast: snapshot rank_slug exceeds 20 characters');
        }

        $floats = [];
        foreach (['maturity', 'rank_multiplier', 'trust_multiplier', 'trust_score', 'fraud_discount', 'effective_weight'] as $key) {
            $value = $snapshot[$key] ?? null;
            if (!is_int($value) && !is_float($value)) {
                throw new \InvalidArgumentException("poll cast: snapshot {$key} must be numeric");
            }
            $floats[$key] = (float) $value;
        }

        return [
            'rank_slug'        => $rankSlug,
            'maturity'         => $floats['maturity'],
            'rank_multiplier'  => $floats['rank_multiplier'],
            'trust_multiplier' => $floats['trust_multiplier'],
            'trust_score'      => $floats['trust_score'],
            'fraud_discount'   => $floats['fraud_discount'],
            'effective_weight' => max(0.0, min($this->config->voteCeiling, $floats['effective_weight'])),
        ];
    }

    /**
     * The ORIGINAL snapshot, copied verbatim from a prior ballot row —
     * the recast/re-entry path never re-weights.
     *
     * @phpstan-param BallotRow $row
     * @phpstan-return BallotSnapshot
     */
    private function snapshotFromRow(object $row): array
    {
        return [
            'rank_slug'        => $row->rank_slug,
            'maturity'         => (float) $row->maturity,
            'rank_multiplier'  => (float) $row->rank_multiplier,
            'trust_multiplier' => (float) $row->trust_multiplier,
            'trust_score'      => (float) $row->trust_score,
            'fraud_discount'   => (float) $row->fraud_discount,
            'effective_weight' => (float) $row->effective_weight,
        ];
    }

    /**
     * @phpstan-param ClusterFindingRow $finding
     * @return list<int>
     */
    private function memberIds(object $finding): array
    {
        $decoded = json_decode($finding->member_user_ids, true);
        if (!is_array($decoded)) {
            return [];
        }
        $ids = [];
        foreach ($decoded as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $iid = (int) $id;
                if ($iid > 0) {
                    $ids[] = $iid;
                }
            }
        }
        return $ids;
    }

    /** UTC comparison of a MySQL datetime against $now ("at or before"). */
    private function isPast(string $mysqlDatetime, \DateTimeImmutable $now): bool
    {
        $timestamp = strtotime($mysqlDatetime . ' UTC');
        // Unparseable ⇒ NOT past (fail safe: never evaluate/close early).
        return $timestamp !== false && $timestamp <= $now->getTimestamp();
    }

    private function assertChoice(string $choice): void
    {
        if (!in_array($choice, self::CHOICES, true)) {
            throw new \InvalidArgumentException("poll ballot: choice must be one of 'for'|'against'");
        }
    }

    /**
     * @phpstan-param BallotRow $ballot
     */
    private function cooldownElapsed(object $ballot, \DateTimeImmutable $now): bool
    {
        $last = strtotime($ballot->last_action_at . ' UTC');
        return $last !== false
            && ($now->getTimestamp() - $last) >= self::ACTION_COOLDOWN_SECONDS;
    }

    private function utcNow(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
