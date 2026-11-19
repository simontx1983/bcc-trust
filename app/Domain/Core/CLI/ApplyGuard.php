<?php
/**
 * The shared safety rules of a dry-run-first repair command:
 *
 *     wp bcc-trust <command>                                          # dry run: reads only
 *     wp bcc-trust <command> --apply --confirm=<token> --user-id=<id> # apply
 *
 *   - nothing is written without the bare --apply flag;
 *   - --apply needs the exact token the dry run printed, bound to BCC_ENV
 *     and to the exact plan: a staging token cannot apply on production,
 *     and a token minted before the data moved is refused;
 *   - --apply needs --user-id of an existing administrator (manage_options),
 *     recorded in the audit row;
 *   - unknown flags and positional arguments are refused.
 *
 * Used by `block-follows backfill` and `attestations prune-orphans`.
 *
 * @package BCC\Trust\Core\CLI
 */

declare(strict_types=1);

namespace BCC\Trust\Core\CLI;

if (!defined('ABSPATH')) {
    exit;
}

final class ApplyGuard
{
    public const EXIT_INVALID_ARGS = 2;
    public const EXIT_FAILED       = 3;

    private const ALLOWED_FLAGS = ['apply', 'confirm', 'user-id'];

    /**
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     * @return array{ok: true, apply: bool, confirm: string|null, user_id: string|null}|array{ok: false, error: string}
     */
    public static function parse(array $args, array $assoc): array
    {
        if ($args !== []) {
            return ['ok' => false, 'error' => 'This command takes no positional arguments.'];
        }
        $unknown = array_values(array_diff(array_keys($assoc), self::ALLOWED_FLAGS));
        if ($unknown !== []) {
            return ['ok' => false, 'error' => 'Unknown flag(s): --' . implode(', --', array_map('strval', $unknown)) . '. Accepted: --apply, --confirm=<token>, --user-id=<id>.'];
        }

        $apply = false;
        if (array_key_exists('apply', $assoc)) {
            if ($assoc['apply'] !== true) {
                return ['ok' => false, 'error' => '--apply must be the bare flag.'];
            }
            $apply = true;
        }
        $confirm = null;
        if (array_key_exists('confirm', $assoc)) {
            if (!is_string($assoc['confirm']) || $assoc['confirm'] === '') {
                return ['ok' => false, 'error' => '--confirm requires a value.'];
            }
            $confirm = $assoc['confirm'];
        }
        $userId = null;
        if (array_key_exists('user-id', $assoc)) {
            if (!is_string($assoc['user-id']) && !is_int($assoc['user-id'])) {
                return ['ok' => false, 'error' => '--user-id requires a numeric value.'];
            }
            $userId = (string) $assoc['user-id'];
        }

        return ['ok' => true, 'apply' => $apply, 'confirm' => $confirm, 'user_id' => $userId];
    }

    /**
     * A token bound to the environment and to the exact plan: the same keys
     * in any order give the same token; any other plan or environment does not.
     *
     * @param list<string> $planKeys
     */
    public static function token(string $env, array $planKeys): string
    {
        sort($planKeys);
        return substr(hash('sha256', $env . '|' . implode(',', $planKeys)), 0, 16);
    }

    public static function requireOperator(?string $raw): int
    {
        if ($raw === null || !ctype_digit($raw) || (int) $raw <= 0) {
            self::fail('--apply requires --user-id=<administrator-id>.', self::EXIT_INVALID_ARGS);
        }
        $id = (int) $raw;
        if (get_userdata($id) === false || !user_can($id, 'manage_options')) {
            self::fail('--user-id must be an existing user holding manage_options.', self::EXIT_INVALID_ARGS);
        }
        return $id;
    }

    public static function environment(): string
    {
        $env = defined('BCC_ENV') ? (string) constant('BCC_ENV') : 'unknown';
        return preg_replace('/[^a-z0-9_-]/i', '', $env) ?: 'unknown';
    }

    public static function fail(string $message, int $code): never
    {
        \WP_CLI::error($message, false);
        \WP_CLI::halt($code);
    }
}
