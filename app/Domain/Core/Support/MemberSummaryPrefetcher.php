<?php
/**
 * MemberSummaryPrefetcher — shared bounded-batch prefetch helper.
 *
 * Collapses the eleven per-user signal lookups that
 * {@see \BCC\Trust\Core\Services\UserViewService::getSummary()} reads
 * into a single bounded batch keyed on `IN (userIds)`. Without this
 * the per-row hydration N+1's across the page (24 rows × 11 reads).
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
 * @package BCC\Trust\Core\Support
 * @since 2026-05-14
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

use BCC\Core\Repositories\PeepSoFollowerRepository;
use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Core\Repositories\PeepSoPageRepository;
use BCC\Trust\Core\Repositories\EndorsementRepository;
use BCC\Trust\Core\Repositories\FlagsRepository;
use BCC\Trust\Core\Repositories\GitHubRepository;
use BCC\Trust\Core\Repositories\PeepSoReactionRepository;
use BCC\Trust\Core\Repositories\UserSyncRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Repositories\XRepository;
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
     * Eleven bounded queries total irrespective of `count($userIds)`.
     * Solid-reaction id can be null pre-seeder; we skip that batch in
     * that case and let `getSummary` default `solids_received` to 0.
     *
     * Empty `$userIds` returns each map empty — callers should
     * short-circuit before calling this if they have no users to
     * hydrate, but the helper itself stays safe to call regardless.
     *
     * @param list<int> $userIds Bounded by caller; the IN-clause is
     *                           expected to be paginated upstream.
     * @return array<string, mixed>
     */
    public static function primeFor(array $userIds): array
    {
        $solidId = ReactionTypeRegistry::solidId();
        $solidsReceivedCounts = $solidId === null
            ? []
            : (new PeepSoReactionRepository())->countReceivedByUsers($userIds, $solidId);

        return [
            'follower_counts'              => PeepSoFollowerRepository::getFollowersCountForUsers($userIds),
            'primary_locals'               => PeepSoGroupRepository::getPrimaryLocalForUsers($userIds),
            'owned_pages_counts'           => UserSyncRepository::getOwnedPageCountsForUsers($userIds),
            'owned_pages_by_type'          => PeepSoPageRepository::getOwnedPageTypeCountsForUsers($userIds),
            'endorsements_received_counts' => (new EndorsementRepository())->getReceivedCountsForUsers($userIds),
            'solids_received_counts'       => $solidsReceivedCounts,
            'reviews_written_counts'       => (new VoteRepository())->countByVoters($userIds),
            'disputes_signed_counts'       => (new FlagsRepository())->countByFlaggers($userIds),
            'wallets_verified_counts'      => WalletRepository::getVerifiedCountsForUsers($userIds),
            'x_connections'                => (new XRepository())->getConnectionsForUsers($userIds),
            'github_connections'           => (new GitHubRepository())->getConnectionsForUsers($userIds),
        ];
    }
}
