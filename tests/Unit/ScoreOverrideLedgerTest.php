<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\ScoreOverrideLedger;
use PHPUnit\Framework\TestCase;


/**
 * F-113 — the score-override undo ledger: a bounded, latest-first stack per
 * user. The real class; only user meta is in-memory (protected hooks).
 */
final class ScoreOverrideLedgerTest extends TestCase
{
    private const USER = 31;

    public function testLatestFirstUndoAcrossAStack(): void
    {
        $l   = $this->ledger();
        $id1 = $l->push(self::USER, 1, 70, 'first', 't1', [10 => ['delta' => 20.0, 'prev_pin' => null]]);
        $id2 = $l->push(self::USER, 1, 85, 'second', 't2', [10 => ['delta' => 15.0, 'prev_pin' => null]]);
        self::assertNotSame('', $id1);

        self::assertSame($id2, $l->latestUndoable(self::USER)['id'] ?? null);
        self::assertTrue($l->markUndone(self::USER, $id2));
        self::assertSame($id1, $l->latestUndoable(self::USER)['id'] ?? null, 'undo walks back down the stack');
        self::assertTrue($l->markUndone(self::USER, $id1));
        self::assertNull($l->latestUndoable(self::USER), 'nothing left to undo');
    }

    public function testAnEntryCannotBeUndoneTwice(): void
    {
        $l  = $this->ledger();
        $id = $l->push(self::USER, 1, 70, 'r', 't', [10 => ['delta' => 5.0, 'prev_pin' => null]]);
        self::assertTrue($l->markUndone(self::USER, $id));
        self::assertFalse($l->markUndone(self::USER, $id));
        self::assertFalse($l->markUndone(self::USER, 'no-such-id'));
    }

    public function testTheStackIsBoundedToTheLastTwenty(): void
    {
        $l   = $this->ledger();
        $ids = [];
        for ($i = 0; $i < ScoreOverrideLedger::MAX_ENTRIES + 5; $i++) {
            $ids[] = $l->push(self::USER, 1, 50 + $i, 'r', 't', [10 => ['delta' => 1.0, 'prev_pin' => null]]);
        }
        $undone = 0;
        while (($e = $l->latestUndoable(self::USER)) !== null) {
            $l->markUndone(self::USER, $e['id']);
            $undone++;
        }
        self::assertSame(ScoreOverrideLedger::MAX_ENTRIES, $undone);
        self::assertFalse($l->markUndone(self::USER, $ids[0]), 'the oldest entries were dropped');
    }

    public function testDeltasAndPriorPinsRoundTripExactly(): void
    {
        $l = $this->ledger();
        $l->push(self::USER, 9, 85, 'r', 't', [
            10 => ['delta' => -12.5, 'prev_pin' => ['admin_id' => 3, 'granted_at' => '2026-01-01 00:00:00']],
            11 => ['delta' => 0.0, 'prev_pin' => null],
        ]);
        $e = $l->latestUndoable(self::USER);
        self::assertNotNull($e);
        self::assertSame(-12.5, $e['pages']['10']['delta']);
        self::assertSame(['admin_id' => 3, 'granted_at' => '2026-01-01 00:00:00'], $e['pages']['10']['prev_pin']);
        self::assertNull($e['pages']['11']['prev_pin']);
    }

    public function testMalformedMetaReadsAsAnEmptyLedger(): void
    {
        $l = $this->ledger();
        $l->meta[self::USER] = 'not json';
        self::assertNull($l->latestUndoable(self::USER));
        $l->meta[self::USER] = '[{"id":"x"}]'; // no pages
        self::assertNull($l->latestUndoable(self::USER));
    }

    public function testAFailedWriteIsReported(): void
    {
        $l = $this->ledger();
        $l->failWrites = true;
        self::assertSame('', $l->push(self::USER, 1, 70, 'r', 't', [10 => ['delta' => 1.0, 'prev_pin' => null]]));
    }

    private function ledger(): object
    {
        return new class extends ScoreOverrideLedger {
            /** @var array<int, mixed> */
            public array $meta = [];
            public bool $failWrites = false;

            protected function readMeta(int $userId): mixed
            {
                return $this->meta[$userId] ?? '';
            }
            protected function writeMeta(int $userId, string $json): bool
            {
                if ($this->failWrites) {
                    return false;
                }
                $this->meta[$userId] = $json;
                return true;
            }
        };
    }
}
