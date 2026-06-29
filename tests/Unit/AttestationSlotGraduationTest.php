<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Services {
    // Namespace-local apply_filters polyfill: isPreConsensusPick() calls the
    // unqualified apply_filters(), which PHP resolves to this namespace first.
    // Returns the passed-through default so the test exercises the shipped
    // BCC_RELIABILITY_PRECONSENSUS_MAX_ORDER band without WordPress loaded.
    if (!function_exists(__NAMESPACE__ . '\\apply_filters')) {
        /**
         * @param mixed $value
         * @return mixed
         */
        function apply_filters(string $hook, $value)
        {
            return $value;
        }
    }
}

namespace BCC\Trust\Core\Tests\Unit {

    use BCC\Trust\Core\Services\AttestationService;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;

    /**
     * Pins the Slice-4 §J.1 slot-graduation ladder (the pure static
     * AttestationService::graduatedSlotsFor) and the §J.3.2.1 pre-consensus
     * band (isPreConsensusPick).
     *
     * Graduation requires SUSTAINED reliability: a high accurate-count with an
     * unproven standing earns nothing. A regression that grants bonus slots to
     * a 'newly_active' operator, that mis-orders the ladder, or that drops the
     * +3 cap must turn this suite red.
     */
    final class AttestationSlotGraduationTest extends TestCase
    {
        private const T1 = 100;
        private const T2 = 250;
        private const T3 = 500;
        private const MAX = 3;

        private function graduated(string $standing, int $accurate): int
        {
            return AttestationService::graduatedSlotsFor(
                $standing,
                $accurate,
                self::T1,
                self::T2,
                self::T3,
                self::MAX
            );
        }

        // ── Standing gate ──────────────────────────────────────────────────

        public function testNewlyActiveGraduatesNothingRegardlessOfCount(): void
        {
            self::assertSame(0, $this->graduated('newly_active', 0));
            self::assertSame(0, $this->graduated('newly_active', self::T1));
            self::assertSame(0, $this->graduated('newly_active', self::T3));
            self::assertSame(0, $this->graduated('newly_active', 99999));
        }

        public function testUnknownStandingGraduatesNothing(): void
        {
            self::assertSame(0, $this->graduated('caution', self::T3));
            self::assertSame(0, $this->graduated('', self::T3));
        }

        // ── Ladder for the two graduating standings ────────────────────────

        /** @return list<array{0: string}> */
        public static function graduatingStandings(): array
        {
            return [['highly_reliable'], ['consistent']];
        }

        #[DataProvider('graduatingStandings')]
        public function testBelowT1GraduatesZero(string $standing): void
        {
            self::assertSame(0, $this->graduated($standing, 0));
            self::assertSame(0, $this->graduated($standing, self::T1 - 1));
        }

        #[DataProvider('graduatingStandings')]
        public function testT1GraduatesOne(string $standing): void
        {
            self::assertSame(1, $this->graduated($standing, self::T1));
            self::assertSame(1, $this->graduated($standing, self::T2 - 1));
        }

        #[DataProvider('graduatingStandings')]
        public function testT2GraduatesTwo(string $standing): void
        {
            self::assertSame(2, $this->graduated($standing, self::T2));
            self::assertSame(2, $this->graduated($standing, self::T3 - 1));
        }

        #[DataProvider('graduatingStandings')]
        public function testT3GraduatesThree(string $standing): void
        {
            self::assertSame(3, $this->graduated($standing, self::T3));
        }

        #[DataProvider('graduatingStandings')]
        public function testCapHoldsAboveT3(string $standing): void
        {
            // Even a colossal accurate-count never exceeds the +3 cap.
            self::assertSame(3, $this->graduated($standing, 100000));
        }

        public function testMaxBonusClampLowersTheCeiling(): void
        {
            // A tighter cap clamps the ladder result, never the threshold.
            self::assertSame(
                1,
                AttestationService::graduatedSlotsFor('highly_reliable', self::T3, self::T1, self::T2, self::T3, 1)
            );
        }

        // ── §J.3.2.1 pre-consensus band ────────────────────────────────────

        private function preConsensus(string $kind, int $order): bool
        {
            $m = new ReflectionMethod(AttestationService::class, 'isPreConsensusPick');
            $m->setAccessible(true);
            /** @var bool $result */
            $result = $m->invoke(null, $kind, $order);
            return $result;
        }

        public function testVouchIsNeverPreConsensus(): void
        {
            self::assertFalse($this->preConsensus('vouch', 1));
            self::assertFalse($this->preConsensus('vouch', 3));
        }

        public function testStandBehindOrdersOneThroughFiveArePreConsensus(): void
        {
            for ($order = 1; $order <= 5; $order++) {
                self::assertTrue($this->preConsensus('stand_behind', $order), "order $order");
            }
        }

        public function testStandBehindOrderSixIsNotPreConsensus(): void
        {
            self::assertFalse($this->preConsensus('stand_behind', 6));
            self::assertFalse($this->preConsensus('stand_behind', 0));
        }
    }
}
