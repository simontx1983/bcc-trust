<?php
/**
 * Vote Eligibility Checker
 *
 * Gate stage of the vote pipeline. Performs every read-only check that must
 * pass before a vote is written. Throws VoteEligibilityException on any
 * rejection so the caller never reaches the write stage.
 *
 * Responsibilities:
 *  - Authentication guard
 *  - Vote-type validation
 *  - Page existence check
 *  - Self-vote prevention
 *  - Downvote reputation gate (trusted/elite only)
 *  - Activity gate (email-verified + at least one content action)
 *  - Duplicate vote check
 *  - Rate limiting
 *
 * Caching:
 *  - User-level checks (downvote gate + activity gate) are cached in
 *    the WordPress object cache for 120 seconds per user.
 *  - Page-specific checks (self-vote, duplicate) always run live.
 *  - Cache is invalidated via the bcc_trust_vote_cast and
 *    bcc_trust_vote_removed action hooks.
 *  - Any cache read/write failure falls back silently to live checks.
 *
 * No database writes to vote tables. Returns the existing vote row (or null)
 * so later pipeline stages can read it without a second query.
 *
 * @package BCC\Trust\Core\Services\Vote
 * @version 1.1.0
 */

namespace BCC\Trust\Core\Services\Vote;

use BCC\Trust\Core\DTO\VoteEligibilityCacheEntry;
use BCC\Trust\Core\Exceptions\VoteEligibilityException;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Security\RateLimiter;

if (!defined('ABSPATH')) {
    exit;
}

class VoteEligibilityChecker {

    private const DOWNVOTE_TIERS = ['trusted', 'elite'];

    /** Cache TTL in seconds. */
    private const CACHE_TTL = 120;

    /**
     * One-time cache for the PeepSo pages table existence check.
     */
    public function __construct(
        private readonly VoteRepository       $voteRepo,
        private readonly ReputationRepository $reputationRepo,
        private readonly UserInfoRepository   $userInfoRepo,
    ) {}

    /**
     * Run all eligibility checks in order.
     *
     * @param  int       $voterId   Current user ID (0 = not logged in).
     * @param  int       $pageId    Target page.
     * @param  int       $voteType  1 = upvote, -1 = downvote.
     * @return object|null          Existing vote row, or null if first-time voter.
     *
     * @throws VoteEligibilityException  On any failed check.
     */
    public function check(int $voterId, int $pageId, int $voteType): ?object {
        // --- Fast checks: no DB needed ---
        $this->assertAuthenticated($voterId);
        $this->assertValidVoteType($voteType);

        // --- Page checks: always live (page-specific) ---
        $this->assertPageExists($pageId);
        $this->assertNotSelfVote($voterId, $pageId);
        $this->assertNotRetaliatoryDownvote($voterId, $pageId, $voteType);

        // --- User-level checks: served from cache when available ---
        $this->assertUserLevelEligibility($voterId, $voteType);

        // --- Vote-specific checks: always live (depend on current vote state) ---
        $existing = $this->voteRepo->get($voterId, $pageId);
        $this->assertNotDuplicate($existing, $voteType);

        $this->enforceRateLimit();

        return $existing;
    }

    // -------------------------------------------------------------------------
    // User-level eligibility — cached in WordPress object cache
    // -------------------------------------------------------------------------

    /**
     * Run user-level checks (downvote gate + activity gate), consulting the
     * DB cache first. Falls back to live checks if the cache is unavailable.
     *
     * The cache is keyed by (user_id, vote_type) so that upvote and downvote
     * eligibility are evaluated and cached independently. Page-specific checks
     * always run live.
     *
     * @throws VoteEligibilityException
     */
    private function assertUserLevelEligibility(int $voterId, int $voteType): void {
        // Only BCC_TRUST_TEST_MODE bypasses user-level gates. Role-based
        // exemptions were removed: an admin account on a production site
        // could otherwise vote/downvote with zero verification, activity,
        // tier or rate-limit gating — a trivial abuse path if the admin
        // account is ever compromised or used casually.
        if (defined('BCC_TRUST_TEST_MODE') && \BCC_TRUST_TEST_MODE) {
            return;
        }

        $cached = $this->readCache($voterId, $voteType);

        if ($cached !== null) {
            // Cache hit: honour the stored result without any repository queries.
            if (!$cached->is_eligible) {
                $reason = ($cached->reason !== null && $cached->reason !== '')
                    ? $cached->reason
                    : 'Voting not permitted for this account.';
                throw new VoteEligibilityException($reason);
            }
            // Eligible — skip live checks entirely.
            return;
        }

        // Cache miss: run live checks and store the outcome.
        $reason = null;
        try {
            if ($voteType === -1) {
                $this->assertDownvoteEligibility($voterId);
            }
            $this->assertActivityGate($voterId);
            $eligible = true;
        } catch (VoteEligibilityException $e) {
            $eligible = false;
            $reason   = $e->getMessage();
        }

        $this->writeCache($voterId, $voteType, $eligible, $reason);

        if (!$eligible) {
            throw new VoteEligibilityException($reason);
        }
    }

    // -------------------------------------------------------------------------
    // Cache read / write / invalidation
    // -------------------------------------------------------------------------

    /** Object cache group for eligibility results. */
    private const CACHE_GROUP = 'bcc_trust';

    /**
     * Read a cached eligibility result for this user + vote type.
     *
     * Returns the cached DTO on hit, null on miss OR on any shape mismatch
     * (treated as a cache miss so the live checks re-populate). Zero DB
     * queries — served entirely from the WordPress object cache.
     */
    private function readCache(int $voterId, int $voteType): ?VoteEligibilityCacheEntry {
        $key    = "eligibility_{$voterId}_{$voteType}";
        $cached = wp_cache_get($key, self::CACHE_GROUP);

        if ($cached instanceof VoteEligibilityCacheEntry) {
            return $cached;
        }
        return null;
    }

    /**
     * Write an eligibility result to the WordPress object cache.
     *
     * TTL is 120 seconds (CACHE_TTL). Replaces the previous DB-backed
     * bcc_trust_eligibility table — no INSERT/DELETE queries, no row
     * accumulation, no expired-row cleanup cron needed.
     */
    private function writeCache(int $voterId, int $voteType, bool $eligible, ?string $reason): void {
        $key       = "eligibility_{$voterId}_{$voteType}";
        $truncated = ($reason !== null && $reason !== '') ? substr($reason, 0, 100) : null;
        $entry     = new VoteEligibilityCacheEntry(
            is_eligible: $eligible,
            reason:      $truncated,
        );

        wp_cache_set($key, $entry, self::CACHE_GROUP, self::CACHE_TTL);
    }

    /**
     * Invalidate cached eligibility for a user (both vote types).
     *
     * Called from VoteService::invalidateAfterVoteChange() via action hooks.
     */
    public static function invalidateForUser(int $userId): void {
        wp_cache_delete("eligibility_{$userId}_1", self::CACHE_GROUP);
        wp_cache_delete("eligibility_{$userId}_-1", self::CACHE_GROUP);
    }

    // -------------------------------------------------------------------------
    // Individual guard methods — each tests exactly one condition
    // -------------------------------------------------------------------------

    private function assertAuthenticated(int $voterId): void {
        if ($voterId <= 0) {
            throw new VoteEligibilityException('User not authenticated');
        }

        // Rank Phase 5 final policy (§5.3 / §16.2): New Members — no
        // rank_state row — cannot cast reputation-bearing votes.
        // Apprentice or higher suffices; Journeyman is never required.
        if (\BCC\Trust\Core\Plugin::instance()->rankStateRepository()->getForUser($voterId) === null) {
            throw new VoteEligibilityException(
                'Become an Apprentice to cast reputation-bearing votes.'
            );
        }
    }

    private function assertValidVoteType(int $voteType): void {
        if (!in_array($voteType, [-1, 1], true)) {
            throw new VoteEligibilityException('Invalid vote type');
        }
    }

    private function assertPageExists(int $pageId): void {
        // Self-page (Slice 2, Architecture A): a member IS a trust subject.
        // Self-pages live only in bcc_trust_page_scores — there is no
        // peepso_pages / wp_posts row — so validate via the deterministic
        // id scheme + the owning user actually existing.
        if (\BCC\Trust\Core\Services\MemberSelfPageService::isSelfPage($pageId)) {
            $ownerId = \BCC\Trust\Core\Services\MemberSelfPageService::ownerOfSelfPage($pageId);
            if ($ownerId > 0 && get_userdata($ownerId) !== false) {
                return;
            }
            throw new VoteEligibilityException('Invalid page');
        }

        if (\BCC\Trust\Core\Repositories\PeepSoQueryRepository::pageExists($pageId)) {
            return;
        }

        // Fall back to standard WP post
        if (get_post($pageId)) {
            return;
        }

        throw new VoteEligibilityException('Invalid page');
    }

    private function assertNotSelfVote(int $voterId, int $pageId): void {
        // Only BCC_TRUST_TEST_MODE may bypass self-vote. The previous
        // manage_options exemption allowed any admin account to inflate
        // their own page's trust score at will.
        if (defined('BCC_TRUST_TEST_MODE') && \BCC_TRUST_TEST_MODE) {
            return;
        }

        $ownerId = $this->resolvePageOwner($pageId);

        // Fail CLOSED if the owner resolver returned 0. That means either:
        //  - PeepSo is inactive and the ServiceLocator returned a NullObject,
        //  - the page has no owner yet (race with page creation), or
        //  - the resolver threw and was silently swallowed upstream.
        // In all three cases we cannot distinguish "not self" from "we
        // don't know" — the only safe choice is to refuse the vote rather
        // than let a self-vote slip through a degraded resolver.
        if ($ownerId <= 0) {
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::warning('[bcc-trust] assertNotSelfVote: resolver returned 0 — failing closed', [
                    'page_id'  => $pageId,
                    'voter_id' => $voterId,
                ]);
            }
            throw new VoteEligibilityException('This page is not ready to receive votes.');
        }

        if ($ownerId === $voterId) {
            throw new VoteEligibilityException('Page owner cannot vote on their own page.');
        }
    }

    /**
     * Retaliation guard for direct person-reviews (Slice 2).
     *
     * Refuses a DOWN-review on a member's self-page when that member
     * filed a user-report against the voter within
     * BCC_TRUST_RETALIATION_WINDOW_DAYS. Closes the "you reported me, so
     * I'll down-rate you" path that opens once people are directly
     * down-voteable.
     *
     * Scoped narrowly so it cannot affect the existing entity-vote flow:
     *   - only downvotes (voteType === -1) — upvotes are never retaliatory;
     *   - only self-pages — entity pages carry no reporter/voter relation;
     *   - runs LIVE (page-specific + relational), never cached, since the
     *     user-level eligibility cache is keyed only by (voter, voteType).
     */
    private function assertNotRetaliatoryDownvote(int $voterId, int $pageId, int $voteType): void {
        if ($voteType !== -1) {
            return;
        }
        if (defined('BCC_TRUST_TEST_MODE') && \BCC_TRUST_TEST_MODE) {
            return;
        }
        if (!\BCC\Trust\Core\Services\MemberSelfPageService::isSelfPage($pageId)) {
            return;
        }

        $targetUserId = \BCC\Trust\Core\Services\MemberSelfPageService::ownerOfSelfPage($pageId);
        if ($targetUserId <= 0) {
            return; // assertNotSelfVote already fails closed on a bad owner.
        }

        $windowDays = defined('BCC_TRUST_RETALIATION_WINDOW_DAYS')
            ? (int) BCC_TRUST_RETALIATION_WINDOW_DAYS
            : 14;
        $cutoff = gmdate('Y-m-d H:i:s', (int) current_time('timestamp') - ($windowDays * DAY_IN_SECONDS));

        if (\BCC\Trust\Disputes\Repositories\UserReportRepository::existsRecentReportBetween(
            $targetUserId,
            $voterId,
            $cutoff
        )) {
            throw new VoteEligibilityException(
                'You recently were reported by this member, so you cannot down-review them right now.'
            );
        }
    }

    /**
     * Downvote eligibility — multi-layered defence against weaponization.
     *
     * Requirements (all must pass):
     *  1. Trusted or elite reputation tier
     *  2. At least one identity verification (GitHub OR wallet)
     *  3. Daily downvote rate limit (max 3 per day)
     *
     * Identity verification makes bot farms significantly harder to scale.
     * The daily rate limit prevents targeted vote-bombing campaigns.
     */
    private function assertDownvoteEligibility(int $voterId): void {
        $reputation = $this->reputationRepo->getByUserId($voterId);
        $tier       = $reputation->reputation_tier ?? 'neutral';

        if (!in_array($tier, self::DOWNVOTE_TIERS, true)) {
            throw new VoteEligibilityException(
                'Only trusted or elite members can downvote.'
            );
        }

        // Require at least one identity verification (GitHub or any wallet).
        $verificationService = new \BCC\Trust\Core\Application\WalletVerificationReadService();
        $hasGithub = $verificationService->hasVerification($voterId, 'github');
        $hasWallet = $verificationService->hasVerifiedWallet($voterId);

        if (!$hasGithub && !$hasWallet) {
            throw new VoteEligibilityException(
                'Identity verification required for downvoting.'
            );
        }

        // Daily downvote rate limit: max 3 downvotes per 24 hours.
        $this->assertDownvoteRateLimit($voterId);
    }

    /**
     * Enforce a daily downvote cap (3 per 24h).
     *
     * Uses the trust-engine's atomic RateLimiter (wp_options CONCAT/CAST
     * pattern) which is safe under concurrent requests.
     */
    private function assertDownvoteRateLimit(int $voterId): void {
        try {
            RateLimiter::enforce('downvote_daily', 3, DAY_IN_SECONDS);
        } catch (\Exception $e) {
            if ($e->getCode() === 429) {
                throw new VoteEligibilityException(
                    'Daily downvote limit reached.'
                );
            }
            throw $e; // Re-throw unexpected errors
        }
    }

    /**
     * Voter must be email-verified before voting.
     *
     * The previous "activity gate" (requiring votes_cast > 0 or
     * content_actions > 0) was removed because it created a circular
     * dependency: users couldn't vote without prior activity, but
     * couldn't get activity without voting. New users are adequately
     * protected by the weight system — a brand-new unverified-tier
     * account's vote is worth ~5% of an elite user's vote due to:
     *   - Low base weight (BCC_TRUST_WEIGHT_NEUTRAL = 0.15)
     *   - New account factor (×0.70)
     *   - Vesting stage 0 (×0.30)
     *   - Neutral tier multiplier (×1.0)
     */
    private function assertActivityGate(int $voterId): void {
        $userInfo = $this->userInfoRepo->getByUserId($voterId);

        if (!$userInfo || !$userInfo->is_verified) {
            throw new VoteEligibilityException(
                'Email verification required before voting.'
            );
        }

        // Block high-risk users (fraud_score >= BCC_TRUST_FRAUD_HIGH = 60) outright.
        // Their votes would carry near-zero weight via FraudDiscountCalculator's
        // additive penalty model, but rejecting here avoids DB pollution from
        // negligible-weight vote rows.
        if ((int) ($userInfo->fraud_score ?? 0) >= BCC_TRUST_FRAUD_HIGH) {
            throw new VoteEligibilityException(
                'Voting is temporarily unavailable.'
            );
        }
    }

    /**
     * Reject a vote if the user already cast a vote of the same type on this
     * page. Only `vote_type` is inspected — the shape annotation narrows the
     * param to exactly that column, which is all the duplicate check requires.
     *
     * @param object{vote_type: int|numeric-string}|null $existing
     */
    private function assertNotDuplicate(?object $existing, int $voteType): void {
        if ($existing !== null && (int) $existing->vote_type === $voteType) {
            throw new VoteEligibilityException('You have already voted on this page.');
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Enforce the vote rate limit.
     *
     * Extracted to a protected method so test subclasses can override it
     * without needing to stub the static RateLimiter call:
     *
     *     class TestableChecker extends VoteEligibilityChecker {
     *         protected function enforceRateLimit(): void {}
     *     }
     */
    protected function enforceRateLimit(): void {
        RateLimiter::enforce(
            'vote',
            BCC_TRUST_RATE_LIMIT_DEFAULT,
            BCC_TRUST_RATE_WINDOW_DEFAULT
        );
    }

    private function resolvePageOwner(int $pageId): int {
        return \BCC\Trust\Core\Plugin::instance()->pageOwnerResolver()->getPageOwner($pageId);
    }
}
