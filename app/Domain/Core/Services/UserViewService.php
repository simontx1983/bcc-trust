<?php
/**
 * User View Service — composes the GET /bcc/v1/users/:handle response
 * per contract §3.1 and §4.4.
 *
 * Two output variants:
 *   - is_self === true  → includes living, progression, feature_access,
 *     ux_helpers (the four own-only blocks)
 *   - is_self === false → omits the four own-only blocks entirely
 *
 * V1 wiring status (all real, no Phase-1 stubs remain):
 *   - flags                        → resolveFlags() composes V1 slugs
 *                                    from Permissions + wp_usermeta
 *                                    (suspended/shadow_limited/hidden/under_review)
 *   - wallets                      → resolveWallets() reads WalletRepository::getForUser
 *   - living                       → delegated to LivingService::compose
 *   - counts.disputes_signed       → DisputeRepository::countByReporter
 *                                    (disputes FILED by the user; live bcc_disputes.reporter_id)
 *   - counts.solids_given/received → PeepSoReactionRepository::countGivenByUser /
 *                                    countReceivedByUser, gated on §D5 reaction-id
 *                                    presence (returns 0 pre-seeder)
 *   - progression.next_rank_thresholds       → resolveProgression() derives
 *                                              from RankCatalog + trust_score
 *   - progression.trust_score_recent_changes → ScoreEventRepository::getRecentForPage
 *                                              (the member self-page's live
 *                                              bcc_trust_score_events ledger)
 *
 * Intentional V1 design (NOT a stub):
 *   - counts.watching_size = followers_count proxy. §C2 single-graph rule:
 *     watchlist IS the follow set; page-resolution lights every follow as
 *     a renderable card, so a "filter to BCC kinds" would remove valid
 *     watch entries. Field stays equal to following count by design.
 *
 * @package BCC\Trust\Core\Services
 * @since V1 (2026-04)
 */

namespace BCC\Trust\Core\Services;

use BCC\Core\Permissions\Permissions;
use BCC\Core\Repositories\PeepSoBlockRepository;
use BCC\Core\Repositories\PeepSoFollowerRepository;
use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Core\Repositories\PeepSoPageRepository;
use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Core\Repositories\GitHubRepository;
use BCC\Trust\Core\Repositories\PeepSoReactionRepository;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Repositories\ScoreEventRepository;
use BCC\Trust\Core\Repositories\UserSyncRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Repositories\XRepository;
use BCC\Trust\Core\Services\Quest\QuestProgressService;
use BCC\Trust\Core\Support\PrivacySettings;
use BCC\Trust\Core\Support\RankCatalog;
use BCC\Trust\Core\Support\ReactionTypeRegistry;
use BCC\Trust\Core\Support\ReputationTierMap;
use BCC\Trust\Core\Support\WalletAddressValidator;
use BCC\Trust\Disputes\Repositories\DisputeParticipationRepository;
use BCC\Trust\Disputes\Repositories\DisputeRepository;
use BCC\Trust\Onchain\Repositories\WalletRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class UserViewService
{
    private VoteRepository $voteRepo;
    private ReputationRepository $reputationRepo;
    private RankService $rankService;
    private FeatureAccessService $featureAccess;
    private LivingService $livingService;
    private ScoreEventRepository $scoreEventRepo;
    private PeepSoReactionRepository $reactionRepo;
    private DisputeParticipationRepository $participationRepo;
    private AttestationService $attestationService;
    private QuestProgressService $questProgress;

    /**
     * Per-request memoization of resolveAugmentedTrustScore — UserViewService
     * is request-scoped, so this cache lives one HTTP request and dies. Same
     * user requested twice in one request (e.g. a profile view that also
     * mounts a card for the same user) reads the second from memory.
     *
     * @var array<int, int>
     */
    private array $trustScoreCache = [];

    public function __construct(
        VoteRepository $voteRepo,
        ReputationRepository $reputationRepo,
        RankService $rankService,
        FeatureAccessService $featureAccess,
        LivingService $livingService,
        ScoreEventRepository $scoreEventRepo,
        PeepSoReactionRepository $reactionRepo,
        DisputeParticipationRepository $participationRepo,
        AttestationService $attestationService,
        QuestProgressService $questProgress
    ) {
        $this->voteRepo            = $voteRepo;
        $this->reputationRepo      = $reputationRepo;
        $this->rankService         = $rankService;
        $this->featureAccess       = $featureAccess;
        $this->livingService       = $livingService;
        $this->scoreEventRepo      = $scoreEventRepo;
        $this->reactionRepo        = $reactionRepo;
        $this->participationRepo   = $participationRepo;
        $this->attestationService  = $attestationService;
        $this->questProgress       = $questProgress;
    }

    /**
     * Build the User view-model. Returns null when the user_id doesn't
     * resolve to a real wp_user.
     *
     * `$prefetched` (optional) is the shared MemberSummaryPrefetcher /
     * MemberCardPrefetcher map — MemberProfileComposer primes it once for
     * the hero card and passes it here so the rank/counts resolutions
     * reuse the batch instead of re-running their single-user queries.
     * Same prefer-prefetched-else-fallback semantics as getSummary.
     *
     * @param array{
     *   levels?: array<int, int>,
     *   reviews_written_counts?: array<int, int>,
     *   disputes_signed_counts?: array<int, int>,
     *   solids_received_counts?: array<int, int>
     * }|null $prefetched
     * @return array<string, mixed>|null
     */
    public function getUser(int $userId, int $viewerId, ?array $prefetched = null): ?array
    {
        $user = get_userdata($userId);
        if ($user === false) {
            return null;
        }

        $isSelf = $userId > 0 && $userId === $viewerId;

        $tier         = $this->reputationRepo->getTier($userId);
        $handle       = self::resolveHandle($user);
        $followCounts = PeepSoFollowerRepository::getCounts($userId);
        $halls        = $this->resolveHalls($userId);
        $primaryHall  = $this->resolvePrimaryHall($userId, $halls);

        // Rank — same prefer-prefetched branch as getSummary (the levels
        // map shares resolveLevel + thresholds with the per-user chain),
        // falling back to autoDerivedRank→getLevel, which costs four
        // queries per call.
        if ($prefetched !== null && isset($prefetched['levels'])) {
            $rank = $this->rankFromPrefetchedLevel($userId, $prefetched['levels']);
        } else {
            $rank = $this->resolveRank($userId);
        }

        $privacy = self::resolvePrivacy($userId);

        // §K2: when the viewer isn't the owner, apply each toggle to the
        // outgoing fields. Owner always sees full data so the settings
        // surface can show "you've hidden X" without lying to itself.
        $displayName  = $user->display_name !== '' ? $user->display_name : $user->user_login;
        $effectiveName = (!$isSelf && $privacy['real_name_hidden'])
            ? '@' . $handle
            : $displayName;

        $payload = [
            'id'                  => $userId,
            'handle'              => $handle,
            'display_name'        => $effectiveName,
            'avatar_url'             => self::resolveAvatar($userId),
            'cover_photo_url'        => self::resolveCoverPhotoUrl($userId),
            'cover_photo_position'   => self::resolveCoverPhotoPosition($userId),
            'joined_at'              => self::toIso8601((string) $user->user_registered),
            'is_self'             => $isSelf,
            'trust_score'         => $this->resolveAugmentedTrustScore($userId),
            'reputation_tier'     => $tier,
            // The member trust chip (Risky…Elite), rendered verbatim per §A2.
            // Sole tier vocabulary since v1.57 — the retired rarity words had
            // no slot for `risky` at all, so the most safety-relevant state in
            // the system was invisible wherever they were used.
            'reputation_tier_label' => ReputationTierMap::toReputationTierLabel($tier),
            'rank'                => $rank['key'],
            'rank_label'          => $rank['label'],
            'current_rank_label'  => $rank['label'],
            'is_in_good_standing' => self::isInGoodStanding($tier),
            'flags'               => self::resolveFlags($userId),
            'bio'                 => self::resolveBio($user),
            'primary_hall'        => $primaryHall,
            'halls'               => $halls,
            'wallets'             => self::resolveWallets($userId, $isSelf),
            'counts'              => $this->resolveCounts($userId, $followCounts, $isSelf, $privacy, $prefetched),
            'privacy'             => $privacy,
            'permissions'         => $this->resolvePermissions($userId, $viewerId, $isSelf),
            // §K1 Phase A — true when the viewer is currently blocking
            // this profile's owner. Drives the "Block"/"Unblock" copy
            // swap on the profile actions strip without a separate
            // /me/blocks fetch per profile render.
            'viewer_blocking'     => self::resolveViewerBlocking($userId, $viewerId, $isSelf),
            'links'               => self::buildLinks($handle),
        ];

        if ($isSelf) {
            // Build the feature_access block once and reuse it for the
            // rank-progress bar + the §2.5 progression block — Rank now
            // mirrors the level, so all three surfaces share one set of
            // canonical level thresholds (no re-derivation, no drift).
            $featureAccess = $this->featureAccess->getFeatureAccess($userId);

            // §O3 living header — composed by LivingService:
            //   - streak_days (peepso_activities walker)
            //   - today (reviews + disputes_signed; solids stub)
            //   - rank_progress (level thresholds toward the next rank)
            //   - comparison (V1 stub; §O3.1 percentile aggregator deferred)
            $payload['living']         = $this->livingService->compose($userId, $rank['key'], $featureAccess);
            $payload['progression']    = $this->resolveProgression($rank, $userId, $featureAccess);
            $payload['feature_access'] = $featureAccess;
            $payload['ux_helpers']     = self::resolveUxHelpers($userId);
        }

        return self::enforceWalletPrivacyAtEgress($payload, $isSelf);
    }

    /**
     * Fail-closed egress safety net for the §3.1 `wallets` block.
     *
     * ALLOWLIST, not denylist. The previous implementation stripped one
     * hard-coded key (`address`) and passed everything else through —
     * which is why `address_short` leaked to every non-self viewer for
     * the entire life of the endpoint without a single log line. A
     * denylist can only ever catch the leaks someone already thought of.
     *
     * The rule this enforces is absolute and needs no field vocabulary:
     * **for a non-self viewer the only legal value of `wallets` is `[]`.**
     * Any non-empty array at this point is a P0 privacy violation
     * regardless of which keys it carries, so a future field added
     * upstream is caught by default instead of shipping silently.
     *
     * The violation log deliberately records only the KEY NAMES and the
     * entry count — never a value. Logging the offending address (even
     * truncated) to report an address leak would be the same leak with
     * extra steps.
     *
     * See docs/wallet-privacy-policy.md.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function enforceWalletPrivacyAtEgress(array $payload, bool $isSelf): array
    {
        // Own account data — the owner is entitled to their own wallets.
        if ($isSelf) {
            return $payload;
        }
        if (!isset($payload['wallets']) || !is_array($payload['wallets'])) {
            return $payload;
        }
        if ($payload['wallets'] === []) {
            return $payload;
        }

        // Non-self viewer with a non-empty wallets block. Collect the key
        // names only, so the alert is actionable without being a leak.
        $offendingKeys = [];
        foreach ($payload['wallets'] as $wallet) {
            if (is_array($wallet)) {
                foreach (array_keys($wallet) as $key) {
                    $offendingKeys[(string) $key] = true;
                }
            }
        }

        \BCC\Core\Log\Logger::error(
            '[UserViewService] P0 PRIVACY VIOLATION: non-empty wallets block for non-self viewer; forced to [] at egress',
            [
                'profile_user_id' => isset($payload['id']) ? (int) $payload['id'] : 0,
                'entry_count'     => count($payload['wallets']),
                // Key names only. Never a value.
                'offending_keys'  => implode(',', array_keys($offendingKeys)),
            ]
        );

        $payload['wallets'] = [];
        return $payload;
    }

    /**
     * Slim view-model for the /members directory list. Drops the heavy
     * blocks `getUser` carries (counts, locals, wallets, permissions,
     * privacy, viewer_blocking, plus the self-only living/progression/
     * feature_access bundles) — those are profile-page concerns. List
     * surfaces want a card-sized read-only payload.
     *
     * Privacy: still honors `real_name_hidden` — if set and viewer
     * isn't self, returns `@handle` as display_name (same rule as
     * `getUser`). The directory shouldn't leak names the user opted
     * out of.
     *
     * Returns null when $userId doesn't resolve to a real wp_user.
     *
     * `reputation_tier` mirrors the slug already exposed by `getUser`
     * (full /users/:handle payload). Surfaced here so list-shaped UIs
     * (members directory rows, member cards in feeds) can encode the
     * tier as a color/border treatment rather than rendering the
     * label as a duplicate word next to the rank_label.
     *
     * `trust_score`, `followers_count`, `primary_hall`, and
     * `owned_pages_count` populate the directory card with social-proof
     * signals (the directory was previously sparse — handle + chips
     * only — and couldn't carry "is this a real operator" weight).
     * Where single-user resolvers exist (trust score is per-request
     * memoized; followers/primary_hall/owned_pages have single-user
     * fallbacks) `getSummary` will resolve these per-row when called
     * outside a list path.
     *
     * **List-shape callers should pass `$prefetched`** — that's the
     * batched-map path used by `UsersEndpoint::members`. Without it,
     * each row triggers up to four extra SQL queries (followers count,
     * primary-local lookup, page-owner count, plus the trust-score
     * row), N+1ing across the page. The prefetch path collapses those
     * to one batched SQL each (~4 queries total per page-load
     * regardless of `per_page`). Note: the field named `primary_hall`
     * still reads the (renamed) primary-Hall prefetch bucket.
     *
     * `owned_pages_by_type` carries a per-canonical-type breakdown of
     * the user's PeepSo page ownership (validator/project/nft/dao),
     * driven by the page-categories taxonomy. PeepSo pages are tags-not-
     * types — a single page can carry multiple categories — so the sum
     * across the four buckets may exceed `owned_pages_count` for a
     * multi-categorized portfolio. Pages with no recognized category
     * slug don't contribute to any bucket but still increment the
     * total `owned_pages_count`.
     *
     * `cover_photo_url`, `verifications`, and `engagement` populate the
     * back-of-card "trust dossier" on the directory's flippable cards.
     * All four `engagement` counts (endorsements_received, solids_received,
     * reviews_written, disputes_signed) come from established single-user
     * resolvers; the prefetch path collapses the per-row N+1 to one
     * batched SQL each. `verifications.x_*` and `verifications.github_*`
     * are connection presence + provider username for the X / GitHub
     * social-link panel; tokens are NEVER decrypted into this payload.
     *
     * @param array{
     *   follower_counts?: array<int, int>,
     *   primary_halls?: array<int, object{id: numeric-string, post_name: string, post_title: string, post_content: string, member_count: numeric-string}>,
     *   owned_pages_counts?: array<int, int>,
     *   owned_pages_by_type?: array<int, array{validator: int, project: int, nft: int, dao: int}>,
     *   endorsements_received_counts?: array<int, int>,
     *   solids_received_counts?: array<int, int>,
     *   reviews_written_counts?: array<int, int>,
     *   disputes_signed_counts?: array<int, int>,
     *   wallets_verified_counts?: array<int, int>,
     *   x_connections?: array<int, array{provider_username: string|null, verified_at: string|null}>,
     *   github_connections?: array<int, array{provider_username: string|null, verified_at: string|null}>,
     *   levels?: array<int, int>,
     *   viewer_attestations?: array<int, array{vouch: object|null, stand_behind: object|null}>
     * }|null $prefetched
     *
     * @return array{
     *   id: int,
     *   handle: string,
     *   display_name: string,
     *   avatar_url: string,
     *   cover_photo_url: string|null,
     *   joined_at: string,
     *   reputation_tier: string,
     *   reputation_tier_label: string,
     *   rank_label: string,
     *   current_rank_label: string,
     *   is_in_good_standing: bool,
     *   flags: list<string>,
     *   trust_score: int,
     *   followers_count: int,
     *   primary_hall: array{id: int, slug: string, name: string}|null,
     *   owned_pages_count: int,
     *   owned_pages_by_type: array{validator: int, project: int, nft: int, dao: int},
     *   verifications: array{
     *     x_verified: bool,
     *     x_username: string|null,
     *     github_verified: bool,
     *     github_username: string|null,
     *     wallets_verified: int
     *   },
     *   engagement: array{
     *     endorsements_received: int,
     *     solids_received: int,
     *     reviews_written: int,
     *     disputes_signed: int
     *   }
     * }|null
     */
    public function getSummary(int $userId, int $viewerId, ?array $prefetched = null): ?array
    {
        $user = get_userdata($userId);
        if ($user === false) {
            return null;
        }

        $isSelf  = $userId > 0 && $userId === $viewerId;
        $tier    = $this->reputationRepo->getTier($userId);
        $handle  = self::resolveHandle($user);
        $privacy = self::resolvePrivacy($userId);

        // Rank — prefer the prefetched batched levels map (FeatureAccess-
        // Service::getLevelsForUsers; same resolveLevel + thresholds as the
        // per-user chain), fall back to autoDerivedRank→getLevel, which
        // costs four queries per user (two follower COUNTs, votes-cast
        // COUNT, wallet-links read).
        if ($prefetched !== null && isset($prefetched['levels'])) {
            $rank = $this->rankFromPrefetchedLevel($userId, $prefetched['levels']);
        } else {
            $rank = $this->resolveRank($userId);
        }

        $displayName  = $user->display_name !== '' ? $user->display_name : $user->user_login;
        $effectiveName = (!$isSelf && $privacy['real_name_hidden'])
            ? '@' . $handle
            : $displayName;

        // Followers count — prefer prefetched batch, fall back to the
        // single-user repo call so non-list callers (admin tools,
        // tests) still work without a prefetch ceremony.
        if ($prefetched !== null && isset($prefetched['follower_counts'])) {
            $followersCount = $prefetched['follower_counts'][$userId] ?? 0;
        } else {
            $followersCount = PeepSoFollowerRepository::getCounts($userId)['followers'];
        }

        // Primary Hall — same prefer-prefetched pattern. The prefetch
        // delivers the raw group post info; we build the same wire shape
        // in both paths so the format is identical.
        if ($prefetched !== null && array_key_exists('primary_halls', $prefetched)) {
            $primaryHallInfo = $prefetched['primary_halls'][$userId] ?? null;
            $primaryHall     = $primaryHallInfo === null
                ? null
                : [
                    'id'   => (int) $primaryHallInfo->id,
                    'slug' => $primaryHallInfo->post_name,
                    'name' => $primaryHallInfo->post_title,
                ];
        } else {
            $halls       = $this->resolveHalls($userId);
            $primaryHall = $this->resolvePrimaryHall($userId, $halls);
        }

        // Owned-pages count — `member_owner` rows in
        // `peepso_page_members`. Distinguishes "regular community
        // member" from "operator/builder" at a glance on the directory.
        if ($prefetched !== null && isset($prefetched['owned_pages_counts'])) {
            $ownedPagesCount = $prefetched['owned_pages_counts'][$userId] ?? 0;
        } else {
            $ownedPagesCount = UserSyncRepository::fetchCountsForUser($userId)['pages_owned'];
        }

        // Owned-pages typed breakdown — joins page-categories to
        // canonical type slugs. Falls back to per-row resolution
        // (single-element batch) when `$prefetched` doesn't include
        // the map; non-list callers are rare enough that the extra
        // SQL is acceptable.
        if ($prefetched !== null && isset($prefetched['owned_pages_by_type'])) {
            $ownedPagesByType = $prefetched['owned_pages_by_type'][$userId]
                ?? ['validator' => 0, 'project' => 0, 'nft' => 0, 'dao' => 0];
        } else {
            $batched = PeepSoPageRepository::getOwnedPageTypeCountsForUsers([$userId]);
            $ownedPagesByType = $batched[$userId]
                ?? ['validator' => 0, 'project' => 0, 'nft' => 0, 'dao' => 0];
        }

        // Back-of-card engagement counts (endorsements_received,
        // solids_received, reviews_written, disputes_signed). Same
        // prefer-prefetched / fall-back-to-single-user pattern as the
        // other batched signals above.
        if ($prefetched !== null && isset($prefetched['endorsements_received_counts'])) {
            $endorsementsReceived = $prefetched['endorsements_received_counts'][$userId] ?? 0;
        } else {
            $endorsementsReceived = (new AttestationRepository())
                ->countActiveVouchesByTargets('user_profile', [$userId])[$userId] ?? 0;
        }

        // Solids: PeepSo reaction of kind=KIND_SOLID. ReactionTypeRegistry
        // returns null when the reaction set isn't seeded yet — surface 0
        // rather than crash; the back-of-card row is supplementary chrome.
        $solidId = ReactionTypeRegistry::solidId();
        if ($prefetched !== null && isset($prefetched['solids_received_counts'])) {
            $solidsReceived = $prefetched['solids_received_counts'][$userId] ?? 0;
        } else {
            $solidsReceived = $solidId === null
                ? 0
                : $this->reactionRepo->countReceivedByUser($userId, $solidId);
        }

        if ($prefetched !== null && isset($prefetched['reviews_written_counts'])) {
            $reviewsWritten = $prefetched['reviews_written_counts'][$userId] ?? 0;
        } else {
            $reviewsWritten = $this->voteRepo->countByVoter($userId);
        }

        if ($prefetched !== null && isset($prefetched['disputes_signed_counts'])) {
            $disputesSigned = $prefetched['disputes_signed_counts'][$userId] ?? 0;
        } else {
            $disputesSigned = DisputeRepository::countByReporter($userId);
        }

        // Verifications. X / GitHub are batched-prefetched maps of
        // {provider_username, verified_at}; presence in the map (with a
        // non-null verified_at) means active+verified. Wallet count is
        // verified-only by construction.
        if ($prefetched !== null && isset($prefetched['x_connections'])) {
            $xConnection = $prefetched['x_connections'][$userId] ?? null;
        } else {
            $xConnections = (new XRepository())->getConnectionsForUsers([$userId]);
            $xConnection  = $xConnections[$userId] ?? null;
        }
        if ($prefetched !== null && isset($prefetched['github_connections'])) {
            $githubConnection = $prefetched['github_connections'][$userId] ?? null;
        } else {
            $githubConnections = (new GitHubRepository())->getConnectionsForUsers([$userId]);
            $githubConnection  = $githubConnections[$userId] ?? null;
        }
        if ($prefetched !== null && isset($prefetched['wallets_verified_counts'])) {
            $walletsVerified = $prefetched['wallets_verified_counts'][$userId] ?? 0;
        } else {
            $walletsVerified = WalletRepository::getVerifiedCountsForUsers([$userId])[$userId] ?? 0;
        }

        return [
            'id'                  => $userId,
            'handle'              => $handle,
            'display_name'        => $effectiveName,
            'avatar_url'          => self::resolveAvatar($userId),
            'cover_photo_url'     => self::resolveCoverPhotoUrl($userId),
            'joined_at'           => self::toIso8601((string) $user->user_registered),
            'reputation_tier'       => $tier,
            'reputation_tier_label' => ReputationTierMap::toReputationTierLabel($tier),
            'rank_label'          => $rank['label'],
            'current_rank_label'  => $rank['label'],
            'is_in_good_standing' => self::isInGoodStanding($tier),
            'flags'               => self::resolveFlags($userId),
            'trust_score'         => $this->resolveAugmentedTrustScore($userId),
            'followers_count'     => $followersCount,
            'primary_hall'        => $primaryHall,
            'owned_pages_count'   => $ownedPagesCount,
            'owned_pages_by_type' => $ownedPagesByType,
            'verifications'       => [
                'x_verified'      => $xConnection !== null && $xConnection['verified_at'] !== null,
                'x_username'      => $xConnection['provider_username'] ?? null,
                'github_verified' => $githubConnection !== null && $githubConnection['verified_at'] !== null,
                'github_username' => $githubConnection['provider_username'] ?? null,
                'wallets_verified' => $walletsVerified,
            ],
            'engagement' => [
                'endorsements_received' => $endorsementsReceived,
                'solids_received'       => $solidsReceived,
                'reviews_written'       => $reviewsWritten,
                'disputes_signed'       => $disputesSigned,
            ],
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // Tier & rank
    // ──────────────────────────────────────────────────────────────────

    /**
     * @return array{key: string, label: string}
     */
    private function resolveRank(int $userId): array
    {
        $derived = $this->rankService->autoDerivedRank($userId);
        $label   = RankCatalog::getLabel($derived) ?? '';
        return ['key' => $derived, 'label' => $label];
    }

    /**
     * Rank from a prefetched levels map (FeatureAccessService::
     * getLevelsForUsers). Users absent from the map resolve to LEVEL_NEW —
     * the same outcome the per-user path produces for zero counts.
     *
     * @param array<int, int> $levels
     * @return array{key: string, label: string}
     */
    private function rankFromPrefetchedLevel(int $userId, array $levels): array
    {
        $key = RankService::rankForLevel($levels[$userId] ?? FeatureAccessService::LEVEL_NEW);
        return ['key' => $key, 'label' => RankCatalog::getLabel($key) ?? ''];
    }

    /**
     * Reputation tiers considered "in good standing" per §E1.
     * Single source of truth — `isInGoodStanding()` reads from this
     * list, and the directory's `good_standing_only` filter
     * (PageDiscoveryRepository) `IN`-clauses the same list so the
     * frontend chip and the per-row stamp can never diverge.
     *
     * @var list<string>
     */
    public const GOOD_STANDING_TIERS = ['neutral', 'trusted', 'elite'];

    /**
     * Pure tier → good-standing boolean. Public so AuthEndpoint can
     * reuse it when minting the login response (the chrome stamp slot
     * on the frontend reads from the session, which carries this bool
     * from the JWT). Single source of truth for the tier-to-standing
     * mapping — do NOT duplicate the in_array list elsewhere.
     */
    public static function isInGoodStanding(string $tier): bool
    {
        // §E1: tier ≥ neutral. Tier-fail and moderation flags are now
        // surfaced through separate channels — `is_in_good_standing`
        // tracks tier alone; `flags` (resolveFlags) carries explicit
        // moderation slugs from Permissions + wp_usermeta.
        return in_array($tier, self::GOOD_STANDING_TIERS, true);
    }

    /**
     * §D5 — augmented trust score for the user view-model.
     *
     * NOTE:
     * trust_score includes participation bonus (read-time only).
     * reputation_score in DB remains the base truth.
     * Do NOT use trust_score for persistence or tier calculations.
     * See docs/trust-engine-coverage.md "Known divergences" section.
     *
     * The bonus is the user's clamped lifetime participation contribution
     * (see DisputeParticipationRepository::getEarnedLifetimeTrust). The
     * final score is clamped to [0, 100] as a defensive bound — the
     * repo already caps the bonus, but a future weight tweak could
     * theoretically push past 100 if base + bonus drift; the clamp here
     * keeps PageScore semantics consistent without trusting upstream.
     *
     * Memoized per request — UserViewService is request-scoped and the
     * same user can be composed multiple times in one render (profile +
     * an embedded card for the same user). Cache lifetime = HTTP request.
     */
    private function resolveAugmentedTrustScore(int $userId): int
    {
        if (isset($this->trustScoreCache[$userId])) {
            return $this->trustScoreCache[$userId];
        }

        $base  = $this->reputationRepo->getScore($userId);
        $bonus = $this->participationRepo->getEarnedLifetimeTrust($userId);

        // Round once at the end — never round base and bonus separately.
        // Mid-pipeline rounding would create ±1 jitter that reads as bugs.
        $score = $base + $bonus;
        if ($score < 0.0)   { $score = 0.0; }
        if ($score > 100.0) { $score = 100.0; }

        return $this->trustScoreCache[$userId] = (int) round($score);
    }

    /**
     * V1 contract `flags: string[]` (per api-contract-v1.md §3 / line 625).
     *
     * Allowed slugs (exhaustive — frontend lights moderation chips ONLY
     * for these values): suspended, shadow_limited, hidden, under_review.
     *
     * Sources are LOCKED — do not introduce new flag codes or new data
     * sources without a contract amendment:
     *   - suspended      ← Permissions::is_not_suspended($userId, false) === false
     *   - shadow_limited ← wp_usermeta key 'bcc_shadow_limited' (boolean-truthy)
     *   - hidden         ← wp_usermeta key 'bcc_hidden' (boolean-truthy)
     *   - under_review   ← wp_usermeta key 'bcc_under_review' (boolean-truthy)
     *
     * "Boolean-truthy" uses FILTER_VALIDATE_BOOLEAN, NOT a `(bool)` cast —
     * WP serialises meta as strings, so the literal string "false" cast
     * to bool would be true, surfacing phantom flags. metaFlag() handles
     * the {1, true, yes, on} / {0, false, no, off, ""} convention.
     *
     * Per-request memoisation: resolveFlags is invoked from both
     * UserViewService (for the user view-model) AND CardViewService
     * (for member cards + page-owner flags on every card render). The
     * static $memo dedupes repeat lookups within a single request.
     * Stale-mid-request risk is acceptable for V1 — moderation actions
     * complete before the next request anyway.
     *
     * Public so CardViewService can route page-owner / member-card flags
     * through the same composer (§A4 — single source of trust logic).
     *
     * @return list<string>
     */
    public static function resolveFlags(int $userId): array
    {
        /** @var array<int, list<string>> $memo */
        static $memo = [];

        if ($userId <= 0) {
            return [];
        }
        if (isset($memo[$userId])) {
            return $memo[$userId];
        }

        // The second arg (allowAdminBypass=false) is intentional: a
        // suspended admin's `flags` MUST still surface the slug so the
        // moderation UI is consistent with the actual account state.
        $suspended = !Permissions::is_not_suspended($userId, false);

        $shadowLimited = self::metaFlag($userId, 'bcc_shadow_limited');
        $hidden        = self::metaFlag($userId, 'bcc_hidden');
        $underReview   = self::metaFlag($userId, 'bcc_under_review');

        return $memo[$userId] = self::composeFlagSlugs(
            $suspended,
            $shadowLimited,
            $hidden,
            $underReview
        );
    }

    /**
     * Boolean-truthy meta lookup. WP stores user_meta as strings, so
     * the literal "false" / "0" / "off" cast to bool would be true —
     * use filter_var with FILTER_VALIDATE_BOOLEAN instead.
     *
     * Returns true for {1, "1", true, "true", "yes", "on"} (case-insensitive).
     * Returns false for {0, "0", "", false, "false", "no", "off", null}.
     */
    private static function metaFlag(int $userId, string $key): bool
    {
        $raw = get_user_meta($userId, $key, true);
        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Pure decision function: maps four boolean signals to the V1
     * flag-slug list, in deterministic order. Extracted for testability
     * — exercised directly by tests/UserViewServiceFlagsTest.php without
     * needing WordPress / Permissions / wp_usermeta in scope.
     *
     * Order is stable (suspended → shadow_limited → hidden → under_review)
     * so frontend rendering and snapshot tests don't churn on flag-set
     * changes. Each slug appears at most once by construction.
     *
     * @return list<string>
     */
    public static function composeFlagSlugs(
        bool $suspended,
        bool $shadowLimited,
        bool $hidden,
        bool $underReview
    ): array {
        $flags = [];
        if ($suspended)     { $flags[] = 'suspended'; }
        if ($shadowLimited) { $flags[] = 'shadow_limited'; }
        if ($hidden)        { $flags[] = 'hidden'; }
        if ($underReview)   { $flags[] = 'under_review'; }
        return $flags;
    }

    /**
     * Verified wallet links for the user — OWN-ACCOUNT ONLY.
     *
     * Wallet connections are private account data. A viewer who is not
     * the wallet owner receives an EMPTY LIST — not a full address, not
     * a shortened one, not a hash, not an ENS name, and not the
     * wallet-link `id` (a stable cross-user join key that made
     * enumeration possible even without an address).
     *
     * The only wallet signal permitted to cross a member boundary is the
     * non-identifying `verifications.wallets_verified` count, which
     * MemberProfileComposer sources directly from
     * WalletRepository::getVerifiedCountsForUsers() — never by counting
     * this array.
     *
     * Fail-closed by construction: `$isSelf` is false whenever the viewer
     * is anonymous, unresolved, or a different user, so every uncertain
     * identity lands on the empty branch.
     *
     * Unverified wallets remain excluded even for the owner — the surface
     * only shows wallets proven via signature.
     *
     * See docs/wallet-privacy-policy.md.
     *
     * @return list<array{
     *   id: int,
     *   address: string,
     *   address_short: string,
     *   chain_slug: string,
     *   chain_name: string,
     *   is_primary: bool,
     *   verified_at: string
     * }>
     */
    private static function resolveWallets(int $userId, bool $isSelf): array
    {
        if ($userId <= 0 || !$isSelf) {
            return [];
        }

        $rows = WalletRepository::getForUser($userId, null, true);
        $items = [];
        foreach ($rows as $row) {
            $address = (string) $row->wallet_address;
            $items[] = [
                'id'            => (int) $row->id,
                'address'       => $address,
                'address_short' => WalletAddressValidator::shorten($address),
                'chain_slug'    => (string) $row->chain_slug,
                'chain_name'    => (string) $row->chain_name,
                'is_primary'    => ((int) $row->is_primary) === 1,
                'verified_at'   => self::toIso8601((string) ($row->verified_at ?? '')),
            ];
        }
        return $items;
    }

    // ──────────────────────────────────────────────────────────────────
    // Identity
    // ──────────────────────────────────────────────────────────────────

    private static function resolveHandle(\WP_User $user): string
    {
        $handle = (string) get_user_meta($user->ID, 'bcc_handle', true);
        if ($handle !== '') {
            return $handle;
        }
        return $user->user_login; // fallback for users without a bcc_handle yet
    }

    /**
     * Resolve the absolute URL of a user's avatar.
     *
     * Mirrors `CardViewService::resolveMemberAvatarUrl` — prefers
     * PeepSo's avatar resolution (`PeepSoUser::get_avatar`) because
     * PeepSo stores custom uploads under its own image dir; WP's
     * `get_avatar_url()` only sees them when PeepSo is filtering the
     * native pipeline (a plugin option). Asking PeepSo directly is
     * reliable across PeepSo configurations.
     *
     * Falls back to WP's `get_avatar_url` when PeepSo isn't loaded so
     * the profile avatar still resolves on installations without it.
     * §1.7 "Asset / media URLs are absolute" is satisfied either way.
     */
    private static function resolveAvatar(int $userId): string
    {
        // Cached, shared seam (§11) — see bcc-core PeepSoMediaCache for the
        // PeepSo-first resolution + why caching the URL is safe.
        return \BCC\Core\PeepSo\PeepSoMediaCache::avatarUrl($userId);
    }

    private static function resolveBio(\WP_User $user): string
    {
        return (string) ($user->description ?? '');
    }

    /**
     * §3.1 cover_photo_url — reads PeepSo's stored cover hash from
     * user_meta and returns the absolute URL. Returns null when no
     * custom cover is set (frontend falls back to a default treatment;
     * spec §1.7 — absolute URLs for media).
     *
     * Wraps PeepSoUser::get_cover() when PeepSo is loaded; falls back
     * to direct user_meta read if PeepSo is missing (defense — bio +
     * the rest of the profile still work without PeepSo, only the
     * cover photo URL is unavailable).
     */
    private static function resolveCoverPhotoUrl(int $userId): ?string
    {
        // Cached, shared seam (§11) — see bcc-core PeepSoMediaCache.
        // Resolving this constructs a PeepSoUser (a per-user peepso_users
        // SELECT), so it is cached alongside the avatar and busted on
        // peepso_cover_hash user-meta writes.
        return \BCC\Core\PeepSo\PeepSoMediaCache::coverPhotoUrl($userId);
    }

    /**
     * §3.1 cover_photo_position — `{x, y}` percentages (0–100) for
     * the cover photo crop position. Stored in PeepSo's
     * `peepso_cover_position_x` / `peepso_cover_position_y` user_meta;
     * defaults to center (50, 50) when no row exists.
     *
     * @return array{x: int, y: int}
     */
    private static function resolveCoverPhotoPosition(int $userId): array
    {
        if ($userId <= 0) {
            return ['x' => 50, 'y' => 50];
        }
        $x = get_user_meta($userId, 'peepso_cover_position_x', true);
        $y = get_user_meta($userId, 'peepso_cover_position_y', true);
        return [
            'x' => is_numeric($x) ? max(0, min(100, (int) $x)) : 50,
            'y' => is_numeric($y) ? max(0, min(100, (int) $y)) : 50,
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // Halls
    // ──────────────────────────────────────────────────────────────────

    /**
     * The member's group memberships, primary-Hall flagged. Preserves the
     * pre-rename all-member-groups listing behaviour (this is the "groups
     * I'm in" surface); the `is_primary` flag marks the home Hall.
     *
     * @return list<array{id: int, slug: string, name: string, is_primary: bool}>
     */
    private function resolveHalls(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        // §C2 single-graph rule: membership comes from PeepSo. We don't
        // have a "list user's groups" repository method that returns
        // everything (only findUserMemberships which expects group_ids).
        // For the User view-model, we need every group the user is in,
        // not just a filtered set. So: query membership table directly
        // for the user, then bulk-resolve group display info.
        $myGroupIds = $this->collectUserGroupIds($userId);
        if ($myGroupIds === []) {
            return [];
        }

        $primaryGroupId = self::loadPrimaryGroupId($userId);
        $groupInfo      = PeepSoGroupRepository::findManyByIds($myGroupIds);

        $items = [];
        foreach ($myGroupIds as $groupId) {
            $info = $groupInfo[$groupId] ?? null;
            if ($info === null) {
                continue;
            }
            $items[] = [
                'id'         => $groupId,
                'slug'       => $info->post_name,
                'name'       => $info->post_title,
                'is_primary' => $primaryGroupId === $groupId,
            ];
        }
        return $items;
    }

    /**
     * @return list<int>
     */
    private function collectUserGroupIds(int $userId): array
    {
        // Delegates to the canonical bcc-core repo helper; the
        // active-membership filter (`gm_user_status LIKE 'member%'`)
        // and the LIMIT 200 cap are owned there. A user is realistically
        // in 1–3 Halls + a few holder groups, well under the cap.
        return \BCC\Core\Repositories\PeepSoGroupRepository::getUserMemberGroupIds($userId);
    }

    /**
     * @param list<array{id: int, slug: string, name: string, is_primary: bool}> $halls
     * @return array{id: int, slug: string, name: string}|null
     */
    private function resolvePrimaryHall(int $userId, array $halls): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        foreach ($halls as $hall) {
            if ($hall['is_primary']) {
                return [
                    'id'   => $hall['id'],
                    'slug' => $hall['slug'],
                    'name' => $hall['name'],
                ];
            }
        }
        return null;
    }

    private static function loadPrimaryGroupId(int $userId): ?int
    {
        $value = get_user_meta($userId, 'bcc_primary_hall_group_id', true);
        if (!is_numeric($value)) {
            return null;
        }
        $intVal = (int) $value;
        return $intVal > 0 ? $intVal : null;
    }

    // ──────────────────────────────────────────────────────────────────
    // Counts
    // ──────────────────────────────────────────────────────────────────

    /**
     * §K2: when the viewer isn't the owner, hidden categories collapse
     * to 0 in the publicly-visible counts. Owner always sees the truth
     * so the settings UI can surface "you've hidden X · Y items affected"
     * if we ever need it. Counts kept as ints (vs nullable) to preserve
     * the §3.1 contract shape — the privacy block is the signal.
     *
     * @param array{following: int, followers: int} $followCounts
     * @param array{
     *   watching_hidden: bool,
     *   reviews_hidden: bool,
     *   disputes_hidden: bool,
     *   delegations_hidden: bool,
     *   follower_count_hidden: bool,
     *   real_name_hidden: bool,
     *   email_hidden: bool
     * } $privacy
     * @param array{
     *   reviews_written_counts?: array<int, int>,
     *   disputes_signed_counts?: array<int, int>,
     *   solids_received_counts?: array<int, int>
     * }|null $prefetched
     * @return array{
     *   followers: int,
     *   following: int,
     *   watching_size: int,
     *   reviews_written: int,
     *   disputes_signed: int,
     *   solids_given: int,
     *   solids_received: int
     * }
     *
     * (Plus reviews_received + blog_posts_written — see the return
     * block; the phpstan shape above narrows only the legacy keys.)
     */
    private function resolveCounts(int $userId, array $followCounts, bool $isSelf, array $privacy, ?array $prefetched = null): array
    {
        // Prefer the shared member batch where a key exists — identical
        // repository sources (countByVoters / countByReporters /
        // countReceivedByUsers with the same solid-id gate), single-user
        // fallbacks otherwise. Same pattern as getSummary's signal blocks.
        $reviewsWritten = ($prefetched !== null && isset($prefetched['reviews_written_counts']))
            ? (int) ($prefetched['reviews_written_counts'][$userId] ?? 0)
            : (int) $this->voteRepo->countByVoter($userId);
        // disputes_signed: disputes this user has FILED, read from the
        // live bcc_disputes table keyed on reporter_id (the filer). For
        // a member, reporter_id = their user id (they own the self-page
        // being defended), so the per-user count is direct.
        $disputesSigned = ($prefetched !== null && isset($prefetched['disputes_signed_counts']))
            ? (int) ($prefetched['disputes_signed_counts'][$userId] ?? 0)
            : DisputeRepository::countByReporter($userId);

        // §C2 single-graph rule: watchlist IS the follow set;
        // watching_size equals the following count by design. Do not
        // "filter to BCC card kinds" — page-resolution makes every
        // follow renderable as a card, so any filter would remove
        // valid watch entries.
        $watchingSize = $followCounts['following'];

        if (!$isSelf) {
            if ($privacy['follower_count_hidden']) {
                $followCounts['followers'] = 0;
            }
            if ($privacy['watching_hidden']) {
                $watchingSize = 0;
                $followCounts['following'] = 0;
            }
            if ($privacy['reviews_hidden']) {
                $reviewsWritten = 0;
            }
            if ($privacy['disputes_hidden']) {
                $disputesSigned = 0;
            }
        }

        return [
            'followers'         => $followCounts['followers'],
            'following'         => $followCounts['following'],
            'watching_size'     => $watchingSize,
            'reviews_written'   => $reviewsWritten,
            // v1.49 — reviews RECEIVED: votes filed on the member's
            // self-page (what the /u Reviews tab lists since v1.48).
            // Public by decision (2026-07-22) — deliberately NOT zeroed
            // under reviews_hidden, which governs the written list only.
            'reviews_received'  => (int) $this->voteRepo->countByPageId(
                MemberSelfPageService::selfPageId($userId)
            ),
            'disputes_signed'   => $disputesSigned,
            'solids_given'      => $this->countSolidsGiven($userId),
            'solids_received'   => ($prefetched !== null && isset($prefetched['solids_received_counts']))
                ? (int) ($prefetched['solids_received_counts'][$userId] ?? 0)
                : $this->countSolidsReceived($userId),
            // §D6 blog tab count — lifetime published blog posts authored
            // by the user. Sourced from peepso_activities rows where
            // act_module_id='blog' joined against wp_posts for the
            // published filter. Mirrors what /u/:handle/blog renders.
            'blog_posts_written' => \BCC\Core\Repositories\PeepSoActivityRepository::countBlogsByAuthor($userId),
        ];
    }

    /**
     * Lifetime count of Solid reactions the user has placed.
     * Returns 0 when the §D5 reactions haven't been seeded
     * (idFor returns null) — same contract as before the seeder
     * shipped, just data-driven now.
     */
    private function countSolidsGiven(int $userId): int
    {
        $solidId = ReactionTypeRegistry::solidId();
        if ($solidId === null) {
            return 0;
        }
        return $this->reactionRepo->countGivenByUser($userId, $solidId);
    }

    /**
     * Lifetime count of Solid reactions the user's content has received.
     */
    private function countSolidsReceived(int $userId): int
    {
        $solidId = ReactionTypeRegistry::solidId();
        if ($solidId === null) {
            return 0;
        }
        return $this->reactionRepo->countReceivedByUser($userId, $solidId);
    }

    // ──────────────────────────────────────────────────────────────────
    // Privacy, permissions, ux helpers
    // ──────────────────────────────────────────────────────────────────

    /**
     * §K2 privacy block — read from `wp_usermeta.bcc_privacy_*` via
     * PrivacySettings. Always returns a complete array; missing meta
     * defaults to false (V1 baseline: everything public per §K2).
     *
     * @return array{
     *   watching_hidden: bool,
     *   reviews_hidden: bool,
     *   disputes_hidden: bool,
     *   delegations_hidden: bool,
     *   follower_count_hidden: bool,
     *   real_name_hidden: bool,
     *   email_hidden: bool
     * }
     */
    private static function resolvePrivacy(int $userId): array
    {
        return PrivacySettings::readProfile($userId);
    }

    /**
     * Permissions for what the *viewer* can do TO this user — distinct
     * from §O5 feature gates (those live in feature_access). For V1:
     *
     *   can_follow      — true for any other authed viewer
     *   can_message     — true when the recipient has chat enabled
     *                     (cheap PeepSoChatModel check). Server still
     *                     re-runs the deeper gates (chat_friends_only +
     *                     friendship, mutual blocks, rate limit) on
     *                     every actual send (§4.19); this flag is a
     *                     UI-affordance signal only.
     *   can_block       — true for any other authed viewer
     *   can_edit_profile — true only on own profile
     *
     * §J.6 attestation extension — the trust-attestation gates
     * (can_vouch / can_stand_behind / can_report) are resolved by
     * reusing the same primitives the entity-card resolver uses
     * (CardViewService::resolveMemberPermissions) via AttestationService.
     * The sole person-level negative action is can_report; vote-disputes
     * are owner-only via can_open_dispute (page / self-page surfaces).
     * Same gates, same shape — so the frontend's AttestationActionCluster
     * renders identically on member profiles and entity cards.
     *
     * @return array<string, array{allowed: bool, unlock_hint: string|null}>
     */
    private function resolvePermissions(int $userId, int $viewerId, bool $isSelf): array
    {
        $isAuthed = $viewerId > 0;
        $isOther  = $isAuthed && !$isSelf;

        // can_message — the SAME evaluator the send path uses
        // (MessagesService's single messaging policy), so the rendered
        // button and the POST agree on every permanent rule. It used to
        // be a bare chat-enabled probe, which meant a friends-only or
        // mutually-blocked recipient rendered an enabled "Message"
        // button that always 403'd/404'd on submit. Transient state
        // (the rate limit) stays POST-only by design.
        $canMessagePerm = MessagesService::canMessage($viewerId, $userId);

        // §J.6 attestation gates — same resolver as entity cards.
        // getViewerActionPermissions ships {allowed, unlock_hint} for
        // vouch / stand_behind / report; we forward them verbatim.
        $attestationPerms = $this->attestationService->getViewerActionPermissions(
            $viewerId,
            $userId
        );

        return [
            'can_follow'       => ['allowed' => $isOther,    'unlock_hint' => null],
            'can_message'      => [
                'allowed'     => $canMessagePerm['allowed'],
                'unlock_hint' => $canMessagePerm['unlock_hint'],
            ],
            'can_block'        => ['allowed' => $isOther,    'unlock_hint' => null],
            'can_edit_profile' => ['allowed' => $isSelf,     'unlock_hint' => null],
            'can_vouch'        => $attestationPerms['can_vouch'],
            'can_stand_behind' => $attestationPerms['can_stand_behind'],
            'can_report'       => $attestationPerms['can_report'],
        ];
    }

    /**
     * §K1 Phase A — viewer-relative "is this viewer blocking the profile
     * owner?" flag. Anonymous viewers and self-views are always false.
     */
    private static function resolveViewerBlocking(int $userId, int $viewerId, bool $isSelf): bool
    {
        if ($isSelf || $viewerId <= 0 || $userId <= 0) {
            return false;
        }
        return PeepSoBlockRepository::isBlocking($viewerId, $userId);
    }

    /**
     * @return array{show_helpers: bool}
     */
    private static function resolveUxHelpers(int $userId): array
    {
        $familiar = (bool) get_user_meta($userId, 'bcc_ui_familiar', true);
        return ['show_helpers' => !$familiar];
    }

    // ──────────────────────────────────────────────────────────────────
    // Progression (own only)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Phase 1 progression block. Real progression thresholds depend on
     * a ranks-threshold service that hasn't shipped — for now we
     * surface current rank + next rank from the catalog with empty
     * threshold/event arrays.
     *
     * @param array{key: string, label: string} $rank
     * @return array{
     *   current_rank: string,
     *   current_rank_label: string,
     *   next_rank: string|null,
     *   next_rank_label: string|null,
     *   next_rank_thresholds: list<array{metric: string, label: string, current: int, required: int}>,
     *   trust_score_recent_changes: list<array{delta: int, reason: string, at: string}>
     * }
     */
    /**
     * §2.5 / §N11 progression block. Rank mirrors the feature-access
     * level, so the next-rank gate is exactly the level's
     * `next_level_thresholds` (pulls / reviews / days active) — the real
     * capability requirements, not a trust-score proxy. At Master (top
     * of the ladder) `next_rank` is null and thresholds are empty.
     *
     * @param array{key: string, label: string} $rank
     * @param array{next_level_thresholds: list<array{metric: string, label: string, current: int, required: int}>} $featureAccess
     * @return array{
     *   current_rank: string,
     *   current_rank_label: string,
     *   next_rank: string|null,
     *   next_rank_label: string|null,
     *   next_rank_thresholds: list<array{metric: string, label: string, current: int, required: int}>,
     *   trust_score_recent_changes: list<array{delta: int, reason: string, at: string}>,
     *   quests: array{multiplier: float, completed_count: int, total_count: int, pct: int, items: list<array{slug: string, label: string, hint: string, done: bool, weight_bonus: float, category: string}>}
     * }
     */
    private function resolveProgression(array $rank, int $userId, array $featureAccess): array
    {
        $next = RankCatalog::getNextRank($rank['key']); // master → null

        return [
            'current_rank'               => $rank['key'],
            'current_rank_label'         => $rank['label'],
            'next_rank'                  => $next,
            'next_rank_label'            => $next !== null ? RankCatalog::getLabel($next) : null,
            'next_rank_thresholds'       => $featureAccess['next_level_thresholds'],
            'trust_score_recent_changes' => $this->resolveTrustScoreRecentChanges($userId),
            'quests'                     => $this->resolveQuests($userId),
        ];
    }

    /**
     * §N11 quest block — the completion checklist plus the earned vote-weight
     * multiplier it grants (VoteWeightCalculator applies this at cast time).
     * Sourced from QuestProgressService::getProgress (object-cached), reshaped
     * from a slug-keyed map into a stable ordered list so the frontend renders
     * without deriving anything. Own-only: resolveProgression is only reached
     * on the self view.
     *
     * @return array{
     *   multiplier: float,
     *   completed_count: int,
     *   total_count: int,
     *   pct: int,
     *   items: list<array{slug: string, label: string, hint: string, done: bool, weight_bonus: float, category: string}>
     * }
     */
    private function resolveQuests(int $userId): array
    {
        // Backfill quests completed before their emitter was wired (throttled,
        // own-view only — resolveProgression is self-only). Makes the checklist
        // and the vote-weight multiplier reflect the operator's real state.
        $this->questProgress->reconcile($userId);

        $progress = $this->questProgress->getProgress($userId);

        $items = [];
        foreach ($progress['quests'] as $slug => $quest) {
            $items[] = [
                'slug'         => (string) $slug,
                'label'        => (string) $quest['label'],
                'hint'         => (string) $quest['hint'],
                'done'         => (bool) $quest['done'],
                'weight_bonus' => round((float) $quest['weight_bonus'], 2),
                'category'     => (string) $quest['category'],
            ];
        }

        return [
            'multiplier'      => round((float) $progress['multiplier'], 2),
            'completed_count' => (int) $progress['completed_count'],
            'total_count'     => (int) $progress['total_count'],
            'pct'             => (int) $progress['pct'],
            'items'           => $items,
        ];
    }

    /**
     * Last few score-change events for a member, shaped per §N11.
     * Sourced from the member's self-page row in the LIVE
     * bcc_trust_score_events ledger (written by ScoreMutationLogger on
     * every self-page score mutation — vote, endorsement, contribution
     * bonus, moderation). Read via getRecentForPage so the synthetic
     * self-page id (no wp_posts row) is not dropped by an inner join.
     *
     * @return list<array{delta: int, reason: string, at: string}>
     */
    private function resolveTrustScoreRecentChanges(int $userId): array
    {
        $rows = $this->scoreEventRepo->getRecentForPage(
            MemberSelfPageService::selfPageId($userId),
            5
        );
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                // Round to int — the contract surfaces score points,
                // not sub-point precision. Internal float deltas are
                // preserved in the row for audit.
                'delta'  => (int) round((float) $row->delta),
                'reason' => (string) $row->reason,
                'at'     => self::toIso8601((string) $row->created_at),
            ];
        }
        return $items;
    }

    // ──────────────────────────────────────────────────────────────────
    // Misc
    // ──────────────────────────────────────────────────────────────────

    /**
     * @return array<string, string>
     */
    private static function buildLinks(string $handle): array
    {
        $base = '/u/' . $handle;
        return [
            'self'     => $base,
            'watching' => $base . '/watching',
            'reviews'  => $base . '/reviews',
            'activity' => $base . '/activity',
            'disputes' => $base . '/disputes',
            'network'  => $base . '/network',
            'blog'     => $base . '/blog',
        ];
    }

    private static function toIso8601(string $mysqlDatetime): string
    {
        if ($mysqlDatetime === '' || $mysqlDatetime === '0000-00-00 00:00:00') {
            return '';
        }
        $ts = strtotime($mysqlDatetime . ' UTC');
        return $ts === false ? '' : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }
}
