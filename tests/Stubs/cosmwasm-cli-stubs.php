<?php

/**
 * WP-CLI stubs for the supervised CosmWasm one-shot command.
 *
 * Loaded ONLY from inside #[RunTestsInSeparateProcesses] subprocesses,
 * the same isolation strategy as cosmwasm-discovery-stubs.php — which
 * this file requires LAST, so its guards skip anything defined here.
 *
 * ── THE ONE THING THAT MATTERS HERE ─────────────────────────────────────
 * `WP_CLI::halt()` really does `exit()`. A fake that merely RECORDS the
 * code would let execution continue past a refusal, and every "the
 * command refuses to X" test would pass while the command carried
 * straight on and did X. So halt() THROWS {@see BccTestCliHalt}, which
 * terminates the call the way `exit` does and carries the code out to the
 * test. `WP_CLI::error($msg, true)` — the exiting form — does the same.
 *
 * NOTE: the constant `WP_CLI` is deliberately NOT defined here. The class
 * and the constant are separate things in wp-cli, the command checks the
 * CONSTANT, and one test has to be able to observe the "not running under
 * WP-CLI" refusal. Every other test defines it itself.
 */

declare(strict_types=1);

namespace {

    if (!class_exists('BccTestCliHalt', false)) {
        /**
         * What `WP_CLI::halt()`'s `exit()` looks like from inside a test.
         *
         * The property is `exitCode`, not `code`: `Exception::$code`
         * already exists and is not readonly, so redeclaring it is a fatal
         * error rather than a shadow.
         */
        final class BccTestCliHalt extends \RuntimeException
        {
            public function __construct(public readonly int $exitCode)
            {
                parent::__construct('WP_CLI::halt(' . $exitCode . ')');
            }
        }
    }

    if (!class_exists('BccTestCliRunner', false)) {
        /**
         * The sliver of `WP_CLI::get_runner()` the REAL dispatcher touches.
         *
         * {@see \WP_CLI\Dispatcher\CompositeCommand::__construct()} calls
         * `WP_CLI::get_runner()->register_early_invoke()` whenever the
         * docblock it is handed carries a `@when` tag — and the CosmWasm
         * command's `run()` carries `@when after_wp_load`. So a test that
         * builds the command through WP-CLI's own
         * {@see \WP_CLI\Dispatcher\CommandFactory} (rather than
         * re-implementing what WP-CLI would have done) needs this to exist.
         * It records rather than ignores, so the hook is assertable.
         */
        final class BccTestCliRunner
        {
            /** @var list<string> every `@when` tag the dispatcher registered. */
            public static array $earlyInvokes = [];

            public static function reset(): void
            {
                self::$earlyInvokes = [];
            }

            /** @param mixed $command */
            public function register_early_invoke(string $when, $command): void
            {
                self::$earlyInvokes[] = $when;
            }
        }
    }

    if (!class_exists('WP_CLI', false)) {
        /** Recording WP-CLI double. */
        final class WP_CLI
        {
            /** @var list<string> */
            public static array $logs = [];

            /** @var list<string> */
            public static array $warnings = [];

            /** @var list<string> */
            public static array $errors = [];

            /** @var list<string> */
            public static array $successes = [];

            /** @var list<array{name: string, class: string}> */
            public static array $commands = [];

            public static function reset(): void
            {
                self::$logs      = [];
                self::$warnings  = [];
                self::$errors    = [];
                self::$successes = [];
                self::$commands  = [];
                \BccTestCliRunner::reset();
            }

            /**
             * Only ever called by WP-CLI's own dispatcher, for `@when`.
             * See {@see \BccTestCliRunner}.
             */
            public static function get_runner(): \BccTestCliRunner
            {
                return new \BccTestCliRunner();
            }

            public static function log(string $message): void
            {
                self::$logs[] = $message;
            }

            public static function line(string $message = ''): void
            {
                self::$logs[] = $message;
            }

            public static function warning(string $message): void
            {
                self::$warnings[] = $message;
            }

            public static function success(string $message): void
            {
                self::$successes[] = $message;
            }

            /** @param bool $exit true = wp-cli exits with status 1 */
            public static function error(string $message, bool $exit = true): void
            {
                self::$errors[] = $message;
                if ($exit) {
                    throw new \BccTestCliHalt(1);
                }
            }

            public static function halt(int $code): void
            {
                throw new \BccTestCliHalt($code);
            }

            /** @param mixed $callable */
            public static function add_command(string $name, $callable): void
            {
                self::$commands[] = ['name' => $name, 'class' => is_string($callable) ? $callable : ''];
            }

            /** Everything the command prints, joined — for substring assertions. */
            public static function output(): string
            {
                return implode("\n", array_merge(self::$logs, self::$warnings, self::$errors, self::$successes));
            }
        }
    }

    if (!function_exists('wp_json_encode')) {
        /**
         * @param mixed $data
         * @return string|false
         */
        function wp_json_encode($data, int $options = 0, int $depth = 512)
        {
            return json_encode($data, $options, $depth);
        }
    }

    if (!function_exists('wp_get_environment_type')) {
        function wp_get_environment_type(): string
        {
            return 'local';
        }
    }

    if (!function_exists('home_url')) {
        function home_url(string $path = ''): string
        {
            return 'https://bcc.test' . $path;
        }
    }
}

namespace BCC\Trust\Core\Security {

    if (!class_exists(__NAMESPACE__ . '\\AuditLogger', false)) {
        /**
         * Audit-table double.
         *
         * Faithful to the production contract in the way that matters
         * here: the real logger persists ACTION / TARGET / TIMESTAMP and
         * DROPS the meta array, which is precisely why the command encodes
         * the outcome into the action name. Recording meta as well lets a
         * test assert the negative — that no secret was ever handed to it.
         */
        final class AuditLogger
        {
            /** @var list<array{action: string, target_id: ?int, meta: array<string, mixed>, target_type: ?string}> */
            public static array $entries = [];

            public static function reset(): void
            {
                self::$entries = [];
            }

            /**
             * @param array<string, mixed> $meta
             */
            public static function log(
                string $action,
                ?int $targetId = null,
                array $meta = [],
                ?string $targetType = null,
                ?int $userId = null
            ): void {
                self::$entries[] = [
                    'action'      => $action,
                    'target_id'   => $targetId,
                    'meta'        => $meta,
                    'target_type' => $targetType,
                ];
            }

            /**
             * PR 7A: the CHECKED variant.
             *
             * This fake predates `logChecked()`, and its absence made every
             * checked audit return null — so the ledger-backed CLI refused
             * every run with `audit_uncommitted`. A fake that cannot
             * succeed tests only the failure path.
             *
             * @param array<string, mixed> $meta
             */
            public static function logChecked(
                string $action,
                ?int $targetId = null,
                array $meta = [],
                ?string $targetType = null,
                ?int $userId = null
            ): ?int {
                self::log($action, $targetId, $meta, $targetType, $userId);

                return count(self::$entries);
            }

            /** @return list<string> every action recorded, in order. */
            public static function actions(): array
            {
                return array_map(
                    static fn(array $entry): string => $entry['action'],
                    self::$entries
                );
            }
        }
    }
}

// ── PR 7A: the run ledger the CLI now goes through ──────────────────────
//
// The command no longer calls the worker directly — every discovery pass is
// recorded, so a CLI run is attributable and recoverable like any other.
// These fakes give the unit suite the ledger surface without a database.

namespace {
    if (!function_exists('get_userdata')) {
        /** @return object|false */
        function get_userdata(int $userId)
        {
            // The CLI tests authorize one administrator: user 2.
            return $userId === 2 ? (object) ['ID' => 2] : false;
        }
    }

    if (!function_exists('user_can')) {
        /** @param mixed $user */
        function user_can($user, string $cap): bool
        {
            $id = is_object($user) ? (int) ($user->ID ?? 0) : (int) $user;

            return $id === 2 && $cap === 'manage_options';
        }
    }

    if (!function_exists('wp_generate_uuid4')) {
        function wp_generate_uuid4(): string
        {
            return sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xffffffff), random_int(0, 0xffffffffffff));
        }
    }
}

namespace BCC\Core\Cron {
    if (!class_exists(AsyncDispatcher::class, false)) {
        final class AsyncDispatcher
        {
            /** @var list<array{hook: string, args: array<mixed>, ts: int}> */
            public static array $scheduled = [];

            /** @param list<mixed> $args */
            public static function enqueueAsync(string $hook, array $args = [], string $group = ''): bool
            {
                // The CLI executes inline; dispatch is accepted and ignored.
                return true;
            }

            /**
             * PR 7.3 — recorded, never executed.
             *
             * ⚠ RECORDED, not swallowed. The supervised CLI must run EXACTLY
             * ONE pass per invocation, and a session continuation that
             * scheduled a chunk here would break that contract silently if
             * the stub simply returned. Keeping the list lets a test assert
             * the CLI path schedules nothing.
             *
             * @param array<mixed> $args
             */
            public static function scheduleSingle(int $timestamp, string $hook, array $args = [], string $group = ''): void
            {
                self::$scheduled[] = ['hook' => $hook, 'args' => $args, 'ts' => $timestamp];
            }
        }
    }
}

namespace BCC\Trust\Core\Security {
    if (!class_exists(TransactionManager::class, false)) {
        final class TransactionManager
        {
            /**
             * @param callable():mixed $callback
             * @return mixed
             */
            public static function run(callable $callback)
            {
                return $callback();
            }

            public static function isInRunTransaction(): bool { return false; }
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {
    // ⚠ ChainCheckpointRepository is deliberately NOT faked here. The
    // delegate below (cosmwasm-discovery-stubs.php) provides a far richer
    // one, and because every fake in this tree is guarded by class_exists,
    // whichever is declared FIRST wins — a thin stub here would silently
    // shadow it and strip the methods the CLI tests rely on.

    if (!class_exists(DiscoveryRunRepository::class, false)) {
        /**
         * Minimal in-memory ledger for the CLI tests.
         *
         * Enough to carry one run through request -> claim -> terminal, so
         * the command's own behaviour (flags, confirmation, exit codes,
         * summary) is what the tests observe. The ledger's own guarantees
         * are proven against a real MySQL in
         * DiscoveryRunLedgerIntegrationTest, not here.
         */
        final class DiscoveryRunRepository
        {
            public const LEASE_SECONDS        = 120;
            public const MAX_ATTEMPTS         = 3;
            public const PICKUP_GRACE_SECONDS = 900;
            public const RETENTION_DAYS       = 90;

            /** @var array<int, array<string, mixed>> */
            public static array $rows = [];
            public static int $nextId = 1;

            /**
             * Make the POST-TERMINAL re-read fail, and only that read.
             *
             * ⚠ PR 7.4 asks what happens when a terminal write is confirmed
             * but the row cannot be read back: the audit must degrade rather
             * than invent totals. A blanket `findById() === null` cannot
             * express that — the executor reads the same row at claim time,
             * and killing that read never reaches the branch under test.
             * Hiding the row only once it is terminal is exactly the window.
             */
            public static bool $hideTerminalRow = false;

            public static function reset(): void
            {
                self::$rows = [];
                self::$nextId = 1;
                self::$hideTerminalRow = false;
            }

            /** @return array{id: int, run_uuid: string}|null */
            public static function insertQueued(
                string $jobKind,
                string $scanMode,
                int $chainId,
                int $requestedBy,
                ?int $retryOfRunId = null
            ): ?array {
                foreach (self::$rows as $row) {
                    if ($row['chain_id'] === $chainId && $row['active_marker'] !== null) {
                        return null;
                    }
                }

                $id   = self::$nextId++;
                $uuid = wp_generate_uuid4();

                self::$rows[$id] = [
                    'id' => $id, 'run_uuid' => $uuid, 'job_kind' => $jobKind,
                    'scan_mode' => $scanMode, 'chain_id' => $chainId,
                    'status' => 'queued', 'active_marker' => 1,
                    'requested_by' => $requestedBy, 'attempt_count' => 0,
                    'retry_of_run_id' => $retryOfRunId, 'audit_degraded' => 0,
                    'stop_reason' => null, 'error_code' => null, 'partial' => 0,
                    'requests_used' => 0, 'pages_fetched' => 0, 'families_seen' => 0,
                    'contracts_seen' => 0, 'collections_emitted' => 0,
                    'collections_denied' => 0,
                ];

                return ['id' => $id, 'run_uuid' => $uuid];
            }

            public static function findActive(string $jobKind, int $chainId): ?object
            {
                foreach (self::$rows as $row) {
                    if ($row['chain_id'] === $chainId && $row['active_marker'] !== null) {
                        return (object) $row;
                    }
                }

                return null;
            }

            public static function findById(int $runId): ?object
            {
                $row = self::$rows[$runId] ?? null;

                if ($row === null) {
                    return null;
                }

                if (self::$hideTerminalRow
                    && in_array((string) ($row['status'] ?? ''), ['succeeded', 'failed', 'cancelled'], true)
                ) {
                    return null;
                }

                return (object) $row;
            }

            public static function claim(int $runId): ?string
            {
                if (($self = self::$rows[$runId] ?? null) === null || $self['status'] !== 'queued') {
                    return null;
                }

                self::$rows[$runId]['status'] = 'running';
                self::$rows[$runId]['attempt_count']++;

                return 'lease-' . $runId;
            }

            /** @param array<string, int> $counts */
            public static function markSucceeded(
                int $runId,
                string $leaseToken,
                string $stopReason,
                bool $partial,
                array $counts = []
            ): bool {
                if (($self = self::$rows[$runId] ?? null) === null || $self['status'] !== 'running') {
                    return false;
                }

                // ⚠ ACCUMULATES, like the real one (PR 7.3). A stub that
                // overwrote would hide a dropped-count regression across
                // chunks — the exact bug the cumulative-count test exists
                // to catch.
                $row = array_merge(self::$rows[$runId], [
                    'status' => 'succeeded', 'active_marker' => null,
                    'stop_reason' => $stopReason, 'partial' => $partial ? 1 : 0,
                ]);
                $row['chunks_used'] = (int) ($row['chunks_used'] ?? 0) + 1;
                foreach ($counts as $k => $v) {
                    $row[$k] = (int) ($row[$k] ?? 0) + (int) $v;
                }
                self::$rows[$runId] = $row;

                return true;
            }

            /**
             * PR 7.3 — release the row for the session's next chunk.
             *
             * ⚠ `active_marker` STAYS SET, exactly as in production: the
             * session is still the one authorized run for this chain. A stub
             * that cleared it would let a "no second run" test pass while
             * production allowed one.
             */
            public static function releaseForNextChunk(
                int $runId,
                string $leaseToken,
                array $counts,
                int $delaySeconds
            ): bool {
                $row = self::$rows[$runId] ?? null;
                if ($row === null || ($row['status'] ?? '') !== 'running') {
                    return false;
                }
                if (($row['lease_token'] ?? null) !== null && $row['lease_token'] !== $leaseToken) {
                    return false;
                }

                $row['status']        = 'queued';
                $row['lease_token']   = null;
                $row['attempt_count'] = 0;
                $row['chunks_used']   = (int) ($row['chunks_used'] ?? 0) + 1;
                $row['next_retry_at'] = gmdate('Y-m-d H:i:s', time() + max(1, $delaySeconds));

                foreach ($counts as $k => $v) {
                    $row[$k] = (int) ($row[$k] ?? 0) + (int) $v;
                }

                self::$rows[$runId] = $row;

                return true;
            }

            public static function markFailed(
                int $runId,
                string $leaseToken,
                string $errorCode,
                ?string $stopReason = null
            ): bool {
                if (($self = self::$rows[$runId] ?? null) === null || $self['status'] !== 'running') {
                    return false;
                }

                self::$rows[$runId]['status'] = 'failed';
                self::$rows[$runId]['active_marker'] = null;
                self::$rows[$runId]['error_code'] = $errorCode;
                self::$rows[$runId]['stop_reason'] = $stopReason;

                return true;
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

namespace {
    // The repositories, the chain registry, the fetcher factory, the
    // transport fake, Logger, AdvisoryLock and the WP shims. Loaded LAST so
    // its class_exists() guards skip everything above.
    require_once __DIR__ . '/cosmwasm-discovery-stubs.php';
}
