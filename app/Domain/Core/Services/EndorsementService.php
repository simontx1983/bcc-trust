<?php
/**
 * Endorsement Services
 *
 * Handles users endorsing PeepSo Pages with enhanced fraud protection
 * Updated to use PageScore value objects and user_info table
 *
 * @package BCC\Trust\Core\Services
 * @version 2.1.0
 */

namespace BCC\Trust\Core\Services;

use Exception;
use BCC\Trust\Core\Exceptions\EndorsementException;
use BCC\Trust\Core\Repositories\EndorsementRepository;
use BCC\Trust\Core\Repositories\ScoreRepository;
use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Repositories\VerificationRepository;
use BCC\Trust\Core\Security\TransactionManager;
use BCC\Trust\Core\Security\RateLimiter;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Services\PeepSoPageResolver;
use BCC\Trust\Core\Services\EndorsementWeightCalculator;
use BCC\Trust\Core\Services\EndorsementFraudOrchestrator;
use BCC\Trust\Core\Services\PageDataLoader;
use BCC\Trust\Core\Support\CacheManager;
use BCC\Trust\Core\ValueObjects\PageScore;

if (!defined('ABSPATH')) {
    exit;
}

class EndorsementService {

    private EndorsementRepository $endorsementRepo;
    private ScoreRepository $scoreRepo;
    private UserInfoRepository $userInfoRepo;
    private VerificationRepository $verificationRepo;
    private EndorsementQueryService $queryService;
    private EndorsementWeightCalculator $weightCalculator;
    private EndorsementFraudOrchestrator $fraudOrchestrator;

    public function __construct(
        EndorsementRepository  $endorsementRepo,
        ScoreRepository        $scoreRepo,
        UserInfoRepository     $userInfoRepo,
        VerificationRepository $verificationRepo
    ) {
        $this->endorsementRepo   = $endorsementRepo;
        $this->scoreRepo         = $scoreRepo;
        $this->userInfoRepo      = $userInfoRepo;
        $this->verificationRepo  = $verificationRepo;
        $this->queryService      = new EndorsementQueryService();
        $this->weightCalculator  = new EndorsementWeightCalculator(
            $this->scoreRepo,
            $this->verificationRepo
        );
        $p = \BCC\Trust\Core\Plugin::instance();
        $this->fraudOrchestrator = new EndorsementFraudOrchestrator(
            $this->scoreRepo,
            $p->fraudAnalysisRepository(),
            $p->deviceFingerprinter()
        );
    }

    /**
     * Add endorsement to a page
     *
     * @param array<string, mixed>|null $fingerprintData
     * @return array<string, mixed>
     */
    public function endorsePage(int $pageId, string $context = 'general', ?string $reason = null, ?array $fingerprintData = null): array {
        if (!is_user_logged_in()) {
            throw EndorsementException::authRequired('Authentication required');
        }

        $endorserUserId = get_current_user_id();

        // Verify page exists
        $page = PeepSoPageResolver::resolve($pageId);
        if (!$page) {
            throw EndorsementException::invalidPage('Invalid page.');
        }

        // Get page owner
        $pageOwnerId = PeepSoPageResolver::getOwnerId($pageId);

        // Can't endorse your own page. No role-based exemption: admins on a
        // production site would otherwise be able to self-inflate their own
        // trust score past every fraud and Sybil gate. A BCC_TRUST_TEST_MODE
        // constant is available for automated-test environments only.
        $testMode = defined('BCC_TRUST_TEST_MODE') && \BCC_TRUST_TEST_MODE;
        if ($pageOwnerId === $endorserUserId && !$testMode) {
            throw EndorsementException::selfPage('You cannot endorse your own page');
        }

        // Fire pre-mutation snapshot hook so ScoreMutationLogger can capture
        // score_before for the audit trail. Must run before any mutating step.
        do_action('bcc_trust_endorsement_pre', $endorserUserId, $pageId);

        // NOTE: The duplicate-endorsement check has been moved INSIDE the
        // TransactionManager::run() closure below (after the FOR UPDATE lock
        // in EndorsementRepository::create()). Checking here outside the
        // transaction was a TOCTOU race — two concurrent requests could both
        // pass this check before either entered the transaction.

        // Quest gate: endorsements require completing enough onboarding quests
        // (milestone-based) including at least one identity verification.
        // Only BCC_TRUST_TEST_MODE may bypass — roles never do.
        if (!$testMode) {
            $questService = \BCC\Trust\Core\Plugin::instance()->questProgressService();
            if (!$questService->canEndorse($endorserUserId)) {
                $progress = $questService->getEndorseProgress($endorserUserId);
                throw EndorsementException::softGate(
                    is_string($progress['missing_reason'] ?? null) && $progress['missing_reason'] !== ''
                        ? (string) $progress['missing_reason']
                        : 'Complete your onboarding quests to unlock endorsements.'
                );
            }
        }

        RateLimiter::enforce('endorse', BCC_TRUST_RATE_LIMIT_ENDORSE, BCC_TRUST_RATE_WINDOW_ENDORSE);

        // ── Defense: Account age gate (Sybil mitigation) ────────────────
        // New accounts cannot endorse until they have existed for a minimum
        // number of days. This raises the cost of Sybil attacks: creating
        // 10 fresh accounts and endorsing the same day is now impossible.
        // Only BCC_TRUST_TEST_MODE may bypass — roles never do.
        if (!$testMode) {
            $endorserUser = get_userdata($endorserUserId);
            if ($endorserUser instanceof \WP_User) {
                $registeredTs   = strtotime($endorserUser->user_registered);
                $accountAgeDays = $registeredTs !== false
                    ? (int) floor((time() - $registeredTs) / DAY_IN_SECONDS)
                    : 0;
                /** @var int $minAgeDays */
                $minAgeDays = (int) apply_filters('bcc_trust_endorse_min_account_age_days', 7);
                if ($accountAgeDays < $minAgeDays) {
                    throw EndorsementException::softGate(
                        sprintf('Your account must be at least %d days old to endorse pages.', $minAgeDays)
                    );
                }
            }
        }

        // ── Defense: Fraud score gate (pre-transaction, defense-in-depth) ─
        // Users with high fraud scores are blocked from endorsing. The fraud
        // orchestrator below also checks this, but an explicit early gate
        // avoids wasting compute on weight calculation and fingerprinting.
        // Only BCC_TRUST_TEST_MODE may bypass — roles never do.
        if (!$testMode) {
            $endorserInfo = $this->userInfoRepo->getByUserId($endorserUserId);
            if ($endorserInfo !== null && (int) $endorserInfo->fraud_score >= BCC_TRUST_FRAUD_HIGH) {
                throw EndorsementException::fraudLocked('Your account has been flagged for unusual activity. Endorsements are temporarily restricted.');
            }
        }

        // Cap and coordination checks are performed inside the transaction
        // (see TransactionManager::run below) to prevent TOCTOU races where
        // concurrent requests both pass the check before either commits.

        // ======================================================
        // FRAUD DETECTION & RISK ANALYSIS
        // Blocking: fraud score > HIGH, vote ring membership
        // Signal-only: fingerprinting, behavioral, trust rank
        // ======================================================

        $fraudChecks = $this->fraudOrchestrator->runPreEndorsementChecks(
            $endorserUserId,
            $pageId,
            $fingerprintData
        );

        $fraudAnalysis    = $fraudChecks['fraud_analysis'];
        $automationData   = $fraudChecks['automation_data'];
        $multiAccountRisk = $fraudChecks['multi_account_risk'];
        $behavior         = $fraudChecks['behavior'];
        $trustRank        = $fraudChecks['trust_rank'];

        // ======================================================
        // CALCULATE FINAL ENDORSEMENT WEIGHT
        // ======================================================

        $baseWeight = $this->calculateBaseEndorserWeight($endorserUserId);
        
        $adjustedWeight = $this->applyFraudAdjustments(
            $baseWeight,
            $fraudAnalysis,
            $automationData,
            $multiAccountRisk,
            $behavior,
            $trustRank,
            $endorserUserId
        );

        // ── Defense: Endorsement vesting (10% at stage 0) ────────────────
        $vestingFactor = BCC_TRUST_VESTING_STAGE_0_PCT;
        $vestedWeight  = $adjustedWeight * $vestingFactor;

        // ── Defense: Source diversity factor ─────────────────────────────
        $diversityFactor = $this->calculateDiversityFactor($pageId, $endorserUserId);
        $vestedWeight   *= $diversityFactor;

        // ── Defense: Endorsement-to-vote ratio detection ────────────────
        $this->fraudOrchestrator->checkEndorsementVoteRatio($pageId);

        // ── Defense: Temporal coordination detection ────────────────────
        $this->fraudOrchestrator->detectTemporalCoordination($pageId, $endorserUserId);

        AuditLogger::log('endorse_analysis', $pageId, [
            'endorser_id' => $endorserUserId,
            'base_weight' => $baseWeight,
            'adjusted_weight' => $adjustedWeight,
            'vesting_factor' => $vestingFactor,
            'diversity_factor' => $diversityFactor,
            'final_weight' => $vestedWeight,
            'fraud_score' => $fraudAnalysis['score'],
            'automation_detected' => $automationData['is_automated'],
            'behavior_score' => $behavior['behavior_score'] ?? 0,
            'trust_rank' => $trustRank
        ], 'page');

        // ── Defense: serialise per-user and per-page caps with advisory locks ─
        // The cap-counting reads inside the transaction (assertDailyEndorsementCap,
        // assertPerEndorserDailyCap, assertNoCoordination) issue COUNT(DISTINCT …)
        // queries that are NOT covered by the FOR UPDATE on (endorser, page, ctx).
        // Without coarser serialisation, N parallel endorsements on different
        // (endorser, page) tuples would each observe count = N-1 and all commit,
        // breaching the cap.
        //
        // LOCK SCOPE FIX (C2): locks are acquired OUTSIDE the TransactionManager::run()
        // closure so that RELEASE_LOCK fires AFTER COMMIT. Previously the finally
        // block released locks inside the closure — the closure returned, the waiter
        // woke up, and its COUNT ran before the predecessor's COMMIT made its INSERT
        // visible under READ COMMITTED / REPEATABLE READ. Both requests then passed
        // the cap check and the cap was breached.
        //
        // Held across deadlock-retries: TransactionManager::run re-invokes the
        // closure on deadlock. The same connection already owns the GET_LOCK, so
        // no other worker can slip in between retries — that's exactly the
        // serialization we want.
        $userLockKey  = 'bcc_endorse_u_' . $endorserUserId;
        $pageLockKey  = 'bcc_endorse_p_' . $pageId;
        // Cross-mutex with VoteWriter: same key used in VoteWriter::write so
        // vote and endorse paths never interleave their SUM snapshots /
        // score row writes on one page.
        $scoreLockKey = 'bcc_score_page_' . $pageId;

        if (!$this->endorsementRepo->acquireConcurrencyLock($userLockKey, 5)) {
            throw EndorsementException::busy('Endorsement system is busy. Please try again in a moment.');
        }
        if (!$this->endorsementRepo->acquireConcurrencyLock($pageLockKey, 5)) {
            $this->endorsementRepo->releaseConcurrencyLock($userLockKey);
            throw EndorsementException::busy('Endorsement system is busy. Please try again in a moment.');
        }
        $scoreLockAcquired = false;
        if (class_exists('\\BCC\\Core\\DB\\AdvisoryLock')) {
            $scoreLockAcquired = \BCC\Core\DB\AdvisoryLock::acquire($scoreLockKey, 5);
            if (!$scoreLockAcquired) {
                $this->endorsementRepo->releaseConcurrencyLock($pageLockKey);
                $this->endorsementRepo->releaseConcurrencyLock($userLockKey);
                throw EndorsementException::busy('Endorsement system is busy. Please try again in a moment.');
            }
        }

        try {
            $endorsementResult = TransactionManager::run(function () use (
                $endorserUserId, $pageId, $context, $reason, $pageOwnerId,
                $vestedWeight, $adjustedWeight, $automationData, $fraudAnalysis,
                $baseWeight, $vestingFactor, $diversityFactor
            ) {
            // ── Defense: Duplicate-endorsement check (inside transaction) ──
            // The FOR UPDATE lock in EndorsementRepository::create() will
            // serialise concurrent requests, but we check here first to
            // avoid computing the bonus and then discarding it.
            if ($this->hasEndorsedPage($pageId, $endorserUserId, $context)) {
                throw EndorsementException::alreadyEndorsed('You have already endorsed this page');
            }

            // ── Defense: Re-check fraud score inside transaction ────────
            // The pre-transaction fraud gate (above) may be stale by the
            // time we reach here. Re-read the authoritative fraud_score
            // to close the TOCTOU window.
            // Only BCC_TRUST_TEST_MODE may bypass — roles never do.
            $testModeInTx = defined('BCC_TRUST_TEST_MODE') && \BCC_TRUST_TEST_MODE;
            if (!$testModeInTx) {
                $freshUserInfo = $this->userInfoRepo->getByUserId($endorserUserId);
                if ($freshUserInfo !== null && (int) $freshUserInfo->fraud_score >= BCC_TRUST_FRAUD_HIGH) {
                    throw EndorsementException::fraudLocked('Your account has been flagged for unusual activity. Endorsements are temporarily restricted.');
                }
            }

            // ── Defense: Daily endorsement cap + coordination check ─────
            // Inside the transaction to prevent TOCTOU races. The outer
            // advisory locks guarantee release happens after COMMIT, so
            // these COUNTs see the committed state of the predecessor.
            $this->assertDailyEndorsementCap($pageId);
            $this->assertPerEndorserDailyCap($endorserUserId);
            $this->assertNoCoordination($pageId, $endorserUserId);

            // Create endorsement with vested weight (not full weight).
            // Store fraud_score at endorsement time for retroactive discount
            // during cron recalculation (same pattern as votes).
            // Store adjustedWeight as base_weight — the fraud-adjusted weight
            // BEFORE vesting and diversity. Recalculation recomputes the rest
            // dynamically from created_at and current page state.
            $endorsementId = $this->endorsementRepo->create(
                $endorserUserId,
                $pageId,
                $context,
                $vestedWeight,
                $reason,
                (int) $fraudAnalysis['score'],
                $adjustedWeight
            );

            // Apply endorsement bonus using PageScore
            $this->applyEndorsementBonus($pageId, $vestedWeight);

            // NOTE: updateEndorserStats + updateFraudScore moved post-commit
            // (audit H-7). They write to user_info — holding user_info row
            // locks alongside endorsement + score row locks expanded the
            // critical section and created deadlock windows with concurrent
            // fraud-score updaters. The fraud-score cache clear in the
            // orchestrator + subsequent re-analysis on the next endorse is
            // still authoritative.

            // Log the endorsement
            AuditLogger::endorse($pageId, $context, [
                'endorser_id' => $endorserUserId,
                'page_owner_id' => $pageOwnerId,
                'weight' => $adjustedWeight,
                'endorsement_id' => $endorsementId,
                'fraud_score' => $fraudAnalysis['score'],
                'automation_score' => $automationData['confidence']
            ]);

            return [
                'endorsement_id' => $endorsementId,
                'analysis' => [
                    'weight_applied' => $vestedWeight,
                    'base_weight' => $baseWeight,
                    'adjusted_weight' => $adjustedWeight,
                    'vesting_factor' => $vestingFactor,
                    'diversity_factor' => $diversityFactor,
                    'vesting_stage' => 0,
                    'fraud_score' => $fraudAnalysis['score'],
                    'risk_level' => $fraudAnalysis['risk_level'],
                    'automation_detected' => $automationData['is_automated']
                ],
            ];
            });
        } finally {
            // Release AFTER the TransactionManager::run returns (commit or
            // final rethrow). This is what couples lock handoff to commit
            // visibility — any waiter entering the critical section now
            // sees the committed INSERT under any isolation level.
            // Release in reverse acquisition order.
            if ($scoreLockAcquired && class_exists('\\BCC\\Core\\DB\\AdvisoryLock')) {
                \BCC\Core\DB\AdvisoryLock::release($scoreLockKey);
            }
            $this->endorsementRepo->releaseConcurrencyLock($pageLockKey);
            $this->endorsementRepo->releaseConcurrencyLock($userLockKey);
        }

        // ── Post-commit user_info updates (audit H-7) ───────────────────
        // Moved out of the endorse transaction: these touch user_info, which
        // async fraud analysers and concurrent endorses also write. Keeping
        // them inside the tx held the user_info row lock alongside
        // endorsement/score locks and expanded the deadlock surface.
        $this->updateEndorserStats($endorserUserId);
        $this->fraudOrchestrator->updateFraudScore($endorserUserId, $fraudAnalysis, $automationData);

        // ── Post-transaction: invalidate caches, read fresh state ───────
        // Cache invalidation MUST happen after commit but BEFORE any read
        // so that getByPageId() / getVoteCountsByType() return post-mutation
        // data. Includes user_info for the endorser (endorsements_given changed).
        CacheManager::invalidatePageCaches($pageId, $endorserUserId, 'endorse');

        /**
         * Fires after an endorsement is recorded and all caches are cleared.
         *
         * @param int    $endorserUserId The endorser's user ID.
         * @param int    $pageId         The endorsed page ID.
         * @param string $context        Endorsement context (e.g. 'general').
         */
        do_action('bcc_trust_endorsement_added', $endorserUserId, $pageId, $context);

        // ── Async fraud re-analysis (post-commit, mirrors VoteFraudAnalyzer) ──
        // Runs on next cron tick to re-evaluate fraud signals and flag pages
        // for recalculation if the endorser's fraud score increased.
        EndorsementFraudAnalyzer::schedule($endorserUserId, $pageId);

        // Fresh reads from DB (caches were just cleared)
        $freshScore     = $this->scoreRepo->getByPageId($pageId);
        $voteCounts     = \BCC\Trust\Core\Plugin::instance()->voteRepository()->getVoteCountsByType($pageId);

        return [
            'action'            => 'endorse',
            'page_id'           => $pageId,
            'vote'              => null,
            'endorsement'       => [
                'endorsement_id' => $endorsementResult['endorsement_id'],
                'page_title'     => $page->title,
                'context'        => $context,
                'weight'         => $vestedWeight,
            ],
            'score'             => $freshScore ? $freshScore->toApiResponse() : null,
            'votes_up'          => (int) ($voteCounts['upvotes'] ?? 0),
            'votes_down'        => (int) ($voteCounts['downvotes'] ?? 0),
            'endorsement_count' => $freshScore ? $freshScore->getEndorsementCount() : 0,
            'analysis'          => $endorsementResult['analysis'],
        ];
    }

    /**
     * Apply endorsement bonus using atomic UPDATE (not REPLACE INTO).
     *
     * Previously used scoreRepo->save() which issues REPLACE INTO (= DELETE +
     * INSERT). That races with concurrent vote-delta UPDATEs that land between
     * the implicit DELETE and INSERT, silently discarding them.
     *
     * incrementEndorsementCount() issues a single atomic
     *   UPDATE … SET endorsement_count = endorsement_count + 1
     * which is safe under concurrency.
     */
    private function applyEndorsementBonus(int $pageId, float $weight): void {
        // Round to 2dp so the incremental path matches recalculation precision.
        // Without this, sub-penny differences between paths cause visible
        // score shifts when the next recalculation cron runs.
        $weight = round($weight, 2);
        $score  = $this->scoreRepo->getByPageId($pageId);

        if (!$score) {
            $pageOwnerId = PeepSoPageResolver::getOwnerId($pageId);
            $this->scoreRepo->createDefault($pageId, $pageOwnerId);
        }

        // Atomic UPDATE via ScoreRepository: increment endorsement_bonus
        // (dedicated column) and endorsement_count. total_score is recomputed
        // from the canonical formula which includes endorsement_bonus, so the
        // bonus survives vote deltas and cron recalculations.
        try {
            \BCC\Trust\Core\Plugin::instance()->scoreRepository()->applyEndorsementBonus($pageId, $weight);
        } catch (Exception $e) {
            AuditLogger::log('endorsement_bonus_failed', $pageId, [
                'operation' => 'apply',
                'weight'    => $weight,
                'error'     => $e->getMessage(),
            ], 'page');
            throw $e;
        }
    }

    /**
     * Remove endorsement bonus using atomic UPDATE (not REPLACE INTO).
     */
    private function removeEndorsementBonus(int $pageId, float $weight): void {
        $weight = round($weight, 2);
        // Atomic UPDATE via ScoreRepository: decrement endorsement_bonus
        // (dedicated column) and endorsement_count.
        try {
            \BCC\Trust\Core\Plugin::instance()->scoreRepository()->removeEndorsementBonus($pageId, $weight);
        } catch (Exception $e) {
            AuditLogger::log('endorsement_bonus_failed', $pageId, [
                'operation' => 'remove',
                'weight'    => $weight,
                'error'     => $e->getMessage(),
            ], 'page');
            throw $e;
        }
    }

    // ======================================================
    // SLICE 3 — VOUCH (light per-post trust signal)
    // ======================================================

    /**
     * Run $fn inside the standard endorsement advisory-lock + transaction
     * ceremony. Mirrors endorsePage()/revokePageEndorsement() exactly:
     * acquire user → page → score locks OUTSIDE the TransactionManager::run
     * closure so RELEASE_LOCK fires AFTER COMMIT, then release in finally in
     * reverse order. Used ONLY by the two vouch methods — endorsePage/revoke
     * keep their inline ceremony to limit blast radius.
     */
    private function runLockedEndorsementTx(int $userId, int $pageId, callable $fn): void {
        $userLockKey  = 'bcc_endorse_u_' . $userId;
        $pageLockKey  = 'bcc_endorse_p_' . $pageId;
        $scoreLockKey = 'bcc_score_page_' . $pageId;

        if (!$this->endorsementRepo->acquireConcurrencyLock($userLockKey, 5)) {
            throw EndorsementException::busy('Endorsement system is busy. Please try again in a moment.');
        }
        if (!$this->endorsementRepo->acquireConcurrencyLock($pageLockKey, 5)) {
            $this->endorsementRepo->releaseConcurrencyLock($userLockKey);
            throw EndorsementException::busy('Endorsement system is busy. Please try again in a moment.');
        }
        $scoreLockAcquired = false;
        if (class_exists('\\BCC\\Core\\DB\\AdvisoryLock')) {
            $scoreLockAcquired = \BCC\Core\DB\AdvisoryLock::acquire($scoreLockKey, 5);
            if (!$scoreLockAcquired) {
                $this->endorsementRepo->releaseConcurrencyLock($pageLockKey);
                $this->endorsementRepo->releaseConcurrencyLock($userLockKey);
                throw EndorsementException::busy('Endorsement system is busy. Please try again in a moment.');
            }
        }

        try {
            TransactionManager::run($fn);
        } finally {
            // Release AFTER commit (reverse acquisition order) — couples lock
            // handoff to commit visibility, same invariant as endorsePage.
            if ($scoreLockAcquired && class_exists('\\BCC\\Core\\DB\\AdvisoryLock')) {
                \BCC\Core\DB\AdvisoryLock::release($scoreLockKey);
            }
            $this->endorsementRepo->releaseConcurrencyLock($pageLockKey);
            $this->endorsementRepo->releaseConcurrencyLock($userLockKey);
        }
    }

    /**
     * Land a light, permanent `post_vouch` endorsement on the post
     * author's self-page. Deliberately bypasses endorsePage()'s heavy
     * gates (5-pages/day cap, coordination windows, 7-day account age,
     * quest gate) — those would choke a per-post reaction. The vouch's
     * anti-farm IS: the rank gate + per-author idempotency + self-exclusion.
     *
     * @return bool True iff a new post_vouch row was created (idempotent —
     *              a repeat vouch on the same author returns false).
     */
    public function vouchForAuthor(int $voucherId, int $authorId): bool {
        if ($voucherId <= 0 || $authorId <= 0 || $voucherId === $authorId) {
            return false;
        }

        // Rank gate — silent skip. The social reaction still lands in
        // PeepSo even when the user can't confer trust; we just don't
        // write the endorsement.
        $gate = \BCC\Trust\Core\Plugin::instance()->featureAccessService()->canPerform($voucherId, 'vouch_reaction');
        if (empty($gate['allowed'])) {
            return false;
        }

        $pageId = \BCC\Trust\Core\Plugin::instance()->memberSelfPageService()->ensureSelfPage($authorId);
        if ($pageId <= 0) {
            return false;
        }

        /** @var float $weight */
        $weight = (float) apply_filters('bcc_trust_vouch_weight', BCC_TRUST_VOUCH_WEIGHT);

        $created = false;
        $this->runLockedEndorsementTx($voucherId, $pageId, function () use ($voucherId, $pageId, $weight, &$created): void {
            if ($this->endorsementRepo->hasEndorsed($voucherId, $pageId, 'post_vouch')) {
                return; // already vouched — idempotent.
            }
            $this->endorsementRepo->create($voucherId, $pageId, 'post_vouch', $weight, null, 0, $weight);
            $this->applyEndorsementBonus($pageId, $weight);
            $created = true;
        });

        if ($created) {
            AuditLogger::log('vouch_added', $pageId, [
                'voucher_id' => $voucherId,
                'author_id'  => $authorId,
                'weight'     => $weight,
            ], 'page', $voucherId);
        }

        return $created;
    }

    /**
     * Lift the light `post_vouch` endorsement a voucher placed on an
     * author's self-page. No ensureSelfPage on revoke — if no self-page
     * exists there's nothing to revoke; selfPageId is a pure id computation.
     *
     * @return bool True iff an active post_vouch row was removed.
     */
    public function revokeVouchForAuthor(int $voucherId, int $authorId): bool {
        if ($voucherId <= 0 || $authorId <= 0 || $voucherId === $authorId) {
            return false;
        }

        $pageId = \BCC\Trust\Core\Services\MemberSelfPageService::selfPageId($authorId);
        if ($pageId <= 0) {
            return false;
        }

        $removed = false;
        $this->runLockedEndorsementTx($voucherId, $pageId, function () use ($voucherId, $pageId, &$removed): void {
            if (!$this->endorsementRepo->hasEndorsed($voucherId, $pageId, 'post_vouch')) {
                return; // nothing to revoke.
            }
            $this->endorsementRepo->delete($voucherId, $pageId, 'post_vouch');
            $this->removeEndorsementBonus($pageId, 0.0);
            $removed = true;
        });

        if ($removed) {
            AuditLogger::log('vouch_removed', $pageId, [
                'voucher_id' => $voucherId,
                'author_id'  => $authorId,
            ], 'page', $voucherId);
        }

        return $removed;
    }

    /**
     * Remove endorsement from a page
     *
     * @return array<string, mixed>
     */
    public function revokePageEndorsement(int $pageId, string $context = 'general'): array {
        if (!is_user_logged_in()) {
            throw EndorsementException::authRequired('Authentication required');
        }

        $endorserUserId = get_current_user_id();

        RateLimiter::enforce('revoke_endorse', 5, 60);

        // Fire pre-mutation snapshot hook so ScoreMutationLogger can capture
        // score_before for the audit trail. Must run before any mutating step.
        do_action('bcc_trust_endorsement_pre', $endorserUserId, $pageId);

        // Symmetric lock acquisition with endorsePage (audit MED-9/CRIT-4).
        // Without these, a revoke can interleave with a concurrent endorse on
        // the same page and invalidate the assumed serialisation of the
        // bonus + score mutations that follow.
        $userLockKey  = 'bcc_endorse_u_' . $endorserUserId;
        $pageLockKey  = 'bcc_endorse_p_' . $pageId;
        $scoreLockKey = 'bcc_score_page_' . $pageId;

        if (!$this->endorsementRepo->acquireConcurrencyLock($userLockKey, 5)) {
            throw EndorsementException::busy('Endorsement system is busy. Please try again in a moment.');
        }
        if (!$this->endorsementRepo->acquireConcurrencyLock($pageLockKey, 5)) {
            $this->endorsementRepo->releaseConcurrencyLock($userLockKey);
            throw EndorsementException::busy('Endorsement system is busy. Please try again in a moment.');
        }
        $scoreLockAcquired = false;
        if (class_exists('\\BCC\\Core\\DB\\AdvisoryLock')) {
            $scoreLockAcquired = \BCC\Core\DB\AdvisoryLock::acquire($scoreLockKey, 5);
            if (!$scoreLockAcquired) {
                $this->endorsementRepo->releaseConcurrencyLock($pageLockKey);
                $this->endorsementRepo->releaseConcurrencyLock($userLockKey);
                throw EndorsementException::busy('Endorsement system is busy. Please try again in a moment.');
            }
        }

        try {
            TransactionManager::run(function () use ($endorserUserId, $pageId, $context) {
                // Check if endorsement exists
                $endorsement = $this->endorsementRepo->get($endorserUserId, $pageId, $context);

                if (!$endorsement) {
                    throw EndorsementException::notFound('Endorsement not found');
                }

                // Store weight before deletion (stored weight may have been
                // graduated since creation; the authoritative recalc below
                // heals any incremental drift).
                $weight = (float) $endorsement->weight;

                // Delete endorsement (soft delete). Returns false if another
                // concurrent revoke already flipped status=0 — in that case
                // we MUST NOT subtract the bonus again.
                $wasDeleted = $this->endorsementRepo->delete($endorserUserId, $pageId, $context);

                if (!$wasDeleted) {
                    // Another worker already revoked this row. Skip bonus
                    // subtraction and stats update; it was already done.
                    return;
                }

                // Remove endorsement bonus using PageScore
                $this->removeEndorsementBonus($pageId, $weight);

                // Flag the page for authoritative recalc so any drift between
                // incrementally-applied bonus and the current endorsement set
                // (e.g. after vesting graduation bumped stored weights) is
                // healed on the next 5-minute recalc tick.
                \BCC\Trust\Core\Plugin::instance()->scoreRepository()->flagForRecalculation($pageId);

                // Log the revocation
                AuditLogger::revokeEndorsement($pageId, $context, [
                    'endorser_id' => $endorserUserId,
                    'weight' => $weight
                ]);
            });
        } finally {
            if ($scoreLockAcquired && class_exists('\\BCC\\Core\\DB\\AdvisoryLock')) {
                \BCC\Core\DB\AdvisoryLock::release($scoreLockKey);
            }
            $this->endorsementRepo->releaseConcurrencyLock($pageLockKey);
            $this->endorsementRepo->releaseConcurrencyLock($userLockKey);
        }

        // ── Post-commit: endorser stats (audit H-7) ─────────────────────
        // Moved out of the tx to shrink user_info lock scope. Same pattern
        // as endorsePage.
        $this->updateEndorserStats($endorserUserId);

        // ── Post-transaction: invalidate caches, read fresh state ───────
        CacheManager::invalidatePageCaches($pageId, $endorserUserId, 'revoke_endorsement');

        /**
         * Fires after an endorsement is revoked and all caches are cleared.
         *
         * @param int    $endorserUserId The endorser's user ID.
         * @param int    $pageId         The page whose endorsement was revoked.
         * @param string $context        Endorsement context.
         */
        do_action('bcc_trust_endorsement_removed', $endorserUserId, $pageId, $context);

        // Fresh reads from DB (caches were just cleared)
        $freshScore = $this->scoreRepo->getByPageId($pageId);
        $voteCounts = \BCC\Trust\Core\Plugin::instance()->voteRepository()->getVoteCountsByType($pageId);

        return [
            'action'            => 'revoke_endorsement',
            'page_id'           => $pageId,
            'vote'              => null,
            'endorsement'       => null,
            'score'             => $freshScore ? $freshScore->toApiResponse() : null,
            'votes_up'          => (int) ($voteCounts['upvotes'] ?? 0),
            'votes_down'        => (int) ($voteCounts['downvotes'] ?? 0),
            'endorsement_count' => $freshScore ? $freshScore->getEndorsementCount() : 0,
        ];
    }

    /**
     * Calculate base endorsement weight using user_info table
     * Delegates to EndorsementWeightCalculator.
     */
    private function calculateBaseEndorserWeight(int $userId): float {
        return $this->weightCalculator->calculateBaseEndorserWeight($userId);
    }

    /**
     * Apply fraud adjustments to endorsement weight
     * Delegates to EndorsementWeightCalculator.
     *
     * @param array<string, mixed> $fraudAnalysis
     * @param array<string, mixed> $automationData
     * @param array<string, mixed> $behavior
     */
    private function applyFraudAdjustments(
        float $baseWeight,
        array $fraudAnalysis,
        array $automationData,
        bool $multiAccountRisk,
        array $behavior,
        float $trustRank,
        int $userId
    ): float {
        return $this->weightCalculator->applyFraudAdjustments(
            $baseWeight,
            $fraudAnalysis,
            $automationData,
            $multiAccountRisk,
            $behavior,
            $trustRank,
            $userId
        );
    }

    /**
     * Check if user has endorsed page
     */
    public function hasEndorsedPage(int $pageId, ?int $endorserUserId = null, ?string $context = null): bool {
        return $this->queryService->hasEndorsedPage($pageId, $endorserUserId, $context);
    }

    /**
     * Read-only eligibility check — mirrors the gates in endorsePage()
     * but without mutating. Returns the §L5 Permission shape:
     *
     *   {allowed: bool, unlock_hint: string|null, reason_code: string|null}
     *
     * Gate evaluation order matches endorsePage() so reason codes line
     * up with the eventual rejection. Coordination caps and per-page
     * race conditions are NOT preflighted — they're transient and the
     * controller's catch block surfaces them as 400s if the user hits
     * them at submit time.
     *
     * Used by CardViewService to populate `permissions.can_endorse`,
     * giving the frontend a sensible "show button" gate without
     * exposing internal fraud signals (reason_code is intentionally
     * coarse).
     *
     * @return array{allowed: bool, unlock_hint: string|null, reason_code: string|null}
     */
    public function getEndorseEligibility(int $viewerId, int $pageId): array {
        if ($viewerId <= 0) {
            return ['allowed' => false, 'unlock_hint' => 'Sign in to endorse.', 'reason_code' => 'auth_required'];
        }

        // Self-endorse blocked except in BCC_TRUST_TEST_MODE.
        $testMode = defined('BCC_TRUST_TEST_MODE') && \BCC_TRUST_TEST_MODE;
        if (!$testMode) {
            $pageOwnerId = PeepSoPageResolver::getOwnerId($pageId);
            if ($pageOwnerId === $viewerId) {
                return ['allowed' => false, 'unlock_hint' => "You can't endorse your own page.", 'reason_code' => 'self_action_blocked'];
            }
        }

        // Viewer-level gates (quest / account-age / fraud) — extracted
        // so the batch path can evaluate them ONCE per request instead
        // of once per card. Gate order inside is unchanged.
        $viewerGate = $this->evaluateViewerEndorseGates($viewerId);
        if ($viewerGate !== null) {
            return $viewerGate;
        }

        return ['allowed' => true, 'unlock_hint' => null, 'reason_code' => null];
    }

    /**
     * The viewer-level (page-independent) endorse gates: quest /
     * account-age / fraud-score, in that order — exactly the order
     * getEndorseEligibility evaluated them inline before extraction.
     * Returns the first failing gate's Permission shape, or null when
     * every gate passes (including BCC_TRUST_TEST_MODE, which skips
     * all three).
     *
     * Page-level gates (auth, self-endorse) stay with the callers —
     * this method only owns what's constant across pages for a viewer,
     * so getEndorseEligibilityForPages can memoize one result for a
     * whole cards-list page.
     *
     * @return array{allowed: bool, unlock_hint: string|null, reason_code: string|null}|null
     */
    private function evaluateViewerEndorseGates(int $viewerId): ?array {
        $testMode = defined('BCC_TRUST_TEST_MODE') && \BCC_TRUST_TEST_MODE;
        if ($testMode) {
            return null;
        }

        // Quest gate (identity verification).
        $questService = \BCC\Trust\Core\Plugin::instance()->questProgressService();
        if (!$questService->canEndorse($viewerId)) {
            $progress = $questService->getEndorseProgress($viewerId);
            $hint = is_string($progress['missing_reason'] ?? null) && $progress['missing_reason'] !== ''
                ? (string) $progress['missing_reason']
                : 'Verify your identity to unlock endorsements.';
            return ['allowed' => false, 'unlock_hint' => $hint, 'reason_code' => 'identity_required'];
        }

        // Account-age gate.
        $user = get_userdata($viewerId);
        if ($user instanceof \WP_User) {
            $registeredTs = strtotime($user->user_registered);
            $accountAgeDays = $registeredTs !== false
                ? (int) floor((time() - $registeredTs) / DAY_IN_SECONDS)
                : 0;
            /** @var int $minAgeDays */
            $minAgeDays = (int) apply_filters('bcc_trust_endorse_min_account_age_days', 7);
            if ($accountAgeDays < $minAgeDays) {
                return [
                    'allowed' => false,
                    'unlock_hint' => sprintf('Your account must be at least %d days old to endorse.', $minAgeDays),
                    'reason_code' => 'account_too_new',
                ];
            }
        }

        // Fraud-score gate. Defense-in-depth — endorsePage() re-checks
        // inside the transaction.
        $userInfo = $this->userInfoRepo->getByUserId($viewerId);
        if ($userInfo !== null && (int) $userInfo->fraud_score >= BCC_TRUST_FRAUD_HIGH) {
            // Coarse reason — never echo the actual fraud score.
            return [
                'allowed' => false,
                'unlock_hint' => 'Endorsements are temporarily restricted on this account.',
                'reason_code' => 'account_restricted',
            ];
        }

        return null;
    }

    /**
     * Batch variant of getEndorseEligibility for the cards-list path.
     * The viewer-level gates (quest / account-age / fraud) are
     * page-independent, so they're evaluated ONCE; per page only the
     * self-endorse check remains. Per-page gate order is unchanged
     * relative to getEndorseEligibility: self-check first, then the
     * memoized viewer-gate result.
     *
     * The self-check resolves owners through PageOwnerResolver — the
     * same canonical source getEndorseEligibility reaches via
     * PeepSoPageResolver::getOwnerId (PeepSo member_owner first,
     * post_author fallback) — batched once via primeOwnerCache so the
     * per-page lookups are cache hits.
     *
     * Anon viewers get an empty map (per the PageCardPrefetcher bundle
     * contract); the per-card consumer falls back to
     * getEndorseEligibility, which short-circuits to the auth_required
     * shape with zero queries.
     *
     * @param list<int> $pageIds Bounded by caller (cards per_page ≤ 50).
     * @return array<int, array{allowed: bool, unlock_hint: string|null, reason_code: string|null}>
     *     Keyed by page_id.
     */
    public function getEndorseEligibilityForPages(int $viewerId, array $pageIds): array {
        if ($viewerId <= 0 || $pageIds === []) {
            return [];
        }

        $testMode = defined('BCC_TRUST_TEST_MODE') && \BCC_TRUST_TEST_MODE;
        $viewerGate = $this->evaluateViewerEndorseGates($viewerId);
        $allowed = ['allowed' => true, 'unlock_hint' => null, 'reason_code' => null];

        // Canonical owner resolution, batched (1-3 queries for the whole
        // page) — keeps the self-check on the same PeepSo-first source
        // getEndorseEligibility uses via PeepSoPageResolver::getOwnerId.
        $ownerResolver = \BCC\Trust\Core\Plugin::instance()->pageOwnerResolver();
        if (!$testMode) {
            $ownerResolver->primeOwnerCache($pageIds);
        }

        $out = [];
        foreach ($pageIds as $pageId) {
            $pageId = (int) $pageId;
            if ($pageId <= 0) {
                continue;
            }

            // Self-endorse blocked except in BCC_TRUST_TEST_MODE —
            // same first position it holds in getEndorseEligibility.
            if (!$testMode) {
                $ownerId = $ownerResolver->getPageOwner($pageId);
                if ($ownerId === $viewerId) {
                    $out[$pageId] = [
                        'allowed' => false,
                        'unlock_hint' => "You can't endorse your own page.",
                        'reason_code' => 'self_action_blocked',
                    ];
                    continue;
                }
            }

            $out[$pageId] = $viewerGate ?? $allowed;
        }

        return $out;
    }

    /**
     * Get endorsements given by user with fraud data from user_info.
     *
     * Passthrough to the query service. See EndorsementQueryService::getUserEndorsements()
     * for the per-entry shape — each element is an associative array of the
     * EndorsementWithPage columns plus the computed `endorser_fraud_score`.
     *
     * @return list<array<string, mixed>>
     */
    public function getUserEndorsements(?int $endorserUserId = null, int $limit = 20): array {
        return $this->queryService->getUserEndorsements($endorserUserId, $limit);
    }

    /**
     * Hydrate raw endorsement rows into the §J.6 contract-stable item
     * shape (page_title + page_url + page-owner avatar, current tier
     * snapshot from the read model, weight + context + reason +
     * timestamp).
     *
     * Shared between:
     *   - UserEndorsementsEndpoint::handleList  (`GET /endorsements/mine`)
     *   - UsersEndpoint::endorsements           (`GET /users/:handle/endorsements`)
     *
     * Single source of trust per §A4 — both surfaces emit identical
     * row shapes. The owner-side and public-side reads diverge on
     * permission + cache headers, not on row shape.
     *
     * §A view-model boundary: this is presentation-layer assembly
     * (avatar URL via WP, esc_url / sanitize_key for the wire). The
     * underlying score / tier / weight values are computed elsewhere
     * (read model). This method only joins them per row.
     *
     * @param list<array<string, mixed>> $endorsements Raw rows from
     *     EndorsementService::getUserEndorsements.
     * @return list<array<string, mixed>>
     */
    public function hydrateEndorsementItems(array $endorsements): array
    {
        $page_ids = array_map(static fn(array $e): int => (int) ($e['page_id'] ?? 0), $endorsements);
        $rm_rows  = [];
        if (!empty($page_ids)) {
            $rm_rows = \BCC\Trust\Core\Plugin::instance()->pageReadModelRepository()->getByPageIds($page_ids);
        }

        $items = [];
        foreach ($endorsements as $e) {
            $pid = (int) ($e['page_id'] ?? 0);
            $rm  = $rm_rows[$pid] ?? null;

            $avatar = '';
            if ($rm && $rm->owner_id) {
                $avatar = get_avatar_url((int) $rm->owner_id, ['size' => 64]);
            }

            $pageTitle = isset($e['page_title']) && is_string($e['page_title']) ? $e['page_title'] : null;
            $context   = isset($e['context'])    && is_string($e['context'])    ? $e['context']    : null;
            $reason    = isset($e['reason'])     && is_string($e['reason'])     ? $e['reason']     : null;
            $createdAt = isset($e['created_at']) && is_string($e['created_at']) ? $e['created_at'] : null;

            $items[] = [
                'id'           => (int) ($e['id'] ?? 0),
                'page_id'      => $pid,
                'page_title'   => $pageTitle ?? __('(Untitled)', 'bcc-trust'),
                'page_url'     => get_permalink($pid) ? esc_url(get_permalink($pid)) : '',
                'avatar_url'   => $avatar ? esc_url($avatar) : '',
                'trust_score'  => $rm ? (int) round((float) $rm->trust_score) : null,
                'tier'         => $rm ? sanitize_key($rm->reputation_tier ?? 'neutral') : 'unavailable',
                'weight'       => round((float) ($e['weight'] ?? 0), 2),
                'context'      => $context ?? 'general',
                'reason'       => $reason,
                'created_at'   => $createdAt,
            ];
        }
        return $items;
    }

    /**
     * Update endorser's stats in user_info table
     */
    private function updateEndorserStats(int $userId): void {
        $endorsementCount = $this->endorsementRepo->countByEndorser($userId);
        
        // Update user_info table
        $this->userInfoRepo->updateEndorsementsGiven($userId, $endorsementCount);
    }

    /**
     * Get endorsement statistics for a user
     *
     * @return array<string, mixed>
     */
    public function getUserEndorsementStats(int $userId): array {
        return $this->queryService->getUserEndorsementStats($userId);
    }

    // ======================================================
    // DEFENSE: Endorsement Vesting
    // ======================================================

    /**
     * Batch-update endorsement vesting stages and weights.
     *
     * Called by cron to graduate endorsements through vesting stages.
     * Delegates to EndorsementVestingProcessor.
     *
     * @return int Number of endorsements updated.
     */
    public function processEndorsementVesting(): int {
        return (new EndorsementVestingProcessor())->process();
    }

    // ======================================================
    // DEFENSE: Endorsement Source Diversity
    // ======================================================

    /**
     * Calculate diversity factor based on endorsement source concentration.
     * Delegates to EndorsementWeightCalculator.
     */
    private function calculateDiversityFactor(int $pageId, int $endorserUserId): float {
        return $this->weightCalculator->calculateDiversityFactor($pageId, $endorserUserId);
    }

    // ── Blocking coordination guards ───────────────────────────────────

    /**
     * Block endorsement if page has received 10+ unique endorsers today.
     *
     * Prevents coordinated endorsement farming on a single target page.
     */
    private function assertDailyEndorsementCap(int $pageId): void {
        $endorseRepo = \BCC\Trust\Core\Plugin::instance()->endorsementRepository();

        $todayCount = $endorseRepo->countDistinctEndorsersToday($pageId);

        if ($todayCount >= 10) {
            throw EndorsementException::dailyLimit('This page has reached its daily endorsement limit. Please try again tomorrow.');
        }
    }

    /**
     * Block endorsement if user has endorsed too many distinct pages today.
     *
     * Limits the surface area for cross-page endorsement farming: a single
     * user (or sockpuppet) cannot endorse an unlimited number of pages per day.
     * Default: 5 distinct pages per user per day.
     */
    private function assertPerEndorserDailyCap(int $endorserUserId): void {
        $endorseRepo = \BCC\Trust\Core\Plugin::instance()->endorsementRepository();
        $maxPagesPerDay = (int) apply_filters('bcc_trust_endorser_daily_page_cap', 5);

        $todayPages = $endorseRepo->countDistinctPagesEndorsedToday($endorserUserId);

        if ($todayPages >= $maxPagesPerDay) {
            throw EndorsementException::dailyLimit(
                sprintf('You have reached the daily limit of %d endorsements. Please try again tomorrow.', $maxPagesPerDay)
            );
        }
    }

    /**
     * Block endorsement if temporal coordination is detected.
     *
     * Rejects when 3+ distinct endorsers have endorsed this page within
     * the coordination window (BCC_TRUST_COORDINATION_WINDOW_SECONDS).
     * Previously signal-only — now enforced as a hard block.
     */
    private function assertNoCoordination(int $pageId, int $endorserUserId): void {
        // Tightened from 5-in-120s to 3-in-300s to catch slower coordination
        // attacks (e.g., 1 endorsement per 31 seconds evaded the old threshold).
        $window    = defined('BCC_TRUST_COORDINATION_WINDOW_SECONDS') ? BCC_TRUST_COORDINATION_WINDOW_SECONDS : 300;
        $threshold = defined('BCC_TRUST_COORDINATION_ACTION_THRESHOLD') ? BCC_TRUST_COORDINATION_ACTION_THRESHOLD : 3;

        $endorseRepo = \BCC\Trust\Core\Plugin::instance()->endorsementRepository();
        $recentCount = $endorseRepo->countDistinctEndorsersInWindow($pageId, $window);

        // +1 for the current endorser (not yet committed)
        if (($recentCount + 1) >= $threshold) {
            throw EndorsementException::dailyLimit('This page is receiving endorsements too quickly. Please try again later.');
        }

        // Extended window check: catch temporal spreading attacks that stay
        // under the 3-in-300s threshold by spacing endorsements over hours.
        // 6+ distinct endorsers in 1 hour on a single page is abnormal.
        $hourlyCount = $endorseRepo->countDistinctEndorsersInWindow($pageId, 3600);
        if (($hourlyCount + 1) >= 6) {
            AuditLogger::log('endorsement_hourly_velocity', $pageId, [
                'endorser_id'  => $endorserUserId,
                'hourly_count' => $hourlyCount,
            ], 'page', $endorserUserId);
            throw EndorsementException::dailyLimit(
                'This page has reached its hourly endorsement limit. Please try again later.'
            );
        }

        // Cross-page cluster detection: if this endorser has endorsed other
        // pages owned by the same person in the last 24h, that's coordination.
        $this->assertNoCrossPageCluster($pageId, $endorserUserId);
    }

    /**
     * Detect cross-page endorsement clusters: same endorser hitting multiple
     * pages owned by the same person within 24 hours.
     *
     * A legitimate endorser might endorse 1-2 pages per owner. 3+ pages
     * by the same owner in 24h suggests coordinated manipulation.
     */
    private function assertNoCrossPageCluster(int $pageId, int $endorserUserId): void
    {
        $pageOwnerId = \BCC\Trust\Core\Services\PeepSoPageResolver::getOwnerId($pageId);
        if ($pageOwnerId <= 0) {
            return;
        }

        $endorseRepo = \BCC\Trust\Core\Plugin::instance()->endorsementRepository();

        // Count distinct pages owned by this owner that this endorser
        // has endorsed in the last 24 hours.
        $crossPageCount = $endorseRepo->countEndorserPagesForOwnerInWindow(
            $endorserUserId,
            $pageOwnerId,
            86400 // 24 hours
        );

        // 3+ pages by the same owner is a coordination signal.
        if ($crossPageCount >= 3) {
            AuditLogger::log('endorsement_cross_page_cluster', $pageId, [
                'endorser_id'    => $endorserUserId,
                'owner_id'       => $pageOwnerId,
                'pages_endorsed' => $crossPageCount,
            ], 'page', $endorserUserId);
            throw EndorsementException::dailyLimit(
                'You have endorsed multiple pages by this owner recently. Please try again later.'
            );
        }
    }
}
