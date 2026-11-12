<?php
/**
 * Fixture-backed stubs for PageFollowDrainTest.
 *
 * Loaded ONLY inside a PHPUnit subprocess (RunTestsInSeparateProcesses).
 * Fakes Plugin::instance()'s two repositories, the PeepSo follow writer and
 * the logger at their FQNs, so the REAL
 * PeepSoIntegration::migratePageFollowersToPeepSo() loop runs against an
 * in-memory follow table.
 *
 * ⚠ The two database-failure knobs model the REAL return contracts, not
 * convenient ones: `findByPageId()` THROWS RepositoryException (it cannot
 * return a sentinel, because `[]` already means "no followers"), and
 * `deleteByIds()` returns `false` (it cannot return 0, because 0 already
 * means "matched nothing"). A stub that softened either would make the fix
 * untestable in the exact place it was needed.
 *
 * Fixture shape ($GLOBALS['__bcc_drain']):
 *   rows           array<int, object>  id => follow row (the "table")
 *   fail_follow    list<int>           viewer ids whose follow() returns 0
 *   throw_follow   list<int>           viewer ids whose follow() throws
 *   fail_read      int                 pass number whose read throws (0 = never)
 *   fail_delete    int                 pass number whose delete fails (0 = never)
 *   delete_matches_nothing bool        delete succeeds but removes no rows
 *   reads          int                 findByPageId() call count
 *   follows        int                 PeepSoFollowWriter::follow() call count
 *   delete_calls   list<int>           handled-id counts passed to deleteByIds()
 *   deleted        list<int>           ids actually removed
 *   by_page_id     int                 deleteByPageId() call count (MUST stay 0)
 *   meta           list<int>           follow ids given watch-meta rows
 *   info           list<array>         Logger::info contexts
 *   errors         list<array>         Logger::error [message, context]
 */

declare(strict_types=1);

namespace {
    if (!function_exists('__bcc_drain_state')) {
        function __bcc_drain_state(): array { return $GLOBALS['__bcc_drain']; }
    }
}

namespace BCC\Trust\Core\Repositories {
    if (!class_exists(PageFollowRepository::class, false)) {
        final class PageFollowRepository
        {
            public const DRAIN_PAGE_SIZE = 5000;

            /**
             * @return list<object>
             * @throws \BCC\Trust\Core\Exceptions\RepositoryException
             */
            public function findByPageId(int $pageId): array
            {
                $GLOBALS['__bcc_drain']['reads']++;

                if ((int) ($GLOBALS['__bcc_drain']['fail_read'] ?? 0) === $GLOBALS['__bcc_drain']['reads']) {
                    // The real repository throws rather than returning [],
                    // which is the whole point: `[]` is indistinguishable
                    // from a page with no followers.
                    throw new \BCC\Trust\Core\Exceptions\RepositoryException('page_follows_read_failed');
                }

                $out = [];
                foreach ($GLOBALS['__bcc_drain']['rows'] as $row) {
                    if ((int) $row->page_id === $pageId) {
                        $out[] = $row;
                    }
                    if (count($out) >= self::DRAIN_PAGE_SIZE) {
                        break;
                    }
                }

                return $out;
            }

            /**
             * @param list<int> $ids
             * @return int|false
             */
            public function deleteByIds(array $ids): int|false
            {
                $GLOBALS['__bcc_drain']['delete_calls'][] = count($ids);

                if ((int) ($GLOBALS['__bcc_drain']['fail_delete'] ?? 0) === count($GLOBALS['__bcc_drain']['delete_calls'])) {
                    // A failed DELETE removes NOTHING. Every row the caller
                    // just handled is still in the table.
                    return false;
                }

                if (!empty($GLOBALS['__bcc_drain']['delete_matches_nothing'])) {
                    // The other zero: the statement ran and matched no rows.
                    // Indistinguishable from the line above before the fix.
                    return 0;
                }

                $n = 0;
                foreach ($ids as $id) {
                    $id = (int) $id;
                    $GLOBALS['__bcc_drain']['deleted'][] = $id;
                    if (isset($GLOBALS['__bcc_drain']['rows'][$id])) {
                        unset($GLOBALS['__bcc_drain']['rows'][$id]);
                        $n++;
                    }
                }

                return $n;
            }

            /** ⚠ The drain must NEVER call this — it would delete unhandled rows. */
            public function deleteByPageId(int $pageId): int
            {
                $GLOBALS['__bcc_drain']['by_page_id']++;
                foreach ($GLOBALS['__bcc_drain']['rows'] as $id => $row) {
                    if ((int) $row->page_id === $pageId) {
                        unset($GLOBALS['__bcc_drain']['rows'][$id]);
                    }
                }

                return 0;
            }
        }
    }

    if (!class_exists(WatchMetaRepository::class, false)) {
        final class WatchMetaRepository
        {
            public function find(int $followId): ?object { return null; }

            public function insert(int $followId, ?string $tier, ?int $batchId): bool
            {
                $GLOBALS['__bcc_drain']['meta'][] = $followId;
                return true;
            }
        }
    }
}

namespace BCC\Trust\Core {
    if (!class_exists(Plugin::class, false)) {
        final class Plugin
        {
            private static ?self $instance = null;

            public static function instance(): self
            {
                return self::$instance ??= new self();
            }

            public function pageFollowRepository(): \BCC\Trust\Core\Repositories\PageFollowRepository
            {
                return new \BCC\Trust\Core\Repositories\PageFollowRepository();
            }

            public function watchMetaRepository(): \BCC\Trust\Core\Repositories\WatchMetaRepository
            {
                return new \BCC\Trust\Core\Repositories\WatchMetaRepository();
            }
        }
    }
}

namespace BCC\Core\PeepSo {
    if (!class_exists(PeepSoFollowWriter::class, false)) {
        final class PeepSoFollowWriter
        {
            public static function follow(int $viewerId, int $authorId): int
            {
                $GLOBALS['__bcc_drain']['follows']++;

                if (in_array($viewerId, $GLOBALS['__bcc_drain']['throw_follow'] ?? [], true)) {
                    throw new \RuntimeException('simulated PeepSo failure');
                }
                if (in_array($viewerId, $GLOBALS['__bcc_drain']['fail_follow'] ?? [], true)) {
                    return 0;
                }

                return 9000 + $viewerId;
            }
        }
    }
}

/**
 * F-29: the drain asks bcc-core whether the viewer and the claimer are
 * blocked (either direction) BEFORE following. bcc-core is not autoloaded
 * in the unit suite, so the read is stubbed here. Default: not blocked.
 *   blocked        list<int>  viewer ids whose pair with the claimer is blocked
 *   block_unknown  list<int>  viewer ids whose block state is unreadable (null)
 *   block_reads    int        call count
 */
namespace BCC\Core\Repositories {
    if (!class_exists(PeepSoBlockRepository::class, false)) {
        final class PeepSoBlockRepository
        {
            public static function isMutuallyBlockedOrUnknown(int $userA, int $userB): ?bool
            {
                $GLOBALS['__bcc_drain']['block_reads']++;

                if (in_array($userA, $GLOBALS['__bcc_drain']['block_unknown'] ?? [], true)) {
                    return null;
                }

                return in_array($userA, $GLOBALS['__bcc_drain']['blocked'] ?? [], true);
            }
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(Logger::class, false)) {
        final class Logger
        {
            /** @param array<string, mixed> $ctx */
            public static function error(string $m, array $ctx = []): void
            {
                $GLOBALS['__bcc_drain']['errors'][] = ['message' => $m, 'ctx' => $ctx];
            }
            /** @param array<string, mixed> $ctx */
            public static function warning(string $m, array $ctx = []): void
            {
                $GLOBALS['__bcc_drain']['warnings'][] = ['message' => $m, 'ctx' => $ctx];
            }
            /** @param array<string, mixed> $ctx */
            public static function info(string $m, array $ctx = []): void
            {
                $GLOBALS['__bcc_drain']['info'][] = $ctx;
            }
        }
    }
}

/**
 * ⚠ Needed by the RETRY entry point (`onClaimFollowersDrain`), not by the
 * drain itself: the retry reads `post_author` to refuse a claimer who is not
 * the page author, so without this the non-owner guard could not be
 * exercised and would have had to be taken on trust.
 *
 * Defined in PeepSoIntegration's own namespace, which is where PHP resolves
 * an unqualified call before falling back to global - the same convention
 * page-claim-dispatch-stubs.php uses for PagesEndpoint.
 */
namespace BCC\Trust\Core\Integration {
    if (!function_exists('BCC\Trust\Core\Integration\get_post_field')) {
        /**
         * @param  int|string $postId
         * @return mixed
         */
        function get_post_field(string $field, $postId = 0)
        {
            $GLOBALS['__bcc_drain']['author_reads']++;

            return $GLOBALS['__bcc_drain']['post_author'][(int) $postId] ?? 0;
        }
    }
}
