<?php
/**
 * MemberSummaryPrefetcher — shared bounded-batch prefetch helper.
 *
 * Collapses the twelve per-user signal lookups that
 * {@see \BCC\Trust\Core\Services\UserViewService::getSummary()} reads
 * into a single bounded batch keyed on `IN (userIds)`. Without this
 * the per-row hydration N+1's across the page (24 rows × 11 reads,
 * plus a per-row rank_state read for the rank chip — see the
 * `member_states` key below).
 *
 * Three pre-existing call sites carried this same eleven-key array
 * inline:
 *
 *   - UsersEndpoint::members          (/members directory)
 *   - GroupMembersService::primePrefetched (/groups/:id/members)
 *   - UserFollowsService::primePrefetched  (/users/:handle/followers)
 *
 * Extracted on 2026-05-14 when CardWatchersService became the fourth
 * caller. The migration replaces each inline copy with
 * `MemberSummaryPrefetcher::primeFor($userIds)`; the array shape stays
 * byte-identical so `UserViewService::getSummary($_, $_, $map)`
 * doesn't need any change.
 *
 * F-571: primeFor() takes the viewer too. Two of the maps (reviews written,
 * disputes signed) are that viewer's: see contentCountsFor().
 *
 * @package BCC\Trust\Core\Support
 * @since 2026-05-14
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

use BCC\Core\Repositories\PeepSoFollowerRepository;
use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Core\Repositories\PeepSoPageRepository;
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Core\Repositories\GitHubRepository;
use BCC\Trust\Core\Repositories\PeepSoReactionRepository;
use BCC\Trust\Core\Repositories\UserSyncRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Repositories\XRepository;
use BCC\Trust\Disputes\Repositories\DisputeRepository;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use BCC\Trust\Onchain\Repositories\WalletRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class MemberSummaryPrefetcher
{
    /**
     * Prime the per-user-signal map UserViewService reads on
     * `getSummary($userId, $viewerId, $prefetched)`.
     *
     * A bounded set of batch queries total irrespective of
     * `count($userIds)` (eleven signal batches plus the one inside
     * memberStatesFor). Solid-reaction id can be null pre-seeder; we
     * skip that batch in that case and let `getSummary` default
     * `solids_received` to 0.
     *
     * Empty `$userIds` returns each map empty — callers should
     * short-circuit before calling this if they have no users to
     * hydrate, but the helper itself stays safe to call regardless.
     *
     * ⚠ PER VIEWER (F-571): `reviews_written_counts` and
     * `disputes_signed_counts` leave out the SECRET pages `$viewerId` may
     * not see (contentCountsFor()). Pass the SAME viewer the cards are then
     * built for with getSummary(), and never reuse the map for another one.
     *
     * @param list<int> $userIds Bounded by caller; the IN-clause is
     *                           expected to be paginated upstream.
     * @param int       $viewerId who the cards are built for; 0 = anonymous
     * @return array<string, mixed>
     */
    public static function primeFor(array $userIds, int $viewerId): array
    {
        // Warm WP's request-global user + user-meta caches in two batch
        // queries before any per-row hydration runs. CardViewService /
        // UserViewService resolve each member's avatar via
        // `PeepSoUser::get_instance($id)->get_avatar('full')`, which on a
        // cold cache fires `get_user_by()` + `get_user_meta()`
        // (peepso_avatar_hash / peepso_use_gravatar) per member — an N+1
        // across the page. These two primers collapse that into one users
        // query + one usermeta query for the whole batch; the per-row
        // PeepSo calls then hit the primed caches. (PeepSo's own
        // `SELECT * FROM peepso_users` in its constructor still runs per
        // user — it queries the DB directly and consults no WP cache — so
        // it is not removable here without duplicating PeepSo internals.)
        // Read-only priming: no behavior change, no invalidation needed.
        if ($userIds !== []) {
            cache_users($userIds);
            update_meta_cache('user', $userIds);
            // The §K2 privacy flags are read from their own guarded copy,
            // not the meta cache; one batch for the page.
            PrivacySettings::prime($userIds);
        }

        $solidId = ReactionTypeRegistry::solidId();
        $solidsReceivedCounts = $solidId === null
            ? []
            : (new PeepSoReactionRepository())->countReceivedByUsers($userIds, $solidId);
        $contentCounts = self::contentCountsFor($userIds, $viewerId);

        return [
            'follower_counts'              => PeepSoFollowerRepository::getFollowersCountForUsers($userIds),
            // Active side of the same graph — how many accounts each user
            // watches. Batched for the identical reason the passive side
            // is: N × getCounts() would issue 2N sequential COUNTs.
            'following_counts'             => PeepSoFollowerRepository::getFollowingCountForUsers($userIds),
            'primary_halls'                => \BCC\Trust\Onchain\Repositories\HallRepository::primaryHallsForUsers($userIds),
            'owned_pages_counts'           => UserSyncRepository::getOwnedPageCountsForUsers($userIds),
            'owned_pages_by_type'          => PeepSoPageRepository::getOwnedPageTypeCountsForUsers($userIds),
            // Attestation-backed since the endorsements-table retirement:
            // "received" = active vouches cast ON the member
            // (target_kind='user_profile', keyed by raw user id).
            'endorsements_received_counts' => (new AttestationRepository())->countActiveVouchesByTargets('user_profile', $userIds),
            'solids_received_counts'       => $solidsReceivedCounts,
            'reviews_written_counts'       => $contentCounts['reviews_written_counts'],
            'disputes_signed_counts'       => $contentCounts['disputes_signed_counts'],
            'wallets_verified_counts'      => WalletRepository::getVerifiedCountsForUsers($userIds),
            'x_connections'                => (new XRepository())->getConnectionsForUsers($userIds),
            'github_connections'           => (new GitHubRepository())->getConnectionsForUsers($userIds),
            // Batched member-state for the rank chip (Rank redesign
            // Phase 5). One bounded rank_state query per page via
            // RankStateService::memberStatesFor; users without a row
            // shape to the explicit New Member state (rank/rank_label
            // null). Without this map getSummary falls back to a
            // per-row memberState read.
            'member_states'                => Plugin::instance()->rankStateService()->memberStatesFor($userIds),
        ];
    }

    /**
     * F-571: each member's `reviews_written` and `disputes_signed` as
     * $viewerId may see them, for a list of member cards. They leave out the
     * SECRET pages that viewer may not see, as the member's own Reviews and
     * Disputes lists do (UserReviewsService / UserDisputesService, F-528), so
     * a card's chip agrees with the list it opens.
     *
     * Per LIST, not per member: one secret-page read per table for the whole
     * list (VoteRepository::findSecretPageIdsReviewedByAny(),
     * DisputeRepository::findSecretPageIdsDisputedByAny()), one
     * PageAccess::hiddenAmong() for the viewer, then the batched counts leave
     * the hidden pages out in SQL.
     *
     * Exempt, with no read: the viewer's OWN card (they see every page of
     * their own), and a viewer who is a site admin. ⚠ The exemption is
     * $viewerId's own: the request user's when the viewer IS that user, and
     * otherwise asked of the viewer (ProfileAccess::userIsExemptFromVisibility(),
     * as AnnouncementPageAccess does). A list built for someone else never
     * borrows the requester's rights.
     *
     * ⚠ FAILS CLOSED, never 503: when a secret-page read fails, or the list's
     * members together pass PageAccess::HIDDEN_LIST_CAP (logged, and counted
     * on `page_privacy` / `member_list_over_cap`), that count is 0 on every
     * card but the viewer's own. A whole list must not answer 503 over a
     * chip, and an unfiltered count would show what the viewer may not see.
     * Rationale: F-571 (private tracker).
     *
     * @param list<int> $userIds
     * @param int       $viewerId who the cards are built for; 0 = anonymous
     * @return array{reviews_written_counts: array<int, int>, disputes_signed_counts: array<int, int>}
     */
    public static function contentCountsFor(array $userIds, int $viewerId): array
    {
        $ids = [];
        foreach ($userIds as $id) {
            $intId = (int) $id;
            if ($intId > 0) {
                $ids[$intId] = $intId;
            }
        }
        $ids = array_values($ids);

        $voteRepo = new VoteRepository();
        $exempt   = $viewerId > 0 && ($viewerId === (int) get_current_user_id()
            ? ProfileAccess::viewerIsExemptFromVisibility()
            : ProfileAccess::userIsExemptFromVisibility($viewerId));
        $others   = array_values(array_filter($ids, static fn (int $id): bool => $id !== $viewerId));

        if ($exempt || $others === []) {
            return [
                'reviews_written_counts' => self::countOf('reviews_written_counts', $voteRepo, $ids, []),
                'disputes_signed_counts' => self::countOf('disputes_signed_counts', $voteRepo, $ids, []),
            ];
        }

        return [
            'reviews_written_counts' => self::countSeenBy('reviews_written_counts', $voteRepo, $ids, $others, $viewerId),
            'disputes_signed_counts' => self::countSeenBy('disputes_signed_counts', $voteRepo, $ids, $others, $viewerId),
        ];
    }

    /**
     * One count of contentCountsFor(): the list's hidden pages found once,
     * then the batched count without them. The viewer's own card is counted
     * in full, and only the viewer's own card survives a failed read.
     *
     * @param 'reviews_written_counts'|'disputes_signed_counts' $map
     * @param list<int> $ids    every member of the list
     * @param list<int> $others the members other than the viewer
     * @return array<int, int>
     */
    private static function countSeenBy(string $map, VoteRepository $voteRepo, array $ids, array $others, int $viewerId): array
    {
        try {
            $secret = $map === 'reviews_written_counts'
                ? $voteRepo->findSecretPageIdsReviewedByAny($others)
                : DisputeRepository::findSecretPageIdsDisputedByAny($others);
            $hidden = PageAccess::hiddenAmong($secret, $viewerId, false);
        } catch (RepositoryReadFailure $e) {
            \BCC\Core\Log\Logger::error('[MemberSummaryPrefetcher] secret-page read failed: member cards show this count as 0', [
                'count'     => $map,
                'viewer_id' => $viewerId,
                'error'     => $e->getMessage(),
            ]);
            $hidden = null;
        }

        if ($hidden === []) {
            return self::countOf($map, $voteRepo, $ids, []);
        }

        $out = $hidden === null ? [] : self::countOf($map, $voteRepo, $others, $hidden);
        if (count($others) < count($ids)) {
            $out += self::countOf($map, $voteRepo, [$viewerId], []);
        }

        return $out;
    }

    /**
     * The batched count behind one map, leaving out $exclude.
     *
     * @param 'reviews_written_counts'|'disputes_signed_counts' $map
     * @param list<int> $members
     * @param list<int> $exclude
     * @return array<int, int>
     */
    private static function countOf(string $map, VoteRepository $voteRepo, array $members, array $exclude): array
    {
        return $map === 'reviews_written_counts'
            ? $voteRepo->countByVoters($members, $exclude)
            : DisputeRepository::countByReporters($members, $exclude);
    }
}
