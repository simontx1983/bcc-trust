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
 * This helper warms all of them in four bounded batches:
 *
 *   1. MemberSummaryPrefetcher::primeFor      — the eleven summary maps
 *   2. cache_users()                          — WP users + usermeta in 2 queries
 *   3. ReputationRepository::primeByUserIds   — rows for members AND viewer
 *      (the viewer's tier gates getViewerActionPermissions on every row)
 *   4. AttestationRepository::findActiveByAttestorForTargets
 *                                             — viewer→user_profile rows,
 *      returned under the `viewer_attestations` key (same key + row shape
 *      the page-card path uses, consumed via
 *      AttestationService::shapeViewerAttestationFromRows)
 *   6. §J.6 negative_signals counts (members are full trust subjects):
 *        - dispute_active_counts      — DisputeRepository::
 *          countActiveDisputesForPages, keyed by the member's SELF-PAGE
 *          id (MemberSelfPageService::selfPageId) where disputes/votes/
 *          scores live;
 *        - attestation_active_counts  — AttestationRepository::
 *          countActiveByTargets('user_profile', …), keyed by the RAW
 *          user id where user_profile attestations live;
 *        - attestation_revoked_counts — the revoked sibling, same raw id.
 *      This is the id-duality: the dispute map keys on selfPageId, the
 *      attestation maps key on the raw user id, exactly as
 *      CardViewService::buildNegativeSignals consumes them. These three
 *      keys are the page-card prefetch bundle's keys verbatim, so the
 *      member-card list path resolves negative_signals from the batch
 *      instead of the per-row reads (O(1) dispute + O(1) attestation
 *      queries for the whole page, not O(N)). Only THIS prefetcher
 *      carries them — the lighter MemberSummaryPrefetcher (rosters,
 *      group members, suggestions) stays untouched, so non-card member
 *      surfaces pay nothing for a signal block they never render.
 *
 * Batches 2-3 warm request-scoped memos inside their owners, so the
 * existing single-user accessors (getTier, getScore, get_userdata,
 * get_user_meta) become free — no call-site changes beyond swapping
 * primeFor here.
 *
 * @package BCC\Trust\Core\Support
 * @since 2026-06-12
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Services\MemberSelfPageService;
use BCC\Trust\Disputes\Repositories\DisputeRepository;

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
        }

        $map = MemberSummaryPrefetcher::primeFor($idList);

        $attestationRepo = new AttestationRepository();

        $map['viewer_attestations'] = $viewerId > 0 && $idList !== []
            ? $attestationRepo->findActiveByAttestorForTargets(
                $viewerId,
                'user_profile',
                $idList
            )
            : [];

        // §J.6 negative_signals batch (id-duality). Three bounded queries
        // for the whole page — the same keys CardViewService::
        // buildNegativeSignals' prefetch branch reads. Disputes key on
        // the member self-page id (selfPageId), attestation counts on the
        // raw user id; CardViewService translates user_profile→selfPageId
        // for its dispute lookup, so the dispute map MUST be self-page
        // keyed here to match. Absent ids default to 0 at the read side.
        if ($idList === []) {
            $map['dispute_active_counts']      = [];
            $map['attestation_active_counts']  = [];
            $map['attestation_revoked_counts'] = [];
            $map['viewer_votes']               = [];
        } else {
            $selfPageIds = [];
            foreach ($idList as $userId) {
                $selfPageIds[] = MemberSelfPageService::selfPageId($userId);
            }
            $map['dispute_active_counts']      = DisputeRepository::countActiveDisputesForPages($selfPageIds);
            $map['attestation_active_counts']  = $attestationRepo->countActiveByTargets('user_profile', $idList);
            $map['attestation_revoked_counts'] = $attestationRepo->countRevokedByTargets('user_profile', $idList);
            // v1.49 viewer_has_reviewed batch — one bounded IN() over
            // the page's self-page ids. Same map key + keying the
            // page-card path reads (viewer_votes[pageId] => true), so
            // CardViewService's member branch consumes it verbatim.
            // Empty for anon viewers (read side short-circuits false).
            $map['viewer_votes'] = $viewerId > 0
                ? (new VoteRepository())->getVotedPageIdsForVoter($viewerId, $selfPageIds)
                : [];
        }

        return $map;
    }
}
