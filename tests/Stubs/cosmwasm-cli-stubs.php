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

namespace {
    // The repositories, the chain registry, the fetcher factory, the
    // transport fake, Logger, AdvisoryLock and the WP shims. Loaded LAST so
    // its class_exists() guards skip everything above.
    require_once __DIR__ . '/cosmwasm-discovery-stubs.php';
}
