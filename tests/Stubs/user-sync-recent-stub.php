<?php
/**
 * A fake UserSyncRepository whose recency source the test sets
 * (`UserSyncRepository::$recent`): the cold-start candidate pool. Shared by
 * ColdStartVisibilityTest and SuggestionVisibilityTest (through
 * suggestion-visibility-stubs.php).
 *
 * ⚠⚠ SUBPROCESS-ONLY: require it from the test's setUp(), never declare it
 * at file level and never load it in a parent-process test. A class cannot
 * be undone in a process. Declared at file level, PHPUnit's collection ran
 * it in the MAIN process, and it was the UserSyncRepository for every test
 * there. A test that needed the real class failed in CI.
 *
 * Guarded with class_exists(…, false), so checking never autoloads the real
 * class. If the real one is already loaded, a test that sets `$recent` fails
 * loudly instead of reading real data.
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Repositories;

if (!class_exists(UserSyncRepository::class, false)) {
    /** The cold-start recency source. */
    final class UserSyncRepository
    {
        /** @var list<int> */
        public static array $recent = [];

        /** @return list<int> */
        public static function getRecentlyActiveUserIds(int $intervalDays, int $limit, int $excludeUserId = 0): array
        {
            return array_values(array_filter(self::$recent, static fn (int $id): bool => $id !== $excludeUserId));
        }
    }
}
