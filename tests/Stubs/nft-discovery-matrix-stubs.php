<?php

/**
 * Collaborators for {@see \BCC\Trust\Onchain\Support\NftChainCapability::operationMatrix()}.
 *
 * ── WHAT IS FAKED, AND WHAT DELIBERATELY IS NOT ─────────────────────────
 * FAKED — the two things that would otherwise reach a database:
 *
 *   ChainNftCapabilityRepository   the driver-override store
 *   ChainCheckpointRepository      the measured `cw_discovery_state`
 *
 * REAL — everything that decides anything:
 *
 *   NftDriverRegistry              which drivers exist for a chain
 *   NftProviderReadiness           whether each is configured
 *   ChainNftCapabilityOverrides    the available/unavailable value object
 *
 * That split is the point. A test that faked the registry or readiness
 * would be asserting against its own fixture rather than against the model,
 * and the ladder these tests exist to pin is composed almost entirely OUT
 * of those two.
 *
 * `ChainNftCapabilityOverrides` in particular is used verbatim: it is a
 * pure value object, and faking it would erase the distinction between
 * `loaded([])` — "read fine, no overrides" — and `unavailable(...)` — "we
 * know nothing" — which is the single most load-bearing fact in the model.
 *
 * Loads the namespace-scoped `get_option` shim last, so the Solana
 * endpoint-bound refusal path can be driven without WordPress.
 *
 * @package BCC\Trust\Tests
 */

declare(strict_types=1);

namespace BCC\Trust\Onchain\Repositories {

    use BCC\Trust\Onchain\ValueObjects\ChainNftCapabilityOverrides;

    if (!class_exists(ChainNftCapabilityRepository::class, false)) {
        final class ChainNftCapabilityRepository
        {
            /** @var array<int, ChainNftCapabilityOverrides> */
            public static array $forChain = [];

            /** Counted so a test can prove the store is read ONCE per chain. */
            public static int $reads = 0;

            public static function getForChain(int $chainId): ChainNftCapabilityOverrides
            {
                self::$reads++;

                // No seeded entry means a readable store with no override
                // rows — what every install actually has, since nothing in
                // this build writes to that table.
                return self::$forChain[$chainId] ?? ChainNftCapabilityOverrides::loaded([]);
            }

            /**
             * @param list<array{operation: string, driver_key: string, enabled: bool, priority: int}> $rows
             */
            public static function seedLoaded(int $chainId, array $rows = []): void
            {
                self::$forChain[$chainId] = ChainNftCapabilityOverrides::loaded($rows);
            }

            public static function seedUnavailable(int $chainId, string $reason): void
            {
                self::$forChain[$chainId] = ChainNftCapabilityOverrides::unavailable($reason);
            }

            public static function reset(): void
            {
                self::$forChain = [];
                self::$reads    = 0;
            }
        }
    }

    if (!class_exists(ChainCheckpointRepository::class, false)) {
        final class ChainCheckpointRepository
        {
            public const CW_STATE_IDLE        = 'idle';
            public const CW_STATE_BACKFILLING = 'backfilling';
            public const CW_STATE_BACKFILLED  = 'backfilled';
            public const CW_STATE_UNSUPPORTED = 'unsupported';
            public const CW_STATE_PAUSED      = 'paused';

            /** @var array<int, object> */
            public static array $rows = [];

            /**
             * An unseeded chain returns null — "never measured".
             *
             * Production treats that as NOT refused on purpose: the first
             * pass is what creates the measurement, so refusing an
             * unmeasured chain would be a permanent deadlock dressed up as
             * caution.
             */
            public static function get(int $chainId): ?object
            {
                return self::$rows[$chainId] ?? null;
            }

            public static function seedCwState(int $chainId, string $cwState): void
            {
                self::$rows[$chainId] = (object) ['cw_discovery_state' => $cwState];
            }

            public static function reset(): void
            {
                self::$rows = [];
            }
        }
    }
}

namespace {
    require_once __DIR__ . '/nft-capability-stubs.php';
}
