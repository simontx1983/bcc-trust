<?php
/**
 * Doubles for ColdStartVisibilityTest (F-78): PeepSo's privacy-aware user
 * search, as MentionPolicy uses it. It returns the subset of `include` the
 * viewer may see, and records each call.
 *
 * Fixture (static, set by the test):
 *   PeepSoUserSearch::$hidden   array<int, true>       ids hidden from everyone (banned, private, ...)
 *   PeepSoUserSearch::$blocked  array<int, list<int>>  viewer => ids blocked either way
 *   PeepSoUserSearch::$calls    list<{include, viewer}> every construction
 *
 * ⚠⚠ SUBPROCESS-ONLY: require it from the test's setUp(), never at file
 * level. Other Stubs/ files declare `\PeepSoUserSearch` in DIFFERENT shapes
 * (filter-visible-stubs.php, mention-audience-stubs.php). Whichever loads
 * first in a process wins, so each one lives only in its own test's child
 * process. Guarded with class_exists(…, false).
 */

declare(strict_types=1);

if (!class_exists('PeepSoUserSearch', false)) {
    final class PeepSoUserSearch
    {
        /** @var array<int, true> ids hidden from everyone (banned, private, ...) */
        public static array $hidden = [];
        /** @var array<int, list<int>> viewer => ids blocked either way */
        public static array $blocked = [];
        /** @var list<array{include: list<int>, viewer: int|null}> */
        public static array $calls = [];

        /** @var list<int> */
        public array $results = [];

        /** @param array{include: list<int>, number: int} $args */
        public function __construct(array $args, ?int $viewerId, string $search)
        {
            self::$calls[] = ['include' => $args['include'], 'viewer' => $viewerId];
            $blocked = $viewerId !== null ? (self::$blocked[$viewerId] ?? []) : [];
            foreach ($args['include'] as $id) {
                if (!isset(self::$hidden[$id]) && !in_array($id, $blocked, true)) {
                    $this->results[] = $id;
                }
            }
        }
    }
}
