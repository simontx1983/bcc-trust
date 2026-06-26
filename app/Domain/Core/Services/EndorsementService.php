<?php
/**
 * Endorsement Services (read / eligibility / hydration / vesting).
 *
 * Slice E cutover (2026-06-25): the WRITE surface of this service —
 * endorsePage / revokePageEndorsement / vouchForAuthor /
 * revokeVouchForAuthor and their bonus-application + fraud + cap
 * helpers — was retired. endorsement_bonus is dropped from the trust
 * formula and the Trust Attestation Layer (AttestationService) is the
 * single backing the score reads. The REST /endorse + /revoke-endorsement
 * surfaces now route through AttestationService::cast / ::revokeByTarget.
 * Vouch later relocated off the PeepSo reaction entirely onto the
 * first-class per-author byline Vouch toggle (full-weight cast() via
 * /me/attestations) — it is no longer a reaction.
 *
 * What remains here is read-only:
 *   - getEndorseEligibility* — the §L5 can_endorse preflight gate the
 *     card composer + PageCardPrefetcher consume (eligibility hint, not
 *     a write path; aligning it to vouch eligibility is a documented
 *     follow-up).
 *   - getUserEndorsements / getUserEndorsementStats / hasEndorsedPage —
 *     legacy endorsement-table reads still surfaced by
 *     UserEndorsementsEndpoint / UsersEndpoint.
 *   - hydrateEndorsementItems — §J.6 row hydration shared by those reads.
 *   - processEndorsementVesting — the daily-cron graduation of any
 *     remaining legacy endorsement rows (shared vesting cron).
 *
 * @package BCC\Trust\Core\Services
 * @version 3.0.0
 */

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Services\PeepSoPageResolver;

if (!defined('ABSPATH')) {
    exit;
}

class EndorsementService {

    private UserInfoRepository $userInfoRepo;
    private EndorsementQueryService $queryService;

    public function __construct(
        UserInfoRepository     $userInfoRepo
    ) {
        $this->userInfoRepo      = $userInfoRepo;
        $this->queryService      = new EndorsementQueryService();
    }

    /**
     * Check if user has endorsed page
     */
    public function hasEndorsedPage(int $pageId, ?int $endorserUserId = null, ?string $context = null): bool {
        return $this->queryService->hasEndorsedPage($pageId, $endorserUserId, $context);
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
     * NOTE (Slice E follow-up): the write path is now vouch-attestation,
     * not the legacy endorsement. Aligning these gates to vouch
     * eligibility (tier-based) is a documented follow-up; this method
     * keeps the legacy quest / account-age / fraud gates for V1.
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
     * account-age / fraud-score, in that order. Returns the first
     * failing gate's Permission shape, or null when every gate passes
     * (including BCC_TRUST_TEST_MODE, which skips all three).
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

        // Fraud-score gate. Defense-in-depth — the cast() path re-checks
        // its own fraud gate inside the transaction.
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
     * Called by the daily cron (CronService::dailyVesting) to graduate
     * any remaining legacy endorsements through vesting stages. Delegates
     * to EndorsementVestingProcessor.
     *
     * @return int Number of endorsements updated.
     */
    public function processEndorsementVesting(): int {
        return (new EndorsementVestingProcessor())->process();
    }
}
