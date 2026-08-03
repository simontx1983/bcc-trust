<?php
/**
 * Vote Writer
 *
 * Write stage of the vote pipeline. Persists the vote row and the updated
 * page score inside a single database transaction. Nothing else.
 *
 * Locking order (must match VoteService::removePageVote):
 *   1. Vote row  — acquired by VoteRepository::upsertRaw() via FOR UPDATE
 *   2. Score row — updated AFTER the vote upsert commits its lock
 *
 * Responsibilities:
 *  - Upsert the vote row via VoteRepository (acquires lock first)
 *  - Apply score delta via ScoreRepository conditioned on upsert result
 *
 * Explicitly NOT responsible for:
 *  - Fraud analysis or scoring
 *  - Audit logging (handled by VoteAuditor after commit)
 *  - Edge table recalculation (post-commit, handled by VoteAuditor)
 *  - Voter stats refresh (async, handled by StatsRefresher)
 *  - Any business logic beyond writing what it receives
 *
 * @package BCC\Trust\Core\Services\Vote
 * @version 2.0.0
 */

namespace BCC\Trust\Core\Services\Vote;

use BCC\Trust\Core\Repositories\ScoreRepository;
use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Security\IpResolver;
use BCC\Trust\Core\Security\TransactionManager;
use BCC\Trust\Core\ValueObjects\VoteWeight;

if (!defined('ABSPATH')) {
    exit;
}

class VoteWriter {

    public function __construct(
        private readonly VoteRepository     $voteRepo,
        private readonly ScoreRepository    $scoreRepo,
        private readonly UserInfoRepository $userInfoRepo,
    ) {}

    /**
     * Persist a vote and its score delta inside a single transaction.
     *
     * Locking order: vote row first (FOR UPDATE inside upsertRaw), score row
     * second (UPDATE inside applyVoteDeltaUpsert / reverseAndReapplyDelta).
     * This order must be kept consistent with VoteService::removePageVote() to
     * prevent deadlocks.
     *
     * Score branch logic:
     *   was_inserted = true              → new vote: apply delta normally
     *   vote_type_changed = true         → direction flip: reverse old delta, apply new
     *   was_inserted = false, no change  → idempotent retry: no score write
     *
     * @param int         $voterId         Authenticated voter's user ID.
     * @param int         $pageId          Target page.
     * @param int         $categoryId      Category (0 for uncategorised).
     * @param int         $ownerId         Page owner user ID (used for score row creation).
     * @param int         $voteType        1 = upvote, -1 = downvote.
     * @param VoteWeight  $weight          Fully calculated weight value object.
     * @param bool        $isNewVoter      True if the voter has no prior vote on this page.
     * @param string|null $downvoteReason  Sanitized reason string, or null for upvotes.
     * @param float|null  $preCappedWeight When non-null, skip the internal applyVelocityCap()
     *                                     call and use this already-capped weight instead.
     *                                     Used by VoteService::castPageVote() to avoid
     *                                     consuming the daily velocity budget N times on
     *                                     multi-category pages.
     * @param bool|null   $incrementUniqueVoter When non-null, controls unique_voters increment
     *                                          independently of $isNewVoter. Pass true only for
     *                                          the first category to prevent over-counting.
     *
     * @return array{vote_id: int, was_inserted: bool, vote_type_changed: bool, weight_changed: bool, capped_weight: float|null}
     * @throws \Exception  Propagated from TransactionManager on failure; triggers rollback.
     */
    public function write(
        int        $voterId,
        int        $pageId,
        int        $categoryId,
        int        $ownerId,
        int        $voteType,
        VoteWeight $weight,
        bool       $isNewVoter,
        ?string    $downvoteReason,
        ?float     $preCappedWeight = null,
        ?bool      $incrementUniqueVoter = null
    ): array {
        // Page-scoped advisory lock. Matches the key used by
        // VoteService::recalculateScore so a concurrent new-category
        // INSERT path cannot slip past the recalc's FOR UPDATE on
        // pre-existing rows. Acquired BEFORE the transaction starts,
        // released in finally — never held across an uncaught exception.
        $lockKey      = 'bcc_score_page_' . $pageId;
        $lockAcquired = false;
        if (class_exists('\\BCC\\Core\\DB\\AdvisoryLock')) {
            $lockAcquired = \BCC\Core\DB\AdvisoryLock::acquire($lockKey, 10);
        }

        try {
        $result = TransactionManager::run(
            function () use (
                $voterId, $pageId, $categoryId, $ownerId,
                $voteType, $weight, $downvoteReason, $preCappedWeight,
                $incrementUniqueVoter
            ) {
                // ── Step 0: ensure score row exists ───────────────────────────
                // INSERT IGNORE on (page_id, category_id=0). Guarantees the
                // ODKU UPDATE branch in applyVoteDeltaUpsert is always taken
                // for the category-0 path, so a concurrent bonus writer can
                // never race the first-vote INSERT and clobber the bonus
                // columns. For non-zero categories, applyVoteDeltaUpsert
                // still takes its INSERT branch (new category row, bonuses
                // legitimately start at 0 per current schema).
                $this->scoreRepo->createIfNotExists($pageId, $ownerId);

                // ── Step 1: upsert vote row (acquires FOR UPDATE lock) ────────
                $upsert = $this->voteRepo->upsertRaw(
                    $this->buildVoteData($voterId, $pageId, $categoryId, $voteType, $weight, $downvoteReason)
                );

                // ── Step 1b: reset vesting on material vote change ────────
                // When a user flips vote direction or the vote weight changes,
                // the vesting clock must restart at stage 0 (30% weight).
                // Without this, a fully vested vote can be instantly converted
                // Vote-time vesting reset retired (Rank Phase 6): the §16.6
                // formula's only vesting is the maturity term, which lives on
                // the MEMBER (apprentice epoch), not on the vote row — a
                // changed vote recomputes at the voter's current weight.

                // ── Step 2: apply velocity cap + score delta ────────────────
                //
                // Enforce BCC_TRUST_MAX_SCORE_CHANGE_PER_DAY before applying
                // the delta. The velocity cap is ONLY consumed when the upsert
                // actually changed state — an idempotent retry (was_inserted
                // = false, no type or weight change) must not burn budget.
                //
                // The score-delta and weight-correction blocks both live under
                // the same gate, so recalc (SUM(v.weight)) stays aligned with
                // the incremental score (uses cappedWeight).
                $cappedWeight = null;

                // Vesting enforcement: score writes use $weight->vested
                // (effective × stage multiplier), not $weight->effective.
                // Without this, new voters' stage-0 10% votes moved the
                // score at full effective weight — defeating the documented
                // Sybil-mitigation vesting layer. The stored `weight`
                // column continues to carry effective for audit/reversal,
                // and the recalc SUM path now sums `vested_weight` so the
                // authoritative recalculation stays aligned with the
                // incremental delta written here.
                $scoreWeight = $weight->vested;
                if ($upsert['was_inserted']) {
                    $cappedWeight = $preCappedWeight ?? $this->scoreRepo->applyVelocityCap(
                        $pageId,
                        $scoreWeight
                    );
                    $this->scoreRepo->applyVoteDeltaUpsert(
                        $pageId,
                        $categoryId,
                        $ownerId,
                        $cappedWeight,
                        $voteType === 1,
                        $upsert['was_inserted'],
                        $incrementUniqueVoter
                    );
                } elseif ($upsert['vote_type_changed'] || !empty($upsert['weight_changed'])) {
                    // Direction flip or weight change — reverse old delta and apply new one.
                    // previous_weight carries the `weight` column's prior value, which
                    // correctWeight() rewrites to the capped vested amount after the
                    // first write — so this IS the actual previously-applied delta.
                    $previousWeight = (float) $upsert['previous_weight'];

                    if ($preCappedWeight !== null) {
                        // Velocity cap already applied by the caller (multi-category path).
                        $cappedWeight = $preCappedWeight;
                    } elseif ($upsert['vote_type_changed']) {
                        // Direction flip: the score swings by (previousWeight + newWeight) * 2
                        // points. Both the reversal and the new direction must be tracked
                        // against the velocity budget to prevent flip-farming attacks where
                        // an attacker rapidly alternates vote direction to bypass the daily
                        // score-change cap. The previous weight reversal is fixed (can't be
                        // capped retroactively), so we cap the new weight from the remainder.
                        $fullSwing   = $previousWeight + $scoreWeight;
                        $cappedSwing = $this->scoreRepo->applyVelocityCap($pageId, $fullSwing);
                        $cappedWeight = max(0.0, $cappedSwing - $previousWeight);
                    } else {
                        // Same-direction weight change — cap the new weight directly.
                        $cappedWeight = $this->scoreRepo->applyVelocityCap(
                            $pageId,
                            $scoreWeight
                        );
                    }

                    $this->scoreRepo->reverseAndReapplyDelta(
                        $pageId,
                        $categoryId,
                        $previousWeight,
                        ($upsert['previous_vote_type'] ?? $voteType) === 1,
                        $cappedWeight,
                        $voteType === 1
                    );
                }
                // Identical retry → no score write needed, no velocity consumed.

                // ── Step 2b: persist the capped vested weight onto the vote row ────
                //
                // The score delta was applied using $cappedWeight (derived
                // from $weight->vested). The stored vested_weight should
                // match so the recalc SUM path reads the same value the
                // incremental path wrote. Without this correction, recalc's
                // SUM(vested_weight) would reverse the velocity clamp on
                // every tick. Idempotent retries (cappedWeight null) skip.
                if ($cappedWeight !== null
                    && abs($cappedWeight - $weight->vested) > 0.0001
                ) {
                    $this->voteRepo->correctWeight($upsert['vote_id'], $cappedWeight);
                }

                // ── Step 3: flag for authoritative recalculation (inside tx) ──
                //
                // The delta above gives instant feedback using the vote's raw
                // stored weight. Flagging for recalculation ensures the 5-minute
                // cron will run a full authoritative recalculation.
                //
                // This runs INSIDE the transaction so that:
                //  - On success: flag becomes visible at the same instant as the
                //    vote data (no TOCTOU gap where vote is committed but flag
                //    is not yet set, which could cause the cron to miss it).
                //  - On failure: flag rolls back with the vote (correct — nothing
                //    changed, no recalculation needed).
                if ($upsert['was_inserted'] || $upsert['vote_type_changed'] || !empty($upsert['weight_changed'])) {
                    $this->scoreRepo->flagForRecalculation($pageId);
                }

                return [
                    'vote_id'           => $upsert['vote_id'],
                    'was_inserted'      => $upsert['was_inserted'],
                    'vote_type_changed' => $upsert['vote_type_changed'],
                    'weight_changed'    => $upsert['weight_changed'],
                    // Null when this call was an idempotent retry (no cap
                    // applied). Otherwise the velocity-capped weight that
                    // was consumed from the daily budget; VoteService
                    // propagates it to subsequent category writes so the
                    // budget is consumed exactly once per vote.
                    'capped_weight'     => $cappedWeight,
                ];
            }
        );

        return $result;
        } finally {
            if ($lockAcquired && class_exists('\\BCC\\Core\\DB\\AdvisoryLock')) {
                \BCC\Core\DB\AdvisoryLock::release($lockKey);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build the data array expected by VoteRepository::upsertRaw().
     * All field names match the votes table schema exactly.
     *
     * @return array<string, mixed>
     */
    private function buildVoteData(
        int        $voterId,
        int        $pageId,
        int        $categoryId,
        int        $voteType,
        VoteWeight $weight,
        ?string    $downvoteReason
    ): array {
        return [
            'voter_user_id'       => $voterId,
            'page_id'             => $pageId,
            'category_id'         => $categoryId,
            'vote_type'           => $voteType,
            'weight'              => $weight->effective,
            'vested_weight'       => $weight->vested,
            'fraud_score_at_vote' => $this->currentFraudScore($voterId),
            'vesting_stage'       => $weight->vestingStage,
            'vesting_started_at'  => $weight->vestingStartedAt,
            'fully_vested_at'     => $weight->fullyVestedAt,
            'reason'              => $downvoteReason,
            'explanation'         => null,
            'ip_address'          => $this->resolveIpBinary(),
        ];
    }

    /**
     * Read the voter's current fraud score snapshot for the vote row.
     * Returns 0 if the user_info record does not yet exist.
     */
    private function currentFraudScore(int $voterId): int {
        $info = $this->userInfoRepo->getByUserId($voterId);
        return $info ? (int) $info->fraud_score : 0;
    }

    /**
     * Resolve the client IP to binary for storage.
     * Returns null when the IP is unavailable or is the zero address.
     *
     * @return string|null  16-byte packed binary string, or null.
     */
    private function resolveIpBinary(): ?string {
        $ip = IpResolver::getClientIp();
        if (!$ip || $ip === '0.0.0.0') {
            return null;
        }
        $binary = inet_pton($ip);
        return ($binary !== false) ? $binary : null;
    }
}
