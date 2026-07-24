<?php
/**
 * Stubs for HallProvisioningServiceTest::testProvisionAllReportsNoAdminOwner.
 *
 * Loaded ONLY inside a @runInSeparateProcess subprocess so the main
 * process never sees these definitions. Makes `\PeepSoGroup` exist (so the
 * provisioner passes its PeepSo-availability gate) while `get_users`
 * returns [] (no administrator), driving the ownerless-groups guard.
 */

declare(strict_types=1);

namespace {
    if (!class_exists('PeepSoGroup', false)) {
        // Minimal shape — this test never constructs one; the provisioner
        // returns at the owner gate before reaching group creation.
        class PeepSoGroup
        {
            public function get(string $key): int
            {
                return 0;
            }
        }
    }
}

namespace BCC\Trust\Onchain\Services {
    if (!function_exists('BCC\\Trust\\Onchain\\Services\\get_users')) {
        /**
         * @param array<string, mixed> $args
         * @return list<int>
         */
        function get_users(array $args = []): array
        {
            return [];
        }
    }
}
