<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Onchain\Services\RelationshipLogWriter as W;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * A standalone log append: vrl lock → transaction → append → COMMIT →
 * anchor → release. The anchor moves only after a successful commit and
 * while the lock is still held; a failure never moves it and always
 * releases the lock.
 */
#[CoversClass(W::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RelationshipLogWriterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/relationship-log-writer-stubs.php';
    }

    /** @return array{action: string, page_id: int, subject_user_id: int|null, actor_kind: string, actor_user_id: int|null, payload: array<mixed>} */
    private static function event(): array
    {
        return ['action' => 'step_up_failed', 'page_id' => 501, 'subject_user_id' => null, 'actor_kind' => 'staff', 'actor_user_id' => 7, 'payload' => []];
    }

    public function testTheAnchorMovesAfterTheCommitWhileTheLockIsHeld(): void
    {
        self::assertTrue(W::appendStandalone(self::event()));

        self::assertSame(
            ['acquire:bcc_vrl_append', 'begin', 'append', 'commit', 'advance:7', 'release:bcc_vrl_append'],
            \WriterWorld::$events
        );
    }

    public function testAFailedAppendNeverMovesTheAnchorAndReleasesTheLock(): void
    {
        \WriterWorld::$appendFails = true;

        self::assertFalse(W::appendStandalone(self::event()));
        self::assertSame(
            ['acquire:bcc_vrl_append', 'begin', 'append', 'rollback', 'metric:log_append_failed', 'release:bcc_vrl_append'],
            \WriterWorld::$events
        );
    }

    public function testAFailedCommitNeverAnchorsTheRolledBackSeq(): void
    {
        \WriterWorld::$commitFails = true;

        self::assertFalse(W::appendStandalone(self::event()));
        self::assertNotContains('advance:7', \WriterWorld::$events);
        self::assertSame('release:bcc_vrl_append', end(\WriterWorld::$events));
    }

    public function testALockTimeoutWritesNothing(): void
    {
        \WriterWorld::$lockFree = false;

        self::assertFalse(W::appendStandalone(self::event()));
        self::assertSame(['acquire:bcc_vrl_append', 'metric:log_lock_unavailable'], \WriterWorld::$events);
    }
}
