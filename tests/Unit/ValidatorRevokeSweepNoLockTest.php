<?php

declare(strict_types=1);

namespace BCC\Core\Log {
    // bcc-core is not autoloaded in the unit context.
    if (!class_exists(__NAMESPACE__ . '\\Logger', false)) {
        final class Logger
        {
            /** @var list<array{string, array<string, mixed>}> */
            public static array $errors = [];

            /** @param array<string, mixed> $c */
            public static function error(string $m, array $c = []): void
            {
                self::$errors[] = [$m, $c];
            }

            /** @param array<string, mixed> $c */
            public static function warning(string $m, array $c = []): void {}

            /** @param array<string, mixed> $c */
            public static function info(string $m, array $c = []): void {}
        }
    }
}

namespace BCC\Trust\Onchain\Tests\Unit {

    use BCC\Core\Log\Logger;
    use BCC\Trust\Onchain\Services\ValidatorGroupRevokeService;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;
    use ReflectionClass;

    /**
     * The delegator-community revoke sweep holds SWEEP_LOCK for the whole tick
     * (the real-lock behaviour is proven in DelegatorGroupIntegrationTest).
     * Without bcc-core's AdvisoryLock the tick cannot be serialized, so it must
     * fail CLOSED: do nothing at all, and say why.
     *
     * Nothing else is stubbed — no WordPress, no repository, no eligibility
     * service. A tick that went ahead would fatal on its first apply_filters().
     */
    #[CoversClass(ValidatorGroupRevokeService::class)]
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class ValidatorRevokeSweepNoLockTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            if (!defined('ABSPATH')) {
                define('ABSPATH', sys_get_temp_dir() . '/');
            }
            Logger::$errors = [];
        }

        public function testWithoutTheLockPrimitiveTheSweepDoesNothingAndLogsWhy(): void
        {
            self::assertFalse(class_exists('BCC\\Core\\DB\\AdvisoryLock'), 'precondition: no lock primitive');

            $service = (new ReflectionClass(ValidatorGroupRevokeService::class))->newInstanceWithoutConstructor();
            $stats   = $service->sweep();

            self::assertSame(
                ['checked' => 0, 'revoked' => 0, 'skipped_unknown' => 0, 'groups_touched' => 0, 'skipped_overlap' => true],
                $stats
            );
            self::assertCount(1, Logger::$errors);
            self::assertStringContainsString('cannot be serialized', Logger::$errors[0][0]);
            self::assertSame(['lock' => ValidatorGroupRevokeService::SWEEP_LOCK], Logger::$errors[0][1]);
        }

        public function testTheLockIsNotTheHolderSweepsLock(): void
        {
            // Both sweeps run on one cron hook; a shared name would let a slow
            // holder tick silently stop every delegator tick.
            self::assertSame('bcc_validator_group_revoke_sweep', ValidatorGroupRevokeService::SWEEP_LOCK);
            self::assertNotSame('bcc_gated_group_revoke_sweep', ValidatorGroupRevokeService::SWEEP_LOCK);
        }
    }
}
