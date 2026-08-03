<?php
/**
 * Vote Service
 *
 * Thin orchestrator for the vote pipeline. Delegates each concern to the
 * appropriate specialist class; the only logic that lives here is the
 * coordination between those classes.
 *
 * Synchronous vote path (must stay fast):
 *   1. VoteEligibilityChecker::check()       — read-only gates
 *   2. runNewAccountSybilGate()              — cheap sync fraud gates
 *   3. buildCachedSignals()                  — assemble pre-fetched signals
 *   4. VoteWeightCalculator::calculate()     — pure PHP weight calc
 *   5. VoteWriter::write()                   — DB transaction (vote + score)
 *   6. VoteJobDispatcher::dispatch()         — async job dispatch (post-commit)
 *
 * @package BCC\Trust\Core\Services
 * @version 3.0.0
 */

namespace BCC\Trust\Core\Services;

use DateTimeImmutable;
use Exception;
use BCC\Trust\Core\Exceptions\VoteEligibilityException;
use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Core\Repositories\AuditLogRepository;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Repositories\ScoreRepository;
use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Security\DeviceFingerprinter;
use BCC\Trust\Core\Security\FraudDetector;
use BCC\Trust\Core\Security\IpResolver;
use BCC\Trust\Core\Security\TransactionManager;
use BCC\Trust\Core\Services\Vote\VoteEligibilityChecker;
use BCC\Trust\Core\Services\Vote\VoteJobDispatcher;
use BCC\Trust\Core\Services\Vote\VoteWeightCalculator;
use BCC\Trust\Core\Services\Vote\VoteWriter;
use BCC\Trust\Core\Support\CacheManager;
use BCC\Trust\Core\ValueObjects\PageScore;

if (!defined('ABSPATH')) exit;

/**
 * @phpstan-import-type UserInfoRow from UserInfoRepository
 */
class VoteService {

    private VoteRepository         $voteRepo;
    private ScoreRepository        $scoreRepo;
    private ReputationRepository   $reputationRepo;
    private UserInfoRepository     $userInfoRepo;
    private AttestationRepository  $attestationRepo;
    private AuditLogRepository     $auditLogRepo;
    private DeviceFingerprinter    $fingerprinter;
    private VoteEligibilityChecker $eligibilityChecker;
    private VoteWeightCalculator   $weightCalculator;
    private VoteWriter             $writer;

    // ── Request-scoped static cache ───────────────────────────────────────────
    // Eliminates duplicate DB calls for the same data within one HTTP request.
    // Each entry stores ['value' => mixed, 'cached_at' => int]. The TTL is 1 s
    // so stale data can never persist across requests even under keep-alive.
    // Set the filter 'bcc_trust_request_cache_enabled' to false to disable.
    /** @var array<string, array{value: mixed, cached_at: int}> */
    private static array $requestCache = [];

    public function __construct(
        VoteRepository       $voteRepo,
        ScoreRepository      $scoreRepo,
        ReputationRepository $reputationRepo,
        UserInfoRepository   $userInfoRepo,
        DeviceFingerprinter  $fingerprinter
    ) {
        $this->voteRepo        = $voteRepo;
        $this->scoreRepo       = $scoreRepo;
        $this->reputationRepo  = $reputationRepo;
        $this->userInfoRepo    = $userInfoRepo;
        $this->fingerprinter   = $fingerprinter;
        $this->attestationRepo = \BCC\Trust\Core\Plugin::instance()->attestationRepository();
        $this->auditLogRepo    = new AuditLogRepository();

        $this->eligibilityChecker = new VoteEligibilityChecker(
            $this->voteRepo,
            $this->reputationRepo,
            $this->userInfoRepo
        );
        $this->weightCalculator   = new VoteWeightCalculator(
            \BCC\Trust\Core\Plugin::instance()->rankScoringConfig()
        );
        $this->writer             = new VoteWriter(
            $this->voteRepo,
            $this->scoreRepo,
            $this->userInfoRepo
        );
    }

    /** @var string Idempotency cache group. */
    private const IDEM_GROUP = 'bcc_trust_idem';

    /**
     * Idempotency TTL in seconds.
     *
     * SECURITY: kept short (15s) so a replay cannot bypass a mid-window state
     * change (admin suspension, daily-cap exhaustion, fraud gate trip). The
     * TTL covers SPA double-clicks and brief network retries only — it is
     * NOT an authorization cache.
     */
    private const IDEM_TTL = 15;

    /**
     * Check whether this vote request has already been processed.
     *
     * Returns the cached result if the idempotency key matches a
     * previous call, or null if this is a fresh request.  The key is
     * scoped to (userId, pageId, voteType, clientKey) so that a
     * legitimate vote-type change is never treated as a duplicate.
     *
     * @param int    $userId
     * @param int    $pageId
     * @param int    $voteType
     * @param string $clientKey  Value of the X-Idempotency-Key header (may be empty).
     * @return array<string, mixed>|null  Previously cached result, or null.
     */
    public function checkIdempotency(int $userId, int $pageId, int $voteType, string $clientKey): ?array {
        $idemKey = md5(implode('|', [$userId, $pageId, $voteType, $clientKey]));
        $cached  = wp_cache_get($idemKey, self::IDEM_GROUP);
        return ($cached !== false) ? $cached : null;
    }

    /**
     * Store a vote result for idempotency replay.
     *
     * @param array<string, mixed> $result
     */
    public function storeIdempotency(int $userId, int $pageId, int $voteType, string $clientKey, array $result): void {
        $idemKey = md5(implode('|', [$userId, $pageId, $voteType, $clientKey]));
        wp_cache_set($idemKey, $result, self::IDEM_GROUP, self::IDEM_TTL);
    }

    /**
     * Cast or update a vote on a PeepSo Page.
     *
     * This method must remain fast. All heavy analysis is dispatched async
     * via VoteJobDispatcher after the transaction commits.
     *
     * @param array<string, mixed>|null $fingerprintData
     * @return array<string, mixed>
     */
    public function castPageVote(
        int    $pageId,
        int    $voteType,
        ?array $fingerprintData = null,
        ?string $reason = null,
        string $idempotencyKey = ''
    ): array {
        $voterId = get_current_user_id();

        // Fire pre-mutation snapshot hook so ScoreMutationLogger can capture
        // score_before for the audit trail. Must run before any mutating step.
        do_action('bcc_trust_vote_pre', $voterId, $pageId);

        // ── 1. Eligibility gates (read-only, throws on failure) ──────────────
        //
        // SECURITY: eligibility/rate/fraud/coordination gates ALWAYS run before
        // any idempotency cache is consulted (checked below). A replay of a
        // legitimately-issued idempotency key cannot bypass a mid-window state
        // change (admin suspension, daily-cap exhaustion, fraud trip).
        $existingVote = $this->eligibilityChecker->check($voterId, $pageId, $voteType);

        // ── Resolve categories and page owner ─────────────────────────────────
        $categoryIds = $this->resolveVoteCategoryIds($pageId);
        $pageOwnerId = $this->getPageOwnerId($pageId);

        // Pre-transaction estimate — used only for weight calculation below.
        // Under concurrent first-votes two threads can both see null here.
        // This estimate does NOT control whether the score delta is applied;
        // that decision is made inside the transaction using was_inserted.
        $preCheckIsNewVoter = ($existingVote === null);

        // ── 2. Synchronous new-account Sybil gate ────────────────────────────
        $this->runNewAccountSybilGate($voterId, $pageId);

        // ── 2b. Synchronous fan-in pre-gate (CRIT-1 fix) ────────────────────
        // Detects coordinated voting in real-time rather than waiting for async
        // cron-based fan-in detection. If a page receives 15+ distinct voters
        // in 5 minutes AND the current voter's account is under 30 days old,
        // defer the vote to cron instead of applying immediately. This closes
        // the window where sybil attacks land before fraud detection fires.
        $this->runFanInPreGate($voterId, $pageId);

        // ── 3. Build cached signals (all already-fetched or cheap) ───────────
        // SECURITY: never trust client-supplied fingerprint.hash — an attacker
        // can pick a fresh random string per sock to evade sybil detection.
        // Always derive fingerprint server-side. If the client sent a hash,
        // we blend it into an observation field only (not used as identifier).
        $fingerprint = $this->getOrGenerateFingerprint();
        $signals     = $this->buildCachedSignals($voterId, $fingerprint);

        // ── Rapid-voting fraud gate ───────────────────────────────────────────
        if (FraudDetector::detectRapidVoting($voterId)) {
            AuditLogger::log('fraud_rapid_voting', $pageId, [
                'voter_id'  => $voterId,
                'vote_type' => $voteType,
            ], 'page');
            throw new VoteEligibilityException('Vote rate limit exceeded.');
        }

        // ── Real-time coordination blocker ────────────────────────────────────
        // Blocks ALL voters (not just new accounts) when a page is under active
        // coordinated attack. Unlike fan-in pre-gate which only catches new
        // accounts, this detects rapid vote accumulation regardless of age.
        $this->assertNoActiveCoordinatedAttack($voterId, $pageId);

        // ── Downvote velocity alert (Attack #9 defence) ───────────────────────
        if ($voteType === -1) {
            $this->maybeLogDownvoteVelocity($voterId, $pageId);
        }

        // ── 4. Weight calculation (pure PHP) ─────────────────────────────────
        $userInfo   = $this->getUserInfo($voterId);       // cached — may have been read in steps 2/3
        $reputation = $this->getVoterReputation($voterId); // cached
        $voterTier  = $reputation->reputation_tier ?? 'neutral';
        $trustScore = (float) ($reputation->reputation_score ?? 50.0);

        // §16.6 (Rank Phase 6): weight = maturity × rank × trust × fraud,
        // ceiling 1.75. Rank + maturity epoch from canonical rank_state
        // (missing row = New Member = weight 0 — the eligibility checker
        // already blocked them upstream; this is defence in depth).
        $rankRow = \BCC\Trust\Core\Plugin::instance()->rankStateRepository()->getForUser($voterId);

        $weight = $this->weightCalculator->calculate(
            $rankRow !== null ? (string) $rankRow->rank_slug : null,
            $rankRow !== null ? (string) $rankRow->apprentice_awarded_at : null,
            $trustScore,
            $voterTier,
            $signals,
            (int) ($userInfo->fraud_score ?? 0)
        );

        // ── 4b. Idempotency check (AFTER all gates, BEFORE mutation) ────────
        //
        // SECURITY: placed here — after eligibility/rate/fraud/coordination
        // have all been re-evaluated — so a replayed idempotency key cannot
        // bypass a mid-window state change. If a legitimate SPA double-click
        // or network retry arrives within IDEM_TTL, we return the previous
        // result without duplicating the write or async dispatch.
        if ($idempotencyKey !== '') {
            $cached = $this->checkIdempotency($voterId, $pageId, $voteType, $idempotencyKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        // ── 5. Write (transaction) — one row per category ──────────────────────
        //
        // All categories are written inside a SINGLE outer transaction so
        // that either ALL categories succeed or NONE do. VoteWriter::write()
        // uses TransactionManager::run() internally, which detects the
        // existing transaction depth and uses SAVEPOINTs instead of new
        // transactions — giving us atomicity across all categories.
        $downvoteReason = ($voteType < 0 && $reason !== null)
            ? sanitize_text_field($reason)
            : null;

        $writeResults = TransactionManager::run(function () use (
            $voterId, $pageId, $categoryIds, $pageOwnerId, $voteType, $weight,
            $preCheckIsNewVoter, $downvoteReason
        ) {
            // Velocity cap is NO LONGER applied pre-emptively here. Doing so
            // consumed daily budget on idempotent retries (tab races) where
            // upsertRaw ultimately detects the row hasn't changed and skips
            // the score delta. Now the first VoteWriter call that actually
            // observes a change (was_inserted / vote_type_changed /
            // weight_changed) computes the cap inside the transaction and
            // returns the capped value; subsequent category calls receive it
            // as $preCappedWeight so the budget is still consumed only once
            // per vote on multi-category pages.
            $preCappedWeight = null;

            $results = [];
            $isFirst = true;
            foreach ($categoryIds as $catId) {
                $result = $this->writer->write(
                    $voterId,
                    $pageId,
                    $catId,
                    $pageOwnerId,
                    $voteType,
                    $weight,
                    $preCheckIsNewVoter,
                    $downvoteReason,
                    $preCappedWeight,
                    $preCheckIsNewVoter && $isFirst  // only increment unique_voters on first category
                );

                // Propagate the capped weight to subsequent category writes so
                // applyVelocityCap is called at most once per vote.
                if ($preCappedWeight === null) {
                    $capped = $result['capped_weight'] ?? null;
                    if (is_float($capped) || is_int($capped)) {
                        $preCappedWeight = (float) $capped;
                    }
                }

                $results[] = $result;
                $isFirst = false;
            }
            return $results;
        });

        $firstWriteResult = $writeResults[0];
        $isNewVoter       = $firstWriteResult['was_inserted'];

        // ── Sync the read model BEFORE invalidating downstream caches ─────
        // Ordering matters: invalidateAfterVoteChange() fires
        // bcc_trust_vote_cast which busts PageEndpoint's cache. If that bust
        // happened first, a concurrent GET /bcc/v1/page/{id} would miss the
        // cache, read a still-stale read-model row, and re-populate the
        // PageEndpoint cache with pre-vote data — defeating invalidation.
        // Running syncPage first ensures the read-model reflects the vote
        // before any listener starts accepting new cache reads.
        try {
            \BCC\Trust\Core\Plugin::instance()->pageReadModelRepository()->syncPage($pageId);
        } catch (\Throwable $e) {
            // Eager sync failed — explicitly enqueue the page as dirty so
            // the deferred batch processor picks it up on the next tick.
            // The bcc_trust_vote_changed action fired below also markDirty's
            // via PageReadModelSync::onVoteChanged; calling it here too is
            // idempotent (INSERT IGNORE on PRIMARY KEY) and closes the
            // narrow window where the action listener might not be
            // registered on a partially-booted request.
            if (class_exists('\\BCC\\Trust\\Core\\Services\\PageReadModelSync')) {
                \BCC\Trust\Core\Services\PageReadModelSync::markDirty($pageId);
            }
            // Elevated to ERROR: silent degradation of the read model is a
            // correctness hazard operators must see, not noise.
            \BCC\Core\Log\Logger::error('[VoteService] Eager read-model sync failed, page marked dirty for deferred retry', [
                'page_id' => $pageId,
                'error'   => $e->getMessage(),
            ]);
        }

        // ── Invalidate caches post-transaction (after read-model sync) ────
        foreach ($categoryIds as $catId) {
            self::invalidateAfterVoteChange($voterId, $pageId, $catId, 'cast');
        }

        // ── Derive authoritative isNewVoter from transaction result ───────────
        // was_inserted is the ground truth set inside the FOR UPDATE transaction.
        // The pre-check estimate can differ under concurrent first-votes; the score
        // delta is already protected (VoteWriter gates on was_inserted), so no
        // corruption can occur. We log the mismatch for operational visibility.
        if ($preCheckIsNewVoter !== $isNewVoter) {
            AuditLogger::log('new_voter_race_detected', $pageId, [
                'voter_id'            => $voterId,
                'pre_check_is_new'    => $preCheckIsNewVoter,
                'actual_was_inserted' => $isNewVoter,
                'vote_type'           => $voteType,
                'vote_id'             => $firstWriteResult['vote_id'],
            ], 'page');
        }

        // Use the first category for the backward-compatible single-score response.
        $scoreAfter = $this->scoreRepo->getByPageId($pageId, $categoryIds[0]);

        // Multi-category scores removed from the synchronous vote response.
        // The client already receives the primary category score (above) which
        // is sufficient for immediate UI update. Full multi-category scores
        // can be fetched on-demand via GET /bcc/v1/page/{id} if needed.
        // This eliminates 1 DB query (getAllByPageId) from the hot vote path.

        // ── 6. Dispatch async jobs ────────────────────────────────────────────
        VoteJobDispatcher::dispatch($firstWriteResult['vote_id']);

        $voteCounts = $this->voteRepo->getVoteCountsByType($pageId);

        return [
            'action'   => 'vote',
            'vote_id'  => $firstWriteResult['vote_id'],
            'vote_type' => $voteType,
            'weight'   => $weight->vested,
            'page_id'  => $pageId,
            'vote'     => [
                'vote_id'   => $firstWriteResult['vote_id'],
                'vote_type' => $voteType,
                'weight'    => $weight->vested,
            ],
            'endorsement' => null,
            'score'    => $scoreAfter ? $scoreAfter->toApiResponse() : null,
            'votes_up'         => (int) ($voteCounts['upvotes'] ?? 0),
            'votes_down'       => (int) ($voteCounts['downvotes'] ?? 0),
            'endorsement_count' => $scoreAfter ? $scoreAfter->getEndorsementCount() : 0,
            'analysis' => [
                'weight_applied'       => $weight->effective,
                'base_weight'          => $weight->base,
                'fraud_discount'       => $weight->fraudDiscount,
                'fraud_blocked'        => $weight->isFraudBlocked(),
                'penalties'            => $weight->penaltiesBreakdown,
                'voter_tier'           => $voterTier,
                'new_voter'            => $isNewVoter,  // authoritative post-transaction value
                'vote_type_changed'    => $firstWriteResult['vote_type_changed'],
                'has_sufficient_data'  => $scoreAfter
                    ? $scoreAfter->getVoteCount() >= BCC_TRUST_MIN_VOTES_RELIABLE
                    : false,
                'confidence'           => $scoreAfter
                    ? $scoreAfter->getConfidencePercentage()
                    : 0,
            ],
        ];
    }

    /**
     * Remove a vote.
     *
     * Locking order (must match VoteWriter::write):
     *   1. Vote row  — FOR UPDATE via VoteRepository
     *   2. Score row — reverseVoteDelta UPDATE
     *
     * @return array<string, mixed>
     */
    public function removePageVote(int $pageId): array {
        $voterId = get_current_user_id();

        if (!$voterId) {
            throw new VoteEligibilityException('Authentication required.');
        }

        $existingVote = $this->voteRepo->get($voterId, $pageId);
        if (!$existingVote) {
            throw new VoteEligibilityException('No vote found to remove.');
        }

        // Fire pre-mutation snapshot hook so ScoreMutationLogger can capture
        // score_before for the audit trail. Must run before any mutating step.
        do_action('bcc_trust_vote_pre', $voterId, $pageId);

        $pageOwnerId = $this->getPageOwnerId($pageId);

        $result = TransactionManager::run(function () use (
            $voterId, $pageId
        ) {
            // Step 1: acquire lock on ALL vote rows for this voter+page (FOR UPDATE)
            $lockedVotes = $this->voteRepo->lockAllForUpdate($voterId, $pageId);
            if (empty($lockedVotes)) {
                throw new VoteEligibilityException('Vote row not found during removal.');
            }

            // Step 2: reverse score delta for each category (after vote locks are held)
            // Only decrement unique_voters on the FIRST category to prevent
            // over-decrementing when a voter has rows across multiple categories.
            $isFirst = true;
            foreach ($lockedVotes as $locked) {
                $catId = (int) ($locked->category_id ?? 0);
                $this->scoreRepo->reverseVoteDelta(
                    $pageId,
                    $catId,
                    (float) $locked->weight,
                    (int) $locked->vote_type === 1,
                    $isFirst  // only decrement unique_voters once
                );
                $isFirst = false;
            }

            // Step 3: soft-delete all vote rows for this voter+page
            $this->voteRepo->delete($voterId, $pageId);

            // Step 4: flag for authoritative recalculation (inside tx)
            // Atomic with the vote removal — no TOCTOU gap where the vote is
            // removed but the flag is not yet set for the cron to pick up.
            $this->scoreRepo->flagForRecalculation($pageId);

            return ['removed' => true, 'locked_votes' => $lockedVotes];
        });

        $lockedVotes = $result['locked_votes'];

        // ── Sync the read model BEFORE invalidating downstream caches ─────
        // Same ordering rationale as castPageVote: PageEndpoint's cache bust
        // must fire AFTER bcc_page_read_model reflects the removal, otherwise
        // a concurrent read can repopulate the cache with pre-removal data.
        try {
            \BCC\Trust\Core\Plugin::instance()->pageReadModelRepository()->syncPage($pageId);
        } catch (\Throwable $e) {
            \BCC\Core\Log\Logger::info('[VoteService] Eager read-model sync failed on removal, deferred sync will retry', [
                'page_id' => $pageId,
                'error'   => $e->getMessage(),
            ]);
        }

        // ── Invalidate caches post-transaction (after read-model sync) ────
        // Without this the UserInfoRepository wp_cache entry (written during
        // the castPageVote that created this vote) would remain stale for up
        // to BCC_TRUST_CACHE_USER seconds on persistent object-cache backends.
        foreach ($lockedVotes as $locked) {
            $catId = (int) ($locked->category_id ?? 0);
            self::invalidateAfterVoteChange($voterId, $pageId, $catId, 'removed');
        }

        // Use first category for backward-compatible response
        $firstCategoryId = (int) ($lockedVotes[0]->category_id ?? 0);
        $scoreAfter = $this->scoreRepo->getByPageId($pageId, $firstCategoryId);

        // Audit log (post-commit, outside transaction).
        // Audit MED-4: wrap in try/catch + error_log fallback so a logger
        // failure never erases the audit trail for a vote removal — the
        // previous path silently threw past the commit and removeVote
        // events vanished with no forensic record.
        try {
            AuditLogger::removeVote($pageId, [
                'voter_id'        => $voterId,
                'previous_vote'   => $existingVote->vote_type,
                'previous_weight' => $existingVote->weight,
                'categories'      => array_map(fn($v) => (int) ($v->category_id ?? 0), $lockedVotes),
                'score_after'     => $scoreAfter  ? $scoreAfter->getTotalScore()  : null,
            ]);
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[bcc-trust] AuditLogger::removeVote FAILED page=%d voter=%d err=%s',
                $pageId,
                $voterId,
                $e->getMessage()
            ));
        }

        // Dispatch async edge recalculation
        VoteJobDispatcher::dispatchRemoval($voterId, $pageId);

        // FIX: Include votes_up / votes_down so trust-header.js can reconcile
        // vote counts immediately instead of waiting for the page-store bust.
        $voteCounts = $this->voteRepo->getVoteCountsByType($pageId);

        return [
            'action'            => 'remove_vote',
            'page_id'           => $pageId,
            'removed'           => true,
            'vote'              => null,
            'endorsement'       => null,
            'score'             => $scoreAfter ? $scoreAfter->toApiResponse() : null,
            'votes_up'          => (int) ($voteCounts['upvotes'] ?? 0),
            'votes_down'        => (int) ($voteCounts['downvotes'] ?? 0),
            'endorsement_count' => $scoreAfter ? $scoreAfter->getEndorsementCount() : 0,
        ];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Cheap synchronous Sybil gate for new/low-activity accounts.
     *
     * Only runs when account age < 30 days OR votes_cast < 10 to keep the
     * fast path fast for established voters.
     */
    private function runNewAccountSybilGate(int $voterId, int $pageId): void {
        $userInfo    = $this->getUserInfo($voterId);       // cached
        $voterUser   = $this->getVoterUserData($voterId); // cached
        $accountDays = $voterUser
            ? (time() - strtotime($voterUser->user_registered)) / DAY_IN_SECONDS
            : 0;
        $isNew = $accountDays < 30 || ($userInfo->votes_cast ?? 0) < 10;

        if (!$isNew) {
            return;
        }

        // Hard floor: account must be at least 24 hours old.
        if ($accountDays < 1) {
            throw new VoteEligibilityException('Account must be at least 24 hours old before voting.');
        }

        // Device fingerprint shared by multiple accounts?
        $fingerprint = $this->getOrGenerateFingerprint(); // cached
        $userCount   = $this->fingerprinter->getFingerprintUserCount($fingerprint);
        if ($userCount > BCC_TRUST_RING_MIN_SIZE) {
            AuditLogger::log('new_account_device_block', $pageId, [
                'voter_id'        => $voterId,
                'shared_fp_count' => $userCount,
                'account_days'    => round($accountDays, 1),
            ], 'page');
            throw new VoteEligibilityException('Vote blocked: device fingerprint associated with multiple accounts.');
        }

        // IP reuse check.
        $ip       = IpResolver::getClientIp();
        $ipBinary = ($ip && $ip !== '0.0.0.0') ? inet_pton($ip) : null;
        if ($ipBinary) {
            $ipUserCount = $this->auditLogRepo->countDistinctUsersForIp($ipBinary, $voterId, 7);
            if ($ipUserCount > BCC_TRUST_MULTI_ACCOUNT_LIMIT) {
                AuditLogger::log('new_account_ip_block', $pageId, [
                    'voter_id'      => $voterId,
                    'ip_user_count' => $ipUserCount,
                    'account_days'  => round($accountDays, 1),
                ], 'page');
                throw new VoteEligibilityException('Vote blocked: IP address associated with multiple accounts.');
            }
        }
    }

    /**
     * Synchronous fan-in pre-gate (CRIT-1 fix).
     *
     * When a page receives an abnormally high number of distinct voters in a
     * short window AND the current voter's account is young, block the vote
     * instead of letting it land before async fraud detection catches up.
     *
     * Thresholds are deliberately generous to avoid false positives on
     * legitimately popular pages — the async FraudDetector::detectFanInCoordination()
     * still handles the deeper analysis (account-age clustering, vote diversity).
     */
    private function runFanInPreGate(int $voterId, int $pageId): void {
        // Only gate new-ish accounts (under 30 days). Established accounts
        // are already protected by the maturity ramp and fraud-score-based
        // weight reduction.
        $voterUser   = $this->getVoterUserData($voterId); // cached
        $accountDays = $voterUser
            ? (int) ((time() - strtotime($voterUser->user_registered)) / DAY_IN_SECONDS)
            : 0;

        if ($accountDays >= 30) {
            return;
        }

        // Count distinct voters on this page in the last 5 minutes.
        // Uses a cheap cached query — the result is approximate under
        // high concurrency but sufficient for a pre-gate.
        $cacheKey = 'fan_in_' . $pageId;
        $recentVoters = wp_cache_get($cacheKey, 'bcc_trust_fan_in');

        if ($recentVoters === false) {
            $recentVoters = $this->voteRepo->getRecentVotersWithRegistration($pageId, 1);
            // Cache for 5 seconds — long enough to absorb duplicate DB hits on
            // near-simultaneous requests, short enough that a coordinated burst
            // of 15+ votes in a 5-second window can't all read a stale count.
            wp_cache_set($cacheKey, $recentVoters, 'bcc_trust_fan_in', 5);
        }

        $fanInThreshold = (int) apply_filters('bcc_trust_fan_in_pre_gate_threshold', 15);

        if (count($recentVoters) >= $fanInThreshold) {
            AuditLogger::log('fan_in_pre_gate_blocked', $pageId, [
                'voter_id'      => $voterId,
                'account_days'  => $accountDays,
                'recent_voters' => count($recentVoters),
                'threshold'     => $fanInThreshold,
            ], 'page');
            throw new VoteEligibilityException(
                'This page is receiving an unusually high number of votes. Please try again shortly.'
            );
        }
    }

    /**
     * Real-time coordination blocker — applies to ALL voters, not just new accounts.
     *
     * Prevents score manipulation by blocking votes on pages experiencing
     * anomalous vote velocity. Unlike the fan-in pre-gate (new accounts only),
     * this catches established accounts participating in coordinated attacks.
     *
     * Uses a lightweight Redis counter (5-minute sliding window) to avoid
     * DB pressure. Falls back to a lenient pass if Redis is unavailable.
     */
    private function assertNoActiveCoordinatedAttack(int $voterId, int $pageId): void
    {
        // DB-backed atomic counter (was wp_cache_incr which fails open when
        // Redis/object cache is unavailable or returns inconsistent values
        // across FPM workers). RateLimitRepository::incrementBucket() uses
        // INSERT ... ON DUPLICATE KEY UPDATE and is atomic across workers.
        //
        // 5-minute sliding window keyed by 300-second epoch bucket.
        $windowSeconds = 300;
        $bucket        = (int) (time() / $windowSeconds);
        $optionKey     = 'bcc_trust_coord_' . $pageId . '_' . $bucket;
        $expiresAt     = ($bucket + 1) * $windowSeconds;
        $freshValue    = '1|' . $expiresAt;

        $t0    = microtime(true);
        $count = \BCC\Trust\Core\Repositories\RateLimitRepository::incrementBucket($optionKey, $freshValue);
        $elapsedMs = (int) ((microtime(true) - $t0) * 1000);

        // Observe + REACT. Log slow calls AND flip the coordination gate into
        // "stress mode" so the system automatically tightens its defences
        // while the DB is degraded — instead of waiting for an ops human.
        $slowMs = (int) apply_filters('bcc_trust_coord_counter_slow_ms', 50);
        if ($elapsedMs >= $slowMs) {
            $this->recordCoordCounterSlowEvent($elapsedMs, $pageId);
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::warning('[bcc-trust] Coordination counter slow', [
                    'page_id'    => $pageId,
                    'elapsed_ms' => $elapsedMs,
                    'threshold'  => $slowMs,
                ]);
            }
        }

        if ($count === null) {
            // FAIL CLOSED: DB error → deny to prevent coordination attacks
            // from slipping through during DB instability.
            // Also trip stress mode so the next healthy request is met with
            // tightened thresholds until the stress window expires.
            $this->enterCoordinationStressMode('db_error');
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error('[bcc-trust] Coordination counter DB error — failing closed', [
                    'page_id' => $pageId,
                ]);
            }
            throw new VoteEligibilityException('Service temporarily unavailable. Please try again.');
        }

        // Threshold: 25 votes per page in 5 min is abnormal. Stress mode cuts
        // it roughly in half (12 by default) AND drops the fraud-gate to 0 so
        // ANY voter is blocked on an anomalous page — closing the window where
        // an attacker could flood while DB latency spikes. Both are filterable.
        $baseThreshold    = (int) apply_filters('bcc_trust_coordination_block_threshold', 25);
        $stressThreshold  = (int) apply_filters('bcc_trust_coordination_block_threshold_stress', 12);
        $inStress         = $this->inCoordinationStressMode();
        $threshold        = $inStress ? $stressThreshold : $baseThreshold;
        $fraudFloor       = $inStress
            ? (int) apply_filters('bcc_trust_coordination_fraud_floor_stress', 0)
            : (int) BCC_TRUST_FRAUD_LOW;

        if ($count > $threshold) {
            $userInfo = $this->getUserInfo($voterId);
            $fraudScore = (int) ($userInfo->fraud_score ?? 0);

            if ($fraudScore >= $fraudFloor) {
                AuditLogger::log('coordination_block', $pageId, [
                    'voter_id'    => $voterId,
                    'fraud_score' => $fraudScore,
                    'page_votes'  => $count,
                    'threshold'   => $threshold,
                    'stress_mode' => $inStress,
                ], 'page', $voterId);
                throw new VoteEligibilityException(
                    'This page is experiencing unusual voting activity. Please try again later.'
                );
            }
        }
    }

    /**
     * Record a slow coordination-counter event; flip into stress mode when
     * enough slow events accumulate inside a short window.
     *
     * Active defense, not passive logging — if the counter goes slow, the
     * coordination gate immediately hardens so attackers cannot exploit the
     * latency window before ops reacts.
     */
    private function recordCoordCounterSlowEvent(int $elapsedMs, int $pageId): void
    {
        $windowSeconds = (int) apply_filters('bcc_trust_coord_stress_window_seconds', 60);
        $threshold     = (int) apply_filters('bcc_trust_coord_stress_event_threshold', 5);
        $bucket        = (int) (time() / $windowSeconds);
        $slowKey       = 'bcc_trust_coord_slow_' . $bucket;
        $expiresAt     = ($bucket + 2) * $windowSeconds;

        $n = \BCC\Trust\Core\Repositories\RateLimitRepository::incrementBucket($slowKey, '1|' . $expiresAt);
        if ($n !== null && $n >= $threshold) {
            $this->enterCoordinationStressMode('slow_events:' . $n . '@' . $elapsedMs . 'ms');
        }
    }

    /**
     * Flip the coordination gate into stress mode for a short TTL.
     *
     * Tightens thresholds (lower trigger count, lower fraud-floor) until the
     * stress period expires. Idempotent — safe to call repeatedly; the
     * transient TTL is refreshed so a sustained event prolongs the hardening.
     */
    private function enterCoordinationStressMode(string $reason): void
    {
        $ttl    = (int) apply_filters('bcc_trust_coord_stress_ttl_seconds', 300);
        $expiry = time() + $ttl;

        // Dual-write: object-cache-backed transient (fast) AND a
        // WP-options row (survives Redis/object-cache failure — which
        // is EXACTLY when we need stress-mode most). Without the
        // options fallback, inCoordinationStressMode() silently
        // returns false when the cache layer is degraded, which is
        // the failure mode this defence is designed to contain.
        set_transient('bcc_trust_coord_stress', [
            'since'  => time(),
            'reason' => $reason,
        ], $ttl);

        // update_option() is the repository-free path for option
        // writes; autoload=false keeps this row out of the alloptions
        // hot path. Using core WP API (not raw $wpdb) keeps this
        // Services-layer method compliant with the repository-only
        // DB-access rule.
        update_option('bcc_trust_coord_stress_expires', (string) $expiry, false);

        if (class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::warning('[bcc-trust] Coordination gate entered stress mode', [
                'reason' => $reason,
                'ttl_s'  => $ttl,
            ]);
        }
    }

    private function inCoordinationStressMode(): bool
    {
        // Prefer fast path — transient (usually object-cache-backed).
        if (get_transient('bcc_trust_coord_stress')) {
            return true;
        }

        // Options fallback — readable even when Redis is dead.
        // Short-circuits back to false once expiry has passed.
        $expires = (int) get_option('bcc_trust_coord_stress_expires', 0);
        return $expires > 0 && $expires > time();
    }

    /**
     * Assemble pre-fetched fraud signals into the shape VoteWeightCalculator expects.
     * Values from DeviceFingerprinter; trust_rank from cached user_info.
     *
     * @return array<string, mixed>
     */
    private function buildCachedSignals(int $voterId, string $fingerprint): array {
        $automationData = $this->fingerprinter->detectAutomation();
        $this->fingerprinter->storeFingerprint($voterId, $fingerprint, $automationData);

        $userCount   = $this->fingerprinter->getFingerprintUserCount($fingerprint);
        $multiRisk   = $userCount > BCC_TRUST_RING_MIN_SIZE;
        $deviceFraud = $this->fingerprinter->calculateDeviceFraudProbability($voterId);

        $userInfo  = $this->getUserInfo($voterId); // cached
        $trustRank = $userInfo !== null ? (float) $userInfo->trust_rank : 0.0;

        // behavior_score: prefer cached value; 0 if not yet computed
        $behaviorScore = $userInfo !== null ? (float) $userInfo->behavior_score : 0.0;

        return [
            'automation'               => $automationData,
            'multi_account_risk'       => $multiRisk,
            'device_fraud_probability' => $deviceFraud,
            'behavior_score'           => $behaviorScore,
            'trust_rank'               => $trustRank,
        ];
    }

    /**
     * Emit a downvote velocity alert when a page receives ≥5 downvotes in an hour.
     */
    private function maybeLogDownvoteVelocity(int $voterId, int $pageId): void {
        $count = $this->voteRepo->countRecentDownvotesForPage($pageId, '1 HOUR');

        if ($count >= 5) {
            AuditLogger::log('downvote_velocity_alert', $pageId, [
                'voter_id'        => $voterId,
                'downvotes_in_1h' => $count + 1,
            ], 'page');
        }
    }

    /**
     * Resolve ALL category IDs for a page.
     *
     * Every vote now applies to all categories on the page. Returns an array
     * of term_id integers, or [0] (uncategorised sentinel) when the page
     * has no category assignments.
     *
     * @return int[]
     */
    private function resolveVoteCategoryIds(int $pageId): array {
        $categories = $this->getPageCategories($pageId);
        if (empty($categories)) {
            return [0]; // uncategorized sentinel
        }
        return array_map(fn($t) => (int) $t->term_id, $categories);
    }

    /**
     * @return object[]
     * @phpstan-return list<object{term_id: int|numeric-string}>
     */
    private function getPageCategories(int $pageId): array {
        return $this->voteRepo->getPageCategories($pageId);
    }

    private function getPageOwnerId(int $pageId): int {
        return (int) \BCC\Trust\Core\Services\PeepSoPageResolver::getOwnerId($pageId);
    }

    // ── Request-cache helpers ─────────────────────────────────────────────────

    /**
     * Retrieve a value from the request-scoped static cache.
     *
     * Returns an array with 'hit' (bool) and, on a hit, 'value' (mixed).
     * Using a result struct avoids ambiguity when the cached value is null.
     * TTL is 1 second — long enough to deduplicate calls within one request,
     * short enough to prevent stale data if PHP-FPM reuses a worker.
     *
     * Discriminated return shape: when `hit === true`, `value` is guaranteed
     * present (may be null). When `hit === false`, `value` is absent.
     *
     * @return array{hit: false}|array{hit: true, value: mixed}
     */
    private static function getCachedValue(string $key): array {
        if (!apply_filters('bcc_trust_request_cache_enabled', true)) {
            return ['hit' => false];
        }
        if (!array_key_exists($key, self::$requestCache)) {
            return ['hit' => false];
        }
        $entry = self::$requestCache[$key];
        if (time() - $entry['cached_at'] > 1) {
            unset(self::$requestCache[$key]);
            return ['hit' => false];
        }
        return ['hit' => true, 'value' => $entry['value']];
    }

    /** Store a value in the request-scoped static cache. */
    private static function setCachedValue(string $key, mixed $value): void {
        if (!apply_filters('bcc_trust_request_cache_enabled', true)) {
            return;
        }
        self::$requestCache[$key] = ['value' => $value, 'cached_at' => time()];
    }

    /**
     * Invalidate all caches that may contain stale data after a vote is cast
     * or removed.
     *
     * Covers three cache layers:
     *   1. Request-scoped static cache (self::$requestCache) — prevents the
     *      same request from observing pre-transaction userInfo or reputation
     *      data in subsequent reads (e.g. scoreAfter, audit log).
     *   2. WordPress object cache (wp_cache) — UserInfoRepository stores
     *      user_info rows under the 'bcc_trust' group. Without this call a
     *      persistent object cache (Redis, Memcached) would serve the old row
     *      for up to BCC_TRUST_CACHE_USER seconds.
     *   3. Action hooks — allow other plugins (caching layers, analytics, etc.)
     *      to react to the change without patching VoteService directly.
     *
     * The fingerprint request-cache entry is intentionally NOT cleared — it is
     * derived from HTTP request headers and cannot change mid-request.
     *
     * @param string $event  'cast' or 'removed' — passed to the specific hook.
     */

    private static function invalidateAfterVoteChange(
        int    $voterId,
        int    $pageId,
        int    $categoryId,
        string $event = 'cast'
    ): void {
        // ── 1. Request-scoped static cache ───────────────────────────────────
        unset(
            self::$requestCache["user_info:{$voterId}"],
            self::$requestCache["userdata:{$voterId}"],
            self::$requestCache["reputation:{$voterId}"]
        );

        // ── 2. WordPress object cache ─────────────────────────────────────────
        // Centralised via CacheManager: clears score repo (with category),
        // vote stats, PageDataLoader, and user_info in one call.
        CacheManager::invalidatePageCaches($pageId, $voterId, 'vote_' . $event);

        // Also clear the specific category key (CacheManager::invalidatePageCaches
        // calls invalidateCache($pageId) with null category — which clears all
        // variants.  But when we have the specific categoryId, clear it explicitly
        // for belt-and-suspenders safety on the hot path).
        \BCC\Trust\Core\Plugin::instance()->scoreRepository()->invalidateCache($pageId, $categoryId);

        // ── 3. Action hooks ───────────────────────────────────────────────────
        // Specific hook — preferred when the caller cares about the exact event.
        if ($event === 'cast') {
            /**
             * Fires after a vote is cast or updated and all caches are cleared.
             *
             * @param int $voterId    The voter's user ID.
             * @param int $pageId     The page that was voted on.
             * @param int $categoryId The vote category (0 = uncategorised).
             */
            do_action('bcc_trust_vote_cast', $voterId, $pageId, $categoryId);
        } else {
            /**
             * Fires after a vote is removed and all caches are cleared.
             *
             * @param int $voterId    The voter's user ID.
             * @param int $pageId     The page whose vote was removed.
             * @param int $categoryId The vote category (0 = uncategorised).
             */
            do_action('bcc_trust_vote_removed', $voterId, $pageId, $categoryId);
        }

        /**
         * Fires after any vote mutation (cast or removed) and all caches are cleared.
         * Useful for cache-warming or analytics that do not distinguish the event type.
         *
         * @param int    $voterId    The voter's user ID.
         * @param int    $pageId     The affected page ID.
         * @param int    $categoryId The vote category (0 = uncategorised).
         * @param string $event      'cast' or 'removed'.
         */
        do_action('bcc_trust_vote_changed', $voterId, $pageId, $categoryId, $event);
    }

    // ── Cached accessor wrappers ──────────────────────────────────────────────

    /**
     * userInfoRepo->getByUserId() with request-scoped cache.
     *
     * @phpstan-return UserInfoRow|null
     */
    private function getUserInfo(int $userId): ?object {
        $cache = self::getCachedValue("user_info:{$userId}");
        if ($cache['hit']) {
            /** @var UserInfoRow|null */
            return $cache['value'];
        }
        $value = $this->userInfoRepo->getByUserId($userId);
        self::setCachedValue("user_info:{$userId}", $value);
        return $value;
    }

    /** get_userdata() with request-scoped cache. */
    private function getVoterUserData(int $userId): \WP_User|false {
        $cache = self::getCachedValue("userdata:{$userId}");
        if ($cache['hit']) {
            /** @var \WP_User|false */
            return $cache['value'];
        }
        $value = get_userdata($userId);
        self::setCachedValue("userdata:{$userId}", $value);
        return $value;
    }

    /** reputationRepo->getByUserId() with request-scoped cache. */
    private function getVoterReputation(int $userId): ?object {
        $cache = self::getCachedValue("reputation:{$userId}");
        if ($cache['hit']) {
            /** @var object|null */
            return $cache['value'];
        }
        $value = $this->reputationRepo->getByUserId($userId);
        self::setCachedValue("reputation:{$userId}", $value);
        return $value;
    }

    /**
     * fingerprinter->generateFingerprint() with request-scoped cache.
     *
     * The fingerprint is derived from HTTP request headers/server state and is
     * identical for every call within the same request. Caching it avoids
     * repeated header parsing, hashing, and any internal DB reads the
     * fingerprinter may perform.
     */
    private function getOrGenerateFingerprint(): string {
        $cache = self::getCachedValue('fingerprint');
        if ($cache['hit']) {
            /** @var string */
            return $cache['value'];
        }
        $value = $this->fingerprinter->generateFingerprint();
        self::setCachedValue('fingerprint', $value);
        return $value;
    }

    // =========================================================================
    // Public read methods (unchanged from previous version)
    // =========================================================================

    /**
     * @phpstan-return object{
     *   id: int|numeric-string,
     *   voter_user_id: int|numeric-string,
     *   page_id: int|numeric-string,
     *   category_id: int|numeric-string,
     *   vote_type: int|numeric-string,
     *   weight: float|numeric-string,
     *   vested_weight: float|numeric-string,
     *   fraud_score_at_vote: int|numeric-string|null,
     *   vesting_stage: int|numeric-string,
     *   vesting_started_at: string|null,
     *   fully_vested_at: string|null,
     *   weight_corrected_at: string|null,
     *   reason: string|null,
     *   explanation: string|null,
     *   status: int|numeric-string,
     *   ip_address: string|null,
     *   created_at: string,
     *   updated_at: string
     * }|null
     */
    public function getUserPageVote(int $pageId, ?int $userId = null): ?object {
        $userId = $userId ?? get_current_user_id();
        if (!$userId) return null;
        return $this->voteRepo->get($userId, $pageId);
    }

    public function hasUserVotedPage(int $pageId, ?int $userId = null): bool {
        return (bool) $this->getUserPageVote($pageId, $userId);
    }

    /**
     * Returns recent votes enriched with the voter's current fraud score and
     * risk level. Each entry is a shallow array of the VoteByVoterRow columns
     * plus `voter_fraud_score` and `voter_risk_level` — consumed by the
     * `recent_votes` payload of getUserVoteSummary().
     *
     * Voter-enrichment fields cannot live on the VoteByVoterRow shape (they
     * are not in the SQL), so the return type widens to associative arrays
     * to carry the composed shape without mutating the repository result.
     *
     * @return list<array<string, mixed>>
     */
    public function getUserVotes(?int $userId = null, int $limit = 20): array {
        $userId = $userId ?? get_current_user_id();
        if (!$userId) return [];
        $votes          = $this->voteRepo->getByVoter($userId, $limit);
        $userInfo       = $this->userInfoRepo->getByUserId($userId);
        $voterFraud     = $userInfo !== null ? $userInfo->fraud_score : 0;
        $voterRiskLevel = $userInfo !== null ? $userInfo->risk_level  : 'unknown';

        $enriched = [];
        foreach ($votes as $vote) {
            $row = (array) $vote;
            $row['voter_fraud_score'] = $voterFraud;
            $row['voter_risk_level']  = $voterRiskLevel;
            $enriched[] = $row;
        }
        return $enriched;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPageVoteStats(int $pageId): array {
        $stats  = $this->voteRepo->getPageStats($pageId);
        $counts = $this->voteRepo->getVoteCountsByType($pageId);
        $score  = $this->scoreRepo->getByPageId($pageId);
        return [
            'total_votes'          => $stats->total_votes ?? 0,
            'unique_voters'        => $stats->unique_voters ?? 0,
            'positive_votes'       => $counts['upvotes'],
            'negative_votes'       => $counts['downvotes'],
            'positive_weight'      => $counts['upvote_weight'],
            'negative_weight'      => $counts['downvote_weight'],
            'avg_upvote_weight'    => $counts['upvote_avg_weight'] ?? 0,
            'avg_downvote_weight'  => $counts['downvote_avg_weight'] ?? 0,
            'last_vote_at'         => $stats->last_vote_at,
            'has_fraud_alerts'     => $score ? $score->hasFraudAlerts() : false,
        ];
    }

    /**
     * @return object[]
     */
    public function getSuspiciousVotesForPage(int $pageId, float $minFraudScore = BCC_TRUST_FRAUD_MEDIUM): array {
        return $this->voteRepo->getVotesWithFraud($pageId, $minFraudScore);
    }

    /**
     * @return array<string, mixed>
     */
    public function getUserVoteSummary(int $userId): array {
        $userInfo    = $this->userInfoRepo->getByUserId($userId);
        $recentVotes = $this->getUserVotes($userId, 10);
        $reputation  = $this->reputationRepo->getByUserId($userId);
        return [
            'user_id'          => $userId,
            'total_votes_cast' => $userInfo ? $userInfo->votes_cast : 0,
            'recent_votes'     => $recentVotes,
            'voter_tier'       => $reputation->reputation_tier ?? 'neutral',
            'fraud_score'      => $userInfo ? $userInfo->fraud_score : 0,
            'risk_level'       => $userInfo ? $userInfo->risk_level  : 'unknown',
        ];
    }

    /**
     * Recalculate a page's score from its full vote history.
     *
     * Race-condition fix (Issue 8):
     *  - The score row is locked with SELECT … FOR UPDATE before any read or
     *    write inside the transaction, so concurrent applyVoteDelta() calls
     *    block until the recalculation commits.
     *  - The vote snapshot is taken inside the transaction so it is consistent
     *    with the lock epoch.
     *  - The final write uses saveRecalculated() (UPDATE) instead of save()
     *    (REPLACE INTO) to avoid the DELETE + INSERT gap that REPLACE creates,
     *    which could otherwise discard a concurrent delta increment.
     *  - save() (REPLACE INTO) is kept as the fallback only when no score row
     *    exists yet — i.e. the very first recalculation for the page.
     */
    /**
     * @param bool $clearRecalcFlag  If true, clears recalculate_required inside
     *                               the FOR UPDATE transaction. This prevents the
     *                               flag-clearing race where a live vote sets the
     *                               flag between the recalc COMMIT and an external
     *                               flag-clear UPDATE. Only the cron processor
     *                               should pass true — other callers (admin repair,
     *                               hourly cron) don't use the flag.
     * @return array<string, mixed>
     */
    public function recalculateScore(int $pageId, bool $clearRecalcFlag = false): array {
        $ownerId = $this->getPageOwnerId($pageId);

        // Page-scoped advisory lock. Serializes recalculateScore with any
        // concurrent VoteWriter::write on the same page so a brand-new
        // category-row INSERT cannot slip past lockAllForPage()'s row-level
        // locks (which by definition cannot cover rows that don't exist yet).
        // Released by $wpdb's session auto-release on connection close AND
        // explicitly in the finally block below.
        $lockKey      = 'bcc_score_page_' . $pageId;
        $lockAcquired = false;
        if (class_exists('\\BCC\\Core\\DB\\AdvisoryLock')) {
            $lockAcquired = \BCC\Core\DB\AdvisoryLock::acquire($lockKey, 10);
            // If the lock cannot be acquired within 10s, proceed without it
            // rather than silently skipping — a result is still produced. The
            // narrow new-category-insert race can only bite here, so a lock-less
            // recalc deliberately does NOT clear recalculate_required (see the
            // $lockAcquired guards below): the page re-processes on the next
            // cron tick and self-corrects. Logged for ops.
            if (!$lockAcquired && class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::warning('[bcc-trust] recalculateScore advisory lock timed out', [
                    'page_id' => $pageId,
                    'key'     => $lockKey,
                ]);
            }
        }

        try {
        $newScore = TransactionManager::run(function () use ($pageId, $ownerId, $clearRecalcFlag, $lockAcquired) {

            // Lock ALL score rows for this page before reading anything else.
            // This serialises concurrent recalculations and concurrent
            // applyVoteDelta() calls that target any category row for this page.
            $lockedRows = $this->scoreRepo->lockAllForPage($pageId);

            // Aggregate vote scores inside the lock using SQL SUM/COUNT.
            // This replaces the prior getAllForPage(PHP_INT_MAX) + PHP loop
            // approach, eliminating O(N) PHP memory for vote aggregation.
            // The SQL query applies the same fraud discount and time decay
            // formulas that the PHP loop used, producing identical results.
            $voteAggregates = $this->voteRepo->getVoteAggregatesForPage($pageId);

            // Fast path: no active votes → ensure default row and return.
            // Checked INSIDE the lock to prevent TOCTOU race where a
            // concurrent first vote commits between an outside-lock COUNT
            // check and the FOR UPDATE acquisition.
            if ((int) $voteAggregates->vote_count === 0) {
                $score = $this->scoreRepo->getByPageId($pageId)
                    ?? $this->scoreRepo->createDefault($pageId, $ownerId);
                // Only clear the flag when the page lock was actually held —
                // a lock-less recalc leaves it set so the next tick re-processes.
                if ($clearRecalcFlag && $lockAcquired) {
                    $this->scoreRepo->clearRecalcFlagAndFailures($pageId);
                }
                return $score;
            }

            // Derive existing score for endorsement_count / fraud_metadata
            // preservation. Use the first locked row if available.
            $existing = !empty($lockedRows)
                ? $this->scoreRepo->getByPageId($pageId, (int) $lockedRows[0]->category_id)
                : null;

            $newScore = $this->recalculateFromVotes($pageId, $voteAggregates, $ownerId, $existing);

            if (!empty($lockedRows)) {
                // Update every category row with the recalculated score.
                foreach ($lockedRows as $row) {
                    $this->scoreRepo->saveRecalculated($newScore, (int) $row->category_id);
                }
            } else {
                // No row yet — first recalculation; REPLACE INTO is safe here
                // because no concurrent delta can race on a non-existent row.
                $this->scoreRepo->save($newScore);
            }

            // Clear the cron recalculation flag INSIDE the transaction while
            // the scores rows are still locked. This prevents the race where a
            // live vote sets recalculate_required = 1 between COMMIT and an
            // external flag-clear:
            //
            //   - Any live vote that arrives during this transaction blocks on
            //     the FOR UPDATE lock. It will commit AFTER us and then call
            //     flagForRecalculation(), which re-sets the flag.
            //
            //   - Any live vote that committed BEFORE this transaction started
            //     is visible in our REPEATABLE READ snapshot (via the
            //     aggregate query), so the recalculated score already
            //     includes it.
            // ...and only when the page advisory lock was actually held. A
            // lock-less recalc (10s acquire timeout) can miss a brand-new
            // category vote that raced the unlocked window, so we leave the
            // flag set and let the next cron tick re-process with the lock.
            if ($clearRecalcFlag && $lockAcquired && !empty($lockedRows)) {
                $this->scoreRepo->clearRecalcFlagAndFailures($pageId);
            }

            return $newScore;
        });

        return $newScore->toApiResponse();
        } finally {
            if ($lockAcquired && class_exists('\\BCC\\Core\\DB\\AdvisoryLock')) {
                \BCC\Core\DB\AdvisoryLock::release($lockKey);
            }
        }
    }

    // =========================================================================
    // Score recalculation helpers (used by recalculateScore cron)
    // =========================================================================

    /**
     * Build a PageScore from pre-aggregated vote data plus the vouch-
     * attestation count (the endorsement_count display denorm).
     *
     * Vote aggregation (positive/negative scores, counts) is now performed
     * in SQL by VoteRepository::getVoteAggregatesForPage(), eliminating the
     * O(N) PHP memory cost of loading every vote row. The fraud discount and
     * time decay formulas are replicated identically in the SQL expressions.
     *
     * @param int            $pageId
     * @param object         $voteAggregates  Result from getVoteAggregatesForPage():
     *                                        positive_score, negative_score,
     *                                        vote_count, unique_voters,
     *                                        mature_unique_voters, last_vote_at.
     * @param int            $ownerId
     * @param PageScore|null $existing         Already-fetched score row, if any.
     *
     * @phpstan-param object{
     *   positive_score: float|numeric-string,
     *   negative_score: float|numeric-string,
     *   vote_count: int|numeric-string,
     *   unique_voters: int|numeric-string,
     *   mature_unique_voters?: int|numeric-string,
     *   last_vote_at: string|null
     * } $voteAggregates
     */
    private function recalculateFromVotes(int $pageId, object $voteAggregates, int $ownerId, ?PageScore $existing = null): PageScore {
        $positive           = (float) $voteAggregates->positive_score;
        $negative           = (float) $voteAggregates->negative_score;
        $voteCount          = (int)   $voteAggregates->vote_count;
        $uniqueVoters       = (int)   $voteAggregates->unique_voters;
        $matureUniqueVoters = (int)   ($voteAggregates->mature_unique_voters ?? 0);
        $lastVoteAt         = $voteAggregates->last_vote_at;
        $netScore           = $positive - $negative;

        // endorsement_count display denorm — sourced from the Trust
        // Attestation Layer (active kind=vouch rows) since the frozen
        // bcc_trust_endorsements table was retired. The COLUMN name and
        // every downstream consumer (PageScore VO, read model, cards,
        // search's `endorsements` field + sort axis, wp-admin tables)
        // stay untouched; only the data source changed.
        $endorsementCount = $this->countActiveVouchAttestations($pageId);

        // Preserve fields that recalculation does not recompute. penalty_adjustment
        // and attestation_bonus (Slice E) are written by their own dedicated paths
        // (applyPenalty / applyAttestationBonus) and MUST be carried through here —
        // otherwise this vote recompute would write a total_score missing those
        // terms, silently clobbering a member's dispute penalty or attestation
        // backing on the next vote (they live in their own columns but total_score
        // is computed in PHP here, so the components must be threaded in).
        $onchainBonus      = $existing ? $existing->getOnchainBonus() : 0.0;
        $contributionBonus = $existing ? $existing->getContributionBonus() : 0.0;
        $penaltyAdjustment = $existing ? $existing->getPenaltyAdjustment() : 0.0;
        $attestationBonus  = $existing ? $existing->getAttestationBonus() : 0.0;
        $fraudMetadata     = $existing ? $existing->getFraudMetadata() : null;

        // Canonical formula (single source of truth — TrustScoreService):
        // base + onchain_bonus + contribution_bonus + penalty_adjustment +
        // attestation_bonus. contribution_bonus is 0 for entity pages; non-zero
        // only on member self-pages (preserved here, written by
        // ScoreRepository::applyContributionBonus).
        $totalScore = \BCC\Trust\Core\Services\TrustScoreService::compute(
            $positive,
            $negative,
            $onchainBonus,
            $contributionBonus,
            $penaltyAdjustment,
            $attestationBonus
        );

        // §J.12: the native score (conduct excluding onchain_bonus) and the
        // elite-eligibility gate are BOTH required to resolve the tier. Both
        // are carried from the existing row — this path never computes or
        // rewrites them, it only reads them so the tier it writes agrees with
        // the one tierSql() would have written.
        $nativeScore = \BCC\Trust\Core\Services\TrustScoreService::computeNative(
            $positive,
            $negative,
            $contributionBonus,
            $penaltyAdjustment,
            $attestationBonus
        );
        $eliteEligible   = $existing ? $existing->isEliteEligible() : true;
        $eliteEligibleAt = $existing ? $existing->getEliteEligibleAt() : null;

        $confidenceScore = $this->calculateConfidenceScore($voteCount, $uniqueVoters, $matureUniqueVoters, $positive, $negative);
        $tier            = $this->determineTier($totalScore, $nativeScore, $eliteEligible);

        return new PageScore(
            $pageId, $ownerId, $totalScore, $positive, $negative,
            $voteCount, $uniqueVoters, $confidenceScore, $tier,
            $endorsementCount,
            $lastVoteAt ? new DateTimeImmutable($lastVoteAt) : null,
            new DateTimeImmutable(),
            $fraudMetadata,
            $onchainBonus,
            $contributionBonus,
            $penaltyAdjustment,
            $attestationBonus,
            $eliteEligible ? 1 : 0,
            $eliteEligibleAt
        );
    }

    /**
     * Active vouch-attestation count for a score-row page — the source of
     * the endorsement_count denorm. Maps the score-row page id back onto
     * the attestation target space per the locked target_id invariant:
     * a member self-page (page_id > ID_BASE) counts user_profile vouches
     * on the RAW member id; an entity page counts card-kind vouches on
     * the page id itself (a page has exactly one true card kind, so the
     * IN-list over PAGE_TARGET_KINDS is exact, not approximate).
     */
    private function countActiveVouchAttestations(int $pageId): int {
        if ($pageId <= 0) {
            return 0;
        }
        if (MemberSelfPageService::isSelfPage($pageId)) {
            return $this->attestationRepo->countActiveVouchesForTarget(
                ['user_profile'],
                MemberSelfPageService::ownerOfSelfPage($pageId)
            );
        }
        return $this->attestationRepo->countActiveVouchesForTarget(
            AttestationRepository::PAGE_TARGET_KINDS,
            $pageId
        );
    }

    /**
     * Confidence score blends volume, diversity, and balance.
     *
     * Diversity uses $matureUniqueVoters (accounts older than the
     * bcc_trust_confidence_min_voter_age_days threshold, default 7) instead
     * of raw $uniqueVoters. Rationale: weight math already reduces a fresh
     * account's score contribution to ~3% of an established account, but
     * raw unique-voters was weight-agnostic — 24 one-day-old sockpuppets
     * could push diversity to 1.0 and inflate the confidence tier with no
     * real trust signal. Gating diversity on maturity closes that vector
     * without blocking new-user participation.
     */
    private function calculateConfidenceScore(int $voteCount, int $uniqueVoters, int $matureUniqueVoters, float $positive, float $negative): float {
        if ($voteCount === 0) return 0.0;
        $volumeConf   = min(1, $voteCount / BCC_TRUST_MAX_CONFIDENCE_VOTES);
        $diverseConf  = $matureUniqueVoters / max(1, $voteCount);
        $total        = $positive + $negative;
        $balanceConf  = $total > 0 ? 1 - (abs($positive - $negative) / $total * 0.5) : 1.0;
        return min(1.0, round($volumeConf * 0.5 + $diverseConf * 0.3 + $balanceConf * 0.2, 2));
    }

    /**
     * Thin delegate to the canonical ladder. Was a local copy until the §J.12
     * elite gate landed — and it was the P0 in that change: this path runs on
     * the 5-minute recalculation queue, the hourly recalc, and synchronously
     * after every dispute resolution, so a gate applied only in tierSql() was
     * reverted here within five minutes of every demotion.
     *
     * @param bool $eliteEligible Resolved gate from the existing score row.
     */
    private function determineTier(float $score, float $nativeScore, bool $eliteEligible): string {
        return \BCC\Trust\Core\Services\TrustScoreService::tierFor($score, $nativeScore, $eliteEligible);
    }

}
