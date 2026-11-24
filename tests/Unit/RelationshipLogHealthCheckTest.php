<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Onchain\Services\RelationshipLogHealthCheck as H;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The daily relationship-log health check is READ-ONLY: it never moves the
 * anchor (only an appender does, under the vrl lock) and never repairs
 * anything. A clean chain mails nobody; rows with no anchor are a warning; a
 * walk that could not finish is a metric only; a broken chain alerts every
 * admin with the first bad seq. The result is kept for the health view.
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

    public function testACleanAnchoredChainIsOkAndMailsNobody(): void
    {
        $result = H::run();

        self::assertSame(['ok', true, null], [$result['status'], $result['chain_ok'], $result['reason']]);
        self::assertSame([], \HealthWorld::$mails, 'a clean day sends no email');
        self::assertSame([], \HealthWorld::$metrics);
        self::assertSame([], \HealthWorld::$anchored, 'the health check never writes the anchor');
        self::assertSame($result, H::lastResult(), 'kept for the health view');
    }

    public function testRowsWithoutAnAnchorAreAWarning(): void
    {
        \HealthWorld::$anchor = null;

        $result = H::run();

        self::assertSame(['warning', true, 'anchor_missing'], [$result['status'], $result['chain_ok'], $result['reason']]);
        self::assertSame(['validator_relationships/log_anchor_missing'], \HealthWorld::$metrics);
        self::assertSame([], \HealthWorld::$mails, 'a warning, not an alert');
        self::assertSame([], \HealthWorld::$anchored, 'not re-seeded outside the vrl lock');
        self::assertSame('warning', H::lastResult()['status'] ?? null);
    }

    public function testAnEmptyLogWithoutAnAnchorIsOk(): void
    {
        \HealthWorld::$verdict = ['ok' => true, 'rows_checked' => 0, 'head_seq' => 0, 'first_bad_seq' => null, 'reason' => null];
        \HealthWorld::$anchor  = null;

        $result = H::run();

        self::assertSame('ok', $result['status']);
        self::assertSame([], \HealthWorld::$metrics);
        self::assertSame([], \HealthWorld::$mails);
    }

    /** @return array<string, array{string}> */
    public static function breakReasons(): array
    {
        return [
            'row hash'     => ['row_hash_mismatch'],
            'tail missing' => ['tail_missing'],
            'anchor'       => ['anchor_mismatch'],
            'key'          => ['key_unavailable'],
            'seq gap'      => ['seq_gap'],
            'evidence'     => ['evidence_mismatch'],
        ];
    }

    #[DataProvider('breakReasons')]
    public function testABrokenChainAlertsEveryAdmin(string $reason): void
    {
        \HealthWorld::$verdict = ['ok' => false, 'rows_checked' => 1, 'head_seq' => 1, 'first_bad_seq' => 2, 'reason' => $reason];

        $result = H::run();

        self::assertSame(['broken', false, 2, $reason], [$result['status'], $result['chain_ok'], $result['first_bad_seq'], $result['reason']]);
        self::assertSame([], \HealthWorld::$anchored);
        self::assertSame(['validator_relationships/log_chain_broken'], \HealthWorld::$metrics);
        self::assertSame(['admin1@example.org', 'admin2@example.org'], array_column(\HealthWorld::$mails, 0));
        self::assertStringContainsString('ALERT', \HealthWorld::$mails[0][1]);
        self::assertSame('broken', H::lastResult()['status'] ?? null);
    }

    /** @return array<string, array{string}> */
    public static function incompleteReasons(): array
    {
        return [
            'read failed' => ['read_failed'],
            'row limit'   => ['row_limit_reached'],
        ];
    }

    #[DataProvider('incompleteReasons')]
    public function testAWalkThatCouldNotFinishIsAMetricOnly(string $reason): void
    {
        \HealthWorld::$verdict = ['ok' => false, 'rows_checked' => 2, 'head_seq' => 2, 'first_bad_seq' => null, 'reason' => $reason];

        $result = H::run();

        self::assertSame(['incomplete', $reason], [$result['status'], $result['reason']]);
        self::assertSame([], \HealthWorld::$mails, 'nothing was proven broken, so no alert');
        self::assertSame(['validator_relationships/log_verify_incomplete'], \HealthWorld::$metrics);
        self::assertSame('incomplete', H::lastResult()['status'] ?? null);
    }

    public function testAResultStoredWithoutAStatusIsReadFromChainOk(): void
    {
        \HealthWorld::$options[H::RESULT] = ['chain_ok' => false, 'checked_at' => '2026-11-24 00:00:00', 'reason' => 'seq_gap'];

        self::assertSame('broken', H::lastResult()['status'] ?? null);
    }

    public function testNoResultBeforeTheFirstRun(): void
    {
        self::assertNull(H::lastResult());
    }
}
