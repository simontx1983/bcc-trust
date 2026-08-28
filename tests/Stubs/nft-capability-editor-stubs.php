<?php

/**
 * Collaborators for the NFT capability EDITOR — the write path.
 *
 * ── LOAD ORDER IS LOAD-BEARING ──────────────────────────────────────────
 * This file declares the RICHEST versions of `ChainRepository` and
 * `ChainNftCapabilityRepository` and then requires the scanner-operation
 * stubs at the bottom. Whichever file declares a class first wins the
 * `class_exists` guard in the other, so the fake that knows about the
 * capability writers has to go first — the same trap
 * chains-cw-operations-stubs.php documents having fallen into once.
 *
 * ── WHAT IS FAKED, AND WHAT DELIBERATELY IS NOT ─────────────────────────
 * FAKED — everything that would reach a database or a provider:
 *
 *   ChainRepository               the two capability columns + the projection
 *   ChainNftCapabilityRepository  the override rows + the generation counter
 *   ChainCheckpointRepository     the measured `cw_discovery_state`
 *   CosmwasmDiscoveryWorker       so "no discovery ran" is a COUNT
 *
 * REAL — everything that decides anything:
 *
 *   NftCapabilityEditor          the rules under test
 *   NftChainCapability           the model it asks
 *   NftDriverRegistry            which triples exist
 *   NftProviderReadiness         whether a provider is configured
 *   ChainNftCapabilityOverrides  available / unavailable
 *   RepositoryWriteResult        failure / no-op / mutated
 *
 * The split is the point. Faking the registry would make every
 * "configuration cannot invent a capability" test an assertion about a
 * fixture; faking `RepositoryWriteResult` would erase the distinction
 * between a refused write and one that matched no rows, which is the single
 * most load-bearing fact on the write path.
 *
 * ── THE WRITE FAKES MODEL MySQL, NOT A BOOLEAN ──────────────────────────
 * `upsertOverride` returns 1 for an insert, 2 for a changed update and 0
 * when the stored row already held exactly those values — which is what
 * MySQL reports for `INSERT … ON DUPLICATE KEY UPDATE`, and what makes the
 * no-op path testable at all. `deleteOverride` returns 1 or 0 the same way.
 * A test can also force a failure, or force a concurrent write (affected
 * rows 0, desired state present) to prove no audit row is claimed for it.
 *
 * @package BCC\Trust\Tests
 */

declare(strict_types=1);

namespace BCC\Trust\Onchain\Repositories {

    use BCC\Trust\Onchain\ValueObjects\ChainNftCapabilityOverrides;
    use BCC\Trust\Onchain\ValueObjects\RepositoryWriteResult;

    if (!class_exists(ChainRepository::class, false)) {
        /**
         * The authoritative chain registry, with the capability writers.
         *
         * `$rows` holds the FULL row so a test can prove a write is
         * surgical: a product-support change must not disturb identity, RPC
         * config or `is_active`, and the only way to see that is to compare
         * the whole row before and after.
         */
        final class ChainRepository
        {
            /** @var array<int, object> */
            public static array $rows = [];

            /** @var list<array{chain_id: int, enable: bool}> */
            public static array $discoveryWrites = [];

            /** Every capability write, in order. @var list<array{method: string, chain_id: int, value: bool}> */
            public static array $capabilityWrites = [];

            public static bool $writeResult = true;
            public static ?\Throwable $writeThrows = null;

            public static ?bool $readBackOverride = null;
            public static bool $readBackNull = false;

            public static int $cacheBusts = 0;

            /** Force a capability write to REFUSE (wpdb returned false). */
            public static bool $capabilityWriteFails = false;

            /**
             * Force a capability write to report ZERO affected rows.
             *
             * With `$capabilityConcurrentApply` the stored value still moves
             * — that is the concurrent-writer case: our statement changed
             * nothing because somebody else had already applied it.
             */
            public static bool $capabilityWriteNoOp = false;
            public static bool $capabilityConcurrentApply = false;

            /** Suppress the stored change, so the read-back disagrees. */
            public static bool $capabilityWriteSilentlyDrops = false;

            /**
             * The OTHER request in a race.
             *
             * Invoked once, inside the write, AFTER this request has read
             * and decided and BEFORE the statement evaluates its predicate.
             * That is the only window a service-layer check cannot close, so
             * it is the only place a concurrency test can honestly stand.
             *
             * @var (callable(): void)|null
             */
            public static $interleavedWriter = null;

            public static function getById(int $id): ?object
            {
                if (self::$readBackNull && self::$discoveryWrites !== []) {
                    return null;
                }
                if (self::$readBackNull && self::$capabilityWrites !== []) {
                    return null;
                }

                return self::$rows[$id] ?? null;
            }

            /** @return list<object> */
            public static function getActive(): array
            {
                return array_values(self::$rows);
            }

            /** @return list<object> */
            public static function getAll(): array
            {
                return array_values(self::$rows);
            }

            public static function clearCache(): void
            {
                self::$cacheBusts++;
            }

            public static function setCosmwasmNftDiscoveryEnabled(int $chainId, bool $enable): bool
            {
                self::$discoveryWrites[] = ['chain_id' => $chainId, 'enable' => $enable];

                if (self::$writeThrows !== null) {
                    throw self::$writeThrows;
                }
                if (!self::$writeResult) {
                    return false;
                }

                self::$cacheBusts++;

                if (isset(self::$rows[$chainId])) {
                    $stored = self::$readBackOverride ?? $enable;
                    self::$rows[$chainId]->cosmwasm_nft_discovery_enabled = $stored ? '1' : '0';
                }

                return true;
            }

            // ── The capability writers ──────────────────────────────────

            public static function enableNftProductSupport(int $chainId): RepositoryWriteResult
            {
                return self::applyCapabilityWrite(
                    'enableNftProductSupport',
                    $chainId,
                    true,
                    ['bcc_supports_nft_collections' => '1']
                );
            }

            /**
             * The cascade, modelled as ONE statement.
             *
             * Both columns move together or not at all — a test that saw
             * them move separately here would be testing the fake rather
             * than the single `UPDATE` production runs.
             */
            public static function disableNftProductSupport(int $chainId): RepositoryWriteResult
            {
                return self::applyCapabilityWrite(
                    'disableNftProductSupport',
                    $chainId,
                    false,
                    [
                        'bcc_supports_nft_collections'        => '0',
                        'manual_collection_discovery_enabled' => '0',
                    ]
                );
            }

            /**
             * The CONDITIONAL grant, modelled as MySQL executes it.
             *
             * ── THE PREDICATE IS PART OF THE FAKE, NOT SKIPPED BY IT ────
             * `AND bcc_supports_nft_collections = 1` is evaluated HERE,
             * against the stored row, at write time. A fake that ignored it
             * would let the service's own pre-read stand in for the
             * predicate — and every concurrency test below would then be
             * asserting that the check the service already made was made,
             * which proves nothing about the interleaving they exist for.
             *
             * Zero affected rows therefore has the same two meanings it has
             * in production: already granted, or refused because support is
             * gone.
             */
            public static function grantManualCollectionDiscovery(int $chainId): RepositoryWriteResult
            {
                return self::applyCapabilityWrite(
                    'grantManualCollectionDiscovery',
                    $chainId,
                    true,
                    ['manual_collection_discovery_enabled' => '1'],
                    // The WHERE predicate, evaluated against the row as it
                    // stands when the statement runs.
                    static function (object $row): bool {
                        return property_exists($row, 'bcc_supports_nft_collections')
                            && (string) $row->bcc_supports_nft_collections === '1';
                    }
                );
            }

            /** The UNCONDITIONAL withdrawal. No predicate, by design. */
            public static function withdrawManualCollectionDiscovery(int $chainId): RepositoryWriteResult
            {
                return self::applyCapabilityWrite(
                    'withdrawManualCollectionDiscovery',
                    $chainId,
                    false,
                    ['manual_collection_discovery_enabled' => '0']
                );
            }

            /**
             * Run one capability statement against the in-memory row.
             *
             * `$predicate` is the statement's own `WHERE` clause beyond the
             * primary key. When it is present and does not hold, the
             * statement matches NO ROW: nothing is written and ZERO affected
             * rows are reported — which is what MySQL does, and what makes
             * the conditional grant testable at all.
             *
             * @param array<string, string>       $columns   the columns this statement sets
             * @param (callable(object): bool)|null $predicate extra WHERE, evaluated at write time
             */
            private static function applyCapabilityWrite(
                string $method,
                int $chainId,
                bool $value,
                array $columns,
                ?callable $predicate = null
            ): RepositoryWriteResult {
                self::$capabilityWrites[] = [
                    'method'   => $method,
                    'chain_id' => $chainId,
                    'value'    => $value,
                ];

                if (self::$writeThrows !== null) {
                    throw self::$writeThrows;
                }

                // A hook for the OTHER writer in a race: it runs after this
                // request has decided and immediately before the statement
                // executes, which is exactly the window a service-layer
                // check cannot close.
                if (self::$interleavedWriter !== null) {
                    $hook = self::$interleavedWriter;
                    self::$interleavedWriter = null;   // once, not on the re-read
                    $hook();
                }

                // Production clears the cache after the query regardless of
                // the affected-row count — including on a refusal — so a
                // concurrent writer cannot leave the postcondition read
                // answering from this request's stale memo.
                self::$cacheBusts++;

                if (self::$capabilityWriteFails) {
                    return RepositoryWriteResult::failure();
                }

                $row = self::$rows[$chainId] ?? null;

                // THE STATEMENT'S OWN WHERE CLAUSE. Matching no row is not a
                // failure — it is zero affected rows, and the caller has to
                // work out which of its two meanings applies.
                if ($row !== null && $predicate !== null && !$predicate($row)) {
                    return RepositoryWriteResult::executed(0);
                }

                $apply = !self::$capabilityWriteSilentlyDrops
                    && (!self::$capabilityWriteNoOp || self::$capabilityConcurrentApply);

                if ($apply && $row !== null) {
                    foreach ($columns as $column => $stored) {
                        if (property_exists($row, $column)) {
                            $row->{$column} = $stored;
                        }
                    }
                }

                return RepositoryWriteResult::executed(self::$capabilityWriteNoOp ? 0 : 1);
            }

            public static function seed(
                int $id,
                string $slug = 'cosmos',
                bool $optedIn = false,
                bool $bccSupportsNft = true,
                bool $manualDiscovery = true,
                string $chainType = 'cosmos'
            ): void {
                self::$rows[$id] = (object) [
                    'id'                                  => $id,
                    'slug'                                => $slug,
                    'name'                                => ucfirst($slug),
                    'chain_type'                          => $chainType,
                    'is_active'                           => 1,
                    'is_testnet'                          => 0,
                    'rpc_url'                             => 'https://rpc.' . $slug . '.example',
                    'rest_url'                            => 'https://' . $slug . '.example',
                    'description'                         => 'About ' . $slug . '.',
                    'icon_url'                            => 'https://cdn.example/' . $slug . '.png',
                    'color'                               => '#123456',
                    'cosmwasm_nft_discovery_enabled'      => $optedIn ? '1' : '0',
                    'bcc_supports_nft_collections'        => $bccSupportsNft ? '1' : '0',
                    'manual_collection_discovery_enabled' => $manualDiscovery ? '1' : '0',
                ];
            }

            /**
             * Drop a capability column entirely, as a pre-migration
             * projection would.
             *
             * `unset` and not `= null`: the production reader branches on
             * `array_key_exists`, because "the column is absent" and "the
             * column holds NULL" are different facts and only the first one
             * means "this install cannot say".
             */
            public static function dropColumn(int $id, string $column): void
            {
                if (isset(self::$rows[$id])) {
                    unset(self::$rows[$id]->{$column});
                }
            }

            /** The stored value of one capability column, as a tri-state. */
            public static function storedFlag(int $id, string $column): ?bool
            {
                $row = self::$rows[$id] ?? null;
                if ($row === null || !property_exists($row, $column)) {
                    return null;
                }

                return (string) $row->{$column} === '1';
            }

            public static function reset(): void
            {
                self::$rows                        = [];
                self::$discoveryWrites             = [];
                self::$capabilityWrites            = [];
                self::$writeResult                 = true;
                self::$writeThrows                 = null;
                self::$readBackOverride            = null;
                self::$readBackNull                = false;
                self::$cacheBusts                  = 0;
                self::$capabilityWriteFails        = false;
                self::$capabilityWriteNoOp         = false;
                self::$capabilityConcurrentApply   = false;
                self::$capabilityWriteSilentlyDrops = false;
                self::$interleavedWriter           = null;
            }
        }
    }

    if (!class_exists(ChainNftCapabilityRepository::class, false)) {
        /**
         * The override store, backed by an in-memory table keyed exactly the
         * way the real UNIQUE key is: `(chain_id, operation, driver_key)`.
         *
         * Keying the fake on the real key is what makes the duplicate test
         * meaningful — a second save of the same triple lands on the same
         * array slot here for the same reason it lands on the same row in
         * MySQL, rather than because the fake happened to overwrite.
         */
        final class ChainNftCapabilityRepository
        {
            /** @var array<int, array<string, array{operation: string, driver_key: string, enabled: bool, priority: int}>> */
            public static array $table = [];

            /** @var array<int, ChainNftCapabilityOverrides> Forced read results. */
            public static array $forChain = [];

            public static int $reads = 0;

            /** @var list<array{op: string, chain_id: int, operation: string, driver_key: string, enabled: bool, priority: int}> */
            public static array $writes = [];

            /** @var list<int> every bumpChainGeneration() call, in order */
            public static array $bumps = [];

            public static bool $writeFails = false;

            /**
             * Report ZERO affected rows while still applying the change, or
             * while not applying it — the two concurrency shapes.
             */
            public static bool $writeNoOp = false;
            public static bool $writeSilentlyDrops = false;

            /** Make the SECOND read (the postcondition) unavailable. */
            public static ?string $postconditionUnavailable = null;

            /**
             * The OTHER request in a race, invoked once immediately before
             * the upsert evaluates what is stored. @var (callable(): void)|null
             */
            public static $interleavedWriter = null;

            public static function getForChain(int $chainId): ChainNftCapabilityOverrides
            {
                self::$reads++;

                if (self::$postconditionUnavailable !== null && self::$writes !== []) {
                    return ChainNftCapabilityOverrides::unavailable(self::$postconditionUnavailable);
                }
                if (isset(self::$forChain[$chainId])) {
                    return self::$forChain[$chainId];
                }

                return ChainNftCapabilityOverrides::loaded(
                    array_values(self::$table[$chainId] ?? [])
                );
            }

            public static function upsertOverride(
                int $chainId,
                string $operation,
                string $driverKey,
                bool $enabled,
                int $priority
            ): RepositoryWriteResult {
                self::$writes[] = [
                    'op'         => 'upsert',
                    'chain_id'   => $chainId,
                    'operation'  => $operation,
                    'driver_key' => $driverKey,
                    'enabled'    => $enabled,
                    'priority'   => $priority,
                ];

                if (self::$interleavedWriter !== null) {
                    $hook = self::$interleavedWriter;
                    self::$interleavedWriter = null;
                    $hook();
                }

                if (self::$writeFails) {
                    return RepositoryWriteResult::failure();
                }

                // Read AFTER the interleave, exactly as MySQL evaluates the
                // stored row when the statement actually runs.
                $key      = self::key($operation, $driverKey);
                $existing = self::$table[$chainId][$key] ?? null;

                if (!self::$writeSilentlyDrops && !self::$writeNoOp) {
                    self::$table[$chainId][$key] = [
                        'operation'  => $operation,
                        'driver_key' => $driverKey,
                        'enabled'    => $enabled,
                        'priority'   => $priority,
                    ];
                }

                if (self::$writeNoOp) {
                    return RepositoryWriteResult::executed(0);
                }

                // MySQL: 1 = inserted, 2 = updated, 0 = identical values.
                if ($existing !== null
                    && $existing['enabled'] === $enabled
                    && $existing['priority'] === $priority
                ) {
                    return RepositoryWriteResult::executed(0);
                }

                return RepositoryWriteResult::executed($existing === null ? 1 : 2);
            }

            public static function deleteOverride(
                int $chainId,
                string $operation,
                string $driverKey
            ): RepositoryWriteResult {
                self::$writes[] = [
                    'op'         => 'delete',
                    'chain_id'   => $chainId,
                    'operation'  => $operation,
                    'driver_key' => $driverKey,
                    'enabled'    => false,
                    'priority'   => 0,
                ];

                if (self::$writeFails) {
                    return RepositoryWriteResult::failure();
                }

                $key    = self::key($operation, $driverKey);
                $exists = isset(self::$table[$chainId][$key]);

                if (!self::$writeSilentlyDrops && !self::$writeNoOp) {
                    unset(self::$table[$chainId][$key]);
                }

                if (self::$writeNoOp) {
                    return RepositoryWriteResult::executed(0);
                }

                return RepositoryWriteResult::executed($exists ? 1 : 0);
            }

            public static function bumpChainGeneration(int $chainId): void
            {
                self::$bumps[] = $chainId;
            }

            public static function getChainGeneration(int $chainId): int
            {
                return count(array_filter(self::$bumps, static fn(int $id): bool => $id === $chainId));
            }

            private static function key(string $operation, string $driverKey): string
            {
                return $operation . '|' . $driverKey;
            }

            /** Seed the in-memory table directly — the pre-existing state. */
            public static function seedRow(
                int $chainId,
                string $operation,
                string $driverKey,
                bool $enabled,
                int $priority = 10
            ): void {
                self::$table[$chainId][self::key($operation, $driverKey)] = [
                    'operation'  => $operation,
                    'driver_key' => $driverKey,
                    'enabled'    => $enabled,
                    'priority'   => $priority,
                ];
            }

            /** @return list<array{operation: string, driver_key: string, enabled: bool, priority: int}> */
            public static function storedRows(int $chainId): array
            {
                return array_values(self::$table[$chainId] ?? []);
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
                self::$table                    = [];
                self::$forChain                 = [];
                self::$reads                    = 0;
                self::$writes                   = [];
                self::$bumps                    = [];
                self::$writeFails               = false;
                self::$writeNoOp                = false;
                self::$writeSilentlyDrops       = false;
                self::$postconditionUnavailable = null;
                self::$interleavedWriter        = null;
            }
        }
    }
}

namespace {
    require_once __DIR__ . '/chains-cw-operations-stubs.php';

    // The namespace-scoped `get_option` shim, so NftProviderReadiness can
    // resolve the Solana DAS-unsupported mark without WordPress. Required
    // LAST: it declares only a function and a small state holder, so it
    // cannot win a class_exists race against the richer fakes above, and
    // every Solana readiness path needs it.
    require_once __DIR__ . '/nft-capability-stubs.php';
}
