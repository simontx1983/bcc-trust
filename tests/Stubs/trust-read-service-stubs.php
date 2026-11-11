<?php
/**
 * The bcc-core contract TrustReadService implements, for unit suites that
 * construct the real TrustReadService (the unit bootstrap does not load
 * bcc-core). The signatures mirror bcc-core's
 * src/Contracts/TrustReadServiceInterface.php, so a drift in the real class
 * still fails to compile here.
 *
 * Required from setUp() of a #[RunTestsInSeparateProcesses] class.
 */

declare(strict_types=1);

namespace BCC\Core\Contracts {
    if (!interface_exists(__NAMESPACE__ . '\TrustReadServiceInterface', false)) {
        interface TrustReadServiceInterface
        {
            /** @return array<string, mixed>|null */
            public function getVoteById(int $voteId): ?array;

            /** @return array<int, array<string, mixed>> */
            public function getActiveVotesForPage(int $pageId, int $limit = 50, int $offset = 0): array;

            public function countActiveVotesForPage(int $pageId): int;

            /**
             * @param  int[] $voteIds
             * @return array<int, array{vote_type: int, weight: float, reason: string, created_at: ?string}>
             */
            public function getVotesByIds(array $voteIds): array;

            public function isSuspended(int $userId): bool;

            public function lockActiveVoteForDispute(int $voteId): bool;
        }
    }
}
