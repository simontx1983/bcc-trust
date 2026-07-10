<?php

declare(strict_types=1);

// ── Global ActionScheduler + WP shims (state-driven so tests control returns) ──
namespace {

    if (!class_exists('BccVoteDispatchState', false)) {
        final class BccVoteDispatchState
        {
            /** Return value of the faked as_enqueue_async_action (0 = soft failure). */
            public static int $asEnqueueReturn = 100;
            /** Whether the job already appears scheduled (dedupe short-circuit). */
            public static bool $asHasScheduled = false;
            /** @var list<array{0:string,1:string}> recorded DegradationMetrics */
            public static array $metrics = [];

            public static function reset(): void
            {
                self::$asEnqueueReturn = 100;
                self::$asHasScheduled  = false;
                self::$metrics         = [];
            }
        }
    }

    if (!function_exists('wp_json_encode')) {
        /** @param mixed $data */
        function wp_json_encode($data): string
        {
            return (string) json_encode($data);
        }
    }

    if (!function_exists('as_has_scheduled_action')) {
        /** @param mixed $args */
        function as_has_scheduled_action(string $hook, $args = [], string $group = ''): bool
        {
            return \BccVoteDispatchState::$asHasScheduled;
        }
    }

    if (!function_exists('as_enqueue_async_action')) {
        /** @param mixed $args */
        function as_enqueue_async_action(string $hook, $args = [], string $group = ''): int
        {
            return \BccVoteDispatchState::$asEnqueueReturn;
        }
    }
}

// ── bcc-core collaborators (guarded; DegradationMetrics is the assertion spy) ──
namespace BCC\Core\DB {
    if (!class_exists(__NAMESPACE__ . '\\AdvisoryLock', false)) {
        final class AdvisoryLock
        {
            public static function acquire(string $key, int $timeout = 0): bool
            {
                return true;
            }

            public static function release(string $key): void
            {
            }
        }
    }
}

namespace BCC\Core\Log {
    // May also be defined (no-op) by another in-process test; guarded, and this
    // suite deliberately does NOT assert on it (the winning definition varies
    // with test-load order). The metric spy below is the sole assertion.
    if (!class_exists(__NAMESPACE__ . '\\Logger', false)) {
        final class Logger
        {
            /** @param array<string,mixed> $c */
            public static function error(string $m, array $c = []): void
            {
            }

            /** @param array<string,mixed> $c */
            public static function info(string $m, array $c = []): void
            {
            }

            /** @param array<string,mixed> $c */
            public static function warning(string $m, array $c = []): void
            {
            }
        }
    }
}

namespace BCC\Core\Observability {
    if (!class_exists(__NAMESPACE__ . '\\DegradationMetrics', false)) {
        final class DegradationMetrics
        {
            public static function record(string $subsystem, string $event = 'activation'): void
            {
                \BccVoteDispatchState::$metrics[] = [$subsystem, $event];
            }
        }
    }
}

// ── The test ──────────────────────────────────────────────────────────────
namespace BCC\Trust\Core\Services\Vote\Tests {

    use BCC\Trust\Core\Services\Vote\VoteJobDispatcher;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\TestCase;

    /**
     * Pins the Action Scheduler dispatch path's failure observability.
     *
     * The AS path (the primary path in production) previously discarded
     * as_enqueue_async_action()'s return, so a soft enqueue failure stranded
     * every post-vote sub-task — fraud analysis, trust graph, stats — with no
     * metric and no log, unlike the wp-cron fallback. This suite fails red if
     * that silent-loss regresses: a failed enqueue MUST record the
     * cron_dispatch/vote_job_dispatcher DegradationMetric (the exact subsystem
     * the bcc-core health map already documents for "AS returning 0").
     */
    #[CoversClass(VoteJobDispatcher::class)]
    final class VoteJobDispatcherTest extends TestCase
    {
        protected function setUp(): void
        {
            \BccVoteDispatchState::reset();
        }

        public function testFailedAsEnqueueRecordsCronDispatchMetric(): void
        {
            \BccVoteDispatchState::$asHasScheduled  = false;
            \BccVoteDispatchState::$asEnqueueReturn = 0; // Action Scheduler soft failure

            VoteJobDispatcher::dispatch(4242);

            self::assertContains(
                ['cron_dispatch', 'vote_job_dispatcher'],
                \BccVoteDispatchState::$metrics,
                'a failed AS enqueue must record the cron_dispatch/vote_job_dispatcher metric'
            );
        }

        public function testSuccessfulEnqueueRecordsNothing(): void
        {
            \BccVoteDispatchState::$asHasScheduled  = false;
            \BccVoteDispatchState::$asEnqueueReturn = 555; // real action id

            VoteJobDispatcher::dispatch(4242);

            self::assertSame([], \BccVoteDispatchState::$metrics, 'a successful enqueue is silent');
        }

        public function testAlreadyScheduledRecordsNothing(): void
        {
            \BccVoteDispatchState::$asHasScheduled = true; // dedupe short-circuit — never enqueues

            VoteJobDispatcher::dispatch(4242);

            self::assertSame([], \BccVoteDispatchState::$metrics, 'the dedupe path is not a failure');
        }
    }
}
