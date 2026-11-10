<?php
/**
 * Stubs for the #122 blocked-sender unit tests.
 *
 * Two things, both following patterns already in this directory.
 *
 * 1. `wp_strip_all_tags` — the single WordPress function
 *    `MessagesService::previewBody` needs, and the unit bootstrap loads
 *    no WordPress.
 *
 *    ⚠ `tests/Stubs/page-posts-stubs.php` already defines it for its own
 *    feature. Both are `function_exists`-guarded, so in a shared process
 *    whichever loads first wins — which is only safe because this body
 *    is BEHAVIOURALLY IDENTICAL to that one (`trim(strip_tags($text))`).
 *    Two guarded stubs that disagreed would make a result depend on file
 *    order, which is a hijack this project has been burned by.
 *
 *    ⚠ Noticed while writing these tests and worth keeping: the BLOCKED
 *    path never calls `previewBody`, so it needed no stub at all. The
 *    undefined-function errors appeared only on the ORDINARY-sender
 *    cases — incidental evidence that the substitution short-circuits
 *    the formatter rather than formatting the real text and discarding
 *    it.
 *
 * 2. `BCC\Core\Repositories\PeepSoBlockRepository` — bcc-trust's unit
 *    bootstrap does not autoload bcc-core (CI checks that plugin out for
 *    the integration jobs; the unit jobs stub its collaborators by
 *    fully-qualified name). Eight stub files in this directory already
 *    declare `BCC\Core\Repositories\*` classes the same way.
 *
 *    ⚠ This stub is DRIVEN, not reimplemented: it holds a configured set
 *    of (blocker, blocked) pairs and records every call. It deliberately
 *    does NOT simulate the SQL — the real predicate is covered by
 *    bcc-core's own capturing-$wpdb tests and by bcc-trust's integration
 *    suite against a real engine. What a unit test can honestly prove
 *    here is which question the caller ASKS and what it does with the
 *    answer, which is why the calls are recorded in order.
 */

declare(strict_types=1);

namespace BCC\Core\Repositories {
    if (!class_exists('BCC\Core\Repositories\PeepSoBlockRepository', false)) {
        final class PeepSoBlockRepository
        {
            /**
             * Pairs that exist, as [blockerId, blockedId].
             *
             * @var list<array{int, int}>
             */
            public static array $blocking = [];

            /**
             * Every isBlocking() call, in order, as [blockerId, blockedId].
             *
             * @var list<array{int, int}>
             */
            public static array $isBlockingCalls = [];

            /**
             * Every getBlockedSet() call, as [blockerId, candidateIds].
             *
             * @var list<array{int, list<int>}>
             */
            public static array $getBlockedSetCalls = [];

            public static function reset(): void
            {
                self::$blocking           = [];
                self::$isBlockingCalls    = [];
                self::$getBlockedSetCalls = [];
            }

            public static function isBlocking(int $blockerId, int $blockedId): bool
            {
                self::$isBlockingCalls[] = [$blockerId, $blockedId];
                foreach (self::$blocking as [$a, $b]) {
                    if ($a === $blockerId && $b === $blockedId) {
                        return true;
                    }
                }
                return false;
            }

            /**
             * @param list<int> $candidateIds
             * @return array<int, true>
             */
            public static function getBlockedSet(int $blockerId, array $candidateIds): array
            {
                $ids = array_map('intval', $candidateIds);
                self::$getBlockedSetCalls[] = [$blockerId, $ids];

                $out = [];
                foreach ($ids as $id) {
                    foreach (self::$blocking as [$a, $b]) {
                        if ($a === $blockerId && $b === $id) {
                            $out[$id] = true;
                        }
                    }
                }
                return $out;
            }
        }
    }
}

namespace {
    if (!function_exists('wp_strip_all_tags')) {
        function wp_strip_all_tags(string $text, bool $removeBreaks = false): string
        {
            return trim(strip_tags($text));
        }
    }
}
