<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Services\Vote\Tests;

use BCC\Trust\Core\Services\Vote\VoteFraudAnalyzer;
use BCC\Trust\Core\Services\Vote\VoteJobDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-107: a failed post-vote fraud analysis is RETRIED, not dropped.
 *
 * handlePostVote() runs three sub-tasks; trust-graph and stats recover via
 * recurring sweeps, fraud analysis has none, so a throw inside it used to
 * be logged and lost. It now schedules a delayed retry (5, 10, 20, 40, 80
 * min) and gives up loudly after FRAUD_RETRY_MAX_ATTEMPTS.
 *
 * ── Why separate processes, and stubs declared in setUp()
 * A global do_action() is defined by many stub files with different
 * behaviour, so the winner in a shared process depends on load order.
 * Declaring ours via eval() inside setUp() means it exists only in this
 * suite's child processes (same technique as FraudInputFixesTest).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(VoteJobDispatcher::class)]
final class VoteFraudAnalysisRetryTest extends TestCase
{
    private const VOTE = 7101;

    protected function setUp(): void
    {
        $GLOBALS['__fr_throw']     = [];   // hook => true: that hook's handler throws
        $GLOBALS['__fr_fired']     = [];   // list<string> hooks fired
        $GLOBALS['__fr_scheduled'] = [];   // list<array{at:int,hook:string,args:array}>
        $GLOBALS['__fr_schedule_ok'] = true;
        $GLOBALS['__fr_metrics']   = [];

        foreach ([
            'do_action' => 'function do_action(string $hook, ...$args): void { $GLOBALS["__fr_fired"][] = $hook; if (!empty($GLOBALS["__fr_throw"][$hook])) { throw new \RuntimeException("deadlock in " . $hook); } }',
            'as_schedule_single_action' => 'function as_schedule_single_action(int $at, string $hook, array $args = [], string $group = ""): int { if (!$GLOBALS["__fr_schedule_ok"]) { return 0; } $GLOBALS["__fr_scheduled"][] = ["at" => $at, "hook" => $hook, "args" => $args]; return 99; }',
        ] as $name => $code) {
            if (!function_exists($name)) {
                eval($code);
            }
        }
        if (!class_exists('BCC\\Core\\Observability\\DegradationMetrics', false)) {
            eval('namespace BCC\\Core\\Observability; final class DegradationMetrics { public static function record(string $s, string $e = "activation"): void { $GLOBALS["__fr_metrics"][] = [$s, $e]; } }');
        }
        if (!class_exists('BCC\\Core\\Log\\Logger', false)) {
            eval('namespace BCC\\Core\\Log; final class Logger { public static function error(string $m, array $c = []): void {} public static function info(string $m, array $c = []): void {} public static function warning(string $m, array $c = []): void {} }');
        }
    }

    public function testAFailedFraudStageSchedulesTheFirstRetryAndTheOtherTasksStillRun(): void
    {
        $GLOBALS['__fr_throw'][VoteFraudAnalyzer::HOOK] = true;
        $before = time();

        VoteJobDispatcher::handlePostVote(self::VOTE);

        self::assertSame(
            [VoteFraudAnalyzer::HOOK, 'bcc_trust_async_trust_graph_update', 'bcc_trust_async_stats_refresh'],
            $GLOBALS['__fr_fired'],
            'one failure must not block the rest'
        );
        self::assertCount(1, $GLOBALS['__fr_scheduled']);
        $retry = $GLOBALS['__fr_scheduled'][0];
        self::assertSame(VoteJobDispatcher::FRAUD_RETRY_HOOK, $retry['hook']);
        self::assertSame([self::VOTE, 1], $retry['args']);
        self::assertGreaterThanOrEqual($before + 300, $retry['at'], 'first retry after 5 minutes');
        self::assertLessThanOrEqual(time() + 300, $retry['at']);
    }

    public function testOnlyTheFraudStageIsRetried(): void
    {
        $GLOBALS['__fr_throw']['bcc_trust_async_trust_graph_update'] = true;
        $GLOBALS['__fr_throw']['bcc_trust_async_stats_refresh']      = true;

        VoteJobDispatcher::handlePostVote(self::VOTE);

        self::assertSame([], $GLOBALS['__fr_scheduled'], 'trust graph and stats recover via their sweeps');
    }

    public function testASuccessfulRunSchedulesNothing(): void
    {
        VoteJobDispatcher::handlePostVote(self::VOTE);
        VoteJobDispatcher::handleFraudRetry(self::VOTE, 2);

        self::assertSame([], $GLOBALS['__fr_scheduled']);
    }

    public function testAFailedRetryReschedulesWithDoubledBackoff(): void
    {
        $GLOBALS['__fr_throw'][VoteFraudAnalyzer::HOOK] = true;
        $before = time();

        VoteJobDispatcher::handleFraudRetry(self::VOTE, 3);

        self::assertSame([VoteFraudAnalyzer::HOOK], $GLOBALS['__fr_fired'], 'the retry re-runs only the fraud stage');
        self::assertCount(1, $GLOBALS['__fr_scheduled']);
        self::assertSame([self::VOTE, 4], $GLOBALS['__fr_scheduled'][0]['args']);
        self::assertGreaterThanOrEqual($before + 300 * 8, $GLOBALS['__fr_scheduled'][0]['at'], 'attempt 4 waits 40 minutes');
        self::assertContains(['post_commit_task', 'vote_subtask_failed'], $GLOBALS['__fr_metrics']);
    }

    public function testRetriesStopAtTheCap(): void
    {
        $GLOBALS['__fr_throw'][VoteFraudAnalyzer::HOOK] = true;

        VoteJobDispatcher::handleFraudRetry(self::VOTE, VoteJobDispatcher::FRAUD_RETRY_MAX_ATTEMPTS);

        self::assertSame([], $GLOBALS['__fr_scheduled'], 'no retry after the last attempt');
        self::assertContains(['post_commit_task', 'vote_subtask_failed'], $GLOBALS['__fr_metrics']);
    }

    public function testAFailedRetryScheduleIsRecorded(): void
    {
        $GLOBALS['__fr_throw'][VoteFraudAnalyzer::HOOK] = true;
        $GLOBALS['__fr_schedule_ok'] = false;

        VoteJobDispatcher::handlePostVote(self::VOTE);

        self::assertContains(['cron_dispatch', 'vote_job_dispatcher'], $GLOBALS['__fr_metrics']);
    }
}
