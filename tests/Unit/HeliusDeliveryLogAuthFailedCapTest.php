<?php

declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', sys_get_temp_dir() . '/');
    }

    $GLOBALS['__f186_options'] = [];

    if (!function_exists('get_option')) {
        /**
         * @param mixed $default
         * @return mixed
         */
        function get_option(string $name, $default = false)
        {
            return $GLOBALS['__f186_options'][$name] ?? $default;
        }
    }
    if (!function_exists('update_option')) {
        /**
         * @param mixed     $value
         * @param bool|null $autoload
         */
        function update_option(string $name, $value, $autoload = null): bool
        {
            $GLOBALS['__f186_options'][$name] = $value;
            return true;
        }
    }
}

namespace BCC\Trust\Onchain\Tests\Unit {

    use BCC\Trust\Onchain\Services\HeliusDeliveryLog;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    /**
     * F-186 — even at the post-F-49 rate (≈5 auth_failed rows/min), a
     * sustained junk flood filled the 200-row delivery ring in ~40 minutes of
     * quiet and evicted every real delivery row. auth_failed rows are now
     * capped at MAX_AUTH_FAILED_ENTRIES inside the ring: a newer one replaces
     * the oldest auth_failed, never a real row.
     */
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class HeliusDeliveryLogAuthFailedCapTest extends TestCase
    {
        /** @return list<array<string, mixed>> */
        private static function ring(): array
        {
            $rows = $GLOBALS['__f186_options'][HeliusDeliveryLog::OPTION_KEY] ?? [];
            return is_array($rows) ? array_values($rows) : [];
        }

        /** @return list<array<string, mixed>> */
        private static function withOutcome(string $outcome): array
        {
            return array_values(array_filter(self::ring(), static fn (array $r): bool => $r['outcome'] === $outcome));
        }

        private static function authFailed(string $ip): void
        {
            HeliusDeliveryLog::record(['outcome' => HeliusDeliveryLog::OUTCOME_AUTH_FAILED, 'auth_passed' => false, 'ip' => $ip]);
        }

        public function testALongFloodLeavesTheRealDeliveriesIntact(): void
        {
            for ($i = 0; $i < 50; $i++) {
                HeliusDeliveryLog::record(['outcome' => HeliusDeliveryLog::OUTCOME_PROCESSED, 'auth_passed' => true, 'tx_count' => $i]);
            }
            // Hours of flood at the post-F-49 rate, far more than the ring holds.
            for ($i = 0; $i < 1000; $i++) {
                self::authFailed('203.0.113.' . ($i % 250));
            }

            self::assertCount(50, self::withOutcome(HeliusDeliveryLog::OUTCOME_PROCESSED), 'every real delivery survives');
            self::assertCount(HeliusDeliveryLog::MAX_AUTH_FAILED_ENTRIES, self::withOutcome(HeliusDeliveryLog::OUTCOME_AUTH_FAILED));
            self::assertSame(range(0, 49), array_column(self::withOutcome(HeliusDeliveryLog::OUTCOME_PROCESSED), 'tx_count'), 'in order');
        }

        public function testTheNewestAuthFailuresAreTheOnesKept(): void
        {
            for ($i = 1; $i <= 15; $i++) {
                self::authFailed('198.51.100.' . $i);
            }

            self::assertSame(
                array_map(static fn (int $n): string => '198.51.100.' . $n, range(6, 15)),
                array_column(self::withOutcome(HeliusDeliveryLog::OUTCOME_AUTH_FAILED), 'ip')
            );
        }

        public function testRealDeliveriesStillRollOffAtTheRingSize(): void
        {
            for ($i = 0; $i < HeliusDeliveryLog::MAX_ENTRIES + 25; $i++) {
                HeliusDeliveryLog::record(['outcome' => HeliusDeliveryLog::OUTCOME_PROCESSED, 'auth_passed' => true, 'tx_count' => $i]);
            }

            self::assertCount(HeliusDeliveryLog::MAX_ENTRIES, self::ring());
            self::assertSame(25, self::ring()[0]['tx_count'], 'the oldest real rows roll off as before');
        }
    }
}
