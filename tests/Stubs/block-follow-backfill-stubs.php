<?php
/**
 * Doubles for BlockFollowBackfillTest: the REAL BlockFollowBackfill, with
 * bcc-core's block repository and bcc-trust's WatchingRepository faked at
 * their FQNs.
 *
 * Fixture ($GLOBALS['__bcc_bfb']):
 *   blocks       list<array{id, blocker, blocked}> in blk_id order
 *   page_fails   bool  listBlockPairsAfter returns null
 *   follows      list<"active:passive"> existing member follows
 *   follow_fails bool  findFollowIdForPair throws RepositoryReadFailure
 *   cursors      list<int> afterId values the backfill paged with
 *
 * Safe only inside #[RunTestsInSeparateProcesses].
 */

declare(strict_types=1);

namespace BCC\Core\Repositories {
    if (!class_exists(PeepSoBlockRepository::class, false)) {
        final class PeepSoBlockRepository
        {
            /** @return list<array{id: int, blocker: int, blocked: int}>|null */
            public static function listBlockPairsAfter(int $afterId, int $limit = 500): ?array
            {
                $GLOBALS['__bcc_bfb']['cursors'][] = $afterId;
                if (!empty($GLOBALS['__bcc_bfb']['page_fails'])) {
                    return null;
                }
                $page = array_values(array_filter(
                    $GLOBALS['__bcc_bfb']['blocks'] ?? [],
                    static fn (array $r): bool => $r['id'] > $afterId
                ));
                return array_slice($page, 0, $limit);
            }
        }
    }
}

namespace BCC\Trust\Core\Repositories {
    if (!class_exists(WatchingRepository::class, false)) {
        final class WatchingRepository
        {
            public function findFollowIdForPair(int $activeUserId, int $passiveUserId): int
            {
                if (!empty($GLOBALS['__bcc_bfb']['follow_fails'])) {
                    throw new \BCC\Trust\Onchain\Repositories\RepositoryReadFailure('WatchingRepository', 'findFollowIdForPair', 'simulated');
                }
                $key = $activeUserId . ':' . $passiveUserId;
                $at  = array_search($key, $GLOBALS['__bcc_bfb']['follows'] ?? [], true);
                return $at === false ? 0 : 1000 + (int) $at;
            }
        }
    }
}
