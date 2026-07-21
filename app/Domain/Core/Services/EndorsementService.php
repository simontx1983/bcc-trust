<?php
/**
 * Endorsement Services (read / eligibility / hydration) — attestation-backed.
 *
 * Slice E cutover (2026-06-25) retired the WRITE surface; the final
 * endorse-retirement slice (2026-07-02) retired the READ surface's legacy
 * backing table too. Every read here now sources the Trust Attestation
 * Layer (`bcc_trust_attestations`, active kind=vouch rows) — the live
 * truth since Slice E — while preserving the external §J.6 row shape
 * BYTE-FOR-BYTE (field names, endpoint envelopes, `/endorsements/mine`
 * + `/endorsements/mine/stats` unenveloped realities per contract §4.30,
 * `/users/:handle/endorsements` per §4.22). The frozen
 * `bcc_trust_endorsements` table, EndorsementRepository, and
 * EndorsementQueryService were deleted outright (fresh-install doctrine).
 *
 * Kind-scope decision (locked here): "given endorsements" = active VOUCH
 * attestations only. stand_behind is a distinct, slot-bounded action with
 * its own surfaces (the §J.4 attestation roster + slot_holders picker) —
 * folding it into the endorse-shaped list would double-surface it and
 * blur the two vocabularies.
 *
 * Target mapping (locked here): attestations target entity cards AND
 * user_profiles; both map to their score-row page ids for the §J.6 row —
 * `*_card` target_id IS the peepso-page id; `user_profile` maps to the
 * member self-page (MemberSelfPageService::selfPageId). Member rows
 * hydrate title/avatar/tier from the member (display name, user avatar,
 * self-page score row); `page_url` stays '' for self-pages (no permalink),
 * the same documented fallback as unresolvable pages.
 *
 * What remains here:
 *   - getEndorseEligibility* — the §L5 can_endorse preflight gate
 *     (vouch-aligned; delegates to AttestationService's can_vouch tier gate).
 *   - getUserEndorsements / getUserEndorsementStats — given-direction
 *     reads, attestation-backed.
 *   - hydrateEndorsementItems — §J.6 row hydration shared by
 *     UserEndorsementsEndpoint + UsersEndpoint.
 *
 * @package BCC\Trust\Core\Services
 * @version 4.0.0
 */

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Core\Services\PeepSoPageResolver;

if (!defined('ABSPATH')) {
    exit;
}

class EndorsementService {

    private AttestationService $attestationService;
    private AttestationRepository $attestationRepo;

    /** Defensive ceiling on the stats aggregate read (§4 bounded queries). */
    private const STATS_MAX_ROWS = 5000;

    public function __construct(
        AttestationService     $attestationService
    ) {
        $this->attestationService = $attestationService;
        $this->attestationRepo    = \BCC\Trust\Core\Plugin::instance()->attestationRepository();
    }

    /**
     * Read-only eligibility check — mirrors the gates the legacy write
     * path enforced but without mutating. Returns the §L5 Permission shape:
     *
     *   {allowed: bool, unlock_hint: string|null, reason_code: string|null}
     *
     * Used by CardViewService to populate `permissions.can_endorse`,
     * giving the frontend a sensible "show button" gate without
     * exposing internal fraud signals (reason_code is intentionally
     * coarse).
     *
     * NOTE: the write path is vouch-attestation, not the legacy
     * endorsement, so this gate is now vouch-aligned — it delegates to
     * the same Neutral-standing tier gate vouch uses
     * (AttestationService::getViewerActionPermissions()['can_vouch']).
     * The legacy quest / account-age / fraud gates were retired.
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

        // Viewer-level gate: the vouch tier gate (Neutral standing), shared
        // with the byline Vouch toggle so can_endorse and can_vouch track
        // the same threshold. Page-independent, so the batch path memoizes it.
        $viewerGate = $this->resolveViewerVouchGate($viewerId);
        if ($viewerGate !== null) {
            return $viewerGate;
        }

        return ['allowed' => true, 'unlock_hint' => null, 'reason_code' => null];
    }

    /**
     * The viewer-level (page-independent) endorse gate, now vouch-aligned:
     * delegates to the same Neutral-standing tier gate the byline Vouch
     * toggle uses (AttestationService::getViewerActionPermissions, with
     * target 0 so only the viewer tier gate applies). Returns the failing
     * Permission shape, or null when the viewer may endorse (including
     * BCC_TRUST_TEST_MODE, which skips the gate).
     *
     * Page-level gates (auth, self-endorse) stay with the callers — this
     * method only owns what's constant across pages for a viewer, so
     * getEndorseEligibilityForPages can memoize one result for a whole
     * cards-list page.
     *
     * @return array{allowed: bool, unlock_hint: string|null, reason_code: string|null}|null
     */
    private function resolveViewerVouchGate(int $viewerId): ?array {
        $testMode = defined('BCC_TRUST_TEST_MODE') && \BCC_TRUST_TEST_MODE;
        if ($testMode) {
            return null;
        }

        $canVouch = $this->attestationService->getViewerActionPermissions($viewerId, 0)['can_vouch'];
        if (!$canVouch['allowed']) {
            return [
                'allowed' => false,
                'unlock_hint' => 'Reach Neutral standing to endorse.',
                'reason_code' => 'tier_too_low',
            ];
        }

        return null;
    }

    /**
     * Batch variant of getEndorseEligibility for the cards-list path.
     * The viewer-level vouch tier gate is page-independent, so it's
     * evaluated ONCE; per page only the self-endorse check remains.
     * Per-page gate order is unchanged relative to getEndorseEligibility:
     * self-check first, then the memoized viewer-gate result.
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
        $viewerGate = $this->resolveViewerVouchGate($viewerId);
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
     * Given-direction read: active vouch attestations AUTHORED by the
     * user, shaped as the legacy raw-row array the two REST surfaces
     * feed through hydrateEndorsementItems.
     *
     * Per-entry keys (superset of what hydrate consumes; the raw rows
     * also leak — documented as OPAQUE — via the §4.30 stats
     * `recent_endorsements` slot):
     *   id, page_id, page_title, context ('general' constant — the only
     *   value the legacy allowlist ever admitted on this surface),
     *   weight (the attestation's cast-time weight_at_time), reason
     *   (the attestation context_note; null when blank), created_at,
     *   endorser_user_id.
     *
     * `page_id` mapping per the class docblock: card target_id passes
     * through; user_profile maps to the member self-page id.
     *
     * @return list<array<string, mixed>>
     */
    public function getUserEndorsements(?int $endorserUserId = null, int $limit = 20): array {
        $endorserUserId = $endorserUserId ?? get_current_user_id();

        if (!$endorserUserId) {
            return [];
        }

        $rows = $this->attestationRepo->listActiveVouchesByAttestor($endorserUserId, $limit);
        return $this->shapeGivenRows($rows, $endorserUserId);
    }

    /**
     * Shape raw active-vouch attestation rows into the legacy raw-row
     * array shape (see getUserEndorsements). Title resolution:
     * card targets read the page post title (post cache primed in one
     * batch); user_profile targets read the member display name.
     *
     * @param list<object{id: int, target_kind: string, target_id: int, weight_at_time: float, context_note: ?string, created_at: string}> $rows
     * @return list<array<string, mixed>>
     */
    private function shapeGivenRows(array $rows, int $endorserUserId): array {
        if ($rows === []) {
            return [];
        }

        // Batch-prime the WP post cache for card targets so the per-row
        // get_the_title calls below are cache hits (no N+1).
        $cardPageIds = [];
        foreach ($rows as $row) {
            if ($row->target_kind !== 'user_profile' && $row->target_id > 0) {
                $cardPageIds[] = $row->target_id;
            }
        }
        if ($cardPageIds !== []) {
            _prime_post_caches($cardPageIds, false, false);
        }

        $out = [];
        foreach ($rows as $row) {
            if ($row->target_kind === 'user_profile') {
                $pageId = MemberSelfPageService::selfPageId($row->target_id);
                $user   = get_userdata($row->target_id);
                $title  = $user instanceof \WP_User ? (string) $user->display_name : null;
            } else {
                $pageId = $row->target_id;
                $title  = get_the_title($row->target_id);
                $title  = $title !== '' ? $title : null;
            }

            $out[] = [
                'id'               => $row->id,
                'endorser_user_id' => $endorserUserId,
                'page_id'          => $pageId,
                'page_title'       => $title,
                'context'          => 'general',
                'weight'           => $row->weight_at_time,
                'reason'           => $row->context_note,
                'created_at'       => $row->created_at,
            ];
        }
        return $out;
    }

    /**
     * Hydrate raw endorsement rows into the §J.6 contract-stable item
     * shape (page_title + page_url + page-owner avatar, current tier
     * snapshot, weight + context + reason + timestamp).
     *
     * Shared between:
     *   - UserEndorsementsEndpoint::handleList  (`GET /endorsements/mine`)
     *   - UsersEndpoint::endorsements           (`GET /users/:handle/endorsements`)
     *
     * Single source of trust per §A4 — both surfaces emit identical
     * row shapes. The owner-side and public-side reads diverge on
     * permission + cache headers, not on row shape.
     *
     * Snapshot sources by target family:
     *   - entity-card rows — tier/score/avatar from bcc_page_read_model,
     *     page_url from the post permalink (unchanged from the legacy read).
     *   - member self-page rows (page_id > ID_BASE; only produced by the
     *     attestation-backed read — the frozen legacy table never held
     *     them) — tier/score from the member's self-page score row (the
     *     single source of member trust), avatar from the member's user
     *     avatar, page_url '' (no permalink exists; same documented
     *     fallback as an unresolvable page).
     *
     * §A view-model boundary: this is presentation-layer assembly
     * (avatar URL via WP, esc_url / sanitize_key for the wire). The
     * underlying score / tier / weight values are computed elsewhere.
     *
     * @param list<array<string, mixed>> $endorsements Raw rows from
     *     EndorsementService::getUserEndorsements.
     * @return list<array<string, mixed>>
     */
    public function hydrateEndorsementItems(array $endorsements): array
    {
        $card_page_ids = [];
        foreach ($endorsements as $e) {
            $pid = (int) ($e['page_id'] ?? 0);
            if ($pid > 0 && !MemberSelfPageService::isSelfPage($pid)) {
                $card_page_ids[] = $pid;
            }
        }
        $rm_rows = [];
        if (!empty($card_page_ids)) {
            $rm_rows = \BCC\Trust\Core\Plugin::instance()->pageReadModelRepository()->getByPageIds($card_page_ids);
            // Prime post caches so the per-row get_permalink() below is a
            // cache hit rather than one query per entity card.
            _prime_post_caches($card_page_ids, false, false);
        }

        // Pre-pass: batch the two per-row lookups the loop used to do —
        // self-page scores (getByPageId → getBatchScoreData) and avatars
        // for both member and entity owners (per-row get_avatar_url →
        // one cached bulk resolve).
        $selfPageIds = [];
        $memberById  = [];
        $avatarIds   = [];
        foreach ($endorsements as $e) {
            $pid = (int) ($e['page_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            if (MemberSelfPageService::isSelfPage($pid)) {
                $selfPageIds[] = $pid;
                $memberId = MemberSelfPageService::ownerOfSelfPage($pid);
                $memberById[$pid] = $memberId;
                if ($memberId > 0) {
                    $avatarIds[$memberId] = true;
                }
            } else {
                $rm = $rm_rows[$pid] ?? null;
                if ($rm && $rm->owner_id) {
                    $avatarIds[(int) $rm->owner_id] = true;
                }
            }
        }
        $selfScores = $selfPageIds === []
            ? []
            : \BCC\Trust\Core\Plugin::instance()->scoreRepository()->getBatchScoreData($selfPageIds);
        $avatars = $avatarIds === []
            ? []
            : \BCC\Core\PeepSo\PeepSoMediaCache::avatarUrlBulk(array_keys($avatarIds));

        $items = [];
        foreach ($endorsements as $e) {
            $pid = (int) ($e['page_id'] ?? 0);

            $pageTitle = isset($e['page_title']) && is_string($e['page_title']) ? $e['page_title'] : null;
            $context   = isset($e['context'])    && is_string($e['context'])    ? $e['context']    : null;
            $reason    = isset($e['reason'])     && is_string($e['reason'])     ? $e['reason']     : null;
            $createdAt = isset($e['created_at']) && is_string($e['created_at']) ? $e['created_at'] : null;

            if (MemberSelfPageService::isSelfPage($pid)) {
                // Member self-page row: snapshot from the batched self-page
                // score row + the member's own avatar. No permalink exists.
                $memberId = $memberById[$pid] ?? 0;
                $score    = $selfScores[$pid] ?? null;
                $avatar   = $memberId > 0 ? ($avatars[$memberId] ?? '') : '';

                $trustScore = $score !== null ? (int) round($score['total_score']) : null;
                $tier       = $score !== null ? sanitize_key($score['reputation_tier']) : 'unavailable';
                $pageUrl    = '';
            } else {
                $rm     = $rm_rows[$pid] ?? null;
                $avatar = '';
                if ($rm && $rm->owner_id) {
                    $avatar = $avatars[(int) $rm->owner_id] ?? '';
                }
                $trustScore = $rm ? (int) round((float) $rm->trust_score) : null;
                $tier       = $rm ? sanitize_key($rm->reputation_tier ?? 'neutral') : 'unavailable';
                $permalink  = get_permalink($pid);
                $pageUrl    = $permalink ? esc_url($permalink) : '';
            }

            $items[] = [
                'id'           => (int) ($e['id'] ?? 0),
                'page_id'      => $pid,
                'page_title'   => $pageTitle ?? __('(Untitled)', 'bcc-trust'),
                'page_url'     => $pageUrl,
                'avatar_url'   => $avatar ? esc_url($avatar) : '',
                'trust_score'  => $trustScore,
                'tier'         => $tier,
                'weight'       => round((float) ($e['weight'] ?? 0), 2),
                'context'      => $context ?? 'general',
                'reason'       => $reason,
                'created_at'   => $createdAt,
            ];
        }
        return $items;
    }

    /**
     * Aggregate stats over the caller's authored endorsements (§4.30,
     * UNENVELOPED raw array at the document root — preserved exactly).
     * Attestation-backed: one bounded repo read feeds every field.
     *
     * Key-for-key legacy parity:
     *   - total_endorsements_given — count of ACTIVE vouch attestations.
     *   - unique_pages_endorsed — distinct pages within the 10 most
     *     recent only (legacy semantics preserved, not lifetime distinct).
     *   - recent_endorsements — up to 5 raw rows (contract: OPAQUE).
     *   - endorsement_weight_avg — avg cast-time weight across active
     *     vouches; floors to 1.0 when no positive average.
     *   - last_endorsement — newest active vouch created_at, or null.
     *
     * @return array<string, mixed>
     */
    public function getUserEndorsementStats(int $userId): array {
        $rows   = $this->attestationRepo->listActiveVouchesByAttestor($userId, self::STATS_MAX_ROWS);
        $shaped = $this->shapeGivenRows($rows, $userId);

        $uniquePages = [];
        foreach (array_slice($shaped, 0, 10) as $e) {
            $uniquePages[(int) $e['page_id']] = true;
        }

        $weightSum = 0.0;
        foreach ($shaped as $e) {
            $weightSum += (float) $e['weight'];
        }
        $avg = count($shaped) > 0 ? $weightSum / count($shaped) : 0.0;

        return [
            'user_id' => $userId,
            'total_endorsements_given' => count($shaped),
            'unique_pages_endorsed' => count($uniquePages),
            'recent_endorsements' => array_map(
                static fn(array $e): object => (object) $e,
                array_slice($shaped, 0, 5)
            ),
            'endorsement_weight_avg' => $avg > 0 ? round($avg, 2) : 1.0,
            'last_endorsement' => $shaped !== [] ? $shaped[0]['created_at'] : null,
        ];
    }
}
