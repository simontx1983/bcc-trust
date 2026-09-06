<?php

declare(strict_types=1);

/**
 * Stubs for the PR 7A discovery-run unit tests.
 *
 * ── WHAT IS FAKED AND WHAT IS NOT ───────────────────────────────────────
 * The SERVICE is under test, so its collaborators are fakes: the run
 * repository, the chain and checkpoint repositories, and the async
 * dispatcher. The value objects are NOT faked — they are pure, closed
 * vocabularies, and a fake of a vocabulary tests only the fake.
 *
 * ⚠ The repository fake models the DATABASE's guarantee, not a convenient
 * approximation of it. `uq_active` is reproduced literally: an insert for a
 * (job_kind, chain_id) that already has an active row returns null, exactly
 * as a duplicate-key violation does. A fake that let the second insert
 * succeed would make the race tests pass against broken code.
 */

// ── WordPress surface ───────────────────────────────────────────────────

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', __DIR__ . '/');
    }

    if (!class_exists('BccDiscoveryTestState')) {
        final class BccDiscoveryTestState
        {
            /** @var array<int, array{caps: list<string>}> */
            public static array $users = [];

            /** Dispatch acceptances recorded as [hook, args]. */
            /** @var list<array{0: string, 1: array<int, mixed>}> */
            public static array $dispatched = [];

            /** Makes enqueueAsync() report a soft failure. */
            public static bool $dispatchAccepts = true;

            /** PR 7.3 — the timestamps continuations were scheduled for. */
            /** @var list<int> */
            public static array $scheduledAt = [];

            public static function reset(): void
            {
                self::$users = [];
                self::$dispatched = [];
                self::$dispatchAccepts = true;
                self::$scheduledAt = [];
            }

            public static function seedAdmin(int $id): void
            {
                self::$users[$id] = ['caps' => ['manage_options']];
            }

            public static function seedSubscriber(int $id): void
            {
                self::$users[$id] = ['caps' => []];
            }
        }
    }

    if (!function_exists('get_userdata')) {
        /** @return object|false */
        function get_userdata(int $userId)
        {
            if (!isset(\BccDiscoveryTestState::$users[$userId])) {
                return false;
            }

            return (object) ['ID' => $userId];
        }
    }

    if (!function_exists('user_can')) {
        /** @param mixed $user */
        function user_can($user, string $cap): bool
        {
            $id = is_object($user) ? (int) ($user->ID ?? 0) : (int) $user;

            return in_array($cap, \BccDiscoveryTestState::$users[$id]['caps'] ?? [], true);
        }
    }

    if (!function_exists('wp_generate_uuid4')) {
        function wp_generate_uuid4(): string
        {
            return sprintf(
                '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                random_int(0, 0xffff),
                random_int(0, 0xffff),
                random_int(0, 0xffff),
                random_int(0, 0x0fff) | 0x4000,
                random_int(0, 0x3fff) | 0x8000,
                random_int(0, 0xffff),
                random_int(0, 0xffff),
                random_int(0, 0xffff)
            );
        }
    }
}

// ── bcc-core ────────────────────────────────────────────────────────────

namespace BCC\Core\Log {
    if (!class_exists(Logger::class, false)) {
        final class Logger
        {
            /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
            public static array $lines = [];

            public static function reset(): void { self::$lines = []; }

            /** @param array<string, mixed> $c */
            public static function error(string $m, array $c = []): void
            {
                self::$lines[] = ['level' => 'error', 'message' => $m, 'context' => $c];
            }

            /** @param array<string, mixed> $c */
            public static function info(string $m, array $c = []): void
            {
                self::$lines[] = ['level' => 'info', 'message' => $m, 'context' => $c];
            }

            /** @param array<string, mixed> $c */
            public static function warning(string $m, array $c = []): void
            {
                self::$lines[] = ['level' => 'warning', 'message' => $m, 'context' => $c];
            }

            /** @return list<array{level: string, message: string, context: array<string, mixed>}> */
            public static function ofLevel(string $level): array
            {
                return array_values(array_filter(self::$lines, static fn(array $l): bool => $l['level'] === $level));
            }
        }
    }
}

namespace BCC\Core\Cron {
    if (!class_exists(AsyncDispatcher::class, false)) {
        final class AsyncDispatcher
        {
            /** @param list<mixed> $args */
            public static function enqueueAsync(string $hook, array $args = [], string $group = ''): bool
            {
                if (!\BccDiscoveryTestState::$dispatchAccepts) {
                    return false;
                }

                \BccDiscoveryTestState::$dispatched[] = [$hook, $args];

                return true;
            }

            /**
             * PR 7.3 — a delayed continuation of an ALREADY AUTHORIZED run.
             *
             * ⚠ RECORDED IN THE SAME LIST as enqueueAsync, on purpose. Every
             * "no automatic run creation" test counts dispatches; if
             * continuations went to a separate list they would be invisible
             * to exactly the assertions that exist to bound them.
             *
             * @param array<mixed> $args
             */
            public static function scheduleSingle(int $timestamp, string $hook, array $args = [], string $group = ''): void
            {
                if (!\BccDiscoveryTestState::$dispatchAccepts) {
                    return;
                }

                \BccDiscoveryTestState::$dispatched[] = [$hook, $args];
                \BccDiscoveryTestState::$scheduledAt[] = $timestamp;
            }
        }
    }
}

// ── Security ────────────────────────────────────────────────────────────

namespace BCC\Trust\Core\Security {
    if (!class_exists(AuditLogger::class, false)) {
        final class AuditLogger
        {
            /** @var list<array{action: string, targetId: int|null, meta: array<string, mixed>, targetType: string|null, userId: int|null}> */
            public static array $rows = [];

            /** Every checked write fails. */
            public static bool $failChecked = false;

            /** Only these actions fail — an aimed fault. */
            /** @var list<string> */
            public static array $failCheckedActions = [];

            public static function reset(): void
            {
                self::$rows = [];
                self::$failChecked = false;
                self::$failCheckedActions = [];
            }

            /** @param array<string, mixed> $meta */
            public static function logChecked(
                string $action,
                ?int $targetId = null,
                array $meta = [],
                ?string $targetType = null,
                ?int $userId = null
            ): ?int {
                if (self::$failChecked || in_array($action, self::$failCheckedActions, true)) {
                    return null;
                }

                self::$rows[] = [
                    'action'     => $action,
                    'targetId'   => $targetId,
                    'meta'       => $meta,
                    'targetType' => $targetType,
                    'userId'     => $userId,
                ];

                return count(self::$rows);
            }

            /** @param array<string, mixed> $meta */
            public static function log(
                string $action,
                ?int $targetId = null,
                array $meta = [],
                ?string $targetType = null,
                ?int $userId = null
            ): void {
                self::logChecked($action, $targetId, $meta, $targetType, $userId);
            }

            /** @return list<string> */
            public static function actions(): array
            {
                return array_map(static fn(array $r): string => $r['action'], self::$rows);
            }
        }
    }

    if (!class_exists(TransactionManager::class, false)) {
        final class TransactionManager
        {
            public static bool $forceRollback = false;

            public static function reset(): void { self::$forceRollback = false; }

            /**
             * Runs the callback. A throw propagates, and the caller's catch
             * is what the rollback tests observe — the fake does not swallow
             * it, because swallowing is precisely the bug those tests hunt.
             *
             * @param callable():mixed $callback
             * @return mixed
             */
            public static function run(callable $callback)
            {
                $before = \BCC\Trust\Onchain\Repositories\DiscoveryRunRepository::snapshot();

                try {
                    $result = $callback();
                } catch (\Throwable $e) {
                    \BCC\Trust\Onchain\Repositories\DiscoveryRunRepository::restore($before);
                    throw $e;
                }

                if (self::$forceRollback) {
                    \BCC\Trust\Onchain\Repositories\DiscoveryRunRepository::restore($before);
                    throw new \RuntimeException('forced rollback');
                }

                return $result;
            }

            public static function isInRunTransaction(): bool { return false; }
        }
    }
}

// ── Onchain repositories ────────────────────────────────────────────────

namespace BCC\Trust\Onchain\Repositories {
    if (!class_exists(ChainRepository::class, false)) {
        final class ChainRepository
        {
            /** @var array<int, object> */
            public static array $chains = [];

            public static function reset(): void { self::$chains = []; }

            /**
             * @param int|null $supportsNft PR 7.1 — the product-support
             *        switch `bcc_supports_nft_collections`. Defaults to 1 so
             *        pre-7.1 tests keep exercising what they were written
             *        for. NULL seeds a row where the COLUMN IS ABSENT, which
             *        is what a pre-migration install looks like and must
             *        read as "not supported", never as "supported".
             */
            public static function seed(
                int $id,
                string $slug,
                string $type = 'cosmos',
                int $active = 1,
                int $discoveryEnabled = 1,
                ?int $supportsNft = 1
            ): void {
                $row = [
                    'id'                             => $id,
                    'slug'                           => $slug,
                    'chain_type'                     => $type,
                    'is_active'                      => (string) $active,
                    'cosmwasm_nft_discovery_enabled' => (string) $discoveryEnabled,
                ];

                if ($supportsNft !== null) {
                    $row['bcc_supports_nft_collections'] = (string) $supportsNft;
                }

                self::$chains[$id] = (object) $row;
            }

            public static function getById(int $id): ?object
            {
                return self::$chains[$id] ?? null;
            }
        }
    }

    if (!class_exists(ChainCheckpointRepository::class, false)) {
        final class ChainCheckpointRepository
        {
            // `CosmwasmScanEligibility::verdict()` reads these by name. The
            // service reuses that canonical rule rather than re-deriving
            // eligibility, so the fake has to carry the same vocabulary —
            // omitting them made every request fatal on an undefined
            // constant, which surfaced as "the gate refused".
            public const CW_STATE_IDLE        = 'idle';
            public const CW_STATE_BACKFILLING = 'backfilling';
            public const CW_STATE_BACKFILLED  = 'backfilled';
            public const CW_STATE_UNSUPPORTED = 'unsupported';
            public const CW_STATE_PAUSED      = 'paused';

            /** @var array<int, object> */
            public static array $rows = [];

            public static function reset(): void { self::$rows = []; }

            public static function seed(int $chainId, ?string $backfillCompletedAt): void
            {
                self::$rows[$chainId] = (object) [
                    'chain_id'                 => $chainId,
                    'cw_backfill_completed_at' => $backfillCompletedAt,
                ];
            }

            public static function get(int $chainId): ?object
            {
                return self::$rows[$chainId] ?? null;
            }
        }
    }

    if (!class_exists(DiscoveryRunRepository::class, false)) {
        /**
         * In-memory ledger that reproduces the DATABASE's guarantees.
         *
         * ⚠ `uq_active` is modelled literally. An insert for a target that
         * already holds an active row returns null, exactly as a duplicate
         * key does. Anything looser and the race tests would pass against
         * code that never checked.
         */
        final class DiscoveryRunRepository
        {
            public const LEASE_SECONDS        = 120;
            public const MAX_ATTEMPTS         = 3;
            public const RETRY_BACKOFF_SECONDS = [60, 300, 900];
            public const PICKUP_GRACE_SECONDS = 900;
            public const RETENTION_DAYS       = 90;

            /** @var array<int, array<string, mixed>> */
            public static array $rows = [];

            public static int $nextId = 1;

            /** Makes the next insert fail outright (not a duplicate key). */
            public static bool $insertFails = false;

            /**
             * Terminalize the active row the instant an insert loses,
             * modelling the winner finishing between our INSERT and SELECT.
             */
            public static bool $terminalizeOnConflict = false;

            /** How many inserts were attempted — proves the retry loop ran. */
            public static int $insertAttempts = 0;

            public static function reset(): void
            {
                self::$rows = [];
                self::$nextId = 1;
                self::$insertFails = false;
                self::$terminalizeOnConflict = false;
                self::$insertAttempts = 0;
            }

            /** @return array<int, array<string, mixed>> */
            public static function snapshot(): array { return self::$rows; }

            /** @param array<int, array<string, mixed>> $rows */
            public static function restore(array $rows): void { self::$rows = $rows; }

            /** @return array{id: int, run_uuid: string}|null */
            public static function insertQueued(
                string $jobKind,
                string $scanMode,
                int $chainId,
                int $requestedBy,
                ?int $retryOfRunId = null
            ): ?array {
                self::$insertAttempts++;

                if (self::$insertFails) {
                    return null;
                }

                foreach (self::$rows as $id => $row) {
                    if ($row['job_kind'] === $jobKind
                        && $row['chain_id'] === $chainId
                        && $row['active_marker'] !== null
                    ) {
                        if (self::$terminalizeOnConflict) {
                            // The winner just finished; the slot is free for
                            // the caller's next attempt.
                            self::$rows[$id]['active_marker'] = null;
                            self::$rows[$id]['status'] = 'succeeded';
                        }

                        return null;
                    }
                }

                $id = self::$nextId++;
                $uuid = wp_generate_uuid4();

                self::$rows[$id] = [
                    'id'              => $id,
                    'run_uuid'        => $uuid,
                    'job_kind'        => $jobKind,
                    'scan_mode'       => $scanMode,
                    'chain_id'        => $chainId,
                    'status'          => 'queued',
                    'active_marker'   => 1,
                    'requested_by'    => $requestedBy,
                    'attempt_count'   => 0,
                    'retry_of_run_id' => $retryOfRunId,
                    'audit_degraded'  => 0,
                ];

                return ['id' => $id, 'run_uuid' => $uuid];
            }

            public static function findActive(string $jobKind, int $chainId): ?object
            {
                foreach (self::$rows as $row) {
                    if ($row['job_kind'] === $jobKind
                        && $row['chain_id'] === $chainId
                        && $row['active_marker'] !== null
                    ) {
                        return (object) $row;
                    }
                }

                return null;
            }

            public static function findById(int $runId): ?object
            {
                return isset(self::$rows[$runId]) ? (object) self::$rows[$runId] : null;
            }

            public static function markCancelled(int $runId): bool
            {
                if (($self = self::$rows[$runId] ?? null) === null || $self['status'] !== 'queued') {
                    return false;
                }

                self::$rows[$runId]['status'] = 'cancelled';
                self::$rows[$runId]['active_marker'] = null;

                return true;
            }

            public static function markAuditDegraded(int $runId): bool
            {
                if (!isset(self::$rows[$runId])) {
                    return false;
                }

                self::$rows[$runId]['audit_degraded'] = 1;

                return true;
            }
        }
    }
}
