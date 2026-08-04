<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Application;

use BCC\Core\Contracts\TrustReadServiceInterface;
use BCC\Trust\Core\Repositories\VoteRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class TrustReadService implements TrustReadServiceInterface
{
    private VoteRepository $voteRepository;

    public function __construct(VoteRepository $voteRepository)
    {
        $this->voteRepository = $voteRepository;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getVoteById(int $voteId): ?array
    {
        $vote = $this->voteRepository->getById($voteId);

        if (!$vote || (int) $vote->status !== 1) {
            return null;
        }

        return [
            'id' => (int) $vote->id,
            'page_id' => (int) $vote->page_id,
            'voter_user_id' => (int) $vote->voter_user_id,
            'vote_type' => (int) $vote->vote_type,
            'weight' => (float) $vote->weight,
            'reason' => isset($vote->reason) ? (string) $vote->reason : '',
            'status' => (int) $vote->status,
            'created_at' => isset($vote->created_at) ? (string) $vote->created_at : null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getActiveVotesForPage(int $pageId, int $limit = 50, int $offset = 0): array
    {
        $limit = min(max(1, $limit), 500);
        $offset = max(0, $offset);

        $votes = $this->voteRepository->getAllForPage($pageId, $limit, $offset);

        return array_map(static function ($vote): array {
            return [
                'id' => (int) $vote->id,
                'voter_user_id' => (int) $vote->voter_user_id,
                'voter_name' => isset($vote->voter_name) ? (string) $vote->voter_name : 'Unknown',
                'vote_type' => (int) $vote->vote_type,
                'weight' => (float) $vote->weight,
                'reason' => isset($vote->reason) ? (string) $vote->reason : '',
                'created_at' => isset($vote->created_at) ? (string) $vote->created_at : null,
            ];
        }, $votes);
    }

    public function countActiveVotesForPage(int $pageId): int
    {
        return $this->voteRepository->countActiveForPage($pageId);
    }

    /**
     * @param int[] $voteIds
     * @return array<int, array{vote_type: int, weight: float, reason: string, created_at: ?string}>
     */
    public function getVotesByIds(array $voteIds): array
    {
        $voteIds = array_values(array_unique(array_filter(array_map('intval', $voteIds))));

        if (empty($voteIds)) {
            return [];
        }

        return \BCC\Trust\Core\Plugin::instance()->voteRepository()->getBatchByIds($voteIds);
    }
    // getEligiblePanelistUserIds() + selectWithSoftIpDiversity() deleted
    // (Rank Phase 6, D-7): panel seat allocation retired with the panel.

    public function isSuspended(int $userId): bool
    {
        $userInfo = \BCC\Trust\Core\Plugin::instance()->userInfoRepository()->getByUserId($userId);

        return $userInfo ? (bool) $userInfo->is_suspended : false;
    }

    /**
     * Lock the vote row FOR UPDATE and report whether it is still active.
     *
     * Must be called from inside the caller's open transaction on the shared
     * $wpdb connection; the row lock is released on that transaction's
     * COMMIT/ROLLBACK. Prevents the trust engine from soft-deleting the vote
     * between dispute-create validation and the dispute INSERT.
     */
    public function lockActiveVoteForDispute(int $voteId): bool
    {
        return $this->voteRepository->lockActiveForDispute($voteId);
    }
}
