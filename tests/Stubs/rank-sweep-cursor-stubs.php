<?php

declare(strict_types=1);

/**
 * Fakes for the F-13 daily rank sweeps. Load only in a separate process.
 *
 * The repositories are SUBCLASSED (they are not final) with their DB
 * constructors replaced, so the real services run against in-memory state.
 * This file is deliberately separate from rank-unit-stubs.php.
 */

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', sys_get_temp_dir() . '/');
    }

    final class RankSweepWorld
    {
        /** @var list<int> */
        public static array $userIds = [];

        public static int $readFailures = 0;

        /** @var list<int> */
        public static array $evaluated = [];

        /** @var list<int> users whose evaluate() throws */
        public static array $poison = [];

        /** @var array<string, list<int>> day => users recorded */
        public static array $recorded = [];

        public static bool $writeFails = false;

        public static int $purges = 0;

        /** @var list<string> */
        public static array $logs = [];

        public static function reset(): void
        {
            self::$userIds      = [];
            self::$readFailures = 0;
            self::$evaluated    = [];
            self::$poison       = [];
            self::$recorded     = [];
            self::$writeFails   = false;
            self::$purges       = 0;
            self::$logs         = [];
        }

        /** @return list<int> */
        public static function page(int $after, int $limit): array
        {
            if (self::$readFailures > 0) {
                self::$readFailures--;
                throw new \BCC\Trust\Onchain\Repositories\RepositoryReadFailure('fake', 'listUserIdsAfter', 'Lost connection');
            }
            $ids = array_values(array_filter(self::$userIds, static fn (int $id): bool => $id > $after));
            sort($ids);
            return array_slice($ids, 0, $limit);
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(__NAMESPACE__ . '\\Logger', false)) {
        final class Logger
        {
            /** @param array<string, mixed> $c */
            public static function info(string $m, array $c = []): void { \RankSweepWorld::$logs[] = 'info:' . $m; }
            /** @param array<string, mixed> $c */
            public static function warning(string $m, array $c = []): void { \RankSweepWorld::$logs[] = 'warning:' . $m; }
            /** @param array<string, mixed> $c */
            public static function error(string $m, array $c = []): void { \RankSweepWorld::$logs[] = 'error:' . $m; }
        }
    }
}

namespace BCC\Trust\Rank\Tests\Fakes {
    use BCC\Trust\Core\Repositories\ReputationRepository;
    use BCC\Trust\Rank\Repositories\RankStateRepository;
    use BCC\Trust\Rank\Repositories\TierDaysRepository;

    final class FakeRankState extends RankStateRepository
    {
        public function __construct()
        {
        }

        public function listUserIdsAfter(int $cursor, int $limit): array
        {
            return \RankSweepWorld::page($cursor, $limit);
        }
    }

    final class FakeTierDays extends TierDaysRepository
    {
        public function __construct()
        {
        }

        public function listUserIdsAfter(int $cursor, int $limit): array
        {
            return \RankSweepWorld::page($cursor, $limit);
        }

        public function recordDays(array $userIdToOrd, string $day): bool
        {
            if (\RankSweepWorld::$writeFails) {
                return false;
            }
            foreach (array_keys($userIdToOrd) as $id) {
                \RankSweepWorld::$recorded[$day][] = (int) $id;
            }
            return true;
        }

        public function purgeOlderThan(string $beforeDay, int $limit): int|false
        {
            \RankSweepWorld::$purges++;
            return 0;
        }

        public function acquireSnapshotLock(): bool
        {
            return true;
        }

        public function releaseSnapshotLock(): void
        {
        }
    }

    final class FakeReputation extends ReputationRepository
    {
        public function __construct()
        {
        }

        public function getTiersForUsers(array $userIds): array
        {
            return [];
        }
    }
}
