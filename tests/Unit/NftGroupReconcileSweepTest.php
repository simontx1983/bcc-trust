<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\NftGroupGateService;
use BCC\Trust\Onchain\Services\NftGroupReconcileSweepService;
use BCC\Trust\Onchain\Services\NftGroupRevokeService;
use BCC\Trust\Onchain\Services\ValidatorGroupRevokeService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-93: the holder-group reconcile sweep (auto-join for opted-in holders).
 *
 *  1. The cursor moves AFTER each user's work. It used to be saved before
 *     the batch, so a tick that died mid-batch skipped the rest of it.
 *  2. One user's throw is that user's: logged, counted, and the walk goes on.
 *  3. One sweep at a time, under its own advisory lock; an overlapping tick
 *     does nothing; no lock primitive fails closed.
 *
 * The real service over a scripted user read and reconciler, with every
 * observable step in one ordered event log.
 */
#[CoversClass(NftGroupReconcileSweepService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class NftGroupReconcileSweepTest extends TestCase
{
    private const LOCK = 'lock:' . NftGroupReconcileSweepService::SWEEP_LOCK . '/0';
    private const UNLOCK = 'unlock:' . NftGroupReconcileSweepService::SWEEP_LOCK;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir() . '/');
        }
        $GLOBALS['__rs'] = ['options' => [], 'events' => [], 'errors' => [], 'warnings' => [], 'held' => [], 'reads' => []];
        require_once __DIR__ . '/../Stubs/reconcile-sweep-stubs.php';
    }

    /**
     * @param list<int>        $userIds  what the opted-in read returns
     * @param array<int, int>  $joins    uid => joins
     * @param list<int>        $throwFor uids whose reconcile throws
     */
    private function service(array $userIds, array $joins = [], array $throwFor = []): NftGroupReconcileSweepService
    {
        $gate = (new \ReflectionClass(NftGroupGateService::class))->newInstanceWithoutConstructor();

        return new NftGroupReconcileSweepService(
            $gate,
            static function (int $uid) use ($joins, $throwFor): array {
                $GLOBALS['__rs']['events'][] = 'reconcile:' . $uid;
                if (in_array($uid, $throwFor, true)) {
                    throw new \RuntimeException('provider down');
                }
                return ['joined' => $joins[$uid] ?? 0, 'skipped' => 0];
            },
            static function (int $afterId, int $limit) use ($userIds): array {
                $GLOBALS['__rs']['reads'][] = [$afterId, $limit];
                $GLOBALS['__rs']['events'][] = 'read:' . $afterId;
                return $userIds;
            },
        );
    }

    private function withLock(): void
    {
        require_once __DIR__ . '/../Stubs/reconcile-sweep-lock-stub.php';
    }

    public function testTheCursorMovesAfterEachUserAndWrapsOnlyOnceTheBatchIsDone(): void
    {
        $this->withLock();
        $GLOBALS['__rs']['options'][NftGroupReconcileSweepService::CURSOR_OPTION] = 40;

        $stats = $this->service([41, 43, 47], [43 => 2])->sweep();

        self::assertSame([
            self::LOCK,
            'read:40',
            'reconcile:41', 'cursor:41',
            'reconcile:43', 'cursor:43',
            'reconcile:47', 'cursor:47',
            'cursor:0', // short batch: the end of the walk, wrapped only now
            self::UNLOCK,
        ], $GLOBALS['__rs']['events']);
        self::assertSame(['checked' => 3, 'users_touched' => 1, 'joins_total' => 2, 'errored' => 0, 'skipped_overlap' => false], $stats);
        self::assertSame([[40, NftGroupReconcileSweepService::BATCH_SIZE]], $GLOBALS['__rs']['reads']);
    }

    public function testAFullBatchLeavesTheCursorOnItsLastUserForTheNextTick(): void
    {
        $this->withLock();
        $ids = range(101, 100 + NftGroupReconcileSweepService::BATCH_SIZE);

        $this->service($ids)->sweep();

        self::assertSame(120, $GLOBALS['__rs']['options'][NftGroupReconcileSweepService::CURSOR_OPTION]);
        self::assertNotContains('cursor:0', $GLOBALS['__rs']['events'], 'not the end of the walk');
    }

    public function testAThrowingUserIsLoggedAndCountedAndTheRestOfTheBatchStillRuns(): void
    {
        $this->withLock();

        $stats = $this->service([5, 6, 7], [7 => 1], [6])->sweep();

        self::assertSame([
            self::LOCK, 'read:0',
            'reconcile:5', 'cursor:5',
            'reconcile:6', 'cursor:6', // past it: one poisoned user must not stall the walk
            'reconcile:7', 'cursor:7',
            'cursor:0',
            self::UNLOCK,
        ], $GLOBALS['__rs']['events']);
        self::assertSame(1, $stats['errored']);
        self::assertSame(1, $stats['joins_total']);
        self::assertCount(1, $GLOBALS['__rs']['warnings']);
        self::assertSame(6, $GLOBALS['__rs']['warnings'][0][1]['user_id']);
    }

    public function testAnEmptyReadWrapsTheCursor(): void
    {
        $this->withLock();
        $GLOBALS['__rs']['options'][NftGroupReconcileSweepService::CURSOR_OPTION] = 900;

        $stats = $this->service([])->sweep();

        self::assertSame([self::LOCK, 'read:900', 'cursor:0', self::UNLOCK], $GLOBALS['__rs']['events']);
        self::assertSame(0, $stats['checked']);
    }

    public function testAnOverlappingTickDoesNothing(): void
    {
        $this->withLock();
        $GLOBALS['__rs']['held'] = [NftGroupReconcileSweepService::SWEEP_LOCK];
        $GLOBALS['__rs']['options'][NftGroupReconcileSweepService::CURSOR_OPTION] = 40;

        $stats = $this->service([41, 43])->sweep();

        self::assertSame(['busy:' . NftGroupReconcileSweepService::SWEEP_LOCK], $GLOBALS['__rs']['events'], 'no read, no reconcile, no cursor write');
        self::assertTrue($stats['skipped_overlap']);
        self::assertSame(40, $GLOBALS['__rs']['options'][NftGroupReconcileSweepService::CURSOR_OPTION]);
    }

    public function testTheLockIsReleasedEvenWhenTheReadThrows(): void
    {
        $this->withLock();
        $gate    = (new \ReflectionClass(NftGroupGateService::class))->newInstanceWithoutConstructor();
        $service = new NftGroupReconcileSweepService(
            $gate,
            static fn (int $uid): array => ['joined' => 0, 'skipped' => 0],
            static function (): array {
                throw new \RuntimeException('read failed');
            },
        );

        try {
            $service->sweep();
            self::fail('the read failure propagates to the cron callback, which logs it');
        } catch (\RuntimeException) {
        }

        self::assertSame([self::LOCK, self::UNLOCK], $GLOBALS['__rs']['events']);
    }

    public function testWithoutTheLockPrimitiveTheSweepDoesNothingAndLogsWhy(): void
    {
        self::assertFalse(class_exists('BCC\\Core\\DB\\AdvisoryLock'), 'precondition: no lock primitive');

        $stats = $this->service([41])->sweep();

        self::assertSame([], $GLOBALS['__rs']['events']);
        self::assertTrue($stats['skipped_overlap']);
        self::assertCount(1, $GLOBALS['__rs']['errors']);
        self::assertStringContainsString('cannot be serialized', $GLOBALS['__rs']['errors'][0][0]);
    }

    public function testTheCronAndTheAdminButtonBothRunTheServiceTick(): void
    {
        $root = dirname(__DIR__, 2);
        $boot = (string) file_get_contents($root . '/bcc-trust.php');
        $from = strpos($boot, "add_action('bcc_gated_group_reconcile_sweep', function () {");
        self::assertNotFalse($from);
        $closure = substr($boot, $from, (int) strpos($boot, "\n});", $from) - $from);
        self::assertStringContainsString('->nftGroupReconcileSweepService()', $closure);
        self::assertStringContainsString('->sweep();', $closure);
        self::assertStringNotContainsString('update_option', $closure, 'the cursor belongs to the service');
        self::assertStringNotContainsString('reconcileForUser', $closure);

        $page = (string) file_get_contents($root . '/app/Domain/Onchain/Admin/HolderGroupsPage.php');
        $from = strpos($page, 'public static function handle_run_sweep(): void');
        self::assertNotFalse($from);
        $handler = substr($page, $from, (int) strpos($page, 'public static function', $from + 10) - $from);
        self::assertStringContainsString('->nftGroupReconcileSweepService()->sweep()', $handler, 'the manual run shares the lock');
        self::assertStringNotContainsString('reconcileForUser', $handler, 'no second, unlocked loop');
    }

    public function testTheLockIsItsOwnNotARevokeSweeps(): void
    {
        self::assertNotSame(NftGroupRevokeService::SWEEP_LOCK, NftGroupReconcileSweepService::SWEEP_LOCK);
        self::assertNotSame(ValidatorGroupRevokeService::SWEEP_LOCK, NftGroupReconcileSweepService::SWEEP_LOCK);
        self::assertSame('bcc_gated_group_reconcile_cursor', NftGroupReconcileSweepService::CURSOR_OPTION, 'the existing cursor carries over');
    }
}
