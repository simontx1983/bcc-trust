<?php
/**
 * MemberSelfPageService — the "People as First-Class Trust Subjects" keystone.
 *
 * Every member is a trust subject: a "self-page" — a `bcc_trust_scores` row
 * whose `page_owner_id` is the member themselves and whose `total_score`
 * becomes the member's trust tier (direct reviews/votes ON the person +
 * endorsements/vouches + contribution, via the same TrustScoreService engine
 * as entity pages). This unifies member trust into the page-score model
 * (Architecture A); see docs + the People-redesign plan.
 *
 * Self-page ids are minted DETERMINISTICALLY off a high reserved base, so
 * resolution is zero-DB and collision-free: every real wp_post / peepso_page
 * id is an auto-increment from 1 (~1e9 is unreachable for any real site), so
 * any `page_id` above ID_BASE is, unambiguously, member `(page_id - ID_BASE)`'s
 * self-page. `selfPageId(u) = ID_BASE + u`; `ownerOfSelfPage(p) = p - ID_BASE`.
 *
 * Fully wired: tier reads (ScoreRepository/ReputationRepository), direct
 * member reviews (PostsEndpoint kind=review target_kind=user_profile →
 * write; CardReviewsEndpoint target_kind=user_profile → read), attestation
 * synthesis, endorsements, and dispute adjudication all route through this
 * id bridge.
 *
 * @package BCC\Trust\Core\Services
 * @since V1 (2026-06-23)
 */

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Repositories\ScoreRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class MemberSelfPageService
{
    /**
     * Reserved page_id base for member self-pages. Must exceed every real
     * wp_post / peepso_page id and leave headroom for user_id below BIGINT
     * max. 1e9 is safely above any real post/page id on a real site and well
     * within BIGINT. A self-page id is ID_BASE + user_id.
     */
    public const ID_BASE = 1_000_000_000;

    public function __construct(
        private readonly ScoreRepository $scoreRepo
    ) {
    }

    /**
     * Deterministic self-page id for a member (0 for invalid users).
     *
     * This is the SINGLE canonical `user_id → score-row page_id` bridge.
     * Notably it is also the translation an attestation with
     * `target_kind = 'user_profile'` must use: that attestation's `target_id`
     * is a raw user id, and the trust-score row it affects is
     * `selfPageId(target_id)`, never `target_id` itself. Anything wiring
     * attestations into the self-page score (the deferred §J "Slice E"
     * synthesis) routes through here — see
     * {@see AttestationRepository::TARGET_KINDS} for the invariant.
     */
    public static function selfPageId(int $userId): int
    {
        return $userId > 0 ? self::ID_BASE + $userId : 0;
    }

    /** Whether a page_id is a member self-page (vs a real entity page). */
    public static function isSelfPage(int $pageId): bool
    {
        return $pageId > self::ID_BASE;
    }

    /** The member who owns a self-page id (0 if the id isn't a self-page). */
    public static function ownerOfSelfPage(int $pageId): int
    {
        return $pageId > self::ID_BASE ? $pageId - self::ID_BASE : 0;
    }

    /**
     * Idempotently ensure the member's self-page score row exists (with
     * page_owner_id = the member), returning its page_id. Lazy-provisioned
     * the first time the member is reviewed/endorsed or their tier is read.
     * Race-safe (INSERT IGNORE); a no-op when the row already exists.
     */
    public function ensureSelfPage(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        $pageId = self::selfPageId($userId);
        // Pass the owner explicitly so createIfNotExists never tries to
        // resolve a (nonexistent) PeepSo page for a synthetic self-page id.
        $this->scoreRepo->createIfNotExists($pageId, $userId);
        return $pageId;
    }
}
