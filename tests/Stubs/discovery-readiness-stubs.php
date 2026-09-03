<?php

declare(strict_types=1);

/**
 * The minimum WordPress surface `DiscoveryReadiness` needs to LOAD.
 *
 * `evaluate()` and `isNftDiscoverySurface()` are pure — they call no
 * repository and read no constant. But the class file itself imports the
 * repositories its wired entry points use, and every BCC file guards on
 * ABSPATH, so the autoloader still needs those symbols to exist.
 *
 * Deliberately NOT a copy of discovery-run-stubs.php: this file exists to
 * let the PURE rule be tested with nothing else in the way. Anything that
 * needs a seeded chain, a checkpoint or a run belongs in a test that loads
 * the fuller stub set.
 */

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', __DIR__ . '/');
    }
}

namespace BCC\Trust\Onchain\Repositories {
    if (!class_exists(ChainCheckpointRepository::class, false)) {
        final class ChainCheckpointRepository
        {
            public const CW_STATE_IDLE        = 'idle';
            public const CW_STATE_PAUSED      = 'paused';
            public const CW_STATE_UNSUPPORTED = 'unsupported';
            public const CW_STATE_BACKFILLED  = 'backfilled';

            public static function get(int $chainId): ?object
            {
                return null;
            }
        }
    }

    if (!class_exists(ChainRepository::class, false)) {
        final class ChainRepository
        {
            public static function getById(int $id): ?object
            {
                return null;
            }
        }
    }

    if (!class_exists(DiscoveryRunRepository::class, false)) {
        final class DiscoveryRunRepository
        {
            public static function findActive(string $jobKind, int $chainId): ?object
            {
                return null;
            }
        }
    }
}

namespace BCC\Trust\Onchain\Support {
    if (!class_exists(CosmwasmDiscoveryGate::class, false)) {
        final class CosmwasmDiscoveryGate
        {
            public static function discoveryEnabled(): bool
            {
                return defined('BCC_COSMWASM_DISCOVERY_ENABLED')
                    && (bool) constant('BCC_COSMWASM_DISCOVERY_ENABLED');
            }

            public static function backfillEnabled(): bool
            {
                return self::discoveryEnabled()
                    && defined('BCC_COSMWASM_BACKFILL_ENABLED')
                    && (bool) constant('BCC_COSMWASM_BACKFILL_ENABLED');
            }

            /** @return list<int>|null */
            public static function chainAllowlist(): ?array
            {
                return null;
            }
        }
    }
}
