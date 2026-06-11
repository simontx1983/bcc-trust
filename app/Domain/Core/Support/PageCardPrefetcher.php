<?php
/**
 * PageCardPrefetcher — shared bounded-batch prefetch helper for
 * page-card hydration (validator / project / creator kinds).
 *
 * Mirrors {@see MemberSummaryPrefetcher} for the page-card side of the
 * polymorphic Card view-model. Collapses the ~19–25 per-card signal
 * lookups inside {@see \BCC\Trust\Core\Services\CardViewService::getPageCard()}
 * into a fixed set of bounded batches keyed on `IN (pageIds)`. Without
 * this the cards-list hydration N+1's across the page (24 rows ×
 * ~20 reads); with it the list path runs a flat ~21–26 queries
 * irrespective of `count($pageIds)`.
 *
 * Call sites:
 *
 *   - CardsListEndpoint::handle        (GET /bcc/v1/cards)
 *   - OnboardingEndpoint::suggestions  (GET /bcc/v1/onboarding/suggestions)
 *
 * Single-card paths (CardsEndpoint, MemberProfileComposer) deliberately
 * do NOT prefetch — CardViewService's per-key fallback handles the
 * one-card case with the existing single queries.
 *
 * @package BCC\Trust\Core\Support
 * @since 2026-06-11 (Phase 1 backend performance)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Core\Repositories\EndorsementRepository;
use BCC\Trust\Core\Repositories\PageReadModelRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Disputes\Repositories\DisputeRepository;
use BCC\Trust\Onchain\Repositories\ClaimRepository;
use BCC\Trust\Onchain\Repositories\ValidatorRepository;
use BCC\Trust\Onchain\Repositories\WalletRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-import-type PageReadModelRow from PageReadModelRepository
 * @phpstan-import-type ClaimRow from ClaimRepository
 * @phpstan-import-type ValidatorCardRow from ValidatorRepository
 *
 * The bundle CardViewService::getPageCard() reads. Every key is ALWAYS
 * present; viewer-keyed maps (viewer_claims / viewer_votes /
 * viewer_endorsements / viewer_attestations / endorse_eligibility) are
 * empty arrays when `$viewerId <= 0`, so consumers short-circuit to
 * the empty-map default rather than falling back to per-card queries.
 *
 * @phpstan-type PageCardPrefetchBundle array{
 *   read_models: array<int, PageReadModelRow>,
 *   claimed_pages: array<int, bool>,
 *   viewer_claims: array<int, ClaimRow>,
 *   viewer_votes: array<int, true>,
 *   viewer_endorsements: array<int, true>,
 *   viewer_attestations: array<int, array{vouch: object|null, stand_behind: object|null}>,
 *   endorse_eligibility: array<int, array{allowed: bool, unlock_hint: string|null, reason_code: string|null}>,
 *   dispute_active_counts: array<int, int>,
 *   attestation_active_counts: array<int, int>,
 *   attestation_revoked_counts: array<int, int>,
 *   validator_rows: array<int, list<ValidatorCardRow>>,
 *   page_entities: array<int, array{entity_type: string, entity_id: int}>
 * }
 */
final class PageCardPrefetcher
{
    /**
     * Card-kind → §J.1 attestation target_kind. Mirrors
     * CardViewService::cardKindToTargetKind (the page-card kinds only —
     * member/user_profile never flows through this prefetcher).
     *
     * @var array<string, string>
     */
    private const KIND_TO_TARGET_KIND = [
        'validator' => 'validator_card',
        'project'   => 'project_card',
        'creator'   => 'creator_card',
    ];

    /**
     * Prime the per-page-signal map CardViewService reads on
     * `getPageCardForList($kind, $pageId, $viewerId, $prefetched)`.
     *
     * Also primes the WP object caches the per-card path leans on:
     * the page posts + postmeta (`_bcc_page_type`, `_thumbnail_id`,
     * post_date for NewEntityReputationVelocityCap), the page owners'
     * user rows (flags / permissions), and the thumbnail attachment
     * posts — so `get_post()` / `get_post_meta()` / `get_userdata()` /
     * `get_the_post_thumbnail_url()` inside the card build are free.
     *
     * Empty `$pageIds` returns each map empty — callers should
     * short-circuit before calling this if they have no pages to
     * hydrate, but the helper itself stays safe to call regardless.
     *
     * @param list<int> $pageIds  Bounded by caller; the IN-clause is
     *                            expected to be paginated upstream
     *                            (cards per_page ≤ 50).
     * @param int       $viewerId Current viewer (0 for anon — skips
     *                            the viewer-keyed batches entirely).
     * @return array<string, mixed>
     * @phpstan-return PageCardPrefetchBundle
     */
    public static function primeFor(array $pageIds, int $viewerId): array
    {
        $ids = [];
        foreach ($pageIds as $id) {
            $intId = (int) $id;
            if ($intId > 0) {
                $ids[$intId] = true;
            }
        }
        $pageIds = array_keys($ids);

        $empty = [
            'read_models'                => [],
            'claimed_pages'              => [],
            'viewer_claims'              => [],
            'viewer_votes'               => [],
            'viewer_endorsements'        => [],
            'viewer_attestations'        => [],
            'endorse_eligibility'        => [],
            'dispute_active_counts'      => [],
            'attestation_active_counts'  => [],
            'attestation_revoked_counts' => [],
            'validator_rows'             => [],
            'page_entities'              => [],
        ];
        if ($pageIds === []) {
            return $empty;
        }

        // ── 1. WP object-cache priming ──────────────────────────────
        // Posts + postmeta in one round trip each: makes get_post()
        // (title/slug/author/date), get_post_meta('_bcc_page_type') and
        // the velocity cap's post_date read all cache hits.
        _prime_post_caches($pageIds, false, true);

        // Page owners → one users + one usermeta query via cache_users,
        // so buildFlags / permissions / endorse self-checks are free.
        // Thumbnail ids → prime the attachment posts + meta so
        // get_the_post_thumbnail_url() resolves from cache.
        $ownerIds = [];
        $thumbIds = [];
        foreach ($pageIds as $pageId) {
            $post = get_post($pageId);
            if ($post instanceof \WP_Post) {
                $ownerId = (int) $post->post_author;
                if ($ownerId > 0) {
                    $ownerIds[$ownerId] = true;
                }
            }
            $thumbId = (int) get_post_meta($pageId, '_thumbnail_id', true);
            if ($thumbId > 0) {
                $thumbIds[$thumbId] = true;
            }
        }
        if ($ownerIds !== []) {
            cache_users(array_keys($ownerIds));
        }
        if ($thumbIds !== []) {
            _prime_post_caches(array_keys($thumbIds), false, true);
        }

        // ── 2. Group pages by attestation target_kind ───────────────
        // `_bcc_page_type` is cached now; the '' → 'builder' default
        // mirrors CardViewService::getPageCard / PageReadModelRepository.
        $idsByTargetKind  = [];
        $validatorPageIds = [];
        foreach ($pageIds as $pageId) {
            $pageType = (string) get_post_meta($pageId, '_bcc_page_type', true);
            if ($pageType === '') {
                $pageType = 'builder';
            }
            $kind = PageTypeMap::kindForPageType($pageType);
            if ($kind === null) {
                // Unknown page_type — the card build will 404 the row
                // anyway; no batches needed for it.
                continue;
            }
            $targetKind = self::KIND_TO_TARGET_KIND[$kind] ?? null;
            if ($targetKind === null) {
                continue;
            }
            $idsByTargetKind[$targetKind][] = $pageId;
            if ($kind === 'validator') {
                $validatorPageIds[] = $pageId;
            }
        }

        // ── 3. Viewer-independent batches ───────────────────────────
        $readModels = (new PageReadModelRepository())->getByPageIds($pageIds);

        // is_claimed ≡ getForEntity('page', id) !== [] — reduce the
        // verified-claims batch to a presence map (every page keyed).
        $claimsBatch  = ClaimRepository::getForEntityBatch('page', $pageIds);
        $claimedPages = [];
        foreach ($pageIds as $pageId) {
            $claimedPages[$pageId] = isset($claimsBatch[$pageId]) && $claimsBatch[$pageId] !== [];
        }

        $disputeActiveCounts = DisputeRepository::countActiveDisputesForPages($pageIds);

        // Attestation counts run per target_kind group (the table keys
        // on (target_kind, target_id)); page ids are unique across
        // groups so a flat merge is collision-free.
        $attestationRepo  = new AttestationRepository();
        $activeCounts     = [];
        $revokedCounts    = [];
        foreach ($idsByTargetKind as $targetKind => $targetIds) {
            $activeCounts  += $attestationRepo->countActiveByTargets($targetKind, $targetIds);
            $revokedCounts += $attestationRepo->countRevokedByTargets($targetKind, $targetIds);
        }

        $validatorRows = $validatorPageIds !== []
            ? ValidatorRepository::findCardRowsByPageIds($validatorPageIds)
            : [];

        $pageEntities = WalletRepository::resolveEntitiesForPages($pageIds);

        // ── 4. Viewer-keyed batches (skipped entirely for anon) ─────
        $viewerClaims       = [];
        $viewerVotes        = [];
        $viewerEndorsements = [];
        $viewerAttestations = [];
        $endorseEligibility = [];
        if ($viewerId > 0) {
            $plugin = Plugin::instance();

            $viewerClaims       = ClaimRepository::getUserClaimsForEntities($viewerId, 'page', $pageIds);
            $viewerVotes        = (new VoteRepository())->getVotedPageIdsForVoter($viewerId, $pageIds);
            $viewerEndorsements = (new EndorsementRepository())->getEndorsedPageIdsForUser($viewerId, $pageIds, 'general');
            foreach ($idsByTargetKind as $targetKind => $targetIds) {
                $viewerAttestations += $attestationRepo->findActiveByAttestorForTargets(
                    $viewerId,
                    $targetKind,
                    $targetIds
                );
            }
            $endorseEligibility = $plugin->endorsementService()
                ->getEndorseEligibilityForPages($viewerId, $pageIds);
        }

        return [
            'read_models'                => $readModels,
            'claimed_pages'              => $claimedPages,
            'viewer_claims'              => $viewerClaims,
            'viewer_votes'               => $viewerVotes,
            'viewer_endorsements'        => $viewerEndorsements,
            'viewer_attestations'        => $viewerAttestations,
            'endorse_eligibility'        => $endorseEligibility,
            'dispute_active_counts'      => $disputeActiveCounts,
            'attestation_active_counts'  => $activeCounts,
            'attestation_revoked_counts' => $revokedCounts,
            'validator_rows'             => $validatorRows,
            'page_entities'              => $pageEntities,
        ];
    }
}
