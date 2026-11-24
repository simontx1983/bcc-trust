<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Onchain\Services\RelationshipLogHealthCheck as H;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The daily relationship-log health check: a clean chain is anchored and the
 * anchor mailed to every admin; a broken chain is counted and every admin is
 * alerted with the first bad seq; the result is kept for the health view;
 * nothing is ever repaired.
 */
#[CoversClass(H::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RelationshipLogHealthCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/relationship-log-health-stubs.php';
    }

    public function testACleanChainIsAnchoredAndTheAnchorMailedToEveryAdmin(): void
    {
        $result = H::run();

        self::assertTrue($result['chain_ok']);
        self::assertSame([[3, 'aaaa']], \HealthWorld::$anchored);
        self::assertSame(['admin1@example.org', 'admin2@example.org'], array_column(\HealthWorld::$mails, 0));
        self::assertStringContainsString('daily anchor', \HealthWorld::$mails[0][1]);
        self::assertSame([], \HealthWorld::$metrics);
        self::assertSame($result, H::lastResult(), 'kept for the health view');
    }

    public function testABrokenChainAlertsEveryAdminAndIsNeverAnchored(): void
    {
        \HealthWorld::$verdict = ['ok' => false, 'rows_checked' => 1, 'head_seq' => 1, 'first_bad_seq' => 2, 'reason' => 'row_hash_mismatch'];

        $result = H::run();

        self::assertSame([false, 2, 'row_hash_mismatch'], [$result['chain_ok'], $result['first_bad_seq'], $result['reason']]);
        self::assertSame([], \HealthWorld::$anchored, 'a broken chain never moves the anchor');
        self::assertSame(['validator_relationships/log_chain_broken'], \HealthWorld::$metrics);
        self::assertCount(2, \HealthWorld::$mails);
        self::assertStringContainsString('ALERT', \HealthWorld::$mails[0][1]);
        self::assertSame(2, H::lastResult()['first_bad_seq'] ?? null);
    }

    public function testAnEmptyLogIsHealthyButNothingIsAnchoredOrMailed(): void
    {
        \HealthWorld::$verdict = ['ok' => true, 'rows_checked' => 0, 'head_seq' => 0, 'first_bad_seq' => null, 'reason' => null];
        \HealthWorld::$head    = ['seq' => 0, 'row_hash' => str_repeat('0', 64)];

        self::assertTrue(H::run()['chain_ok']);
        self::assertSame([], \HealthWorld::$anchored);
        self::assertSame([], \HealthWorld::$mails);
    }

    public function testNoResultBeforeTheFirstRun(): void
    {
        self::assertNull(H::lastResult());
    }
}
