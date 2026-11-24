<?php

declare(strict_types=1);

/**
 * RelationshipLogWriterTest: recording fakes. Load only in a separate
 * process, from setUp(), BEFORE the writer is autoloaded. Every call is
 * appended to WriterWorld::$events, so the test reads the exact order.
 */

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', sys_get_temp_dir() . '/');
    }

    final class WriterWorld
    {
        /** @var list<string> */
        public static array $events = [];
        public static bool $lockFree = true;
        public static bool $appendFails = false;
        public static bool $commitFails = false;
    }
}

namespace BCC\Core\DB {
    final class AdvisoryLock
    {
        public static function acquire(string $key, int $timeout = 0): bool
        {
            \WriterWorld::$events[] = 'acquire:' . $key;
            return \WriterWorld::$lockFree;
        }

        public static function release(string $key): void
        {
            \WriterWorld::$events[] = 'release:' . $key;
        }
    }
}

namespace BCC\Trust\Core\Security {
    final class TransactionManager
    {
        public static function run(callable $callback, ?int $retryAttempts = null): mixed
        {
            \WriterWorld::$events[] = 'begin';
            try {
                $result = $callback();
            } catch (\Throwable $e) {
                \WriterWorld::$events[] = 'rollback';
                throw $e;
            }
            if (\WriterWorld::$commitFails) {
                \WriterWorld::$events[] = 'rollback';
                throw new \RuntimeException('commit failed');
            }
            \WriterWorld::$events[] = 'commit';
            return $result;
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {
    final class ValidatorRelationshipLogRepository
    {
        public const LOCK_NAME = 'bcc_vrl_append';

        /**
         * @param array<string, mixed> $event
         * @return array{id: int, seq: int, row_hash: string}
         */
        public static function append(array $event): array
        {
            \WriterWorld::$events[] = 'append';
            if (\WriterWorld::$appendFails) {
                throw new \RuntimeException('append failed');
            }
            return ['id' => 1, 'seq' => 7, 'row_hash' => str_repeat('a', 64)];
        }
    }
}

namespace BCC\Trust\Onchain\Support {
    final class RelationshipLogAnchor
    {
        public static function advance(int $seq, string $rowHash): void
        {
            \WriterWorld::$events[] = 'advance:' . $seq;
        }
    }
}

namespace BCC\Core\Observability {
    final class DegradationMetrics
    {
        public static function record(string $subsystem, string $event = 'activation'): void
        {
            \WriterWorld::$events[] = 'metric:' . $event;
        }
    }
}

namespace BCC\Core\Log {
    final class Logger
    {
        /** @param array<string, mixed> $c */
        public static function error(string $m, array $c = []): void {}
    }
}
