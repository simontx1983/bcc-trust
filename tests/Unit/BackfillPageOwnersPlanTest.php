<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\CLI\BackfillPageOwnersCommand as Cmd;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-299 backfill review fixes: the --apply flag can only WRITE when it says
 * so, every write reports the pair's PRIOR state (the exact rollback), and the
 * repair never lifts a ban or creates a second owner.
 */
#[Group('unit')]
#[RunTestsInSeparateProcesses]
final class BackfillPageOwnersPlanTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('ABSPATH')) {
            define('ABSPATH', '/tmp/wp/');
        }
        require_once __DIR__ . '/../Stubs/wp-cli-flag-stub.php';
    }

    /** @return array{page_id: int, user_id: int, current_role: ?string, other_owner: int} */
    private static function row(?string $role, int $otherOwner = 0): array
    {
        return ['page_id' => 7, 'user_id' => 500, 'current_role' => $role, 'other_owner' => $otherOwner];
    }

    // ── --apply ─────────────────────────────────────────────────────────

    public function testOnlyAnAffirmativeApplyWrites(): void
    {
        self::assertFalse(Cmd::wantsApply([]), 'no flag: dry run');
        self::assertTrue(Cmd::wantsApply(['apply' => true]), '--apply');
        self::assertFalse(Cmd::wantsApply(['apply' => false]), '--no-apply must NOT write (isset() said it did)');
        self::assertFalse(Cmd::wantsApply(['apply' => '0']), '--apply=0 must NOT write');
        self::assertTrue(Cmd::wantsApply(['apply' => '1']), '--apply=1');
    }

    // ── plan ────────────────────────────────────────────────────────────

    public function testNoPriorRowIsAJoinWhoseRollbackIsADelete(): void
    {
        $plan = Cmd::plan(self::row(null));
        self::assertSame(Cmd::ACTION_JOIN, $plan['action']);
        self::assertStringContainsString('joined', $plan['detail']);
        self::assertStringContainsString('delete', $plan['detail']);
    }

    public function testAnExistingRowIsAPromotionThatNamesItsPriorRole(): void
    {
        $plan = Cmd::plan(self::row('member'));
        self::assertSame(Cmd::ACTION_PROMOTE, $plan['action']);
        self::assertStringContainsString('promoted from member', $plan['detail']);
        self::assertStringContainsString('set it back to member', $plan['detail']);
    }

    public function testABannedClaimerIsSkippedNotPromoted(): void
    {
        self::assertSame(Cmd::ACTION_SKIP_BANNED, Cmd::plan(self::row('banned'))['action']);
    }

    public function testAPageWithADifferentOwnerIsSkippedEvenForAPlainMember(): void
    {
        foreach ([null, 'member', 'banned'] as $role) {
            $plan = Cmd::plan(self::row($role, 999));
            self::assertSame(Cmd::ACTION_SKIP_OTHER_OWNER, $plan['action'], (string) $role);
            self::assertStringContainsString('999', $plan['detail']);
        }
    }
}
