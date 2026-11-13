<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\CLI\BlockFollowsBackfillCommand;
use BCC\Trust\Core\Repositories\WatchingRepository;
use BCC\Trust\Core\Services\BlockFollowBackfill;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-261: blocks made before F-29 never had their follows removed. The
 * backfill plans every blocked pair that still has a follow either way and
 * severs it; a failed read fails the whole plan; the confirmation token is
 * bound to the environment and the exact plan.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BlockFollowBackfillTest extends TestCase
{
    /** @var list<array{0: int, 1: int}> */
    private array $severed = [];

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/block-follow-backfill-stubs.php';
        $GLOBALS['__bcc_bfb'] = ['blocks' => [], 'follows' => [], 'cursors' => []];
        $this->severed = [];
    }

    private function backfill(): BlockFollowBackfill
    {
        return new BlockFollowBackfill(new WatchingRepository(), function (int $a, int $b): int {
            $this->severed[] = [$a, $b];
            return 1;
        });
    }

    public function testThePlanHoldsEveryBlockedPairThatStillHasAFollowEitherWay(): void
    {
        $GLOBALS['__bcc_bfb']['blocks'] = [
            ['id' => 1, 'blocker' => 10, 'blocked' => 20], // 10 follows 20
            ['id' => 2, 'blocker' => 20, 'blocked' => 10], // the mutual direction: same pair
            ['id' => 3, 'blocker' => 30, 'blocked' => 40], // no follow
            ['id' => 4, 'blocker' => 60, 'blocked' => 50], // 50 follows 60 (blocked follows blocker)
            ['id' => 5, 'blocker' => 70, 'blocked' => 80], // only 80 → 70: the HIGHER id follows the lower
        ];
        $GLOBALS['__bcc_bfb']['follows'] = ['10:20', '50:60', '80:70'];

        $plan = $this->backfill()->plan();

        self::assertTrue($plan['ok']);
        self::assertSame([[10, 20], [50, 60], [70, 80]], $plan['pairs'], 'each unordered pair once, low id first; either direction counts');
        self::assertSame(5, $plan['scanned']);
    }

    public function testItPagesByKeysetUntilAnEmptyPage(): void
    {
        $blocks = [];
        for ($i = 1; $i <= 1200; $i++) {
            $blocks[] = ['id' => $i, 'blocker' => 2 * $i, 'blocked' => 2 * $i + 1];
        }
        $GLOBALS['__bcc_bfb']['blocks'] = $blocks;

        $plan = $this->backfill()->plan();

        self::assertTrue($plan['ok']);
        self::assertSame(1200, $plan['scanned']);
        self::assertSame([0, 500, 1000, 1200], $GLOBALS['__bcc_bfb']['cursors'], 'cursor = last id seen');
    }

    public function testAFailedBlockPageFailsTheWholePlan(): void
    {
        $GLOBALS['__bcc_bfb']['page_fails'] = true;

        $plan = $this->backfill()->plan();

        self::assertFalse($plan['ok']);
        self::assertArrayNotHasKey('pairs', $plan, 'never a partial plan that looks complete');
    }

    public function testAFailedFollowReadFailsTheWholePlan(): void
    {
        $GLOBALS['__bcc_bfb']['blocks']       = [['id' => 1, 'blocker' => 10, 'blocked' => 20]];
        $GLOBALS['__bcc_bfb']['follow_fails'] = true;

        self::assertFalse($this->backfill()->plan()['ok']);
    }

    public function testApplySeversEachPlannedPairOnce(): void
    {
        $result = $this->backfill()->apply([[10, 20], [50, 60]]);

        self::assertSame([[10, 20], [50, 60]], $this->severed);
        self::assertSame(['pairs' => 2, 'severed' => 2], $result);
    }

    public function testThePlanWritesNothing(): void
    {
        $GLOBALS['__bcc_bfb']['blocks']  = [['id' => 1, 'blocker' => 10, 'blocked' => 20]];
        $GLOBALS['__bcc_bfb']['follows'] = ['10:20'];

        $this->backfill()->plan();

        self::assertSame([], $this->severed);
    }

    public function testTheTokenIsBoundToTheEnvironmentAndTheExactPlan(): void
    {
        $plan = [[10, 20], [50, 60]];
        $t    = BlockFollowBackfill::confirmationToken('production', $plan);

        self::assertSame($t, BlockFollowBackfill::confirmationToken('production', [[50, 60], [10, 20]]), 'order-independent');
        self::assertNotSame($t, BlockFollowBackfill::confirmationToken('staging', $plan), 'a staging token cannot apply on production');
        self::assertNotSame($t, BlockFollowBackfill::confirmationToken('production', [[10, 20]]), 'a changed plan needs a new dry run');
    }

    public function testTheCommandAcceptsOnlyItsThreeFlags(): void
    {
        self::assertTrue(BlockFollowsBackfillCommand::parse([], [])['ok']);
        self::assertFalse(BlockFollowsBackfillCommand::parse([], [])['apply'] ?? true, 'dry run by default');
        self::assertTrue(BlockFollowsBackfillCommand::parse([], ['apply' => true, 'confirm' => 'abc', 'user-id' => '1'])['ok']);

        self::assertFalse(BlockFollowsBackfillCommand::parse(['x'], [])['ok'], 'no positional args');
        self::assertFalse(BlockFollowsBackfillCommand::parse([], ['force' => true])['ok'], 'unknown flag');
        self::assertFalse(BlockFollowsBackfillCommand::parse([], ['apply' => 'yes'])['ok'], '--apply must be bare');
        self::assertFalse(BlockFollowsBackfillCommand::parse([], ['confirm' => ''])['ok'], '--confirm needs a value');
    }

    public function testANewBlockFromAnyDoorSeversFollows(): void
    {
        $src = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(dirname(__DIR__, 2) . '/bcc-trust.php'));

        self::assertStringContainsString("add_action('peepso_user_blocked', static function (\$args): void {", $src);
        $hook = substr($src, (int) strpos($src, "add_action('peepso_user_blocked'"), 900);
        self::assertStringContainsString('->watchingService()->severFollowsBetween($from, $to);', $hook);
        self::assertStringContainsString("'bcc-trust block-follows', \\BCC\\Trust\\Core\\CLI\\BlockFollowsBackfillCommand::class", $src);
    }
}
