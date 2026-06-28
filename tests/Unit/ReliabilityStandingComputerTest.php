<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\ReliabilityStandingComputer;
use PHPUnit\Framework\TestCase;

/**
 * Pins the Slice-2 standing gate (the pure ReliabilityStandingComputer::
 * fromReliability): the <minForStanding first-call protection, and the
 * highly/consistent/newly_active bucketing above the gate.
 *
 * The catalogue is positive-only (§J.3.2): a low numeric reliability lands
 * at newly_active, never a stigma marker. A regression that promotes a
 * tiny-sample operator, or that drops the first-call protection, must turn
 * this suite red.
 */
final class ReliabilityStandingComputerTest extends TestCase
{
    private const HIGHLY_MIN     = 0.85;
    private const CONSISTENT_MIN = 0.65;
    private const MIN_STANDING   = 20;

    private function standing(float $reliability, int $counted): string
    {
        return ReliabilityStandingComputer::fromReliability(
            $reliability,
            $counted,
            self::HIGHLY_MIN,
            self::CONSISTENT_MIN,
            self::MIN_STANDING
        );
    }

    public function testBelowGateIsNewlyActiveRegardlessOfNumeric(): void
    {
        // Even a perfect 1.0 stays newly_active under the count gate.
        self::assertSame(
            ReliabilityStandingComputer::STANDING_NEWLY_ACTIVE,
            $this->standing(1.0, 19)
        );
        self::assertSame(
            ReliabilityStandingComputer::STANDING_NEWLY_ACTIVE,
            $this->standing(0.0, 0)
        );
    }

    public function testAtGateHighlyReliableAboveHighlyMin(): void
    {
        // Strictly above HIGHLY_MIN → highly_reliable.
        self::assertSame(
            ReliabilityStandingComputer::STANDING_HIGHLY_RELIABLE,
            $this->standing(0.86, self::MIN_STANDING)
        );
    }

    public function testHighlyMinIsExclusiveBoundary(): void
    {
        // Exactly HIGHLY_MIN is NOT > HIGHLY_MIN → falls to consistent.
        self::assertSame(
            ReliabilityStandingComputer::STANDING_CONSISTENT,
            $this->standing(self::HIGHLY_MIN, self::MIN_STANDING)
        );
    }

    public function testConsistentBand(): void
    {
        // [CONSISTENT_MIN, HIGHLY_MIN] → consistent (inclusive lower bound).
        self::assertSame(
            ReliabilityStandingComputer::STANDING_CONSISTENT,
            $this->standing(self::CONSISTENT_MIN, self::MIN_STANDING)
        );
        self::assertSame(
            ReliabilityStandingComputer::STANDING_CONSISTENT,
            $this->standing(0.75, self::MIN_STANDING)
        );
    }

    public function testBelowConsistentIsNewlyActive(): void
    {
        // At-gate count but low numeric → newly_active (positive-only catalogue,
        // no stigma marker to fall to).
        self::assertSame(
            ReliabilityStandingComputer::STANDING_NEWLY_ACTIVE,
            $this->standing(0.64, self::MIN_STANDING)
        );
        self::assertSame(
            ReliabilityStandingComputer::STANDING_NEWLY_ACTIVE,
            $this->standing(0.10, 100)
        );
    }
}
