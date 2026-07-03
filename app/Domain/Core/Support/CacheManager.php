<?php
/**
 * Cache Manager
 *
 * Thin wrapper around wp_cache_delete / wp_cache_set that adds failure
 * logging.  All trust-engine cache invalidation should go through this
 * class so failures are observable in the audit log.
 *
 * NOT a retry layer — wp_cache_delete failure on a local object cache
 * backend is harmless (process-local anyway), and on Redis it almost
 * always means the key already expired.  Logging is the right tool
 * here, not retries.
 *
 * @package BCC\Trust\Core\Support
 */

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

class CacheManager {

    /**
     * Delete a cache key, logging on failure.
     *
     * @param string $key    Cache key
     * @param string $group  Cache group
     * @param string $context Human-readable label for the log (e.g. 'score_invalidation')
     * @return bool  True if deleted (or did not exist), false on backend failure.
     */
    public static function delete(string $key, string $group, string $context = ''): bool {
        $result = wp_cache_delete($key, $group);

        // wp_cache_delete returns false when the key does not exist OR on
        // backend failure. We only care about actual backend failures, but
        // there is no way to distinguish them portably. Log anyway — the
        // volume will be negligible because this only runs on mutation paths.
        if ($result === false && $context !== '') {
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::info('[bcc-trust cache] delete failed', [
                    'key'     => $key,
                    'group'   => $group,
                    'context' => $context,
                ]);
            }
        }

        return $result;
    }

    /**
     * Invalidate all score-related caches for a page after a mutation.
     *
     * Centralises the 3 cache-delete calls that were duplicated in
     * VoteService::invalidateAfterVoteChange() and EndorsementService.
     *
     * @param int      $pageId
     * @param int|null $userId     User whose user_info cache should be cleared (voter or endorser)
     * @param string   $context    Log label
     */
    public static function invalidatePageCaches(
        int $pageId,
        ?int $userId = null,
        string $context = 'mutation'
    ): void {
        // Score repository caches
        // (ScoreRepository::invalidateCache handles all category variants)
        \BCC\Trust\Core\Plugin::instance()->scoreRepository()->invalidateCache($pageId);

        // Vote stats cache
        self::delete(
            "page_vote_stats_{$pageId}",
            'bcc_trust_votes',
            $context . ':vote_stats'
        );

        // PageDataLoader server-side cache
        \BCC\Trust\Core\Services\PageDataLoader::bust($pageId);

        // NOTE: Do NOT invalidate the read-model object cache here.
        // The read-model DB row is synced asynchronously by PageReadModelSync.
        // Clearing the cache before the DB row is updated creates a window
        // where a concurrent request reads the stale DB row and re-caches it
        // for up to 600s. The cache is correctly invalidated AFTER the DB
        // write inside PageReadModelRepository::syncPage().

        // ScoreReadService batch cache (generation-bumped so all batch
        // key permutations containing stale scores miss on next read).
        \BCC\Trust\Core\Application\ScoreReadService::bustBatchCache();

        // User info cache (if a user is specified)
        if ($userId) {
            self::delete(
                "user_info_{$userId}",
                'bcc_trust',
                $context . ':user_info'
            );
        }
    }

    /**
     * Invalidate caches after an attestation mutation (cast / revoke /
     * reaffirm) per §4.20 §J side-effects. Two surface kinds need a
     * fresh read post-mutation:
     *
     *   - The TARGET's view-model:
     *       - `validator_card` / `project_card` / `creator_card`
     *         targets are peepso-page CPT rows — reuse
     *         invalidatePageCaches so any score/endorsement/page-data
     *         composer reading the card sees fresh state.
     *       - `user_profile` targets compose viewer_attestation per
     *         request via AttestationService at this point (V1 Slice C
     *         has no server-side cross-request composer cache for the
     *         member profile beyond user_info), so no DB-level
     *         invalidation is needed today. Slice E adds a dedicated
     *         AttestationReadService generation counter when the
     *         synthesis layer begins materializing.
     *
     *   - The ATTESTOR's user_info (their attestation count/slot use
     *     changed, which affects their own surfaces). The TARGET owner's
     *     user_info is also invalidated on card targets via
     *     invalidatePageCaches; we invalidate explicitly for
     *     user_profile targets and as belt-and-suspenders for cards.
     *
     * No-op on idempotent re-attempts (status='existing', re-DELETE on
     * already-revoked) — the caller gates the call on a real state
     * transition per the §I1 destructive-mutation-hardening invariant.
     *
     * @param int|null $attestorUserId    Required for attestor user_info clear.
     * @param int|null $targetOwnerUserId Required for user_profile target invalidation.
     * @param string   $context           Log label ('attestation_cast' / '_revoke' / '_reaffirm').
     */
    public static function invalidateAttestationTargetCaches(
        string $targetKind,
        int $targetId,
        ?int $attestorUserId = null,
        ?int $targetOwnerUserId = null,
        string $context = 'attestation'
    ): void {
        // Card-kind targets are peepso-page posts. invalidatePageCaches
        // already handles score / endorsement / page-data / user_info
        // (for the attestor) coherently.
        if (in_array($targetKind, ['validator_card', 'project_card', 'creator_card'], true)) {
            self::invalidatePageCaches(
                $targetId,
                $attestorUserId,
                $context
            );
        }

        // user_profile-target user_info clear (the target user's profile
        // surfaces aggregate counts that this mutation may change once
        // Slice E lights up). Card-target user_info clear is already
        // handled inside invalidatePageCaches for the attestor; we add
        // the target-owner clear explicitly here for completeness.
        if ($targetOwnerUserId !== null && $targetOwnerUserId > 0) {
            self::delete(
                "user_info_{$targetOwnerUserId}",
                'bcc_trust',
                $context . ':user_info_target'
            );
        }

        // Attestor user_info clear (in case invalidatePageCaches wasn't
        // called — i.e. user_profile targets). Idempotent — duplicate
        // delete on the same key is harmless.
        if ($attestorUserId !== null && $attestorUserId > 0) {
            self::delete(
                "user_info_{$attestorUserId}",
                'bcc_trust',
                $context . ':user_info_attestor'
            );
        }
    }
}
