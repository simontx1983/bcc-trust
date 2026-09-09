<?php

/**
 * Hall-specific fakes for OnchainAdminHallCreateTest.
 *
 * ⚠ DELIBERATELY NOT IN onchain-admin-action-stubs.php.
 *
 * That file is loaded by a dozen admin tests, and a fake declared there WINS
 * over the autoloader for every one of them. An `OnchainPlugin` carrying only
 * `hallProvisioningService()` therefore broke unrelated suites that call
 * `gatedGroupProvisioningService()` and `communityRequestService()` on the
 * real class. Keeping these here means only the test that wants them pays for
 * them.
 *
 * Load AFTER onchain-admin-action-stubs.php.
 */

declare(strict_types=1);

namespace BCC\Trust\Onchain\Services {

    if (!class_exists(HallProvisioningService::class, false)) {
        /**
         * Recording Hall creator.
         *
         * The handler test is about the REQUEST BOUNDARY — capability, method,
         * per-chain nonce, target resolution, PRG mapping — so the service is
         * a fake whose result the test dictates. What the service itself does
         * with PeepSo is pinned separately in HallProvisioningServiceTest
         * against its own harness.
         */
        final class HallProvisioningService
        {
            public const AUDIT_CREATED     = 'admin_hall_created';
            public const AUDIT_FAILED      = 'admin_hall_create_failed';
            public const AUDIT_INCOMPLETE  = 'admin_hall_owner_incomplete';
            public const AUDIT_COMPENSATED = 'admin_hall_create_compensated';

            /** Chain ids passed to provisionOne(), in order. */
            public static array $calls = [];

            /** @var array<string, mixed> */
            public static array $result = [
                'status'         => 'created',
                'group_id'       => 900,
                'message'        => 'Hall created.',
                'failure_code'   => null,
                'owner_id'       => 3,
                'audit_degraded' => false,
            ];

            public static ?\Throwable $throws = null;

            /** @return array<string, mixed> */
            public function provisionOne(int $chainId): array
            {
                self::$calls[] = $chainId;

                if (self::$throws !== null) {
                    throw self::$throws;
                }

                return self::$result;
            }

            public static function reset(): void
            {
                self::$calls  = [];
                self::$throws = null;
                self::$result = [
                    'status'         => 'created',
                    'group_id'       => 900,
                    'message'        => 'Hall created.',
                    'failure_code'   => null,
                    'owner_id'       => 3,
                    'audit_degraded' => false,
                ];
            }
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {

    if (!class_exists(HallRepository::class, false)) {
        final class HallRepository
        {
            public const META_KIND             = '_bcc_group_kind';
            public const META_CHAIN_TAG        = '_bcc_chain_tag';
            public const META_OWNER_INCOMPLETE = '_bcc_hall_owner_incomplete';
            public const KIND_HALL             = 'hall';

            /** chainId => groupId */
            public static array $halls = [];

            /** groupId => true */
            public static array $incomplete = [];

            /** @param list<int> $chainIds @return array<int, int> */
            public static function findHallsForChains(array $chainIds): array
            {
                $out = [];
                foreach ($chainIds as $chainId) {
                    if (isset(self::$halls[(int) $chainId])) {
                        $out[(int) $chainId] = self::$halls[(int) $chainId];
                    }
                }
                return $out;
            }

            public static function findHallForChain(int $chainId): ?int
            {
                return self::$halls[$chainId] ?? null;
            }

            public static function isOwnerIncomplete(int $groupId): bool
            {
                return isset(self::$incomplete[$groupId]);
            }

            /** @return list<int> */
            public static function listAllHallIds(int $limit = 500): array
            {
                return array_values(self::$halls);
            }

            public static function reset(): void
            {
                self::$halls      = [];
                self::$incomplete = [];
            }
        }
    }
}

namespace BCC\Trust\Onchain {

    if (!class_exists(OnchainPlugin::class, false)) {
        final class OnchainPlugin
        {
            private static ?self $instance = null;

            public static function instance(): self
            {
                return self::$instance ??= new self();
            }

            public function hallProvisioningService(): \BCC\Trust\Onchain\Services\HallProvisioningService
            {
                return new \BCC\Trust\Onchain\Services\HallProvisioningService();
            }

            public static function reset(): void
            {
                self::$instance = null;
            }
        }
    }
}
