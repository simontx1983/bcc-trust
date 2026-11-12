<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Services\Vote\Tests;

use BCC\Trust\Core\Services\Vote\VoteJobDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-107 follow-up: a post-vote job whose DISPATCH was missed — the enqueue
 * failed, or the dispatch lock timed out and the job is not actually
 * scheduled — is retried as a whole, once, after 5 minutes. Before, every
 * post-vote sub-task (fraud, trust graph, stats) was lost with only a log.
 *
 * Separate processes + eval'd stubs for the same reason as
 * VoteFraudAnalysisRetryTest: several stub files define these globals
 * differently, so a shared process depends on load order.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(VoteJobDispatcher::class)]
final class VotePostVoteDispatchRetryTest extends TestCase
{
    private const VOTE = 7202;

    protected function setUp(): void
    {
        $GLOBALS['__pv'] = [
            'enqueue_return' => 101,   // as_enqueue_async_action result (0 = soft failure)
            'has_scheduled'  => false, // as_has_scheduled_action result
            'lock'           => true,  // AdvisoryLock::acquire result
            'scheduled'      => [],    // as_schedule_single_action calls
            'fired'          => [],    // do_action hooks
            'metrics'        => [],
        ];

        foreach ([
            'as_enqueue_async_action'   => 'function as_enqueue_async_action(string $h, $a = [], string $g = ""): int { return $GLOBALS["__pv"]["enqueue_return"]; }',
            'as_has_scheduled_action'   => 'function as_has_scheduled_action(string $h, $a = [], string $g = ""): bool { return $GLOBALS["__pv"]["has_scheduled"]; }',
            'as_schedule_single_action' => 'function as_schedule_single_action(int $at, string $h, array $a = [], string $g = ""): int { $GLOBALS["__pv"]["scheduled"][] = ["at" => $at, "hook" => $h, "args" => $a]; return 7; }',
            'do_action'                 => 'function do_action(string $h, ...$a): void { $GLOBALS["__pv"]["fired"][] = $h; }',
            'wp_json_encode'            => 'function wp_json_encode($d): string { return (string) json_encode($d); }',
        ] as $name => $code) {
            if (!function_exists($name)) {
                eval($code);
            }
        }
        if (!class_exists('BCC\\Core\\DB\\AdvisoryLock', false)) {
            eval('namespace BCC\\Core\\DB; final class AdvisoryLock { public static function acquire(string $k, int $t = 0): bool { return $GLOBALS["__pv"]["lock"]; } public static function release(string $k): void {} }');
        }
        if (!class_exists('BCC\\Core\\Observability\\DegradationMetrics', false)) {
            eval('namespace BCC\\Core\\Observability; final class DegradationMetrics { public static function record(string $s, string $e = "activation"): void { $GLOBALS["__pv"]["metrics"][] = [$s, $e]; } }');
        }
        if (!class_exists('BCC\\Core\\Log\\Logger', false)) {
            eval('namespace BCC\\Core\\Log; final class Logger { public static function error(string $m, array $c = []): void {} public static function info(string $m, array $c = []): void {} public static function warning(string $m, array $c = []): void {} }');
        }
    }

    /** @return list<array{at:int,hook:string,args:array<int,mixed>}> */
    private function postVoteRetries(): array
    {
        return array_values(array_filter(
            $GLOBALS['__pv']['scheduled'],
            static fn (array $s): bool => $s['hook'] === VoteJobDispatcher::POST_VOTE_RETRY_HOOK
        ));
    }

    public function testAFailedEnqueueSchedulesAPostVoteRetry(): void
    {
        $GLOBALS['__pv']['enqueue_return'] = 0;
        $before = time();

        VoteJobDispatcher::dispatch(self::VOTE);

        $retries = $this->postVoteRetries();
        self::assertCount(1, $retries);
        self::assertSame([self::VOTE], $retries[0]['args']);
        self::assertGreaterThanOrEqual($before + 300, $retries[0]['at']);
        self::assertContains(['cron_dispatch', 'vote_job_dispatcher'], $GLOBALS['__pv']['metrics'], 'the miss is still metered');
    }

    public function testALockTimeoutWithNothingScheduledIsAMissAndRetries(): void
    {
        $GLOBALS['__pv']['lock']          = false;
        $GLOBALS['__pv']['has_scheduled'] = false;

        VoteJobDispatcher::dispatch(self::VOTE);

        self::assertCount(1, $this->postVoteRetries(), 'the lock holder is no longer just assumed to have enqueued it');
    }

    public function testALockTimeoutWithTheJobScheduledIsNotAMiss(): void
    {
        $GLOBALS['__pv']['lock']          = false;
        $GLOBALS['__pv']['has_scheduled'] = true;

        VoteJobDispatcher::dispatch(self::VOTE);

        self::assertSame([], $this->postVoteRetries());
    }

    public function testASuccessfulOrAlreadyScheduledDispatchSchedulesNoRetry(): void
    {
        VoteJobDispatcher::dispatch(self::VOTE);
        $GLOBALS['__pv']['has_scheduled'] = true;
        VoteJobDispatcher::dispatch(self::VOTE);

        self::assertSame([], $this->postVoteRetries());
    }

    public function testAMissedRemovalDispatchDoesNotScheduleAPostVoteRetry(): void
    {
        $GLOBALS['__pv']['enqueue_return'] = 0;

        VoteJobDispatcher::dispatchRemoval(11, 22);

        self::assertSame([], $this->postVoteRetries(), 'only the post-vote job is retried');
    }

    public function testThePostVoteRetryRunsEverySubTaskInline(): void
    {
        VoteJobDispatcher::handlePostVoteRetry(self::VOTE);

        self::assertSame(
            ['bcc_trust_async_fraud_analysis', 'bcc_trust_async_trust_graph_update', 'bcc_trust_async_stats_refresh'],
            $GLOBALS['__pv']['fired']
        );
        self::assertSame([], $GLOBALS['__pv']['scheduled'], 'running inline enqueues nothing that could be missed again');
    }
}
