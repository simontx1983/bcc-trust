<?php
/**
 * MemberCardPrefetcher — card-level batch prefetch for member lists.
 *
 * Sibling of {@see PageCardPrefetcher}, composing
 * {@see MemberSummaryPrefetcher}. The member-card view-model
 * (CardViewService::getMemberCardForList) reads MORE than the eleven
 * summary signals: per row it also resolves the reputation row
 * (tier ×2 + score ×2 via getByUserId), the WP user + usermeta
 * (handle, privacy, moderation flags), the participation lifetime
 * bonus (2 counts), and the viewer's §J.6 attestation against the
 * member. On a 24-row directory page those per-row reads were the
 * dominant share of a ~60-queries-per-member profile.
 *
 * This helper warms all of them in five bounded batches:
 *
 *   1. MemberSummaryPrefetcher::primeFor      — the eleven summary maps
 *   2. cache_users()                          — WP users + usermeta in 2 queries
 *   3. ReputationRepository::primeByUserIds   — rows for members AND viewer
 *      (the viewer's tier gates getViewerActionPermissions on every row)
 *   4. DisputeParticipationRepository::primeCountsForUsers
 *                                             — lifetime trust bonus counts
 *   5. AttestationRepository::findActiveByAttestorForTargets
 *                                             — viewer→user_profile rows,
 *      returned under the `viewer_attestations` key (same key + row shape
 *      the page-card path uses, consumed via
 *      AttestationService::shapeViewerAttestationFromRows)
 *
 * Batches 2-4 warm request-scoped memos inside their owners, so the
 * existing single-user accessors (getTier, getScore,
 * getEarnedLifetimeTrust, get_userdata, get_user_meta) become free —
 * no call-site changes beyond swapping primeFor here.
 *
 * @package BCC\Trust\Core\Support
 * @since 2026-06-12
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Disputes\Repositories\DisputeParticipationRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class MemberCardPrefetcher
{
    /**
     * Prime everything a page of member cards reads. Returns the
     * MemberSummaryPrefetcher map extended with `viewer_attestations`
     * (empty for anon viewers — getViewerAttestation returns null for
     * them anyway).
     *
     * @param list<int> $userIds  Bounded by caller pagination (≤ 50).
     * @param int       $viewerId 0 for anonymous viewers.
     * @return array<string, mixed>
     */
    public static function primeFor(array $userIds, int $viewerId): array
    {
        $ids = [];
        foreach ($userIds as $id) {
            $intId = (int) $id;
            if ($intId > 0) {
                $ids[$intId] = true;
            }
        }
        $idList = array_keys($ids);

        if ($idList !== []) {
            // WP user rows + usermeta (handle, privacy, moderation
            // flags) — two queries for the whole page.
            cache_users($idList);

            $reputationIds = $idList;
            if ($viewerId > 0 && !isset($ids[$viewerId])) {
                $reputationIds[] = $viewerId;
            }
            (new ReputationRepository())->primeByUserIds($reputationIds);
            (new DisputeParticipationRepository())->primeCountsForUsers($idList);
        }

        $map = MemberSummaryPrefetcher::primeFor($idList);

        $map['viewer_attestations'] = $viewerId > 0 && $idList !== []
            ? (new AttestationRepository())->findActiveByAttestorForTargets(
                $viewerId,
                'user_profile',
                $idList
            )
            : [];

        return $map;
    }
}
