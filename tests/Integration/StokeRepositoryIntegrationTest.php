<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Repositories\StokeRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * StokeRepository against a real MySQL — proves the X-"like" model holds at
 * the DB layer: `uq_act_user` makes a row's existence the stoke (one per
 * person), so a repeat POST is an idempotent no-op (not a second row, not
 * an incremented counter) and DELETE removes the row outright rather than
 * decrementing a count.
 */
#[Group('integration')]
#[CoversClass(StokeRepository::class)]
final class StokeRepositoryIntegrationTest extends TestCase
{
    private function table(): string
    {
        return $GLOBALS['wpdb']->prefix . 'bcc_trust_stokes';
    }

    protected function setUp(): void
    {
        $GLOBALS['wpdb']->query('TRUNCATE TABLE `' . $this->table() . '`');
    }

    private function rowCount(string $where = '1'): int
    {
        return (int) $GLOBALS['wpdb']->get_var('SELECT COUNT(*) FROM `' . $this->table() . "` WHERE {$where}");
    }

    public function testDoublePostIsIdempotentNotDuplicated(): void
    {
        $repo = new StokeRepository();

        self::assertTrue($repo->addStoke(100, 1));
        self::assertTrue($repo->addStoke(100, 1)); // repeat POST — unique key makes this a no-op

        self::assertSame(1, $this->rowCount('act_id = 100 AND user_id = 1'), 'still one row, not two');
        self::assertTrue($repo->viewerHasStoked(100, 1));
        self::assertSame(1, $repo->countForActivity(100));
    }

    public function testDistinctStokersGetDistinctRows(): void
    {
        $repo = new StokeRepository();

        $repo->addStoke(100, 1);
        $repo->addStoke(100, 2);
        $repo->addStoke(100, 3);

        self::assertSame(3, $this->rowCount('act_id = 100'), 'three distinct stokers, three rows');
        self::assertSame(3, $repo->countForActivity(100));
    }

    public function testDeleteRemovesTheRow(): void
    {
        $repo = new StokeRepository();
        $repo->addStoke(100, 1);
        self::assertSame(1, $this->rowCount('act_id = 100 AND user_id = 1'));

        self::assertTrue($repo->removeStoke(100, 1));

        self::assertSame(0, $this->rowCount('act_id = 100 AND user_id = 1'), 'row deleted, not decremented');
        self::assertFalse($repo->viewerHasStoked(100, 1));
        self::assertSame(0, $repo->countForActivity(100));
    }

    public function testDeleteOnAbsentStokeIsIdempotentNoOp(): void
    {
        $repo = new StokeRepository();

        self::assertTrue($repo->removeStoke(100, 1), 'no-op delete on a viewer who never stoked still succeeds');
        self::assertSame(0, $this->rowCount());
    }

    public function testRestokeAfterUnstokeWorks(): void
    {
        $repo = new StokeRepository();

        $repo->addStoke(100, 1);
        $repo->removeStoke(100, 1);
        self::assertFalse($repo->viewerHasStoked(100, 1));

        $repo->addStoke(100, 1);
        self::assertTrue($repo->viewerHasStoked(100, 1));
        self::assertSame(1, $repo->countForActivity(100));
    }

    public function testViewerHasStokedAndCountsByActIdsAreBatchedCorrectly(): void
    {
        $repo = new StokeRepository();

        $repo->addStoke(100, 1);
        $repo->addStoke(100, 2);
        $repo->addStoke(200, 1);

        $countsByAct = $repo->countsByActIds([100, 200, 300]);
        self::assertSame(2, $countsByAct[100] ?? 0);
        self::assertSame(1, $countsByAct[200] ?? 0);
        self::assertArrayNotHasKey(300, $countsByAct, 'no stokes at all — absent, not zero-valued');

        $viewer1Stoked = $repo->viewerStokedActIds(1, [100, 200, 300]);
        self::assertTrue($viewer1Stoked[100] ?? false);
        self::assertTrue($viewer1Stoked[200] ?? false);
        self::assertArrayNotHasKey(300, $viewer1Stoked);

        $viewer2Stoked = $repo->viewerStokedActIds(2, [100, 200, 300]);
        self::assertTrue($viewer2Stoked[100] ?? false);
        self::assertArrayNotHasKey(200, $viewer2Stoked, "viewer 2 never stoked act 200");
    }
}
