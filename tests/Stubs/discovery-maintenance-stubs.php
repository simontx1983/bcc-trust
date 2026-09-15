<?php

declare(strict_types=1);

/**
 * Doubles for driving {@see \BCC\Trust\Onchain\Workers\DiscoveryRunMaintenance}.
 *
 * ── THE ONE IDEA IN THIS FILE ───────────────────────────────────────────
 * The claim under test is "the recurring sweep CANNOT create a discovery
 * run". A test that asserted `count($rows)` was unchanged would prove only
 * that it did not create one THIS TIME, on THIS fixture — and the row count
 * is also unchanged by a sweep that inserted and then deleted, or that
 * inserted into a table the assertion does not read.
 *
 * So the fake's `insertQueued()` THROWS. The sweep completing at all is the
 * proof, and it holds for every path through it — the reaper, the
 * re-dispatch loop and the pruner — rather than for the one the fixture
 * happened to exercise. A future edit that gives maintenance the ability to
 * start work does not make an assertion fail politely; it makes the suite
 * fatal at the line that did it.
 *
 * ⚠ THE LEDGER IS THE ONLY WAY A RUN COMES INTO EXISTENCE.
 * `DiscoveryRunRepository::insertQueued()` is the single INSERT site for
 * `wp_bcc_discovery_runs`, and `DiscoveryRunService::createRun()` is its only
 * caller. Trapping it is therefore equivalent to trapping run creation —
 * pinned by `EndpointSafetyCallerInventoryTest`, which fails if a second
 * INSERT site ever appears.
 */

namespace {
    if (!class_exists('BccMaintenanceWorld', false)) {
        final class BccMaintenanceWorld
        {
            /** @var array<int, object> the run ledger */
            public static array $rows = [];

            /** @var list<int> run ids handed to the async dispatcher */
            public static array $dispatched = [];

            /** @var list<int> run ids requeued after a lease expiry */
            public static array $requeued = [];

            /** @var list<int> run ids terminalised as attempt-exhausted */
            public static array $exhausted = [];

            /** Rows the pruner reports removing. */
            public static int $pruned = 0;

            /** Make the dispatcher refuse, as a saturated queue would. */
            public static bool $dispatchAccepts = true;

            /** @var list<string> */
            public static array $log = [];

            public static function reset(): void
            {
                self::$rows            = [];
                self::$dispatched      = [];
                self::$requeued        = [];
                self::$exhausted       = [];
                self::$pruned          = 0;
                self::$dispatchAccepts = true;
                self::$log             = [];
            }

            /** @param array<string, mixed> $overrides */
            public static function seedRun(int $id, int $chainId, string $status, array $overrides = []): void
            {
                self::$rows[$id] = (object) array_merge([
                    'id'            => $id,
                    'chain_id'      => $chainId,
                    'job_kind'      => 'cosmwasm_discovery',
                    'status'        => $status,
                    'attempt_count' => 0,
                    'scan_mode'     => 'incremental',
                    'lease_token'   => null,
                ], $overrides);
            }
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(Logger::class, false)) {
        final class Logger
        {
            public static function reset(): void
            {
                \BccMaintenanceWorld::$log = [];
            }

            /** @param array<string, mixed> $c */
            public static function warning(string $m, array $c = []): void
            {
                \BccMaintenanceWorld::$log[] = 'warning ' . $m;
            }

            /** @param array<string, mixed> $c */
            public static function error(string $m, array $c = []): void
            {
                \BccMaintenanceWorld::$log[] = 'error ' . $m;
            }

            /** @param array<string, mixed> $c */
            public static function info(string $m, array $c = []): void
            {
                \BccMaintenanceWorld::$log[] = 'info ' . $m;
            }

            /** @param array<string, mixed> $c */
            public static function debug(string $m, array $c = []): void
            {
                \BccMaintenanceWorld::$log[] = 'debug ' . $m;
            }
        }
    }
}

namespace BCC\Core\Cron {
    if (!class_exists(AsyncDispatcher::class, false)) {
        final class AsyncDispatcher
        {
            /**
             * @param  list<mixed> $args
             */
            public static function enqueueAsync(string $hook, array $args = [], string $group = ''): bool
            {
                if (!\BccMaintenanceWorld::$dispatchAccepts) {
                    return false;
                }

                \BccMaintenanceWorld::$dispatched[] = (int) ($args[0] ?? 0);

                return true;
            }

            public static function registerRecurring(string $hook, string $interval): bool
            {
                return true;
            }

            public static function scheduleSingle(int $timestamp, string $hook, array $args = []): bool
            {
                return true;
            }
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {
    if (!class_exists(DiscoveryRunRepository::class, false)) {
        final class DiscoveryRunRepository
        {
            public const MAX_ATTEMPTS = 3;

            /**
             * ⚠ THE TRAP. Maintenance has no business here, so reaching this
             * line is the failure — not a value some later assertion might
             * or might not check.
             *
             * @param array<string, mixed> ...$args
             */
            public static function insertQueued(...$args): array
            {
                throw new \LogicException(
                    'DiscoveryRunRepository::insertQueued() was reached from a recurring job. '
                    . 'Only an explicit administrator action may create a discovery run.'
                );
            }

            /** @return list<object> */
            public static function findExpiredLeases(int $limit): array
            {
                $out = [];
                foreach (\BccMaintenanceWorld::$rows as $row) {
                    if ($row->status === 'running' && ($row->lease_expired ?? false)) {
                        $out[] = $row;
                    }
                }

                return array_slice($out, 0, $limit);
            }

            /** @return list<object> */
            public static function findDispatchable(int $limit): array
            {
                $out = [];
                foreach (\BccMaintenanceWorld::$rows as $row) {
                    if ($row->status === 'queued') {
                        $out[] = $row;
                    }
                }

                return array_slice($out, 0, $limit);
            }

            public static function terminalizeExhausted(int $runId): bool
            {
                if (!isset(\BccMaintenanceWorld::$rows[$runId])) {
                    return false;
                }
                \BccMaintenanceWorld::$rows[$runId]->status = 'failed';
                \BccMaintenanceWorld::$exhausted[]          = $runId;

                return true;
            }

            public static function requeueExpiredLease(int $runId): bool
            {
                if (!isset(\BccMaintenanceWorld::$rows[$runId])) {
                    return false;
                }
                \BccMaintenanceWorld::$rows[$runId]->status        = 'queued';
                \BccMaintenanceWorld::$rows[$runId]->lease_expired = false;
                \BccMaintenanceWorld::$requeued[]                  = $runId;

                return true;
            }

            public static function pruneTerminal(int $batch): int
            {
                return \BccMaintenanceWorld::$pruned;
            }
        }
    }
}
