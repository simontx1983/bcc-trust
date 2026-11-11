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

            /**
             * When true, every *OrUnknown* probe answers NULL — "the
             * block table could not be read".
             *
             * ⚠ It drives ONLY the OrUnknown twins. The legacy
             * `isBlocking`/`getBlockedSet` keep their fail-open contract
             * even while this is set, which is what lets a test assert
             * that the READ paths (which still use... nothing: they go
             * through evaluatePolicy) are unaffected.
             */
            public static bool $readFails = false;

            public static function reset(): void
            {
                self::$blocking           = [];
                self::$isBlockingCalls    = [];
                self::$getBlockedSetCalls = [];
                self::$readFails          = false;
            }

            public static function isBlockingOrUnknown(int $blockerId, int $blockedId): ?bool
            {
                if (self::$readFails) {
                    self::$isBlockingCalls[] = [$blockerId, $blockedId];
                    return null;
                }
                return self::isBlocking($blockerId, $blockedId);
            }

            /**
             * Either-direction BATCH probe (bcc-core #72).
             *
             * ⚠ Added for F-99 2b: this double never had the batch
             * method at all, so `canMessageMany()` could not be unit
             * tested through it before now.
             *
             * @param list<int> $otherIds
             * @return array<int, true>|null
             */
            public static function getMutuallyBlockedSetOrUnknown(int $userId, array $otherIds): ?array
            {
                if (self::$readFails) {
                    return null;
                }
                $out = [];
                foreach ($otherIds as $raw) {
                    $id = (int) $raw;
                    if ($id <= 0 || $id === $userId) {
                        continue;
                    }
                    foreach (self::$blocking as [$a, $b]) {
                        if (($a === $userId && $b === $id) || ($a === $id && $b === $userId)) {
                            $out[$id] = true;
                        }
                    }
                }
                return $out;
            }

            public static function isMutuallyBlockedOrUnknown(int $userA, int $userB): ?bool
            {
                if (self::$readFails) {
                    return null;
                }
                // ⚠ EITHER direction, matching the real probe's semantics
                // rather than its name. Implemented here rather than
                // delegating, because this stub has no isMutuallyBlocked()
                // to delegate to — the real class does, the double never
                // needed it before, and a delegation would have fatalled
                // on every non-failing call.
                foreach (self::$blocking as [$a, $b]) {
                    if (($a === $userA && $b === $userB) || ($a === $userB && $b === $userA)) {
                        return true;
                    }
                }
                return false;
            }

            /**
             * @param list<int> $candidateIds
             * @return array<int, true>|null
             */
            public static function getBlockedSetOrUnknown(int $blockerId, array $candidateIds): ?array
            {
                if (self::$readFails) {
                    self::$getBlockedSetCalls[] = [$blockerId, array_map('intval', $candidateIds)];
                    return null;
                }
                return self::getBlockedSet($blockerId, $candidateIds);
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

namespace BCC\Core\Repositories {
    /**
     * Driven stub of the three `PeepSoMessageRepository` primitives the
     * #122 reveal route gates on. Same reasoning as the block stub
     * above: bcc-trust's unit jobs do not autoload bcc-core, and what a
     * unit test can honestly prove here is WHICH questions the service
     * asks, in what order, and what it does with the answers.
     *
     * ⚠ `markConversationAsViewed` records its calls precisely so a
     * test can assert it was NOT called. A reveal must not clear the
     * unread badge — the viewer revealed one message, they did not read
     * the thread.
     */
    if (!class_exists('BCC\Core\Repositories\PeepSoMessageRepository', false)) {
        final class PeepSoMessageRepository
        {
            /** @var array<int, int> message or conversation id => its root conversation id */
            public static array $roots = [];

            /** @var array<string, bool> "viewerId:rootId" => is a participant */
            public static array $participants = [];

            /**
             * "viewerId:messageId" => a recipient row exists.
             *
             * ⚠ Keyed on the MESSAGE, not the conversation, because the
             * real predicate is not conversation-scoped — that is
             * precisely why the service ANDs it with its own
             * conversation check rather than relying on it.
             *
             * @var array<string, bool>
             */
            public static array $received = [];

            /** @var list<array{int, int}> every markConversationAsViewed call */
            public static array $viewedCalls = [];

            /** @var list<string> every question asked, in order */
            public static array $calls = [];

            public static function reset(): void
            {
                self::$roots        = [];
                self::$participants = [];
                self::$received     = [];
                self::$viewedCalls  = [];
                self::$calls        = [];
            }

            public static function findRootConversationId(int $msgId): int
            {
                self::$calls[] = 'findRootConversationId(' . $msgId . ')';
                return self::$roots[$msgId] ?? 0;
            }

            public static function userIsParticipant(int $userId, int $rootMsgId): bool
            {
                self::$calls[] = 'userIsParticipant(' . $userId . ',' . $rootMsgId . ')';
                return self::$participants[$userId . ':' . $rootMsgId] ?? false;
            }

            /**
             * "was this message sent to this viewer?" — the fourth gate
             * on the reveal route (bcc-core #64).
             */
            public static function viewerReceivedMessage(int $userId, int $messageId): bool
            {
                self::$calls[] = 'viewerReceivedMessage(' . $userId . ',' . $messageId . ')';
                return self::$received[$userId . ':' . $messageId] ?? false;
            }

            public static function markConversationAsViewed(int $userId, int $rootMsgId): void
            {
                self::$viewedCalls[] = [$userId, $rootMsgId];
            }
        }
    }
}

namespace {
    /**
     * ⚠ `WP_Post` and `get_post` are stubbed in several other files in
     * this directory, all `class_exists`/`function_exists`-guarded, so in
     * a shared process whichever loads first wins. The reveal tests
     * therefore run in SEPARATE PROCESSES: one of those other stubs does
     * NOT declare `post_content`, which the reveal reads, and under PHP
     * 8.2 setting it would be a dynamic-property deprecation while
     * reading it would yield an empty body. Fresh processes make these
     * definitions the ones in play.
     *
     * The backing array uses its own key so it cannot be confused with
     * another stub's store.
     */
    if (!class_exists('WP_Post', false)) {
        class WP_Post
        {
            public int $ID = 0;
            public string $post_type = '';
            public string $post_status = '';
            public int $post_author = 0;
            public string $post_content = '';
        }
    }

    if (!function_exists('get_post')) {
        /** @param mixed $id */
        function get_post($id = null)
        {
            $posts = $GLOBALS['__bcc_122_posts'] ?? [];
            return $posts[(int) $id] ?? null;
        }
    }

    if (!function_exists('wp_strip_all_tags')) {
        function wp_strip_all_tags(string $text, bool $removeBreaks = false): string
        {
            return trim(strip_tags($text));
        }
    }
}
