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
 * Fixture shape ($GLOBALS['__bcc_drain']):
 *   rows        array<int, object>  id => follow row (the "table")
 *   fail_follow list<int>           viewer ids whose follow() returns 0
 *   throw_follow list<int>          viewer ids whose follow() throws
 *   reads       int                 findByPageId() call count
 *   deleted     list<int>           ids passed to deleteByIds()
 *   by_page_id  int                 deleteByPageId() call count (MUST stay 0)
 *   meta        list<int>           follow ids given watch-meta rows
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

            /** @return list<object> */
            public function findByPageId(int $pageId): array
            {
                $GLOBALS['__bcc_drain']['reads']++;

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

            /** @param list<int> $ids */
            public function deleteByIds(array $ids): int
            {
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

namespace BCC\Core\Log {
    if (!class_exists(Logger::class, false)) {
        final class Logger
        {
            /** @param array<string, mixed> $ctx */
            public static function error(string $m, array $ctx = []): void {}
            /** @param array<string, mixed> $ctx */
            public static function warning(string $m, array $ctx = []): void {}
            /** @param array<string, mixed> $ctx */
            public static function info(string $m, array $ctx = []): void
            {
                $GLOBALS['__bcc_drain']['info'][] = $ctx;
            }
        }
    }
}
