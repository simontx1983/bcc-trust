<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Services\NftGroupRevokeService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-69 — two overlapping holder-group revoke sweeps read the same members,
 * both revoked them and both wrote the audit row. sweep() now holds a
 * session-scoped MySQL advisory lock (SWEEP_LOCK) for the whole tick.
 *
 * REAL GET_LOCK on a REAL server. ⚠ GET_LOCK is re-entrant per session, so
 * the competing run MUST be a SECOND connection — re-acquiring on the
 * harness connection would always succeed and prove nothing.
 */
#[Group('integration')]
#[Group('mariadb')]
final class NftRevokeSweepOverlapLockIntegrationTest extends TestCase
{
    private ?\mysqli $other = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->other = new \mysqli(
            getenv('BCC_TEST_DB_HOST') ?: '127.0.0.1',
            getenv('BCC_TEST_DB_USER') ?: 'root',
            getenv('BCC_TEST_DB_PASS') ?: 'root',
            getenv('BCC_TEST_DB_NAME') ?: 'bcc_test',
            (int) (getenv('BCC_TEST_DB_PORT') ?: 10005)
        );
    }

    protected function tearDown(): void
    {
        if ($this->other !== null) {
            $this->other->query("SELECT RELEASE_LOCK('" . NftGroupRevokeService::SWEEP_LOCK . "')");
            $this->other->close();
        }
        parent::tearDown();
    }

    private function otherSessionLock(): int
    {
        self::assertNotNull($this->other);
        $res = $this->other->query("SELECT GET_LOCK('" . NftGroupRevokeService::SWEEP_LOCK . "', 0) AS got");
        self::assertInstanceOf(\mysqli_result::class, $res);
        $row = $res->fetch_assoc();
        return (int) ($row['got'] ?? -1);
    }

    public function testASweepSkipsWhileAnotherSessionRunsOneAndRunsAfterItEnds(): void
    {
        self::assertSame(1, $this->otherSessionLock(), 'the competing session takes the lock');

        $overlapping = (new NftGroupRevokeService())->sweep();
        self::assertTrue($overlapping['skipped_overlap'], 'the overlapping tick must not run');

        $this->other?->query("SELECT RELEASE_LOCK('" . NftGroupRevokeService::SWEEP_LOCK . "')");

        $next = (new NftGroupRevokeService())->sweep();
        self::assertFalse($next['skipped_overlap'], 'once the other tick ends, the next one runs');
    }

    public function testTheSweepReleasesTheLockSoAnotherSessionCanTakeIt(): void
    {
        (new NftGroupRevokeService())->sweep();

        self::assertSame(1, $this->otherSessionLock(), 'the lock must not outlive the sweep');
    }
}
