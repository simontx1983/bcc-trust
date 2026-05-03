<?php

namespace BCC\Trust\Disputes\Services;

use BCC\Core\Log\Logger as CoreLogger;
use BCC\Core\ServiceLocator;
use BCC\Trust\Disputes\Repositories\DisputeParticipationRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Records a panel-vote participation against the participation table.
 *
 * §D5 — when a panelist casts an accept/reject vote, this service runs
 * AFTER the vote commits (NOT inside the vote's atomic transaction).
 * Rationale: the vote is the user's intentional act and the credit is
 * bookkeeping. A DB hiccup or lock contention while inserting the
 * credit must not nuke the vote.
 *
 * Caps are TRUST-POINT-based, not row-count-based:
 *   - daily: user can earn at most BCC_DISPUTE_PARTICIPATION_DAILY_TRUST_CAP
 *     points of trust contribution from this system in any 24h window
 *   - lifetime: at most BCC_DISPUTE_PARTICIPATION_LIFETIME_TRUST_CAP
 *     points total
 *
 * Per-vote contribution:
 *   - BASE_WEIGHT applies to every credited row
 *   - ACCURACY_WEIGHT applies on top, but only after the user has
 *     accumulated MIN_FOR_ACCURACY credited rows lifetime — prevents
 *     the very first correct vote from spiking score
 *
 * Eligibility short-circuits inserts:
 *   - suspended users → 'suspended' skip
 *   - high / critical fraud score → 'fraud_flag' skip
 *
 * Skipped rows are still INSERTED (with was_credited=0 and a reason)
 * so we have an audit trail of every panel vote, credited or not. The
 * UNIQUE(user_id, dispute_id) constraint catches re-credit attempts;
 * we surface them as success-equivalents.
 *
 * Outcome accuracy backfill (§D5):
 *   This service does NOT set outcome_match — it stays NULL until
 *   DisputeResolver calls
 *   `DisputeParticipationRepository::backfillOutcomeMatch()` after the
 *   dispute resolves.
 */
final class DisputeParticipationService
{
    /**
     * Risk levels that block participation credit. The value strings
     * mirror FraudClassification::level() output.
     */
    private const FRAUD_BLOCK_LEVELS = ['high', 'critical'];

    public function __construct(
        private readonly DisputeParticipationRepository $repo
    ) {
    }

    /**
     * Record a participation row. Idempotent at the DB level.
     *
     * @return array{
     *   credited: bool,
     *   reason: string|null,
     *   today: int,
     *   lifetime: int
     * }
     *   - `credited` true when the row counted toward trust math.
     *   - `reason` is one of the credit_skipped_reason codes, or null
     *     when credited.
     *   - `today` / `lifetime` are post-insert counts so callers can
     *     surface "X / DAILY_CAP today" indicators without a follow-up.
     *
     * Throws \RuntimeException on a real DB error so callers can
     * decide whether to log + continue (the cast-vote controller path
     * does NOT propagate; reconciliation can backfill if needed).
     */
    public function recordParticipation(int $userId, int $disputeId, string $decision): array
    {
        if ($userId <= 0 || $disputeId <= 0) {
            throw new \InvalidArgumentException('userId and disputeId must be positive integers');
        }
        if (!in_array($decision, ['accept', 'reject'], true)) {
            throw new \InvalidArgumentException("decision must be 'accept' or 'reject'");
        }

        // Idempotency pre-check. The UNIQUE index is the authoritative
        // guard, but a cheap SELECT keeps us from issuing INSERTs we
        // know will collide — and lets the caller see "already credited"
        // counts that match reality, not post-collision counts.
        if ($this->repo->hasParticipated($userId, $disputeId)) {
            return [
                'credited' => false,
                'reason'   => 'already_recorded',
                'today'    => $this->repo->countCreditedToday($userId),
                'lifetime' => $this->repo->countCreditedLifetime($userId),
            ];
        }

        // 1. Eligibility — suspended users get an audit row but no credit.
        $trustRead = ServiceLocator::resolveTrustReadService();
        if ($trustRead->isSuspended($userId)) {
            return $this->insertSkipped($userId, $disputeId, $decision, 'suspended');
        }

        // 2. Fraud / risk gate. We resolve risk via UserInfoRepository →
        //    FraudClassification (the same path the rest of the engine
        //    uses). 'high' or 'critical' = skip; anything else = proceed.
        $userInfoRepo = \BCC\Trust\Core\Plugin::instance()->userInfoRepository();
        $riskLevel = $userInfoRepo->getRiskLevel($userId);
        if (in_array($riskLevel, self::FRAUD_BLOCK_LEVELS, true)) {
            return $this->insertSkipped($userId, $disputeId, $decision, 'fraud_flag');
        }

        // 3. Daily trust cap. The cap is denominated in trust points,
        //    not row counts — at BASE=0.01 / ACCURACY=0.02, hitting 1.0
        //    point in 24h would require roughly 33 correct votes, which
        //    is implausible at typical panel volumes. The cap is a
        //    safety ceiling, not the binding constraint.
        if ($this->repo->getEarnedDailyTrust($userId) >= (float) BCC_DISPUTE_PARTICIPATION_DAILY_TRUST_CAP) {
            return $this->insertSkipped($userId, $disputeId, $decision, 'daily_cap');
        }

        // 4. Lifetime trust cap. This IS the binding constraint — once
        //    a user has earned the lifetime maximum from this system,
        //    further votes are recorded for audit but contribute zero.
        if ($this->repo->getEarnedLifetimeTrust($userId) >= (float) BCC_DISPUTE_PARTICIPATION_LIFETIME_TRUST_CAP) {
            return $this->insertSkipped($userId, $disputeId, $decision, 'total_cap');
        }

        // 5. Insert credited.
        $result = $this->repo->recordParticipation($userId, $disputeId, $decision, true, null);

        if ($result['inserted']) {
            CoreLogger::audit('dispute_participation_credited', [
                'user_id'    => $userId,
                'dispute_id' => $disputeId,
                'decision'   => $decision,
            ]);
            // Report POST-insert row counts so the toast/header can
            // surface "X votes today" without a follow-up fetch.
            return [
                'credited' => true,
                'reason'   => null,
                'today'    => $this->repo->countCreditedToday($userId),
                'lifetime' => $this->repo->countCreditedLifetime($userId),
            ];
        }

        if ($result['duplicate']) {
            // Race: between hasParticipated() and insert, another path
            // (reconciliation? double-tap?) inserted the same row. UNIQUE
            // caught it. Treat as success-equivalent — the credit exists.
            return [
                'credited' => false,
                'reason'   => 'already_recorded',
                'today'    => $this->repo->countCreditedToday($userId),
                'lifetime' => $this->repo->countCreditedLifetime($userId),
            ];
        }

        // Real DB error — fail loud. The caller in cast_vote logs and
        // returns vote success anyway (per "vote is critical path").
        throw new \RuntimeException(
            'DisputeParticipationService::recordParticipation insert failed: '
            . ($result['db_error'] ?? 'unknown')
        );
    }

    /**
     * Insert a skipped (was_credited=0) row with a reason code. Returns
     * the same result shape as the credited path so the caller doesn't
     * branch on success/skip semantically — the `credited` flag tells
     * them whether the trust score moved.
     *
     * @return array{credited: bool, reason: string, today: int, lifetime: int}
     */
    private function insertSkipped(int $userId, int $disputeId, string $decision, string $reason): array
    {
        $result = $this->repo->recordParticipation($userId, $disputeId, $decision, false, $reason);

        if (!$result['inserted'] && !$result['duplicate']) {
            // Skip-row INSERT failed for a non-duplicate reason — log,
            // but still report the skip to the caller (the user's vote
            // succeeded; we just couldn't record the audit row).
            CoreLogger::error('[bcc-trust] participation_skip_insert_failed', [
                'user_id'    => $userId,
                'dispute_id' => $disputeId,
                'reason'     => $reason,
                'db_error'   => $result['db_error'],
            ]);
        }

        // Counts AFTER the (failed) skip insert — skipped rows don't
        // change credited counts regardless.
        return [
            'credited' => false,
            'reason'   => $reason,
            'today'    => $this->repo->countCreditedToday($userId),
            'lifetime' => $this->repo->countCreditedLifetime($userId),
        ];
    }

    /**
     * Convenience accessor for callers (e.g. /me/participation endpoint
     * or trust-score scorer) that want the canonical participation
     * status without re-implementing the count queries.
     *
     * Returns BOTH row counts (for "X votes today" UI) and clamped
     * trust contributions (for "Y trust points earned" UI). Callers
     * pick the lens that fits the surface.
     *
     * @return array{
     *   today: int,
     *   lifetime: int,
     *   correct: int,
     *   earned_daily: float,
     *   earned_lifetime: float
     * }
     */
    public function getStatus(int $userId): array
    {
        return [
            'today'           => $this->repo->countCreditedToday($userId),
            'lifetime'        => $this->repo->countCreditedLifetime($userId),
            'correct'         => $this->repo->countCorrect($userId),
            'earned_daily'    => $this->repo->getEarnedDailyTrust($userId),
            'earned_lifetime' => $this->repo->getEarnedLifetimeTrust($userId),
        ];
    }
}
