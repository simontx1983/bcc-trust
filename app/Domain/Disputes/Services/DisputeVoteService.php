<?php
/**
 * DisputeVoteService — disputes ride the generic poll engine
 * (Rank redesign Phase 6, Wave 3; owner decision D-7 retired the
 * five-member panel).
 *
 * Responsibilities:
 *   - open a poll when a dispute enters 'reviewing'
 *     (poll_type 'dispute' / subject_type 'dispute' / subject_id =
 *     dispute id — windows come from RankScoringConfig via the engine);
 *   - §18 voter eligibility (fail closed, checked here — the engine is
 *     mechanics-only): signed-in, not suspended, ranked Apprentice+
 *     (rank_state row), Trust Neutral+, not fraud-hard-blocked, and NOT
 *     a party to the dispute (opener, disputed voter, disputed page's
 *     owner);
 *   - §16.6 weight snapshot via VoteService::assembleBallotWeightSnapshot
 *     (the same input assembly castPageVote feeds VoteWeightCalculator);
 *   - dispute vocabulary mapping ('uphold'/'reject' ⇄ engine
 *     'for'/'against'; poll outcomes → the existing DisputeStatus
 *     terminal values);
 *   - `bcc_trust_poll_closed` subscription (positional args): decisive
 *     outcomes route through the EXISTING resolution machinery
 *     (claimResolutionEnqueue → bcc_disputes_async_resolve →
 *     DisputeResolver → DisputeAdjudicationInterface — the §18.4
 *     severe-consequence admin confirmation lives downstream and is
 *     neither bypassed nor duplicated). 'inconclusive' maps to the
 *     existing no-consequence terminal status 'timeout_no_quorum'
 *     (adjudicator never invoked, no reporter penalty — exactly the
 *     inconclusive semantics D-7 requires).
 *
 * C10: while the poll is open, viewerState exposes ONLY status, windows
 * and the viewer's own ballot facts — no tallies. The closed tally
 * appears only after close (§17.3).
 *
 * @package BCC\Trust\Disputes\Services
 * @since Rank redesign Phase 6 (2026-08-03)
 */

declare(strict_types=1);

namespace BCC\Trust\Disputes\Services;

use BCC\Trust\Disputes\Domain\DisputeStatus;
use BCC\Trust\Disputes\DTO\DisputeCoreDTO;
use BCC\Trust\Disputes\Repositories\DisputeRepository;
use BCC\Trust\Rank\Repositories\PollRepository;
use BCC\Trust\Rank\Services\PollService;
use BCC\Trust\Rank\Support\RankScoringConfig;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type DisputeViewerState array{
 *     dispute_id: int,
 *     status: string,
 *     opened_at: string,
 *     binding_earliest_at: string,
 *     expires_at: string,
 *     viewer: array{my_choice: string|null, my_effective_weight: float|null, can_change: bool, can_withdraw: bool},
 *     outcome?: string|null,
 *     closed_at?: string|null,
 *     tally?: array{counted_voters: int, weight_uphold: float, weight_reject: float}
 * }
 */
class DisputeVoteService
{
    public const POLL_TYPE    = 'dispute';
    public const SUBJECT_TYPE = 'dispute';

    /** Dispute vocabulary → engine ballot choice. */
    private const CHOICE_TO_ENGINE = ['uphold' => 'for', 'reject' => 'against'];

    /** Engine ballot choice → dispute vocabulary. */
    private const CHOICE_TO_DISPUTE = ['for' => 'uphold', 'against' => 'reject'];

    /**
     * Poll outcome → DisputeStatus terminal value. 'inconclusive' reuses
     * the existing no-consequence terminal state (timeout_no_quorum):
     * DisputeResolver::executeAdjudication early-returns on it, so no
     * adjudicator call and no reporter penalty — D-7's "distinct
     * inconclusive resolution, no consequence application".
     */
    private const OUTCOME_TO_DISPUTE = [
        'passed'       => DisputeStatus::ACCEPTED,
        'failed'       => DisputeStatus::REJECTED,
        'inconclusive' => DisputeStatus::TIMEOUT_NO_QUORUM,
    ];

    /** Poll outcome → viewer-facing outcome vocabulary. */
    private const OUTCOME_TO_VIEW = [
        'passed'       => 'upheld',
        'failed'       => 'rejected',
        'inconclusive' => 'inconclusive',
    ];

    public function __construct(
        private readonly PollService $pollService,
        private readonly PollRepository $polls,
        private readonly RankScoringConfig $config
    ) {
    }

    // ── lifecycle ────────────────────────────────────────────────────

    /**
     * Open the community-vote poll for a freshly-created 'reviewing'
     * dispute — the seam where panel assignment used to trigger.
     * Never throws: the dispute row is already committed, so a poll
     * failure must not 500 the submit; the daily backstop sweep
     * (DisputeScheduler → reconcileReviewingDispute) self-heals a
     * missing poll.
     *
     * @return int New poll id, or 0 on failure.
     */
    public function openPollForDispute(int $disputeId): int
    {
        try {
            return $this->pollService->open(self::POLL_TYPE, self::SUBJECT_TYPE, $disputeId);
        } catch (\Throwable $e) {
            \BCC\Core\Log\Logger::error('[bcc-disputes] dispute_poll_open_failed', [
                'dispute_id' => $disputeId,
                'error'      => $e->getMessage(),
            ]);
            return 0;
        }
    }

    // ── ballot surface ───────────────────────────────────────────────

    /**
     * Cast a ballot — or, when the voter already has an active ballot,
     * change it. A same-choice re-submit is an idempotent no-op (never
     * burns recast budget).
     *
     * @param string $choice 'uphold' | 'reject'.
     * @phpstan-return DisputeViewerState
     * @throws DisputeVoteException
     */
    public function castOrChange(int $disputeId, int $voterId, string $choice): array
    {
        $engineChoice = self::CHOICE_TO_ENGINE[$choice] ?? null;
        if ($engineChoice === null) {
            throw new \InvalidArgumentException("dispute vote: choice must be 'uphold' or 'reject'");
        }

        $dispute  = $this->requireDispute($disputeId);
        $poll     = $this->requireOpenPoll($dispute);
        $snapshot = $this->assertEligible($dispute, $voterId);
        $pollId   = (int) $poll->id;

        $state    = $this->pollService->viewerState($pollId, $voterId);
        $myChoice = $state['viewer']['my_choice'];

        try {
            if ($myChoice === null) {
                $this->pollService->cast($pollId, $voterId, $engineChoice, $snapshot);
            } elseif ($myChoice !== $engineChoice) {
                $this->pollService->changeBallot($pollId, $voterId, $engineChoice);
            }
            // Same choice re-submitted → idempotent no-op.
        } catch (\RuntimeException $e) {
            throw $this->mapEngineFailure($e);
        }

        return $this->mapViewerState($disputeId, $pollId, $voterId);
    }

    /**
     * Withdraw the voter's active ballot (engine enforces the 24h
     * cooldown; re-entry later consumes recast budget).
     *
     * @phpstan-return DisputeViewerState
     * @throws DisputeVoteException
     */
    public function withdraw(int $disputeId, int $voterId): array
    {
        $dispute = $this->requireDispute($disputeId);
        $poll    = $this->requireOpenPoll($dispute);
        $pollId  = (int) $poll->id;

        try {
            $this->pollService->withdraw($pollId, $voterId);
        } catch (\RuntimeException $e) {
            throw $this->mapEngineFailure($e);
        }

        return $this->mapViewerState($disputeId, $pollId, $voterId);
    }

    /**
     * The viewer's C10-safe dispute-vote state. Open poll: status +
     * windows + own-ballot facts ONLY. Closed poll: adds outcome,
     * closed_at, and the §17.3 closed tally.
     *
     * @phpstan-return DisputeViewerState
     * @throws DisputeVoteException When the dispute or its poll is missing.
     */
    public function viewerState(int $disputeId, int $viewerId): array
    {
        $this->requireDispute($disputeId);

        $poll = $this->polls->getLatestBySubject(self::POLL_TYPE, self::SUBJECT_TYPE, $disputeId);
        if ($poll === null) {
            // Panel-era terminal disputes (pre-Wave-3) never had a poll.
            throw new DisputeVoteException(
                DisputeVoteException::KIND_NOT_FOUND,
                'poll_missing',
                'This dispute has no community vote.'
            );
        }

        return $this->mapViewerState($disputeId, (int) $poll->id, $viewerId);
    }

    // ── poll-close subscription ──────────────────────────────────────

    /**
     * `bcc_trust_poll_closed` subscriber (positional args — fires AFTER
     * the engine's close transaction commits). Filters poll_type
     * 'dispute' and routes the outcome through the EXISTING resolution
     * machinery; everything downstream of bcc_disputes_async_resolve
     * (DisputeResolver, adjudicator, §18.4 confirmation, reporter
     * penalty gating, notifications) is unchanged.
     */
    public function onPollClosed(int $pollId, string $outcome, string $pollType, string $subjectType, int $subjectId): void
    {
        if ($pollType !== self::POLL_TYPE || $subjectType !== self::SUBJECT_TYPE) {
            return;
        }

        $disputeOutcome = self::OUTCOME_TO_DISPUTE[$outcome] ?? null;
        if ($disputeOutcome === null) {
            \BCC\Core\Log\Logger::error('[bcc-disputes] dispute_poll_closed_unknown_outcome', [
                'poll_id'    => $pollId,
                'outcome'    => $outcome,
                'dispute_id' => $subjectId,
            ]);
            return;
        }

        $dispute = $this->getDispute($subjectId);
        if ($dispute === null) {
            // Poll outlived its dispute (page/user deletion cascade) —
            // nothing to resolve.
            \BCC\Core\Log\Logger::warning('[bcc-disputes] dispute_poll_closed_orphan', [
                'poll_id'    => $pollId,
                'dispute_id' => $subjectId,
            ]);
            return;
        }
        if ($dispute->status !== DisputeStatus::REVIEWING) {
            return; // Admin force-resolve already settled it.
        }

        if (!$this->claimResolution($dispute->id)) {
            return; // Admin/backstop enqueue already in flight.
        }

        $this->enqueueOutcome($dispute, $disputeOutcome, true);
    }

    /**
     * Daily backstop for the poll→dispute seam (called from
     * DisputeScheduler::auto_resolve_expired). Three cases for a
     * dispute stuck in 'reviewing' past the grace period:
     *   - poll missing  → self-heal: open one (submit-time open failed);
     *   - poll open     → nothing to do (the hourly engine sweep owns it);
     *   - poll closed   → the close hook or its async job was lost —
     *     re-drive the SAME outcome application. No claim gate here:
     *     the async handler is idempotent (WHERE status='reviewing'),
     *     and the stuck state usually means the claim is already set.
     */
    public function reconcileReviewingDispute(int $disputeId): void
    {
        $dispute = $this->getDispute($disputeId);
        if ($dispute === null || $dispute->status !== DisputeStatus::REVIEWING) {
            return;
        }

        $poll = $this->polls->getLatestBySubject(self::POLL_TYPE, self::SUBJECT_TYPE, $disputeId);
        if ($poll === null) {
            $healed = $this->openPollForDispute($disputeId);
            \BCC\Core\Log\Logger::warning('[bcc-disputes] dispute_poll_self_healed', [
                'dispute_id' => $disputeId,
                'poll_id'    => $healed,
            ]);
            return;
        }
        if ($poll->status === 'open') {
            return;
        }

        $disputeOutcome = self::OUTCOME_TO_DISPUTE[(string) $poll->outcome] ?? null;
        if ($disputeOutcome === null) {
            \BCC\Core\Log\Logger::error('[bcc-disputes] dispute_reconcile_unknown_outcome', [
                'dispute_id' => $disputeId,
                'outcome'    => $poll->outcome,
            ]);
            return;
        }

        $this->enqueueOutcome($dispute, $disputeOutcome, false);
    }

    // ── internals ────────────────────────────────────────────────────

    /**
     * §18 eligibility, fail closed and in order. Returns the §16.6
     * weight-input snapshot (computed once; PollService::cast stores it
     * verbatim on first cast and ignores it on re-entry).
     *
     * @return array{rank_slug: string|null, maturity: float, rank_multiplier: float, trust_multiplier: float, trust_score: float, fraud_discount: float, effective_weight: float}
     * @throws DisputeVoteException
     */
    private function assertEligible(DisputeCoreDTO $dispute, int $voterId): array
    {
        if ($voterId <= 0) {
            throw $this->forbidden('not_authenticated', 'Sign in to vote on disputes.');
        }
        if (!$this->notSuspended($voterId)) {
            throw $this->forbidden('suspended', 'Suspended accounts cannot vote on disputes.');
        }

        // Apprentice+ (§16.2): a rank_state row with a rank slug.
        $rankRow = $this->rankRow($voterId);
        if ($rankRow === null || (string) $rankRow->rank_slug === '') {
            throw $this->forbidden('rank_required', 'Become an Apprentice to vote on disputes.');
        }

        // Trust Neutral+ — the same canonical tier read the capability
        // gate uses (reputation tier + config tier ordering).
        $tier = $this->tierOf($voterId);
        if ($this->config->tierOrdFor($tier) < $this->config->tierOrdFor('neutral')) {
            throw $this->forbidden('tier_required', 'Reach Neutral standing to vote on disputes.');
        }

        // §18 conflict exclusions: the opener, the disputed voter, and
        // the owner of the page the disputed vote sits on. Owner
        // resolution failing resolves DENY (cannot prove non-party).
        if ($voterId === $dispute->reporter_id || $voterId === $dispute->voter_id) {
            throw $this->forbidden('party_to_dispute', 'Parties to a dispute cannot vote on it.');
        }
        $ownerId = $this->pageOwnerOf($dispute->page_id);
        if ($ownerId <= 0) {
            throw $this->forbidden('party_check_unavailable', 'This dispute is not accepting votes right now.');
        }
        if ($voterId === $ownerId) {
            throw $this->forbidden('party_to_dispute', 'Parties to a dispute cannot vote on it.');
        }

        // §16.6 snapshot + fraud hard-block (FraudDiscountCalculator via
        // the same input assembly VoteService feeds calculate()).
        $snapshot = $this->weightSnapshot($voterId);
        if ($snapshot['fraud_discount'] <= 0.0) {
            throw $this->forbidden('fraud_blocked', 'Voting is temporarily unavailable for this account.');
        }

        return $snapshot;
    }

    /** @throws DisputeVoteException */
    private function requireDispute(int $disputeId): DisputeCoreDTO
    {
        $dispute = $this->getDispute($disputeId);
        if ($dispute === null) {
            throw new DisputeVoteException(
                DisputeVoteException::KIND_NOT_FOUND,
                'dispute_not_found',
                'Dispute not found.'
            );
        }
        return $dispute;
    }

    /**
     * The dispute must still be reviewing AND its poll open.
     *
     * @phpstan-return object{id: numeric-string, status: string}
     * @throws DisputeVoteException
     */
    private function requireOpenPoll(DisputeCoreDTO $dispute): object
    {
        if ($dispute->status !== DisputeStatus::REVIEWING) {
            throw new DisputeVoteException(
                DisputeVoteException::KIND_CLOSED,
                'dispute_resolved',
                'This dispute is no longer open for voting.'
            );
        }

        $poll = $this->polls->getOpenBySubject(self::POLL_TYPE, self::SUBJECT_TYPE, $dispute->id);
        if ($poll === null) {
            throw new DisputeVoteException(
                DisputeVoteException::KIND_CLOSED,
                'poll_closed',
                'The community vote on this dispute is closed.'
            );
        }
        return $poll;
    }

    /**
     * @phpstan-return DisputeViewerState
     */
    private function mapViewerState(int $disputeId, int $pollId, int $viewerId): array
    {
        $state = $this->pollService->viewerState($pollId, $viewerId);

        $myChoice = $state['viewer']['my_choice'];
        $out = [
            'dispute_id'          => $disputeId,
            'status'              => $state['status'],
            'opened_at'           => $state['opened_at'],
            'binding_earliest_at' => $state['binding_earliest_at'],
            'expires_at'          => $state['expires_at'],
            'viewer'              => [
                'my_choice'           => $myChoice !== null ? (self::CHOICE_TO_DISPUTE[$myChoice] ?? null) : null,
                'my_effective_weight' => $state['viewer']['my_effective_weight'],
                'can_change'          => $state['viewer']['can_change'],
                'can_withdraw'        => $state['viewer']['can_withdraw'],
            ],
        ];

        if ($state['status'] !== 'open') {
            $outcome        = $state['outcome'] ?? null;
            $out['outcome'] = $outcome !== null ? (self::OUTCOME_TO_VIEW[$outcome] ?? $outcome) : null;
            $out['closed_at'] = $state['closed_at'] ?? null;
            $tally          = $state['tally'] ?? ['counted_voters' => 0, 'weight_for' => 0.0, 'weight_against' => 0.0];
            $out['tally']   = [
                'counted_voters' => $tally['counted_voters'],
                'weight_uphold'  => $tally['weight_for'],
                'weight_reject'  => $tally['weight_against'],
            ];
        }

        return $out;
    }

    /**
     * Claim (hot path only) happens in the caller; this enqueues the
     * positional async-resolve job the panel used to enqueue — same
     * hook, same args, same downstream machinery.
     */
    private function enqueueOutcome(DisputeCoreDTO $dispute, string $outcome, bool $releaseClaimOnFailure): void
    {
        $enqueued = false;
        try {
            $enqueued = $this->enqueueResolve([
                $dispute->id,
                $dispute->vote_id,
                $dispute->page_id,
                $dispute->voter_id,
                $dispute->reporter_id,
                $outcome,
            ]);
        } catch (\Throwable $e) {
            \BCC\Core\Log\Logger::error('[bcc-disputes] dispute_outcome_enqueue_exception', [
                'dispute_id' => $dispute->id,
                'outcome'    => $outcome,
                'error'      => $e->getMessage(),
            ]);
        }

        if (!$enqueued) {
            if ($releaseClaimOnFailure) {
                // Release our own claim so the daily backstop (or a
                // retry) can re-drive; otherwise the IS NULL guard
                // would block the admin path too.
                $this->releaseResolution($dispute->id);
            }
            \BCC\Core\Log\Logger::error('[bcc-disputes] dispute_outcome_enqueue_soft_failed', [
                'dispute_id' => $dispute->id,
                'outcome'    => $outcome,
            ]);
        }
    }

    /**
     * One place maps the engine's domain RuntimeExceptions onto the
     * REST error kinds. Message fragments are stable strings owned by
     * PollService in this same plugin.
     */
    private function mapEngineFailure(\RuntimeException $e): DisputeVoteException
    {
        $message = $e->getMessage();

        if (str_contains($message, 'recast limit')) {
            return new DisputeVoteException(
                DisputeVoteException::KIND_RECAST_EXHAUSTED,
                'recast_exhausted',
                'You have used both of your vote changes on this dispute.'
            );
        }
        if (str_contains($message, 'cooldown')) {
            return new DisputeVoteException(
                DisputeVoteException::KIND_COOLDOWN,
                'cooldown',
                'You can change or withdraw your vote once every 24 hours.'
            );
        }
        if (str_contains($message, 'no active ballot')) {
            return new DisputeVoteException(
                DisputeVoteException::KIND_NO_BALLOT,
                'no_ballot',
                'You have no active vote on this dispute.'
            );
        }
        // Closed / expired / not-found / concurrent-mutation → the poll
        // is not accepting this action.
        return new DisputeVoteException(
            DisputeVoteException::KIND_CLOSED,
            'poll_closed',
            'The community vote on this dispute is not accepting changes.'
        );
    }

    private function forbidden(string $reason, string $message): DisputeVoteException
    {
        return new DisputeVoteException(DisputeVoteException::KIND_FORBIDDEN, $reason, $message);
    }

    // ── delegate seams (overridden by unit tests) ────────────────────

    protected function getDispute(int $disputeId): ?DisputeCoreDTO
    {
        return DisputeRepository::getDisputeById($disputeId);
    }

    /** @phpstan-return object{rank_slug: string}|null */
    protected function rankRow(int $userId): ?object
    {
        return \BCC\Trust\Core\Plugin::instance()->rankStateRepository()->getForUser($userId);
    }

    protected function tierOf(int $userId): string
    {
        return \BCC\Trust\Core\Plugin::instance()->reputationRepository()->getTier($userId);
    }

    protected function notSuspended(int $userId): bool
    {
        return \BCC\Core\Permissions\Permissions::is_not_suspended($userId, false);
    }

    protected function pageOwnerOf(int $pageId): int
    {
        return \BCC\Trust\Core\Plugin::instance()->pageOwnerResolver()->getPageOwner($pageId);
    }

    /**
     * @return array{rank_slug: string|null, maturity: float, rank_multiplier: float, trust_multiplier: float, trust_score: float, fraud_discount: float, effective_weight: float}
     */
    protected function weightSnapshot(int $userId): array
    {
        return \BCC\Trust\Core\Plugin::instance()->voteService()->assembleBallotWeightSnapshot($userId);
    }

    protected function claimResolution(int $disputeId): bool
    {
        return DisputeRepository::claimResolutionEnqueue($disputeId);
    }

    protected function releaseResolution(int $disputeId): void
    {
        DisputeRepository::releaseResolutionEnqueue($disputeId);
    }

    /**
     * @param list<int|string> $args
     */
    protected function enqueueResolve(array $args): bool
    {
        return DisputeNotificationService::enqueueAsync('bcc_disputes_async_resolve', $args);
    }
}
