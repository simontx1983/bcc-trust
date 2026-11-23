<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Services\Vote\VoteJobDispatcher;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The `bcc_trust_async_edge_recalculate` job contains its own failures (a
 * throw never ends a wp-cron tick), and retries a failure at +5 min, then
 * +30 min, before it gives up. Only the give-up is recorded on
 * `post_commit_task / vote_subtask_failed`; a retry that cannot be booked is
 * recorded on `cron_dispatch` by scheduleRetry(). The real edge read and write
 * are pinned against MySQL by TrustEdgeVoteIntegrityIntegrationTest.
 *
 * Separate processes: the page resolver, the scheduler, the metric and the
 * logger are declared here as doubles (first declaration wins).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversMethod(VoteJobDispatcher::class, 'handleEdgeRecalculate')]
#[CoversMethod(VoteJobDispatcher::class, 'handleEdgeRetry')]
final class VoteEdgeRecalcJobTest extends TestCase
{
    private const VOTER = 41;
    private const PAGE  = 900;

    protected function setUp(): void
    {
        if (!class_exists('BCC\\Trust\\Core\\Services\\PeepSoPageResolver', false)) {
            eval(<<<'PHP'
                namespace BCC\Trust\Core\Services;
                class PeepSoPageResolver
                {
                    public static function getOwnerId(int $pageId): int
                    {
                        $owner = $GLOBALS['__bcc_edge_job']['owner'];
                        if ($owner instanceof \Throwable) {
                            throw $owner;
                        }
                        return (int) $owner;
                    }
                }
                PHP);
        }
        if (!class_exists('BCC\\Core\\Observability\\DegradationMetrics', false)) {
            eval(<<<'PHP'
                namespace BCC\Core\Observability;
                final class DegradationMetrics
                {
                    public static function record(string $subsystem, string $event = 'activation'): void
                    {
                        $GLOBALS['__bcc_edge_job']['metrics'][] = [$subsystem, $event];
                    }
                }
                PHP);
        }
        if (!class_exists('BCC\\Core\\Log\\Logger', false)) {
            eval(<<<'PHP'
                namespace BCC\Core\Log;
                final class Logger
                {
                    /** @param array<string, mixed> $c */
                    public static function error(string $m, array $c = []): void
                    {
                        $GLOBALS['__bcc_edge_job']['logs'][] = ['error', $m];
                    }

                    /** @param array<string, mixed> $c */
                    public static function warning(string $m, array $c = []): void
                    {
                        $GLOBALS['__bcc_edge_job']['logs'][] = ['warning', $m];
                    }
                }
                PHP);
        }
        if (!function_exists('wp_schedule_single_event')) {
            eval(<<<'PHP'
                function wp_schedule_single_event(int $timestamp, string $hook, array $args = [])
                {
                    $GLOBALS['__bcc_edge_job']['scheduled'][] = ['at' => $timestamp, 'hook' => $hook, 'args' => $args];
                    return $GLOBALS['__bcc_edge_job']['schedule_ok'] ? true : false;
                }
                PHP);
        }
        $GLOBALS['__bcc_edge_job'] = [
            'owner'       => 0,
            'metrics'     => [],
            'logs'        => [],
            'scheduled'   => [],
            'schedule_ok' => true,
        ];
    }

    /** @return list<string> */
    private static function logLevels(): array
    {
        return array_map(static fn (array $l): string => $l[0], $GLOBALS['__bcc_edge_job']['logs']);
    }

    private static function failing(): void
    {
        $GLOBALS['__bcc_edge_job']['owner'] = new \RuntimeException('read did not run');
    }

    public function testAFirstFailureIsContainedAndRetriedInFiveMinutes(): void
    {
        self::failing();
        $before = time();

        VoteJobDispatcher::handleEdgeRecalculate(self::VOTER, self::PAGE);

        $scheduled = $GLOBALS['__bcc_edge_job']['scheduled'];
        self::assertCount(1, $scheduled);
        self::assertSame(VoteJobDispatcher::EDGE_RETRY_HOOK, $scheduled[0]['hook']);
        self::assertSame([self::VOTER, self::PAGE, 1], $scheduled[0]['args'], 'retry 1');
        self::assertEqualsWithDelta($before + 300, $scheduled[0]['at'], 5);
        self::assertSame([], $GLOBALS['__bcc_edge_job']['metrics'], 'not a give-up yet');
        self::assertSame(['warning'], self::logLevels());
    }

    public function testASecondFailureRetriesInThirtyMinutes(): void
    {
        self::failing();
        $before = time();

        VoteJobDispatcher::handleEdgeRetry(self::VOTER, self::PAGE, 1);

        $scheduled = $GLOBALS['__bcc_edge_job']['scheduled'];
        self::assertCount(1, $scheduled);
        self::assertSame([self::VOTER, self::PAGE, 2], $scheduled[0]['args'], 'retry 2');
        self::assertEqualsWithDelta($before + 1800, $scheduled[0]['at'], 5);
        self::assertSame([], $GLOBALS['__bcc_edge_job']['metrics']);
    }

    public function testTheLastFailureGivesUpAndRecordsIt(): void
    {
        self::failing();

        VoteJobDispatcher::handleEdgeRetry(self::VOTER, self::PAGE, 2);

        self::assertSame([], $GLOBALS['__bcc_edge_job']['scheduled'], 'no third retry');
        self::assertSame([['post_commit_task', 'vote_subtask_failed']], $GLOBALS['__bcc_edge_job']['metrics']);
        self::assertSame(['error'], self::logLevels());
    }

    public function testARetryThatCannotBeBookedIsRecordedAsADispatchMiss(): void
    {
        self::failing();
        $GLOBALS['__bcc_edge_job']['schedule_ok'] = false;

        VoteJobDispatcher::handleEdgeRecalculate(self::VOTER, self::PAGE);

        self::assertSame([['cron_dispatch', 'vote_job_dispatcher']], $GLOBALS['__bcc_edge_job']['metrics']);
    }

    /** No owner, or the voter's own page: nothing to recalculate, nothing recorded or booked. */
    public function testNoOwnerOrTheVotersOwnPageIsANoOp(): void
    {
        foreach ([0, self::VOTER] as $owner) {
            $GLOBALS['__bcc_edge_job']['owner'] = $owner;
            VoteJobDispatcher::handleEdgeRecalculate(self::VOTER, self::PAGE);
            VoteJobDispatcher::handleEdgeRetry(self::VOTER, self::PAGE, 1);
        }

        self::assertSame([], $GLOBALS['__bcc_edge_job']['metrics']);
        self::assertSame([], $GLOBALS['__bcc_edge_job']['logs']);
        self::assertSame([], $GLOBALS['__bcc_edge_job']['scheduled']);
    }
}
