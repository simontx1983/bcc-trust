<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Services\Vote\VoteJobDispatcher;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The `bcc_trust_async_edge_recalculate` job contains its own failures: it
 * records `post_commit_task / vote_subtask_failed`, logs, and returns, so a
 * failed recalc never ends a wp-cron tick. The real edge read and write are
 * pinned against MySQL by TrustEdgeVoteIntegrityIntegrationTest.
 *
 * Separate processes: the page resolver, the metric and the logger are
 * declared here as doubles (first declaration wins).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversMethod(VoteJobDispatcher::class, 'handleEdgeRecalculate')]
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
                        $GLOBALS['__bcc_edge_job']['logs'][] = $m;
                    }
                }
                PHP);
        }
        $GLOBALS['__bcc_edge_job'] = ['owner' => 0, 'metrics' => [], 'logs' => []];
    }

    public function testAFailureIsRecordedLoggedAndContained(): void
    {
        $GLOBALS['__bcc_edge_job']['owner'] = new \RuntimeException('read did not run');

        VoteJobDispatcher::handleEdgeRecalculate(self::VOTER, self::PAGE);

        self::assertSame([['post_commit_task', 'vote_subtask_failed']], $GLOBALS['__bcc_edge_job']['metrics']);
        self::assertCount(1, $GLOBALS['__bcc_edge_job']['logs']);
    }

    /** No owner, or the voter's own page: nothing to recalculate, nothing recorded. */
    public function testNoOwnerOrTheVotersOwnPageIsANoOp(): void
    {
        foreach ([0, self::VOTER] as $owner) {
            $GLOBALS['__bcc_edge_job']['owner'] = $owner;
            VoteJobDispatcher::handleEdgeRecalculate(self::VOTER, self::PAGE);
        }

        self::assertSame([], $GLOBALS['__bcc_edge_job']['metrics']);
        self::assertSame([], $GLOBALS['__bcc_edge_job']['logs']);
    }
}
