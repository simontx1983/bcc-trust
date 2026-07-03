<?php

declare(strict_types=1);

// ── WP-function shadow ──────────────────────────────────────────────────────
// DeviceFingerprinter::grantSignupConsent calls update_user_meta UNQUALIFIED,
// so PHP resolves it against this namespace first. Defining it here (no WP
// loaded) captures the write for assertion — same pattern as JwtTokenTest.
namespace BCC\Trust\Core\Security {

    if (!function_exists(__NAMESPACE__ . '\\update_user_meta')) {
        /** @param mixed $value */
        function update_user_meta(int $userId, string $key, $value): bool
        {
            \BCC\Trust\Core\Security\Tests\ConsentMetaStore::$writes[] = [$userId, $key, $value];
            return true;
        }
    }
}

namespace BCC\Trust\Core\Security\Tests {

    use BCC\Trust\Core\Security\DeviceFingerprinter;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;

    /** Captures the shadowed update_user_meta writes for assertion. */
    final class ConsentMetaStore
    {
        /** @var list<array{0:int,1:string,2:mixed}> */
        public static array $writes = [];
    }

    /**
     * Pins the two new behaviors that make the device-fingerprint pipeline
     * live: (1) signup grants the per-user consent the storage gate reads,
     * and (2) the client payload's automation tells actually feed scoring
     * (they used to be read from cookies the headless frontend never sets).
     */
    final class DeviceFingerprintConsentTest extends TestCase
    {
        protected function setUp(): void
        {
            ConsentMetaStore::$writes = [];
        }

        public function testSignupStampsConsentMeta(): void
        {
            DeviceFingerprinter::grantSignupConsent(42);

            self::assertSame(
                [[42, '_bcc_fingerprint_consent', 1]],
                ConsentMetaStore::$writes,
                'completing signup must stamp the consent meta hasConsent() reads'
            );
        }

        public function testNonPositiveUserIdIsNoop(): void
        {
            DeviceFingerprinter::grantSignupConsent(0);
            DeviceFingerprinter::grantSignupConsent(-7);

            self::assertSame([], ConsentMetaStore::$writes, 'system actors are never fingerprinted');
        }

        /**
         * @param array<string, mixed> $signals
         * @param list<string>         $expectedSignals
         */
        #[DataProvider('automationCases')]
        public function testClientAutomationSignals(array $signals, array $expectedSignals, int $expectedConfidence): void
        {
            $m = new ReflectionMethod(DeviceFingerprinter::class, 'clientAutomationSignals');
            $m->setAccessible(true);
            /** @var array{signals: list<string>, confidence: int} $out */
            $out = $m->invoke(null, $signals);

            self::assertSame($expectedSignals, $out['signals']);
            self::assertSame($expectedConfidence, $out['confidence']);
        }

        /**
         * @return array<string, array{array<string, mixed>, list<string>, int}>
         */
        public static function automationCases(): array
        {
            return [
                'clean browser'     => [['webdriver' => false, 'outer_zero' => false], [], 0],
                'webdriver flag'    => [['webdriver' => true], ['webdriver_detected'], 25],
                'zero outer height' => [['outer_zero' => true], ['zero_outer_viewport'], 25],
                'both tells'        => [['webdriver' => true, 'outer_zero' => true], ['webdriver_detected', 'zero_outer_viewport'], 50],
                'missing keys'      => [[], [], 0],
                // Strict === true: a forged string/int must NOT inflate the score.
                'forged non-bool'   => [['webdriver' => 'true', 'outer_zero' => 1], [], 0],
            ];
        }
    }
}
