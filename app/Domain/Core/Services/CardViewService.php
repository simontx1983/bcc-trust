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
 * @phpstan-type Flag string
 * @phpstan-type Stat array{key: string, label: string, value: string, raw: int|float|string, format: string}
 * @phpstan-type Permission array{allowed: bool, unlock_hint: string|null, reason_code: string|null}
 * @phpstan-type Action array{method: string, href: string, body?: array<string, mixed>, idempotent: bool, requires_auth: bool}
 */
final class CardViewService
{
    // KIND_TO_PAGE_TYPE moved to Support\PageTypeMap (single source of
    // truth shared with BinderService, which uses the reverse direction).

    // Frontend URL prefix moved to CardUrlMap (single source of truth
    // shared with BinderService — see §C2 binder-Phase-1 corrections).

    public function __construct(
        private readonly PageReadModelRepository $pageReadModelRepo,
        private readonly ReputationRepository $reputationRepo,
        private readonly VoteRepository $voteRepo,
        private readonly FeatureAccessService $featureAccess,
        private readonly VoteService $voteService,
        private readonly EndorsementService $endorsementService,
        private readonly AttestationService $attestationService
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
     * @return array<string, mixed>|null
     */
    private function getPageCard(string $kind, string $id, int $viewerId): ?array
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
        $rm     = $this->pageReadModelRepo->getByPageId($pageId);

        $tier        = $rm !== null ? (string) $rm->reputation_tier : 'neutral';
        $trustScore  = $rm !== null ? (int) round((float) $rm->trust_score) : 50;
        $card        = ReputationTierMap::resolve($tier);

        $name   = (string) $post->post_title;
        $handle = (string) $post->post_name;
        $isClaimed = self::isPageClaimed($pageId);
        $isClaimedByViewer = $viewerId > 0 && self::viewerHasClaim($viewerId, $pageId);
        $viewerHasReviewed = $viewerId > 0 && $this->voteService->hasUserVotedPage($pageId, $viewerId);
        $viewerHasEndorsed = $viewerId > 0 && $this->endorsementService->hasEndorsedPage($pageId, $viewerId, 'general');
        $endorseEligibility = $this->endorsementService->getEndorseEligibility($viewerId, $pageId);
        // §J.6 viewer_attestation — present only for authed viewers.
        // Maps the card kind to the locked target_kind set per §J.1.
        // Always null for anon (the service returns null on viewerId<=0).
        $cardTargetKind = self::cardKindToTargetKind($kind);
        $viewerAttestation = ($viewerId > 0 && $cardTargetKind !== null)
            ? $this->attestationService->getViewerAttestation($viewerId, $cardTargetKind, $pageId)
            : null;

        return [
            'id'                  => $pageId,
            'name'                => $name,
            'handle'              => $handle,
            'card_kind'           => $kind,
            'bio'                 => self::resolvePageBio($post),
            'trust_score'         => $trustScore,
            'reputation_tier'     => $tier,
            'card_tier'           => $card['key'],
            'tier_label'          => $card['label'],
            // §E2: ranks are member-only — but the field is ALWAYS
            // emitted (nullable) per the contract, so the frontend can
            // render `card.rank_label ?? '—'` without a type check.
            'rank_label'          => null,
            'is_in_good_standing' => self::isInGoodStanding($tier),
            'flags'               => self::buildFlags((int) $post->post_author),
            'is_claimed'          => $isClaimed,
            // §D2 — true when the current viewer has already cast a
            // vote on this page. Drives the entity profile's
            // "WRITE A REVIEW" → "REMOVE YOUR REVIEW" CTA swap.
            // Always false for anonymous viewers.
            'viewer_has_reviewed' => $viewerHasReviewed,
            // §V1.5 — true when the current viewer has already endorsed
            // this page. Drives the EndorseButton's "ENDORSE" → "REMOVE
            // ENDORSEMENT" CTA swap. Always false for anonymous viewers.
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
            // "STANDING BEHIND") on the action buttons. Coexists with
            // the legacy viewer_has_endorsed boolean during the §J.11
            // endorse→vouch migration window.
            'viewer_attestation'  => $viewerAttestation,
            // §N8: claim flow needs entity_type + entity_id + chain_slug
            // to drive the four-step modal. Server resolves these from
            // the page id (per §A2/§L5 — frontend never derives). Null
            // for already-claimed pages or non-validator kinds.
            'claim_target'        => self::resolveClaimTarget($kind, $pageId, $isClaimed),
            // §K3 — chains an operator runs on. Null for single-chain
            // pages (the common case) so the frontend doesn't render
            // a dead one-tab strip. Validator-only in V1.5; creator
            // gallery chain filter is V2 (per §H1).
            'chains'              => self::resolveChains($kind, $pageId),
            // V1: tier-keyed coloring for entity cards (chain-keyed lands
            // alongside §K3 chain-wiring in V1.5). `background_value` per
            // §2.9 is the card_tier slug; falls back to 'common' on the
            // edge case where the read-model hasn't projected yet.
            'crest'               => self::buildCrest(
                $name,
                'tier',
                $card['key'] ?? 'common',
                self::resolvePageAvatarUrl($pageId)
            ),
            'stats'               => self::buildPageStats($trustScore, $rm),
            'permissions'         => $this->resolvePagePermissions($viewerId, $isClaimedByViewer, $endorseEligibility),
            // STUB: social_proof composition deferred (§O4). Field is
            // ALWAYS emitted (nullable) per the contract.
            'social_proof'        => null,
            'links'               => self::buildLinks($kind, $handle),
            'actions'             => self::buildPageActions($kind, $pageId),
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

        $user = get_userdata($userId);
        if ($user === false) {
            return null;
        }

        $tier        = $this->reputationRepo->getTier($userId);
        $trustScore  = (int) round($this->reputationRepo->getScore($userId));
        $card        = ReputationTierMap::resolve($tier);
        $resolvedHandle = self::resolveMemberHandle($user);
        // §J.6 viewer_attestation on member cards. Member card
        // target_kind is `user_profile` per §J.1. Anon viewers get
        // null (service returns null when viewerId<=0).
        $viewerAttestation = $viewerId > 0
            ? $this->attestationService->getViewerAttestation($viewerId, 'user_profile', $userId)
            : null;

        return [
            'id'                  => $userId,
            'name'                => $user->display_name !== '' ? $user->display_name : $user->user_login,
            'handle'              => $resolvedHandle,
            'card_kind'           => 'member',
            'bio'                 => self::resolveMemberBio($user),
            'trust_score'         => $trustScore,
            'reputation_tier'     => $tier,
            'card_tier'           => $card['key'],
            'tier_label'          => $card['label'],
            // STUB: rank-label resolved by /users/:handle; cards stay
            // tight per V1. Field is ALWAYS emitted (nullable) per the
            // contract.
            'rank_label'          => null,
            'is_in_good_standing' => self::isInGoodStanding($tier),
            'flags'               => self::buildFlags($userId),
            // §V1.5 endorse fields stay present on member cards for
            // contract uniformity, but always null/false — endorsements
            // target page cards only.
            'viewer_has_endorsed' => false,
            'endorse_unlock_hint' => null,
            // §J.6 viewer_attestation — present on member cards now
            // that attestations land on user_profile target_kind per
            // §J.1. Anon viewers get null per the §4.20 contract.
            'viewer_attestation'  => $viewerAttestation,
            // §K3 — chains is page-only. Always null on member cards
            // for shape uniformity.
            'chains'              => null,
            'crest'               => self::buildCrest(
                (string) $user->display_name ?: $user->user_login,
                'tier',
                $card['key'] ?? 'common',
                self::resolveMemberAvatarUrl($userId)
            ),
            'stats'               => $this->buildMemberStats($userId, $trustScore),
            'permissions'         => $this->resolveMemberPermissions($userId, $viewerId),
            // STUB: social_proof composition deferred (§O4). Field is
            // ALWAYS emitted (nullable) per the contract.
            'social_proof'        => null,
            'links'               => self::buildLinks('member', $resolvedHandle),
            'actions'             => self::buildMemberActions($userId),
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
        $claim = ClaimRepository::getUserClaim($viewerId, 'page', $pageId);
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
     * @return array{entity_type: string, entity_id: int, chain_slug: string}|null
     */
    private static function resolveClaimTarget(string $kind, int $pageId, bool $isClaimed): ?array
    {
        if ($isClaimed || $kind !== 'validator') {
            return null;
        }
        $row = ValidatorRepository::findFirstByPageId($pageId);
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
     * Build the §K3 `chains` array. Returns null for single-chain pages
     * (the common case) so the frontend's <ChainTabs> can self-hide
     * without a length check on the consumer side. Returns the array
     * when 2+ chains back the same page (one operator running on
     * Cosmos + Osmosis + Injective for example).
     *
     * V1.5: validator-only. Creators get their multi-chain treatment
     * via the gallery chain filter (V2 per §H1).
     *
     * @return list<array{slug: string, name: string, operator_address: string}>|null
     */
    private static function resolveChains(string $kind, int $pageId): ?array
    {
        if ($kind !== 'validator') {
            return null;
        }
        $rows = ValidatorRepository::findAllByPageId($pageId);
        if (count($rows) < 2) {
            // Single-chain (or no validator data) → no tabs to render.
            // Returning null keeps the contract uniform with member
            // cards and lets the frontend treat absence as "single chain".
            return null;
        }
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'slug'             => (string) $row->chain_slug,
                'name'             => (string) $row->chain_name,
                'operator_address' => (string) $row->operator_address,
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
     */
    private static function resolvePageAvatarUrl(int $pageId): string
    {
        if (class_exists('PeepSoPagePhoto')) {
            $photo = \PeepSoPagePhoto::get_instance();
            if ($photo) {
                $url = $photo->get_page_avatar_url($pageId);
                if (is_string($url) && $url !== '') {
                    return $url;
                }
            }
        }
        $thumb = get_the_post_thumbnail_url($pageId, 'thumbnail');
        return is_string($thumb) ? $thumb : '';
    }

    /**
     * Resolve a WP user's avatar URL via core's get_avatar_url. Honors
     * Gravatar / Buddyboss / PeepSo overrides because they all hook
     * the `get_avatar_url` filter. Empty string when no avatar resolves.
     */
    private static function resolveMemberAvatarUrl(int $userId): string
    {
        $url = get_avatar_url($userId);
        return is_string($url) ? $url : '';
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
            ['key' => 'endorsements', 'label' => 'Endorsements', 'value' => (string) $endorsements, 'raw' => $endorsements, 'format' => 'count'],
        ];
    }

    /**
     * @return list<Stat>
     */
    private function buildMemberStats(int $userId, int $trustScore): array
    {
        $reviewsWritten = (int) $this->voteRepo->countByVoter($userId);

        return [
            ['key' => 'trust',           'label' => 'Trust',   'value' => (string) $trustScore,    'raw' => $trustScore,    'format' => 'score'],
            ['key' => 'reviews_written', 'label' => 'Reviews', 'value' => (string) $reviewsWritten, 'raw' => $reviewsWritten, 'format' => 'count'],
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // Permissions
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param array{allowed: bool, unlock_hint: string|null, reason_code: string|null} $endorseEligibility
     * @return array<string, Permission>
     */
    private function resolvePagePermissions(int $viewerId, bool $viewerIsClaimer, array $endorseEligibility): array
    {
        if ($viewerId <= 0) {
            return self::lockedPagePermissions();
        }

        return [
            'can_pull'           => self::allow(),
            'can_review'         => self::featureGate($this->featureAccess->canPerform($viewerId, 'write_review')),
            'can_dispute'        => self::featureGate($this->featureAccess->canPerform($viewerId, 'sign_dispute')),
            // §V1.5 — endorse eligibility is precomputed by
            // EndorsementService::getEndorseEligibility (mirrors the
            // gates inside endorsePage but read-only). Pass-through the
            // shape it already produces.
            'can_endorse'        => $endorseEligibility,
            'can_post_as_entity' => $viewerIsClaimer ? self::allow() : self::deny(null, 'not_claimer'),
            'can_edit_bio'       => $viewerIsClaimer ? self::allow() : self::deny(null, 'not_claimer'),
        ];
    }

    /**
     * @return array<string, Permission>
     */
    private function resolveMemberPermissions(int $targetUserId, int $viewerId): array
    {
        if ($viewerId <= 0) {
            return self::lockedMemberPermissions();
        }
        $isSelf = $viewerId === $targetUserId;

        return [
            // "Pull" on a member card = follow that user. Self-follow is meaningless.
            'can_pull'           => $isSelf ? self::deny(null, 'self_action_blocked') : self::allow(),
            'can_review'         => $isSelf
                ? self::deny(null, 'self_action_blocked')
                : self::featureGate($this->featureAccess->canPerform($viewerId, 'write_review')),
            'can_dispute'        => $isSelf
                ? self::deny(null, 'self_action_blocked')
                : self::featureGate($this->featureAccess->canPerform($viewerId, 'sign_dispute')),
            // Endorsements target page-cards (validator/project/creator)
            // only. Members are followed/reviewed via different surfaces.
            'can_endorse'        => self::deny(null, 'not_applicable'),
            'can_post_as_entity' => self::deny(null, 'not_applicable'),
            'can_edit_bio'       => $isSelf ? self::allow() : self::deny(null, 'not_owner'),
        ];
    }

    /**
     * @return array<string, Permission>
     */
    private static function lockedPagePermissions(): array
    {
        $sign = self::deny('Sign in to interact', 'signin_required');
        return [
            'can_pull'           => $sign,
            'can_review'         => $sign,
            'can_dispute'        => $sign,
            'can_endorse'        => $sign,
            'can_post_as_entity' => $sign,
            'can_edit_bio'       => $sign,
        ];
    }

    /**
     * @return array<string, Permission>
     */
    private static function lockedMemberPermissions(): array
    {
        $sign = self::deny('Sign in to interact', 'signin_required');
        return [
            'can_pull'           => $sign,
            'can_review'         => $sign,
            'can_dispute'        => $sign,
            'can_endorse'        => self::deny(null, 'not_applicable'),
            'can_post_as_entity' => self::deny(null, 'not_applicable'),
            'can_edit_bio'       => $sign,
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
     * Both `pull` and `claim` are emitted self-describing — `body`
     * pre-baked so the frontend dispatches the action with zero
     * mapping logic ("frontend is dumb" rule).
     *
     * Every action carries:
     *   - method      — HTTP verb
     *   - href        — absolute API path
     *   - body        — pre-baked request body (when applicable)
     *   - idempotent  — safe to retry on network failure
     *                   (true for our server-side dedup'd POSTs:
     *                    pull → already_pulled status,
     *                    claim → already_verified status)
     *   - requires_auth — true when the endpoint rejects anonymous calls
     *
     * `claim` is omitted when the page has no resolvable underlying
     * on-chain entity (validator or collection); the page isn't
     * claimable from this surface.
     *
     * @return array<string, Action>
     */
    private static function buildPageActions(string $kind, int $pageId): array
    {
        $actions = [
            'pull' => [
                'method'        => 'POST',
                'href'          => '/wp-json/bcc/v1/me/binder/pull',
                'body'          => [
                    'target_kind' => $kind,
                    'target_id'   => $pageId,
                ],
                'idempotent'    => true,
                'requires_auth' => true,
            ],
        ];

        $entity = WalletRepository::resolveEntityForPage($pageId);
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
     * so only `pull` is emitted — same self-describing-body shape as
     * page-cards for cross-kind frontend uniformity.
     *
     * @return array<string, Action>
     */
    private static function buildMemberActions(int $userId): array
    {
        return [
            'pull' => [
                'method'        => 'POST',
                'href'          => '/wp-json/bcc/v1/me/binder/pull',
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
