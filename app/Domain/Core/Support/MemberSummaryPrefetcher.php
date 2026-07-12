<?php
/**
 * MemberSummaryPrefetcher — shared bounded-batch prefetch helper.
 *
 * Collapses the twelve per-user signal lookups that
 * {@see \BCC\Trust\Core\Services\UserViewService::getSummary()} reads
 * into a single bounded batch keyed on `IN (userIds)`. Without this
 * the per-row hydration N+1's across the page (24 rows × 11 reads,
 * plus 4 more per row from the rank chip's getLevel fan-out — see the
 * `levels` key below).
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
use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Core\Repositories\GitHubRepository;
use BCC\Trust\Core\Repositories\PeepSoReactionRepository;
use BCC\Trust\Core\Repositories\UserSyncRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Repositories\XRepository;
use BCC\Trust\Disputes\Repositories\DisputeRepository;
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
     * `count($userIds)` (eleven signal batches plus the two inside
     * getLevelsForUsers). Solid-reaction id can be null pre-seeder; we
     * skip that batch in that case and let `getSummary` default
     * `solids_received` to 0.
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
        }

        $solidId = ReactionTypeRegistry::solidId();
        $solidsReceivedCounts = $solidId === null
            ? []
            : (new PeepSoReactionRepository())->countReceivedByUsers($userIds, $solidId);

        return [
            'follower_counts'              => PeepSoFollowerRepository::getFollowersCountForUsers($userIds),
            'primary_locals'               => PeepSoGroupRepository::getPrimaryLocalForUsers($userIds),
            'owned_pages_counts'           => UserSyncRepository::getOwnedPageCountsForUsers($userIds),
            'owned_pages_by_type'          => PeepSoPageRepository::getOwnedPageTypeCountsForUsers($userIds),
            // Attestation-backed since the endorsements-table retirement:
            // "received" = active vouches cast ON the member
            // (target_kind='user_profile', keyed by raw user id).
            'endorsements_received_counts' => (new AttestationRepository())->countActiveVouchesByTargets('user_profile', $userIds),
            'solids_received_counts'       => $solidsReceivedCounts,
            'reviews_written_counts'       => (new VoteRepository())->countByVoters($userIds),
            'disputes_signed_counts'       => DisputeRepository::countByReporters($userIds),
            'wallets_verified_counts'      => WalletRepository::getVerifiedCountsForUsers($userIds),
            'x_connections'                => (new XRepository())->getConnectionsForUsers($userIds),
            'github_connections'           => (new GitHubRepository())->getConnectionsForUsers($userIds),
            // Batched level for the rank chip. Without this map getSummary's
            // rank falls back to autoDerivedRank→getLevel, which costs four
            // queries PER ROW (two follower COUNTs, votes-cast COUNT,
            // wallet-links read). getLevelsForUsers shares resolveLevel and
            // the admin-tunable thresholds with that per-user path, so the
            // derived rank is identical.
            'levels'                       => Plugin::instance()->featureAccessService()->getLevelsForUsers($userIds),
        ];
    }
}
