<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Rank\Services\RankCredibilityGate;
use PHPUnit\Framework\TestCase;

/**
 * Rank helping emitters — the §9.2 / §14.2 credibility predicates.
 *
 * isCredibleRecognizer (Helpful-mark gate): Apprentice+ AND Neutral+ AND
 * not suspended AND fraud-clear — ALL four required.
 * isResponsibleOwner (stewardship gate): not suspended AND fraud-clear
 * AND not in recovery.
 *
 * The underlying reads (rank_state, tier, suspension, fraud) are
 * scripted via the protected collaborator hooks so the composition logic
 * is pinned without WordPress or a DB.
 */
final class RankCredibilityGateTest extends TestCase
{
    private const U = 7;

    private function gate(
        bool $notSuspended = true,
        bool $hasRankState = true,
        bool $tierNeutralPlus = true,
        bool $fraudClear = true,
        bool $inRecovery = false
    ): ScriptedCredibilityGate {
        return new ScriptedCredibilityGate($notSuspended, $hasRankState, $tierNeutralPlus, $fraudClear, $inRecovery);
    }

    // ── isCredibleRecognizer (§9.2) ─────────────────────────────────────

    public function testCredibleWhenAllFourHold(): void
    {
        self::assertTrue($this->gate()->isCredibleRecognizer(self::U));
    }

    public function testNotCredibleWhenAnySingleGateFails(): void
    {
        self::assertFalse($this->gate(notSuspended: false)->isCredibleRecognizer(self::U), 'suspended');
        self::assertFalse($this->gate(hasRankState: false)->isCredibleRecognizer(self::U), 'new member');
        self::assertFalse($this->gate(tierNeutralPlus: false)->isCredibleRecognizer(self::U), 'below neutral');
        self::assertFalse($this->gate(fraudClear: false)->isCredibleRecognizer(self::U), 'fraud blocked');
    }

    public function testNonPositiveUserIsNeverCredible(): void
    {
        self::assertFalse($this->gate()->isCredibleRecognizer(0));
        self::assertFalse($this->gate()->isCredibleRecognizer(-1));
    }

    // ── isResponsibleOwner (stewardship) ────────────────────────────────

    public function testResponsibleOwnerWhenClearAndNotInRecovery(): void
    {
        self::assertTrue($this->gate()->isResponsibleOwner(self::U));
    }

    public function testResponsibleOwnerDoesNotRequireRankOrTier(): void
    {
        // An owner is Apprentice+ by construction; the stewardship gate
        // deliberately does NOT re-check rank / tier.
        self::assertTrue(
            $this->gate(hasRankState: false, tierNeutralPlus: false)->isResponsibleOwner(self::U)
        );
    }

    public function testIrresponsibleOwnerWhenSuspendedFraudOrInRecovery(): void
    {
        self::assertFalse($this->gate(notSuspended: false)->isResponsibleOwner(self::U), 'suspended');
        self::assertFalse($this->gate(fraudClear: false)->isResponsibleOwner(self::U), 'fraud blocked');
        self::assertFalse($this->gate(inRecovery: true)->isResponsibleOwner(self::U), 'in recovery');
    }

    public function testNonPositiveUserIsNeverResponsible(): void
    {
        self::assertFalse($this->gate()->isResponsibleOwner(0));
    }
}

/**
 * Scripted double — overrides every canonical read so the predicate
 * composition is exercised against fixed facts.
 */
final class ScriptedCredibilityGate extends RankCredibilityGate
{
    public function __construct(
        private readonly bool $notSuspendedFlag,
        private readonly bool $hasRankStateFlag,
        private readonly bool $tierNeutralPlusFlag,
        private readonly bool $fraudClearFlag,
        private readonly bool $inRecoveryFlag
    ) {
    }

    protected function notSuspended(int $userId): bool
    {
        return $this->notSuspendedFlag;
    }

    protected function hasRankState(int $userId): bool
    {
        return $this->hasRankStateFlag;
    }

    protected function inRecovery(int $userId): bool
    {
        return $this->inRecoveryFlag;
    }

    protected function tierAtLeastNeutral(int $userId): bool
    {
        return $this->tierNeutralPlusFlag;
    }

    protected function fraudClear(int $userId): bool
    {
        return $this->fraudClearFlag;
    }
}
