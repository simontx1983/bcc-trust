<?php
/**
 * Card View Service — composes the GET /bcc/v1/cards/:type/:id response
 * per contract §L5 + §C1.
 *
 * The endpoint is polymorphic: one stable shape, four card kinds. The
 * frontend's <CardFactory> reads `card_kind` and dispatches to the right
 * face — but the outer envelope is identical across kinds.
 *
 * Card kind → backing entity (V1 mapping):
 *   validator → peepso-page CPT, _bcc_page_type='validator'
 *   project   → peepso-page CPT, _bcc_page_type='builder'
 *   creator   → peepso-page CPT, _bcc_page_type='nft'
 *   member    → wp_users + bcc_handle
 *
 * The "project → builder" and "creator → nft" maps are V1 backward-compat
 * with the existing CPT slugs introduced by blue-collar-crypto-peepso-
 * integration. The contract uses the canonical kind names so the frontend
 * stays stable; the server hides the legacy slug.
 *
 * Phase 1 stubs documented inline:
 *   - crest.background            → "kind:*" (page cards) / "tier:*" (member cards)
 *                                                        (real chain / colour theme deferred to onchain wiring)
 *   - stats[uptime|commission|*]  → omitted              (validator-specific signals deferred)
 *
 * Previously stubbed, now wired (kept for changelog clarity):
 *   - flags     → buildFlags() routes through UserViewService::resolveFlags;
 *                 page cards derive from `post_author` (the page owner),
 *                 member cards from the user themselves. V1 contract is
 *                 `string[]` of slugs (NOT the legacy {code,message,severity}
 *                 object the typedef previously declared).
 *
 * @package BCC\Trust\Core\Services
 * @since V1 (2026-04)
 */

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Repositories\PageReadModelRepository;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Support\CardUrlMap;
use BCC\Trust\Core\Support\PageTypeMap;
use BCC\Trust\Core\Support\ReputationTierMap;
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
 * @phpstan-type Flag string
 * @phpstan-type Stat array{key: string, label: string, value: string, raw: int|float|string, format: string}
 * @phpstan-type Permission array{allowed: bool, unlock_hint: string|null, reason_code: string|null}
 * @phpstan-type Action array{method: string, href: string, body?: array<string, mixed>, idempotent: bool, requires_auth: bool}
 *
 * The page-card prefetch bundle produced by
 * Support\PageCardPrefetcher::primeFor(). Keys are optional here (the
 * consumer falls back to single-page queries per key) even though the
 * prefetcher always emits all of them — partial-bundle safety mirrors
 * how getSummary treats the MemberSummaryPrefetcher map.
 *
 * @phpstan-type PageCardPrefetch array{
 *   read_models?: array<int, PageReadModelRow>,
 *   claimed_pages?: array<int, bool>,
 *   viewer_claims?: array<int, ClaimRow>,
 *   viewer_votes?: array<int, true>,
 *   viewer_attestations?: array<int, array{vouch: object|null, stand_behind: object|null}>,
 *   endorse_eligibility?: array<int, array{allowed: bool, unlock_hint: string|null, reason_code: string|null}>,
 *   dispute_active_counts?: array<int, int>,
 *   attestation_active_counts?: array<int, int>,
 *   attestation_revoked_counts?: array<int, int>,
 *   validator_rows?: array<int, list<ValidatorCardRow>>,
 *   page_entities?: array<int, array{entity_type: string, entity_id: int}>
 * }
 *
 * Already-loaded group data the community-card composer consumes. Both
 * producers (GroupsDiscoveryEndpoint list rows, GroupsService::getGroup
 * detail composition) have every key in hand before calling — the
 * composer performs ZERO queries (pure composition), so adding a card
 * to a page of discovery items costs nothing beyond the one batched
 * membership lookup the endpoint already does.
 *
 * @phpstan-type CommunityGroupData array{
 *   group_id: int,
 *   slug: string,
 *   name: string,
 *   type: string,
 *   privacy: string,
 *   member_count: int,
 *   description: string|null,
 *   image_url: string|null,
 *   verification: array{kind: string, label: string}|null,
 *   collection_stats: array<string, mixed>|null,
 *   chain_tag: string|null,
 *   trust_min: int|null,
 *   viewer_is_member: bool,
 *   posts_last_7d: int
 * }
 */
final class CardViewService
{
    // KIND_TO_PAGE_TYPE moved to Support\PageTypeMap (single source of
    // truth shared with WatchingService, which uses the reverse direction).

    // Frontend URL prefix moved to CardUrlMap (single source of truth
    // shared with WatchingService — see §C2 watching-Phase-1 corrections).

    public function __construct(
        private readonly PageReadModelRepository $pageReadModelRepo,
        private readonly ReputationRepository $reputationRepo,
        private readonly VoteRepository $voteRepo,
        private readonly FeatureAccessService $featureAccess,
        private readonly VoteService $voteService,
        private readonly EndorsementService $endorsementService,
        private readonly AttestationService $attestationService,
        private readonly NewEntityReputationVelocityCap $newEntityVelocityCap,
        private readonly DivergenceStateClassifier $divergenceClassifier,
        private readonly UserViewService $userViewService
    ) {
    }

    /**
     * Build the Card view-model. Returns null when the entity doesn't
     * resolve.
     *
     * @return array<string, mixed>|null
     */
    public function getCard(string $type, string $id, int $viewerId): ?array
    {
        return match ($type) {
            'validator', 'project', 'creator' => $this->getPageCard($type, $id, $viewerId),
            'member'                          => $this->getMemberCard($id, $viewerId),
            default                           => null,
        };
    }

    // ──────────────────────────────────────────────────────────────────
    // Page-cards (validator / project / creator)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Public list accessor — mirror of getMemberCardForList for the
     * page kinds. The two list producers (cards directory, onboarding
     * suggestions) call this per page with a shared
     * `PageCardPrefetcher::primeFor()` bundle so the per-card signal
     * hydration inside getPageCard doesn't N+1. Thin wrapper over
     * getPageCard; exists so callers don't reach into a private method.
     *
     * @param array<string, mixed>|null $prefetched
     * @phpstan-param PageCardPrefetch|null $prefetched
     * @return array<string, mixed>|null
     */
    public function getPageCardForList(string $kind, int $pageId, int $viewerId, ?array $prefetched): ?array
    {
        return $this->getPageCard($kind, (string) $pageId, $viewerId, $prefetched);
    }

    /**
     * `$prefetched` (when non-null) is the shared PageCardPrefetcher
     * bundle; every per-card read below prefers its map and falls back
     * to the existing single-page query when the key is absent — the
     * same prefer-prefetched contract UserViewService::getSummary uses,
     * so single-card callers (CardsEndpoint, MemberProfileComposer)
     * keep working with no prefetch ceremony.
     *
     * @param array<string, mixed>|null $prefetched
     * @phpstan-param PageCardPrefetch|null $prefetched
     * @return array<string, mixed>|null
     */
    private function getPageCard(string $kind, string $id, int $viewerId, ?array $prefetched = null): ?array
    {
        $expectedPageType = PageTypeMap::KIND_TO_PAGE_TYPE[$kind] ?? null;
        if ($expectedPageType === null) {
            return null;
        }

        $post = self::resolvePagePost($id);
        if ($post === null) {
            return null;
        }

        $actualPageType = (string) get_post_meta($post->ID, '_bcc_page_type', true);
        // The default for legacy pages without the meta is 'builder' —
        // mirror PageReadModelRepository::COLUMNS default.
        if ($actualPageType === '') {
            $actualPageType = 'builder';
        }
        if ($actualPageType !== $expectedPageType) {
            // URL kind doesn't match the page's actual type — return 404
            // rather than serve a mistyped card.
            return null;
        }

        $pageId = (int) $post->ID;
        if ($prefetched !== null && isset($prefetched['read_models'])) {
            $rm = $prefetched['read_models'][$pageId] ?? null;
        } else {
            $rm = $this->pageReadModelRepo->getByPageId($pageId);
        }

        $tier        = $rm !== null ? (string) $rm->reputation_tier : 'neutral';
        $trustScore  = $rm !== null ? (int) round((float) $rm->trust_score) : 50;
        // PR-7 new-entity velocity cap: clamp entity-card scores to
        // ≤50 for the first 60 days of the entity's existence so a
        // burst of day-one attestations can't pump a fresh page into
        // a high-tier public score before sustained trust is earned.
        // Entity-only; user-account scores are not capped.
        $trustScore  = $this->newEntityVelocityCap->cap($pageId, $trustScore);
        $card        = ReputationTierMap::resolve($tier);

        $name   = (string) $post->post_title;
        $handle = (string) $post->post_name;

        if ($prefetched !== null && isset($prefetched['claimed_pages'])) {
            $isClaimed = $prefetched['claimed_pages'][$pageId] ?? false;
        } else {
            $isClaimed = self::isPageClaimed($pageId);
        }

        if ($viewerId <= 0) {
            $isClaimedByViewer = false;
        } elseif ($prefetched !== null && isset($prefetched['viewer_claims'])) {
            // Anon-safe by construction: the prefetcher emits an empty
            // map for viewerId<=0, but the gate above already handled
            // that. Same status==='verified' reduction as the fallback.
            $isClaimedByViewer = self::claimRowIsVerified($prefetched['viewer_claims'][$pageId] ?? null);
        } else {
            $isClaimedByViewer = self::viewerHasClaim($viewerId, $pageId);
        }

        if ($viewerId <= 0) {
            $viewerHasReviewed = false;
        } elseif ($prefetched !== null && isset($prefetched['viewer_votes'])) {
            $viewerHasReviewed = isset($prefetched['viewer_votes'][$pageId]);
        } else {
            $viewerHasReviewed = $this->voteService->hasUserVotedPage($pageId, $viewerId);
        }

        if ($prefetched !== null && isset($prefetched['endorse_eligibility'])) {
            // Anon viewers get an empty prefetch map; the single-page
            // fallback short-circuits to the auth_required shape with
            // zero queries, so the `??` keeps anon byte-identical.
            $endorseEligibility = $prefetched['endorse_eligibility'][$pageId]
                ?? $this->endorsementService->getEndorseEligibility($viewerId, $pageId);
        } else {
            $endorseEligibility = $this->endorsementService->getEndorseEligibility($viewerId, $pageId);
        }

        // §J.6 viewer_attestation — present only for authed viewers.
        // Maps the card kind to the locked target_kind set per §J.1.
        // Always null for anon (the service returns null on viewerId<=0).
        $cardTargetKind = self::cardKindToTargetKind($kind);
        $viewerAttestation = null;
        if ($viewerId > 0 && $cardTargetKind !== null) {
            if ($prefetched !== null && isset($prefetched['viewer_attestations'])) {
                // Batched raw rows shaped through the SAME service
                // method getViewerAttestation uses, so the two paths
                // can never emit divergent shapes.
                $viewerAttestation = $this->attestationService->shapeViewerAttestationFromRows(
                    $prefetched['viewer_attestations'][$pageId] ?? ['vouch' => null, 'stand_behind' => null]
                );
            } else {
                $viewerAttestation = $this->attestationService->getViewerAttestation($viewerId, $cardTargetKind, $pageId);
            }
        }

        // viewer_has_endorsed — since the legacy endorsements-table
        // retirement this is DERIVED from the same viewer-attestation
        // read above: "endorsed" ≡ the viewer holds an active vouch on
        // this card. Field name + false-for-anon semantics unchanged;
        // both single-card and batched paths converge on one source so
        // the boolean can never disagree with viewer_attestation.vouch.
        $viewerHasEndorsed = $viewerAttestation !== null
            && ($viewerAttestation['vouch'] ?? null) !== null;

        // Validator on-chain projection — one shared batch row-set per
        // page feeds claim_target + chains + onchain_signals + the logo
        // step below (replacing 4 per-page queries). `null` = no bundle,
        // helpers fall back to their per-page queries; `[]` = bundle
        // present and the page has no validator rows.
        $validatorRows = ($prefetched !== null && isset($prefetched['validator_rows']))
            ? ($prefetched['validator_rows'][$pageId] ?? [])
            : null;
        // Batched auto-logo for the crest step — replaces the per-card
        // findLogoByPageId N+1 when a bundle is present. null in single-card
        // mode so resolvePageAvatarUrl keeps its per-page fallback.
        $validatorLogo = ($prefetched !== null && isset($prefetched['validator_logos']))
            ? ($prefetched['validator_logos'][$pageId] ?? null)
            : null;

        return [
            'id'                  => $pageId,
            'name'                => $name,
            'handle'              => $handle,
            'card_kind'           => $kind,
            'bio'                 => self::resolvePageBio($post),
            'trust_score'         => $trustScore,
            'reputation_tier'     => $tier,
            // Entity cards use card_tier rarity, not the member trust chip.
            'reputation_tier_label' => null,
            'card_tier'           => $card['key'],
            'tier_label'          => $card['label'],
            // Rank is member-only — but the fields are ALWAYS emitted
            // (nullable) per the contract, so the frontend can render
            // without a kind check.
            'rank_label'          => null,
            'current_rank_label'  => null,
            'is_in_good_standing' => self::isInGoodStanding($tier),
            'flags'               => self::buildFlags((int) $post->post_author),
            'is_claimed'          => $isClaimed,
            // On-chain claim-verified: true when this page has a verified
            // operator/creator claim. Read straight from the read-model row
            // ($rm->has_verified_claim, projected in syncPage) — no per-card
            // ClaimRepository query, so the prefetched-list path stays
            // N+1-free. Distinct from the owner-EMAIL verification signal.
            'is_claim_verified'   => $rm !== null && (bool) ($rm->has_verified_claim ?? 0),
            // §D2 — true when the current viewer has already cast a
            // vote on this page. Drives the entity profile's
            // "WRITE A REVIEW" → "REMOVE YOUR REVIEW" CTA swap.
            // Always false for anonymous viewers.
            'viewer_has_reviewed' => $viewerHasReviewed,
            // §V1.5 — true when the current viewer has already vouched for
            // this page (= holds an active vouch attestation on it).
            // Drives the entity-page vouch button's idle → "REMOVE" CTA
            // swap. Always false for anonymous viewers.
            'viewer_has_endorsed' => $viewerHasEndorsed,
            // §V1.5 — server-rendered "why is endorse disabled?" copy.
            // Null when can_endorse is true; non-null + human-readable
            // when blocked. Surfaces as the disabled-button tooltip.
            'endorse_unlock_hint' => $endorseEligibility['unlock_hint'],
            // §J.6 viewer_attestation — present for authed viewers only;
            // anon viewers get the field omitted entirely (null here so
            // the array shape is uniform; the FE treats undefined and
            // null identically per the §4.20 contract). The FE's
            // AttestationActionCluster reads the inner vouch /
            // stand_behind slots to render cast state ("VOUCHED" /
            // "STANDING BEHIND") on the action buttons. The
            // viewer_has_endorsed boolean above is DERIVED from this
            // field's vouch slot (single source since the endorsements-
            // table retirement).
            'viewer_attestation'  => $viewerAttestation,
            // §N8: claim flow needs entity_type + entity_id + chain_slug
            // to drive the four-step modal. Server resolves these from
            // the page id (per §A2/§L5 — frontend never derives). Null
            // for already-claimed pages or non-validator kinds.
            'claim_target'        => self::resolveClaimTarget($kind, $pageId, $isClaimed, $validatorRows),
            // §K3 — chains an operator runs on. Null for single-chain
            // pages (the common case) so the frontend doesn't render
            // a dead one-tab strip. Validator-only in V1.5; creator
            // gallery chain filter is V2 (per §H1).
            'chains'              => self::resolveChains($kind, $pageId, $validatorRows),
            // On-chain validator signals — status, uptime, commission,
            // voting power, stake. Validator-only; null for other kinds
            // and for validator pages with no resolvable on-chain row
            // (transient indexer state). Surfaces the chain's own data
            // on a card so unclaimed placeholders still show what the
            // operator actually does on-chain.
            'onchain_signals'     => self::resolveOnchainSignals($kind, $pageId, $validatorRows),
            // member_dossier is member-only — always null on page cards
            // for shape uniformity (matches how `chains`/`onchain_signals`
            // are always present). The frontend's CardFactory reads
            // `member_dossier` only on the member face.
            'member_dossier'      => null,
            // community_dossier is community-only — always null on page
            // cards (same always-present/null pattern as member_dossier).
            'community_dossier'   => null,
            // V1: tier-keyed coloring for entity cards (chain-keyed lands
            // alongside §K3 chain-wiring in V1.5). `background_value` per
            // §2.9 is the card_tier slug; falls back to 'common' on the
            // edge case where the read-model hasn't projected yet.
            'crest'               => self::buildCrest(
                $name,
                'tier',
                $card['key'] ?? 'common',
                self::resolvePageAvatarUrl($pageId, $validatorRows, $validatorLogo)
            ),
            'stats'               => self::buildPageStats($trustScore, $rm),
            'permissions'         => $this->resolvePagePermissions(
                $viewerId,
                $isClaimedByViewer,
                $endorseEligibility,
                (int) $post->post_author,
                $cardTargetKind,
                $pageId
            ),
            // STUB: social_proof composition deferred (§O4). Field is
            // ALWAYS emitted (nullable) per the contract.
            'social_proof'        => null,
            // §J.6 negative_signals — PR-8a wires the under_review +
            // divergence_state real-time signals. `volatile` is the
            // nightly worker's job (Slice E.5), shipped here as
            // baseline `false`. `unresolved_claims_count` is V1 = the
            // active-dispute count; content-reports add later when
            // §J.9 reports extend to user_profile / card target kinds.
            'negative_signals'    => $this->buildNegativeSignals(
                $cardTargetKind,
                $pageId,
                $prefetched
            ),
            'links'               => self::buildLinks($kind, $handle),
            'actions'             => self::buildPageActions($kind, $pageId, $prefetched),
        ];
    }

    /**
     * §J.6 derived negative-signals block. Pure composition of:
     *   - DisputeRepository::hasActiveDisputeForPage (real-time)
     *   - DivergenceStateClassifier::classify       (real-time per PR-8a)
     *   - DisputeRepository::countActiveDisputesForPage (real-time)
     *
     * `volatile` stays the nightly-worker slot per §J.8; ships as
     * baseline `false` until Slice E.5 lands. Surface shape is
     * contract-stable — FE renders without branching on whether
     * the field is "real" vs "baseline."
     *
     * Prefetched path: `has_active ≡ count > 0` (both per-page reads
     * use the identical `status = 'reviewing'` predicate, so one
     * grouped count yields both signals), and the divergence state
     * routes through classifyFromCounts — the same single heuristic
     * implementation classify() delegates to.
     *
     * ── id-duality (`user_profile` / member cards) ──────────────────
     * For `targetKind === 'user_profile'` the `$targetId` is a RAW user
     * id: attestation counts (active/revoked) key on it, but disputes /
     * votes / scores key on the member's self-page id
     * `MemberSelfPageService::selfPageId($targetId)`. So the dispute
     * reads here translate to the self-page id while the attestation
     * counts (and the classifier's own attestation reads inside
     * classify()) stay on the raw user id. Entity card kinds carry a
     * peepso-page post id directly, so `$disputeId === $targetId` for
     * them and nothing changes.
     *
     * Two prefetch bundle shapes feed the prefetched branch: the
     * page-card PageCardPrefetcher bundle (dispute counts keyed by page
     * id) and the member-card MemberCardPrefetcher bundle (dispute
     * counts keyed by selfPageId, attestation counts keyed by raw user
     * id). Both expose the same three keys this branch reads; the
     * `$disputeId` translation above resolves the right dispute key for
     * each. Single-card callers pass null and take the per-row read
     * fallback. `$prefetched` is therefore typed loosely (the union of
     * the two bundle shapes) — every read is `isset`/`?? 0` guarded.
     *
     * @param array<string, mixed>|null $prefetched
     * @return array{
     *   under_review: bool,
     *   divergence_state: string,
     *   volatile: bool,
     *   unresolved_claims_count: int
     * }
     */
    private function buildNegativeSignals(?string $targetKind, int $targetId, ?array $prefetched = null): array
    {
        // Dispute reads are self-page-scoped for user_profile; attestation
        // counts stay on the raw user id (id-duality). Entity kinds pass
        // a page id directly, so this is a no-op for them.
        $disputeId = $targetKind === 'user_profile'
            ? MemberSelfPageService::selfPageId($targetId)
            : $targetId;

        if (
            $prefetched !== null
            && isset($prefetched['dispute_active_counts'])
            && isset($prefetched['attestation_active_counts'])
            && isset($prefetched['attestation_revoked_counts'])
        ) {
            $activeCount = $prefetched['dispute_active_counts'][$disputeId] ?? 0;
            $hasActive   = $activeCount > 0;

            $state = $targetKind !== null
                ? $this->divergenceClassifier->classifyFromCounts(
                    $targetKind,
                    $targetId,
                    $hasActive,
                    $prefetched['attestation_active_counts'][$targetId] ?? 0,
                    $prefetched['attestation_revoked_counts'][$targetId] ?? 0
                )
                : DivergenceStateClassifier::STATE_UNTESTED;
        } else {
            $hasActive   = $disputeId > 0
                && \BCC\Trust\Disputes\Repositories\DisputeRepository::hasActiveDisputeForPage($disputeId);
            $activeCount = $disputeId > 0
                ? \BCC\Trust\Disputes\Repositories\DisputeRepository::countActiveDisputesForPage($disputeId)
                : 0;

            $state = $targetKind !== null
                ? $this->divergenceClassifier->classify($targetKind, $targetId)
                : DivergenceStateClassifier::STATE_UNTESTED;
        }

        return [
            'under_review'             => $hasActive,
            'divergence_state'         => $state,
            'volatile'                 => false,
            'unresolved_claims_count'  => $activeCount,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getMemberCard(string $handle, int $viewerId): ?array
    {
        $userId = self::resolveHandle($handle);
        if ($userId === 0) {
            return null;
        }
        // Single-card path stays handle-based and passes no prefetch —
        // the single-user fallback inside getSummary is fine for one card.
        return $this->getMemberCardByUserId($userId, $viewerId, null);
    }

    /**
     * Public list accessor — the three list producers (/members,
     * entity watchers, followers/following) call this per user with a
     * shared `MemberSummaryPrefetcher::primeFor()` map so the per-row
     * dossier hydration inside getSummary doesn't N+1. Thin wrapper over
     * getMemberCardByUserId; exists so callers don't reach into a
     * private method.
     *
     * @param array<string, mixed>|null $prefetched
     * @return array<string, mixed>|null
     */
    public function getMemberCardForList(int $userId, int $viewerId, ?array $prefetched): ?array
    {
        return $this->getMemberCardByUserId($userId, $viewerId, $prefetched);
    }

    /**
     * Build the member Card view-model keyed by user id. Resolves the
     * full member dossier (rank_label, verifications, engagement,
     * owned_pages_by_type, primary_hall) by delegating to
     * UserViewService::getSummary once — the SAME resolution the
     * MemberSummary surfaces use, so there is no parallel dossier query.
     *
     * `$prefetched` (when non-null) is the shared
     * MemberSummaryPrefetcher batch; getSummary reads it and falls back
     * to single-user queries when null.
     *
     * Returns null when the user doesn't resolve (deleted mid-flight).
     *
     * @param array<string, mixed>|null $prefetched
     * @return array<string, mixed>|null
     */
    private function getMemberCardByUserId(int $userId, int $viewerId, ?array $prefetched = null): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $user = get_userdata($userId);
        if ($user === false) {
            return null;
        }

        // Single delegation to the canonical member-dossier resolver.
        // getSummary returns null for a user that vanished between the
        // id list and hydration — propagate that as a null card so the
        // list producers skip the row rather than emit a half-built card.
        $summary = $this->userViewService->getSummary($userId, $viewerId, $prefetched);
        if ($summary === null) {
            return null;
        }

        $tier        = $this->reputationRepo->getTier($userId);
        $trustScore  = (int) round($this->reputationRepo->getScore($userId));
        $card        = ReputationTierMap::resolve($tier);
        $resolvedHandle = self::resolveMemberHandle($user);
        // §J.6 viewer_attestation on member cards. Member card
        // target_kind is `user_profile` per §J.1. Anon viewers get
        // null (service returns null when viewerId<=0). Same
        // batched-rows-or-single-read split as the page-card path —
        // both shapes flow through shapeViewerAttestationFromRows.
        $viewerAttestation = null;
        if ($viewerId > 0) {
            if ($prefetched !== null && isset($prefetched['viewer_attestations'])) {
                $viewerAttestation = $this->attestationService->shapeViewerAttestationFromRows(
                    $prefetched['viewer_attestations'][$userId] ?? ['vouch' => null, 'stand_behind' => null]
                );
            } else {
                $viewerAttestation = $this->attestationService->getViewerAttestation($viewerId, 'user_profile', $userId);
            }
        }

        // Member reviews are votes on the deterministic self-page.
        // Same prefetch-or-single split as the page-card path (the
        // MemberCardPrefetcher viewer_votes map is keyed by self-page
        // id, matching PageCardPrefetcher's page-id keying).
        $selfPageId = MemberSelfPageService::selfPageId($userId);
        if ($viewerId <= 0) {
            $viewerHasReviewed = false;
        } elseif ($prefetched !== null && isset($prefetched['viewer_votes'])) {
            $viewerHasReviewed = isset($prefetched['viewer_votes'][$selfPageId]);
        } else {
            $viewerHasReviewed = $this->voteService->hasUserVotedPage($selfPageId, $viewerId);
        }

        return [
            'id'                  => $userId,
            'name'                => $user->display_name !== '' ? $user->display_name : $user->user_login,
            'handle'              => $resolvedHandle,
            'card_kind'           => 'member',
            'bio'                 => self::resolveMemberBio($user),
            'trust_score'         => $trustScore,
            'reputation_tier'     => $tier,
            // Honest member trust chip (Risky…Proven), distinct from card_tier rarity.
            'reputation_tier_label' => $summary['reputation_tier_label'],
            'card_tier'           => $card['key'],
            'tier_label'          => $card['label'],
            // Real (level-derived) rank label now — resolved once via
            // getSummary's RankService path (no longer a hard-stubbed null).
            'rank_label'          => $summary['rank_label'],
            'current_rank_label'  => $summary['current_rank_label'],
            'is_in_good_standing' => self::isInGoodStanding($tier),
            'flags'               => self::buildFlags($userId),
            // §V1.5 endorse fields stay present on member cards for
            // contract uniformity, but always null/false — endorsements
            // target page cards only.
            'viewer_has_endorsed' => false,
            'endorse_unlock_hint' => null,
            // v1.49 — REAL on member cards now (was undeclared/false):
            // has the viewer reviewed this member (vote on the
            // self-page)? Drives the REMOVE-YOUR-REVIEW branch.
            'viewer_has_reviewed' => $viewerHasReviewed,
            // v1.49 — the self-page id member reviews live on. This is
            // the id the FE passes to DELETE /me/reviews/:id; the FE
            // must never derive ID_BASE itself (§L5). The WRITE path
            // keeps target_kind=user_profile + target_user_id.
            'review_target_id'    => $selfPageId,
            // §J.6 viewer_attestation — present on member cards now
            // that attestations land on user_profile target_kind per
            // §J.1. Anon viewers get null per the §4.20 contract.
            'viewer_attestation'  => $viewerAttestation,
            // §K3 — chains is page-only. Always null on member cards
            // for shape uniformity.
            'chains'              => null,
            // §N8 — members are always claimed (a wp_user exists by
            // being on the platform). WANTED corner stamp targets
            // unclaimed page entities (validator/project/creator); on
            // member cards both fields are constant. Missing earlier;
            // their absence let the frontend `!card.is_claimed` branch
            // evaluate truthy and render the WANTED stamp on member
            // cards — fixed now by setting them explicitly.
            'is_claimed'          => true,
            // Members aren't on-chain-claimed — always false, emitted for
            // shape uniformity with entity cards (matches is_claimed above).
            'is_claim_verified'   => false,
            'claim_target'        => null,
            'crest'               => self::buildCrest(
                (string) $user->display_name ?: $user->user_login,
                'tier',
                $card['key'] ?? 'common',
                self::resolveMemberAvatarUrl($userId)
            ),
            'stats'               => $this->buildMemberStats(
                $userId,
                $trustScore,
                (int) $summary['followers_count'],
                $prefetched
            ),
            'permissions'         => $this->resolveMemberPermissions($userId, $viewerId),
            // STUB: social_proof composition deferred (§O4). Field is
            // ALWAYS emitted (nullable) per the contract.
            'social_proof'        => null,
            // §J.6 negative_signals — members are full trust subjects on
            // their self-page, so they carry the SAME signal block as
            // entity cards (was previously omitted on member cards). The
            // id-duality is threaded inside buildNegativeSignals: target
            // kind 'user_profile' + the raw $userId — the helper reads
            // attestation counts on the raw user id and the dispute
            // signals on selfPageId($userId). On the member-card LIST path
            // $prefetched is a MemberCardPrefetcher bundle carrying
            // dispute_active_counts (self-page keyed) + attestation
            // active/revoked counts (raw-user keyed), so this resolves
            // O(1) per page instead of N+1; single-card callers pass null
            // and take the per-row read fallback.
            'negative_signals'    => $this->buildNegativeSignals('user_profile', $userId, $prefetched),
            // member_dossier — the back-of-card signal blocks the
            // /members + watchers + followers lists used to carry as a
            // bare MemberSummary. Copied straight from getSummary's
            // already-resolved sub-arrays (same wire keys) so nothing is
            // lost when those lists move to the Card view-model. Page
            // cards emit `member_dossier: null` for shape uniformity.
            'member_dossier'      => [
                'verifications'       => $summary['verifications'],
                'engagement'          => $summary['engagement'],
                'owned_pages_by_type' => $summary['owned_pages_by_type'],
                'primary_hall'        => $summary['primary_hall'],
            ],
            // community_dossier is community-only — always null on
            // member cards (mirror of member_dossier on the other kinds).
            'community_dossier'   => null,
            'links'               => self::buildLinks('member', $resolvedHandle),
            'actions'             => self::buildMemberActions($userId),
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // Community cards (PeepSo groups — §4.7 card convergence)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Server-owned kicker copy per group type. Mirrors (and supersedes,
     * per §L5) the frontend GroupCard's KICKER_BY_TYPE map — the FE now
     * renders `tier_label` verbatim instead of deriving from `type`.
     */
    private const COMMUNITY_TIER_LABEL_BY_TYPE = [
        'nft'       => 'HOLDER COMMUNITY',
        'validator' => 'DELEGATOR COMMUNITY',
        'hall'      => 'CHAIN HALL',
        'system'    => 'SYSTEM COMMUNITY',
        'user'      => 'COMMUNITY',
    ];

    /**
     * Compose the community Card (card_kind: "community") FROM
     * ALREADY-LOADED group data — never from a fresh query. Both
     * callers (GroupsDiscoveryEndpoint per list item, GroupsService::
     * getGroup for the detail response) hold every input in hand, so
     * this is pure composition: zero DB reads, zero N+1 risk on the
     * bounded discovery loop.
     *
     * Shape rules (locked, contract §3.2.4):
     *   - trust fields are shape-stable placeholders — communities have
     *     no trust system (trust_score 0 / reputation_tier "neutral" /
     *     card_tier "common"); tier_label carries the group-type kicker.
     *   - crest: chain-keyed background for NFT groups with a chain_tag,
     *     tier-keyed "common" otherwise; image_url = collection cover
     *     (nullable → FE monogram fallback).
     *   - permissions: every action denied `not_applicable` for ALL
     *     viewers (anon and authed alike) — groups use JOIN, not the
     *     watch/review/attest verbs, and the join gate lives on the
     *     group detail's own permissions block, not the card.
     *   - community_dossier: the community-only block (always present
     *     on the wire; null on the other card kinds — mirror of how
     *     member_dossier behaves).
     *
     * Privacy: callers only compose cards for groups their endpoints
     * already returned — the secret-group gates stay upstream; no new
     * privacy logic here.
     *
     * @param array<string, mixed> $groupData
     * @phpstan-param CommunityGroupData $groupData
     * @return array<string, mixed>
     */
    public function getCommunityCardFromGroupData(array $groupData, int $viewerId): array
    {
        $groupId     = $groupData['group_id'];
        $name        = $groupData['name'];
        $slug        = $groupData['slug'];
        $type        = $groupData['type'];
        $chainTag    = $groupData['chain_tag'];
        $imageUrl    = $groupData['image_url'];
        $memberCount = $groupData['member_count'];
        $posts7d     = $groupData['posts_last_7d'];
        // Defensive: membership is only meaningful for authed viewers.
        $viewerIsMember = $viewerId > 0 && $groupData['viewer_is_member'];

        // NFT + validator/delegator groups with a resolved chain get the
        // chain-keyed band (same crest grammar page cards will use in
        // V1.5); everything else gets the tier band at the fixed
        // "common" tier.
        if (($type === 'nft' || $type === 'validator') && $chainTag !== null && $chainTag !== '') {
            $backgroundKind  = 'chain';
            $backgroundValue = $chainTag;
        } else {
            $backgroundKind  = 'tier';
            $backgroundValue = 'common';
        }

        return [
            'id'                  => $groupId,
            'name'                => $name,
            'handle'              => $slug,
            'card_kind'           => 'community',
            // Producers already truncate the description to ~200 chars
            // (same bound as truncateBio) — pass through; '' when unset
            // to match the page/member bio convention.
            'bio'                 => $groupData['description'] ?? '',
            'trust_score'         => 0,
            'reputation_tier'     => 'neutral',
            'reputation_tier_label' => null,
            'card_tier'           => 'common',
            'tier_label'          => self::COMMUNITY_TIER_LABEL_BY_TYPE[$type] ?? 'COMMUNITY',
            'rank_label'          => null,
            'current_rank_label'  => null,
            'is_in_good_standing' => true,
            'flags'               => [],
            'viewer_has_reviewed' => false,
            'viewer_has_endorsed' => false,
            'endorse_unlock_hint' => null,
            'viewer_attestation'  => null,
            'chains'              => null,
            'is_claimed'          => true,
            // Communities aren't on-chain-claimed — always false, emitted
            // for shape uniformity (mirrors is_claimed above).
            'is_claim_verified'   => false,
            'claim_target'        => null,
            'onchain_signals'     => null,
            'member_dossier'      => null,
            // The community-only back-face block. collection_stats is
            // the §4.7.4 NFT market block (null for non-NFT kinds) —
            // replaces FlippableNftCard's bespoke stats display.
            'community_dossier'   => [
                'type'             => $type,
                'privacy'          => $groupData['privacy'],
                'member_count'     => $memberCount,
                'verification'     => $groupData['verification'],
                'chain_tag'        => $chainTag !== '' ? $chainTag : null,
                'trust_min'        => $groupData['trust_min'],
                'collection_stats' => $groupData['collection_stats'],
                'viewer_is_member' => $viewerIsMember,
            ],
            'crest'               => self::buildCrest(
                $name,
                $backgroundKind,
                $backgroundValue,
                $imageUrl ?? ''
            ),
            'stats'               => [
                ['key' => 'members',  'label' => 'Members',    'value' => (string) $memberCount, 'raw' => $memberCount, 'format' => 'count'],
                ['key' => 'posts_7d', 'label' => 'Posts (7d)', 'value' => (string) $posts7d,     'raw' => $posts7d,     'format' => 'count'],
            ],
            'permissions'         => self::communityPermissions(),
            'social_proof'        => null,
            'links'               => ['self' => '/communities/' . $slug],
            // `open` is the only card action — communities are joined
            // via the group detail's own join flow, never watched, so
            // no `watch` action is emitted. Self-describing GET
            // of the §4.7.5 detail endpoint, mirroring the page-card
            // action grammar.
            'actions'             => [
                'open' => [
                    'method'        => 'GET',
                    'href'          => '/wp-json/bcc/v1/groups/' . $slug,
                    'idempotent'    => true,
                    'requires_auth' => false,
                ],
            ],
        ];
    }

    /**
     * The full §3.2 permission key set, every entry denied with
     * `not_applicable` — identical for anon and authed viewers because
     * none of the card verbs (watch / review / dispute / endorse /
     * attest / edit) exist for communities regardless of auth state.
     * Signing in unlocks nothing here, so no `signin_required` copy.
     *
     * @return array<string, Permission>
     */
    private static function communityPermissions(): array
    {
        $na = self::deny(null, 'not_applicable');
        return [
            'can_watch'          => $na,
            'can_review'         => $na,
            'can_open_dispute'   => $na,
            'can_endorse'        => $na,
            'can_post_as_entity' => $na,
            'can_edit_bio'       => $na,
            'can_edit_image'     => $na,
            'can_vouch'          => $na,
            'can_stand_behind'   => $na,
            'can_report'         => $na,
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // Resolution helpers
    // ──────────────────────────────────────────────────────────────────

    private static function resolvePagePost(string $id): ?\WP_Post
    {
        if ($id === '') {
            return null;
        }

        // Numeric → treat as post_id; non-numeric → treat as post_name.
        if (ctype_digit($id)) {
            $post = get_post((int) $id);
        } else {
            $post = get_page_by_path($id, OBJECT, 'peepso-page');
        }

        if (!$post instanceof \WP_Post) {
            return null;
        }
        if ($post->post_type !== 'peepso-page' || $post->post_status !== 'publish') {
            return null;
        }
        return $post;
    }

    private static function resolveHandle(string $handle): int
    {
        $handle = strtolower(trim($handle));
        if ($handle === '') {
            return 0;
        }

        $userIds = get_users([
            'meta_key'   => 'bcc_handle',
            'meta_value' => $handle,
            'number'     => 1,
            'fields'     => 'ID',
        ]);

        if (empty($userIds)) {
            return 0;
        }
        return (int) $userIds[0];
    }

    private static function resolveMemberHandle(\WP_User $user): string
    {
        $handle = (string) get_user_meta($user->ID, 'bcc_handle', true);
        return $handle !== '' ? $handle : $user->user_login;
    }

    private static function isPageClaimed(int $pageId): bool
    {
        return ClaimRepository::getForEntity('page', $pageId) !== [];
    }

    private static function viewerHasClaim(int $viewerId, int $pageId): bool
    {
        return self::claimRowIsVerified(
            ClaimRepository::getUserClaim($viewerId, 'page', $pageId)
        );
    }

    /**
     * The status==='verified' reduction shared by the single-page
     * fallback (viewerHasClaim) and the prefetched path — one place,
     * so the two can never disagree on what "claimed by viewer" means.
     *
     * @phpstan-param ClaimRow|null $claim
     */
    private static function claimRowIsVerified(?object $claim): bool
    {
        if ($claim === null) {
            return false;
        }
        return isset($claim->status) && $claim->status === 'verified';
    }

    /**
     * Build the §N8 claim_target block. Returns null when the page is
     * already claimed (no claim flow needed) or when no on-chain
     * entity backs the page (claim flow has nothing to verify).
     *
     * V1: validator only. Creator (NFT collection) claims layer on
     * later via the same shape — different `entity_type`, different
     * resolver. Project pages don't claim (they aggregate validators).
     *
     * `$validatorRows` (when non-null) is the page's slice of the
     * PageCardPrefetcher batch — its first row replaces the
     * findFirstByPageId query. The batch is chain-name ordered, so
     * "first" is deterministic where the old LIMIT 1 was arbitrary
     * on multi-chain pages (benign tightening).
     *
     * @phpstan-param list<ValidatorCardRow>|null $validatorRows
     * @return array{entity_type: string, entity_id: int, chain_slug: string}|null
     */
    private static function resolveClaimTarget(string $kind, int $pageId, bool $isClaimed, ?array $validatorRows = null): ?array
    {
        if ($isClaimed || $kind !== 'validator') {
            return null;
        }
        $row = $validatorRows !== null
            ? ($validatorRows[0] ?? null)
            : ValidatorRepository::findFirstByPageId($pageId);
        if ($row === null) {
            return null;
        }
        return [
            'entity_type' => 'validator',
            'entity_id'   => (int) $row->validator_id,
            'chain_slug'  => (string) $row->chain_slug,
        ];
    }

    /**
     * Build the `onchain_signals` view-model block from
     * bcc_onchain_validators. Surfaces the chain's own data on a card
     * so unclaimed placeholders still tell the viewer what the operator
     * actually does on-chain (uptime, commission, voting rank, status).
     *
     * Returns null for non-validator kinds and for validator pages with
     * no resolvable on-chain row (transient indexer state). Floats are
     * normalized to 0..1 ratios (uptime, commission); bigint stakes are
     * returned as strings to preserve Cosmos token precision.
     *
     * @return array{
     *     status: string,
     *     uptime_30d: float|null,
     *     commission_rate: float|null,
     *     voting_power_rank: int|null,
     *     total_stake: string|null,
     *     self_stake: string|null,
     *     delegator_count: int|null,
     *     jailed_count: int|null,
     *     last_fetched_at: string|null
     * }|null
     *
     * `$validatorRows` (when non-null) is the page's slice of the
     * PageCardPrefetcher batch — its first row replaces the
     * findSignalsByPageId query (same columns, same two-path
     * resolution; chain-name-ASC "first" is the documented benign
     * tightening over the old arbitrary LIMIT 1).
     *
     * @phpstan-param list<ValidatorCardRow>|null $validatorRows
     */
    private static function resolveOnchainSignals(string $kind, int $pageId, ?array $validatorRows = null): ?array
    {
        if ($kind !== 'validator') {
            return null;
        }
        $row = $validatorRows !== null
            ? ($validatorRows[0] ?? null)
            : ValidatorRepository::findSignalsByPageId($pageId);
        if ($row === null) {
            return null;
        }

        // DB stores uptime/commission as DECIMAL(5,2) which the driver
        // returns as a string like "98.73" — divide by 100 here so the
        // frontend always gets a 0..1 ratio and never has to remember
        // the unit convention.
        $uptime = $row->uptime_30d !== null ? (float) $row->uptime_30d / 100.0 : null;
        $comm   = $row->commission_rate !== null ? (float) $row->commission_rate / 100.0 : null;

        return [
            'status'            => (string) $row->status,
            'uptime_30d'        => $uptime,
            'commission_rate'   => $comm,
            'voting_power_rank' => $row->voting_power_rank !== null ? (int) $row->voting_power_rank : null,
            // Stakes are DECIMAL(30,8); preserve precision by passing
            // through as strings. The frontend formats with a chain-aware
            // helper rather than parsing as float (which would clip
            // precision for large Cosmos stake amounts).
            'total_stake'       => $row->total_stake,
            'self_stake'        => $row->self_stake,
            'delegator_count'   => $row->delegator_count !== null ? (int) $row->delegator_count : null,
            'jailed_count'      => $row->jailed_count !== null ? (int) $row->jailed_count : null,
            'last_fetched_at'   => self::formatIso8601($row->fetched_at),
        ];
    }

    /**
     * Normalize a MySQL DATETIME (UTC) to ISO-8601 with a trailing Z.
     * Returns null when the input is empty / unparseable, so the
     * frontend can collapse "never fetched" into "—" rather than
     * trying to format an empty string.
     */
    private static function formatIso8601(?string $mysqlDateTime): ?string
    {
        if ($mysqlDateTime === null || $mysqlDateTime === '' || $mysqlDateTime === '0000-00-00 00:00:00') {
            return null;
        }
        $ts = strtotime($mysqlDateTime . ' UTC');
        if ($ts === false) {
            return null;
        }
        return gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    /**
     * Build the §K3 `chains` array. Returns null for single-chain pages
     * (the common case) so the frontend's <ChainTabs> can self-hide
     * without a length check on the consumer side. Returns the array
     * when 2+ chains back the same page (one operator running on
     * Cosmos + Osmosis + Injective for example).
     *
     * V1.5: validator-only. Creators get their multi-chain treatment
     * via the gallery chain filter (V2 per §H1).
     *
     * `$validatorRows` (when non-null) is the page's slice of the
     * PageCardPrefetcher batch — replaces the findAllByPageId query
     * (same rows, same chain-name-ASC order).
     *
     * PRIVACY: `operator_address` was removed 2026-07-23. Validator rows
     * are keyed to a wallet link (`onchain_validators.wallet_link_id`),
     * and `ClaimService::matchValidatorWallet` matches the operator
     * address against the CLAIMANT'S VERIFIED WALLET. So on a claimed
     * page this field published an on-chain address provably bound to a
     * named member — a member↔wallet join on an anonymous surface. A
     * Cosmos `valoper` also converts trivially to the operator's account
     * address, and the frontend was rendering it truncated, which is a
     * shortened wallet address by another name.
     *
     * Replaced by the derived, non-identifying `operator_verified`: "we
     * hold a verified on-chain operator identity for this chain." It
     * supports the same UI affordance (which chains this operator runs
     * on) without disclosing an address.
     *
     * See docs/wallet-privacy-policy.md.
     *
     * @phpstan-param list<ValidatorCardRow>|null $validatorRows
     * @return list<array{slug: string, name: string, operator_verified: bool}>|null
     */
    private static function resolveChains(string $kind, int $pageId, ?array $validatorRows = null): ?array
    {
        if ($kind !== 'validator') {
            return null;
        }
        $rows = $validatorRows ?? ValidatorRepository::findAllByPageId($pageId);
        if (count($rows) < 2) {
            // Single-chain (or no validator data) → no tabs to render.
            // Returning null keeps the contract uniform with member
            // cards and lets the frontend treat absence as "single chain".
            return null;
        }
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'slug'              => (string) $row->chain_slug,
                'name'              => (string) $row->chain_name,
                // Derived boolean only — never the address itself.
                'operator_verified' => ((string) $row->operator_address) !== '',
            ];
        }
        return $out;
    }

    // ──────────────────────────────────────────────────────────────────
    // Tier
    // ──────────────────────────────────────────────────────────────────

    private static function isInGoodStanding(string $tier): bool
    {
        return in_array($tier, ['neutral', 'trusted', 'elite'], true);
    }

    /**
     * Map a card_kind slug to the Trust Attestation Layer target_kind
     * per §J.1. Returns null for kinds that don't carry attestations
     * (member cards use `getMemberCard` separately with hard-coded
     * `user_profile`).
     */
    private static function cardKindToTargetKind(string $cardKind): ?string
    {
        return match ($cardKind) {
            'validator' => 'validator_card',
            'project'   => 'project_card',
            'creator'   => 'creator_card',
            default     => null,
        };
    }

    /**
     * V1 contract `flags: string[]` (api-contract-v1.md §3 / line 625).
     *
     * Page cards derive flags from the page's owner (post_author);
     * member cards from the user themselves. Both routes converge on
     * UserViewService::resolveFlags so §A4 (single source of trust
     * logic) holds — no parallel implementation, no extra repository
     * call. Page-level moderation flags are NOT a V1 source per the
     * locked contract; if the page owner is suspended/hidden/etc. that
     * state surfaces here through the owner's own flag set.
     *
     * @return list<string>
     */
    private static function buildFlags(int $ownerId): array
    {
        return UserViewService::resolveFlags($ownerId);
    }

    // ──────────────────────────────────────────────────────────────────
    // Crest
    // ──────────────────────────────────────────────────────────────────

    /**
     * Server-derived crest data (per §L5: monogram + background are
     * server-computed). Initials = up to two leading letters from the
     * first two whitespace-separated tokens, uppercased.
     *
     * @return array{initials: string, monogram_color: string, background: string}
     */
    /**
     * Resolve a short editorial bio for a peepso-page card. The
     * post_excerpt is the WP-canonical short-description field — use
     * it first; fall back to a truncated post_content if the excerpt
     * is empty so legacy pages that never set one still get a back-
     * face line. Returns "" when neither has any text.
     */
    private static function resolvePageBio(\WP_Post $post): string
    {
        $raw = (string) $post->post_excerpt;
        if (trim($raw) === '') {
            // wp_strip_all_tags removes shortcodes' HTML output and any
            // raw HTML; PeepSo pages can hold builder-generated markup
            // we don't want bleeding into a single-line back-face bio.
            $raw = wp_strip_all_tags((string) $post->post_content);
        }
        return self::truncateBio($raw);
    }

    /**
     * Resolve a short editorial bio for a member card from the WP
     * user's `description` field (the standard profile-bio field).
     * Returns "" when not set.
     */
    private static function resolveMemberBio(\WP_User $user): string
    {
        $raw = (string) ($user->description ?? '');
        return self::truncateBio($raw);
    }

    /**
     * Truncate a bio string to ~200 chars at a word boundary, with an
     * ellipsis suffix when it actually got cut. Server-side per §A2 so
     * the frontend renders verbatim and back-face layout stays stable.
     *
     * Empty / whitespace-only input → "".
     */
    private static function truncateBio(string $raw): string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', $raw));
        if ($clean === '') {
            return '';
        }
        $maxChars = 200;
        $length = function_exists('mb_strlen')
            ? mb_strlen($clean)
            : strlen($clean);
        if ($length <= $maxChars) {
            return $clean;
        }
        $cut = function_exists('mb_substr')
            ? mb_substr($clean, 0, $maxChars)
            : substr($clean, 0, $maxChars);
        // Backtrack to the last space so we don't slice mid-word.
        $lastSpace = (function_exists('mb_strrpos') ? mb_strrpos($cut, ' ') : strrpos($cut, ' '));
        if ($lastSpace !== false && $lastSpace > 0) {
            $cut = function_exists('mb_substr')
                ? mb_substr($cut, 0, $lastSpace)
                : substr($cut, 0, $lastSpace);
        }
        return rtrim($cut, " \t\n\r\0\x0B,;:.") . '…';
    }

    /**
     * Resolve a peepso-page's avatar URL — PeepSoPagePhoto first, post
     * thumbnail second. Empty string when neither resolves; the
     * frontend renders the initials monogram fallback in that case.
     *
     * `$validatorRows` (when non-null) is the page's slice of the
     * PageCardPrefetcher batch — its first row's logo_url replaces the
     * findLogoByPageId query in step 3.
     *
     * @phpstan-param list<ValidatorCardRow>|null $validatorRows
     */
    private static function resolvePageAvatarUrl(int $pageId, ?array $validatorRows = null, ?string $batchedValidatorLogo = null): string
    {
        // 1. PeepSo page avatar (manual upload).
        if (class_exists('PeepSoPagePhoto')) {
            $photo = \PeepSoPagePhoto::get_instance();
            if ($photo) {
                $url = $photo->get_page_avatar_url($pageId);
                if (is_string($url) && $url !== '') {
                    return $url;
                }
            }
        }

        // 2. WP featured image — where a claimer's uploaded avatar lands
        //    (POST /pages/{id}/avatar pins it via set_post_thumbnail).
        $thumb = get_the_post_thumbnail_url($pageId, 'thumbnail');
        if (is_string($thumb) && $thumb !== '') {
            return $thumb;
        }

        // 3. Auto-imported validator logo (self-hosted). Lowest precedence,
        //    so a claimer/manual image above always overrides it.
        if ($validatorRows !== null) {
            $first = $validatorRows[0] ?? null;
            $logo  = $first !== null ? $first->logo_url : null;
            if (!is_string($logo) || $logo === '') {
                // Prefetch mode: the card row's logo is empty (e.g. a
                // wallet-link row whose logo lives only on a meta-bound
                // validator the card-rows batch couldn't surface). Read the
                // page's slice of PageCardPrefetcher's validator_logos batch
                // instead of a per-card findLogoByPageId (the old N+1 —
                // 2 queries per validator card).
                $logo = $batchedValidatorLogo;
            }
        } else {
            // Single-card path (no prefetch bundle) — per-page resolution.
            $logo = \BCC\Trust\Onchain\Repositories\ValidatorRepository::findLogoByPageId($pageId);
        }
        if (is_string($logo) && $logo !== '') {
            return $logo;
        }

        // 4. Empty → frontend renders the initials monogram fallback.
        return '';
    }

    /**
     * Resolve a WP user's avatar URL via core's get_avatar_url. Honors
     * Gravatar / Buddyboss / PeepSo overrides because they all hook
     * the `get_avatar_url` filter. Empty string when no avatar resolves.
     */
    /**
     * Resolve the URL of a member's avatar for the card crest.
     *
     * Prefers PeepSo's avatar resolution (`PeepSoUser::get_avatar`)
     * because PeepSo stores custom avatars at its own filesystem path
     * (`/wp-content/peepso/users/{id}/avatar-full.jpg`) — WordPress's
     * native `get_avatar_url()` does not see those files unless PeepSo
     * is also filtering the WP avatar pipeline (which depends on a
     * plugin option that isn't guaranteed on). Asking PeepSo directly
     * gives us the canonical resolved URL — custom upload first,
     * configured Gravatar fallback second, default avatar third.
     *
     * Falls back to WP's `get_avatar_url` when PeepSo isn't loaded so
     * the crest still resolves on installations without PeepSo.
     */
    private static function resolveMemberAvatarUrl(int $userId): string
    {
        // Cached, shared seam (§11) — see bcc-core PeepSoMediaCache for the
        // PeepSo-first resolution + why caching the URL is safe.
        return \BCC\Core\PeepSo\PeepSoMediaCache::avatarUrl($userId);
    }

    /**
     * Build the §2.9 Crest view-model. `background_kind` is one of
     * `chain` / `tier` / `solid`; `background_value` is a chain slug,
     * card_tier, or hex string respectively. `image_url` is null when
     * the entity has no custom crest image — frontend falls back to
     * `initials + monogram_color` inside the hex.
     *
     * @return array{initials: string, monogram_color: string, background_kind: string, background_value: string, image_url: string|null}
     */
    private static function buildCrest(string $name, string $backgroundKind, string $backgroundValue, string $imageUrl = ''): array
    {
        $initials = '';
        $name = trim($name);
        if ($name !== '') {
            $tokens = preg_split('/\s+/u', $name) ?: [];
            $tokens = array_slice($tokens, 0, 2);
            foreach ($tokens as $token) {
                if ($token === '') {
                    continue;
                }
                // First grapheme of each token, uppercased.
                $first = function_exists('mb_substr')
                    ? mb_substr($token, 0, 1)
                    : substr($token, 0, 1);
                $initials .= function_exists('mb_strtoupper')
                    ? mb_strtoupper($first)
                    : strtoupper($first);
            }
        }
        if ($initials === '') {
            $initials = '?';
        }

        // V1 monogram_color: deterministic pick from a small palette
        // hashed off the name. The palette is a Phase-1 placeholder —
        // chain-aware coloring lands when onchain meta is wired.
        $palette = ['#1a0f3e', '#0f3e2a', '#3e1a0f', '#0f3e3e', '#3e0f1a', '#3e3a0f'];
        $hash    = crc32($name);
        $color   = $palette[$hash % count($palette)];

        return [
            'initials'         => $initials,
            'monogram_color'   => $color,
            'background_kind'  => $backgroundKind,
            'background_value' => $backgroundValue,
            'image_url'        => $imageUrl !== '' ? $imageUrl : null,
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // Stats
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param PageReadModelRow|null $rm
     * @return list<Stat>
     */
    private static function buildPageStats(int $trustScore, ?object $rm): array
    {
        $followers    = $rm !== null ? (int) $rm->follower_count : 0;
        $reviews      = $rm !== null ? (int) $rm->vote_count : 0;
        $endorsements = $rm !== null ? (int) $rm->endorsement_count : 0;

        return [
            ['key' => 'trust',        'label' => 'Trust',        'value' => (string) $trustScore, 'raw' => $trustScore,  'format' => 'score'],
            ['key' => 'followers',    'label' => 'Followers',    'value' => (string) $followers,  'raw' => $followers,    'format' => 'count'],
            ['key' => 'reviews',      'label' => 'Reviews',      'value' => (string) $reviews,    'raw' => $reviews,      'format' => 'count'],
            ['key' => 'endorsements', 'label' => 'Vouches', 'value' => (string) $endorsements, 'raw' => $endorsements, 'format' => 'count'],
        ];
    }

    /**
     * @param int $watchersCount Passed in from getSummary's
     *                           `followers_count` so the 3-col
     *                           StatsPanel isn't sparse — no re-query.
     * @param array<string, mixed>|null $prefetched MemberSummaryPrefetcher
     *                           batch; its `reviews_written_counts` map is
     *                           consulted before falling back to a
     *                           per-member countByVoter() query.
     * @return list<Stat>
     */
    private function buildMemberStats(int $userId, int $trustScore, int $watchersCount, ?array $prefetched = null): array
    {
        // Same read pattern as UserViewService::getSummary — when the
        // batch map is present, a missing key means zero (countByVoters
        // GROUP BY omits zero-count voters), not "not prefetched".
        if ($prefetched !== null && isset($prefetched['reviews_written_counts'])) {
            $reviewsWritten = (int) ($prefetched['reviews_written_counts'][$userId] ?? 0);
        } else {
            $reviewsWritten = (int) $this->voteRepo->countByVoter($userId);
        }

        return [
            ['key' => 'trust',           'label' => 'Trust',    'value' => (string) $trustScore,     'raw' => $trustScore,     'format' => 'score'],
            ['key' => 'reviews_written', 'label' => 'Reviews',  'value' => (string) $reviewsWritten, 'raw' => $reviewsWritten, 'format' => 'count'],
            ['key' => 'watchers',        'label' => 'Watchers', 'value' => (string) $watchersCount,  'raw' => $watchersCount,  'format' => 'count'],
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // Permissions
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param array{allowed: bool, unlock_hint: string|null, reason_code: string|null} $endorseEligibility
     * @return array<string, Permission>
     */
    /**
     * @param array<string, mixed> $endorseEligibility
     * @return array<string, mixed>
     */
    private function resolvePagePermissions(
        int $viewerId,
        bool $viewerIsClaimer,
        array $endorseEligibility,
        int $pageOwnerUserId,
        ?string $targetKind,
        int $targetId
    ): array {
        // §J.6 attestation permissions: the service handles both anon
        // (returns "Sign in to <verb>." aspirational copy across all
        // three actions, visible path per §N7) and authed branches
        // (tier-gated + self-target-prevented).
        $attestationPerms = $this->attestationService->getViewerActionPermissions(
            $viewerId,
            $pageOwnerUserId
        );
        $attestationFields = [
            'can_vouch'        => self::attestationPermToCardPerm($attestationPerms['can_vouch']),
            'can_stand_behind' => self::attestationPermToCardPerm($attestationPerms['can_stand_behind']),
            'can_report'       => self::attestationPermToCardPerm($attestationPerms['can_report']),
        ];

        if ($viewerId <= 0) {
            // Legacy permissions stay in their existing locked shape;
            // attestation fields use the service's anon copy.
            return array_merge(self::lockedPagePermissions(), $attestationFields);
        }

        return array_merge(
            [
                'can_watch'          => self::allow(),
                'can_review'         => self::featureGate($this->featureAccess->canPerform($viewerId, 'write_review')),
                // §4.4 can_open_dispute — the OWNER vote-dispute entry
                // (DisputeCallout → OpenDisputeModal → POST /disputes).
                // Mirrors the write gate exactly: DisputeController
                // requires Permissions::owns_page and nothing else — no
                // feature-ladder gate on the write path. owns_page
                // resolves through PageOwnerResolver, request-cached and
                // pre-primed on cards-list paths. This is the SOLE
                // dispute gate — the retired can_dispute (a dead "§J
                // attestation cast" that had no attestation kind) is gone.
                'can_open_dispute'   => \BCC\Core\Permissions\Permissions::owns_page($targetId, $viewerId)
                    ? self::allow()
                    : self::deny(null, 'not_page_owner'),
                // §V1.5 — endorse eligibility is precomputed by
                // EndorsementService::getEndorseEligibility (mirrors the
                // gates inside endorsePage but read-only). Pass-through the
                // shape it already produces.
                'can_endorse'        => $endorseEligibility,
                'can_post_as_entity' => $viewerIsClaimer ? self::allow() : self::deny(null, 'not_claimer'),
                'can_edit_bio'       => $viewerIsClaimer ? self::allow() : self::deny(null, 'not_claimer'),
                'can_edit_image'     => $viewerIsClaimer ? self::allow() : self::deny(null, 'not_claimer'),
            ],
            // §J.6 Trust Attestation Layer permissions. Tier-gated +
            // self-target-prevented. The shapes are
            // {allowed, unlock_hint, reason_code} per CardPermissionEntry;
            // reason_code is null at V1 (the FE only needs allowed +
            // unlock_hint for the AttestationActionCluster's render).
            $attestationFields
        );
    }

    /**
     * Adapt an AttestationService permission entry into the
     * CardPermissionEntry shape (adds reason_code: null since the
     * §J.6 attestation flow doesn't carry reason codes at V1).
     *
     * @param array{allowed: bool, unlock_hint: string|null} $entry
     * @return array{allowed: bool, unlock_hint: string|null, reason_code: string|null}
     */
    private static function attestationPermToCardPerm(array $entry): array
    {
        return [
            'allowed'     => $entry['allowed'],
            'unlock_hint' => $entry['unlock_hint'],
            'reason_code' => null,
        ];
    }

    /**
     * @return array<string, Permission>
     */
    private function resolveMemberPermissions(int $targetUserId, int $viewerId): array
    {
        // §J.6 attestation permissions on member cards (target_kind=
        // user_profile per §J.1). Service handles anon ("Sign in to…")
        // + self-target (hidden) + tier-gating uniformly.
        $attestationPerms = $this->attestationService->getViewerActionPermissions(
            $viewerId,
            $targetUserId
        );
        $attestationFields = [
            'can_vouch'        => self::attestationPermToCardPerm($attestationPerms['can_vouch']),
            'can_stand_behind' => self::attestationPermToCardPerm($attestationPerms['can_stand_behind']),
            'can_report'       => self::attestationPermToCardPerm($attestationPerms['can_report']),
        ];

        if ($viewerId <= 0) {
            return array_merge(self::lockedMemberPermissions(), $attestationFields);
        }
        $isSelf = $viewerId === $targetUserId;

        return array_merge(
            [
                // "Watch" on a member card = follow that user. Self-follow is meaningless.
                'can_watch'          => $isSelf ? self::deny(null, 'self_action_blocked') : self::allow(),
                'can_review'         => $isSelf
                    ? self::deny(null, 'self_action_blocked')
                    : self::featureGate($this->featureAccess->canPerform($viewerId, 'write_review')),
                // §4.4 can_open_dispute — members are full trust subjects:
                // a member viewing their OWN self-page can open a dispute
                // when the self-page carries a contestable active downvote
                // (vote_type=-1, status=1 on selfPageId). Non-owners never
                // see can_open_dispute=true (the owner-only entry point).
                // Mirrors the page-card owner gate, but member profiles
                // have no claimer concept — ownership IS being the member,
                // resolved zero-DB via the self-page id.
                'can_open_dispute'   => $this->resolveMemberOpenDispute($targetUserId, $isSelf),
                // Endorsements target page-cards (validator/project/creator)
                // only. Members are followed/reviewed via different surfaces.
                'can_endorse'        => self::deny(null, 'not_applicable'),
                'can_post_as_entity' => self::deny(null, 'not_applicable'),
                'can_edit_bio'       => $isSelf ? self::allow() : self::deny(null, 'not_owner'),
                // Page-image editing applies to claimed validator/project/
                // creator pages; member self-avatars use /me/profile/avatar.
                'can_edit_image'     => self::deny(null, 'not_applicable'),
            ],
            $attestationFields
        );
    }

    /**
     * §4.4 can_open_dispute for a member self-page. Owner-only: a
     * non-owner viewer never gets the dispute entry point (returns
     * not_applicable, the locked member-card shape). The owner gets
     * allow() iff their self-page carries a contestable active downvote
     * (vote_type=-1, status=1), else a not-yet-applicable denial so the
     * FE can hide the entry point until there is something to contest.
     *
     * id-duality: the downvote read keys on the member's self-page id
     * (MemberSelfPageService::selfPageId), where votes/scores live — not
     * the raw user id. ScoreRepository / VoteRepository are self-page
     * aware (Architecture A), so this is the same predicate the
     * DisputeController enforces per-vote, surfaced page-scoped.
     *
     * @return Permission
     */
    private function resolveMemberOpenDispute(int $targetUserId, bool $isSelf): array
    {
        if (!$isSelf) {
            return self::deny(null, 'not_applicable');
        }
        $selfPageId = MemberSelfPageService::selfPageId($targetUserId);
        if ($selfPageId <= 0 || !$this->voteRepo->hasActiveDownvoteForPage($selfPageId)) {
            // Owner, but nothing to contest yet — distinct from the
            // not_applicable a non-owner gets so the FE can message
            // "no contestable downvotes" vs hide the control entirely.
            return self::deny(null, 'no_contestable_downvote');
        }
        return self::allow();
    }

    /**
     * @return array<string, Permission>
     */
    private static function lockedPagePermissions(): array
    {
        $sign = self::deny('Sign in to interact', 'signin_required');
        return [
            'can_watch'          => $sign,
            'can_review'         => $sign,
            'can_open_dispute'   => $sign,
            'can_endorse'        => $sign,
            'can_post_as_entity' => $sign,
            'can_edit_bio'       => $sign,
            'can_edit_image'     => $sign,
        ];
    }

    /**
     * @return array<string, Permission>
     */
    private static function lockedMemberPermissions(): array
    {
        $sign = self::deny('Sign in to interact', 'signin_required');
        return [
            'can_watch'          => $sign,
            'can_review'         => $sign,
            'can_open_dispute'   => self::deny(null, 'not_applicable'),
            'can_endorse'        => self::deny(null, 'not_applicable'),
            'can_post_as_entity' => self::deny(null, 'not_applicable'),
            'can_edit_bio'       => $sign,
            'can_edit_image'     => self::deny(null, 'not_applicable'),
        ];
    }

    /**
     * Wrap a FeatureAccessService::canPerform() result with the
     * contract's reason_code field. The underlying service emits only
     * {allowed, unlock_hint}; for V1 we tag denials as `feature_locked`
     * generically. A future enhancement to canPerform() can pass through
     * a more specific reason code per gate kind (level / tier / wallet).
     *
     * @param array{allowed: bool, unlock_hint: string|null} $perm
     * @return Permission
     */
    private static function featureGate(array $perm): array
    {
        return [
            'allowed'     => $perm['allowed'],
            'unlock_hint' => $perm['unlock_hint'],
            'reason_code' => $perm['allowed'] ? null : 'feature_locked',
        ];
    }

    /**
     * @return Permission
     */
    private static function allow(): array
    {
        return ['allowed' => true, 'unlock_hint' => null, 'reason_code' => null];
    }

    /**
     * @return Permission
     */
    private static function deny(?string $unlockHint, string $reasonCode): array
    {
        return ['allowed' => false, 'unlock_hint' => $unlockHint, 'reason_code' => $reasonCode];
    }

    // ──────────────────────────────────────────────────────────────────
    // Links (frontend routes) + Actions (API endpoints)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Frontend navigation routes only — not API endpoints.
     *
     * `self`   — canonical entity page in the Next.js app
     * `review` — same entity page with the review composer pre-opened
     *
     * Field names stay semantic (the resource being navigated to),
     * never UI-action-specific. For state-changing operations the
     * frontend must use `actions`, never these.
     *
     * @return array{self: string, review: string}
     */
    private static function buildLinks(string $kind, string $handle): array
    {
        $base = CardUrlMap::frontendUrl($kind, $handle);
        return [
            'self'   => $base,
            'review' => $base . '?compose=review',
        ];
    }

    /**
     * API endpoints for page-cards (validator/project/creator).
     *
     * Both `watch` and `claim` are emitted self-describing — `body`
     * pre-baked so the frontend dispatches the action with zero
     * mapping logic ("frontend is dumb" rule).
     *
     * Every action carries:
     *   - method      — HTTP verb
     *   - href        — absolute API path
     *   - body        — pre-baked request body (when applicable)
     *   - idempotent  — safe to retry on network failure
     *                   (true for our server-side dedup'd POSTs:
     *                    watch → already_watching status,
     *                    claim → already_verified status)
     *   - requires_auth — true when the endpoint rejects anonymous calls
     *
     * `claim` is omitted when the page has no resolvable underlying
     * on-chain entity (validator or collection); the page isn't
     * claimable from this surface.
     *
     * `$prefetched` routes the page→entity resolution through the
     * PageCardPrefetcher batch (resolveEntitiesForPages) instead of
     * the per-page resolveEntityForPage query.
     *
     * @param array<string, mixed>|null $prefetched
     * @phpstan-param PageCardPrefetch|null $prefetched
     * @return array<string, Action>
     */
    private static function buildPageActions(string $kind, int $pageId, ?array $prefetched = null): array
    {
        $actions = [
            'watch' => [
                'method'        => 'POST',
                'href'          => '/wp-json/bcc/v1/me/watching/watch',
                'body'          => [
                    'target_kind' => $kind,
                    'target_id'   => $pageId,
                ],
                'idempotent'    => true,
                'requires_auth' => true,
            ],
        ];

        if ($prefetched !== null && isset($prefetched['page_entities'])) {
            $entity = $prefetched['page_entities'][$pageId] ?? null;
        } else {
            $entity = WalletRepository::resolveEntityForPage($pageId);
        }
        if ($entity !== null) {
            $actions['claim'] = [
                'method'        => 'POST',
                'href'          => '/wp-json/bcc/v1/pages/' . $pageId . '/claim',
                'body'          => [
                    'entity_type' => $entity['entity_type'],
                    'entity_id'   => $entity['entity_id'],
                ],
                'idempotent'    => true,
                'requires_auth' => true,
            ];
        }

        return $actions;
    }

    /**
     * API endpoints for member-cards. Members are not claimable in V1,
     * so only `watch` is emitted — same self-describing-body shape as
     * page-cards for cross-kind frontend uniformity.
     *
     * @return array<string, Action>
     */
    private static function buildMemberActions(int $userId): array
    {
        return [
            'watch' => [
                'method'        => 'POST',
                'href'          => '/wp-json/bcc/v1/me/watching/watch',
                'body'          => [
                    'target_kind' => 'member',
                    'target_id'   => $userId,
                ],
                'idempotent'    => true,
                'requires_auth' => true,
            ],
        ];
    }
}
