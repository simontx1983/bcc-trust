<?php
/**
 * `wp bcc-trust block-follows backfill` — F-261: remove the follows that
 * blocks made before F-29 left behind (see BlockFollowBackfill).
 *
 * THIS IS ITS ONLY ENTRY POINT: no REST route, cron hook, migration or
 * admin handler reaches it. It writes PeepSo's follow graph, so it runs when
 * a named administrator runs it. Same safety as the other graph repairs:
 *
 *     # Dry run — reads only, prints the plan size and the token
 *     wp bcc-trust block-follows backfill
 *
 *     # Apply — needs --apply, the exact token and an administrator
 *     wp bcc-trust block-follows backfill --apply --confirm=<token> --user-id=<id>
 *
 * The token is bound to BCC_ENV and to the exact planned pairs: a staging
 * token cannot apply on production, and a token minted before the data moved
 * is refused (re-run the dry run). Idempotent — a re-run severs nothing new.
 *
 * @package BCC\Trust\Core\CLI
 */

declare(strict_types=1);

namespace BCC\Trust\Core\CLI;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Services\BlockFollowBackfill;

if (!defined('ABSPATH')) {
    exit;
}

final class BlockFollowsBackfillCommand
{
    private const EXIT_INVALID_ARGS = ApplyGuard::EXIT_INVALID_ARGS;
    private const EXIT_FAILED       = ApplyGuard::EXIT_FAILED;

    /**
     * Remove member-to-member follows between every blocked pair.
     *
     * ## OPTIONS
     *
     * [--apply]
     * : Apply the plan. Without it nothing is written.
     *
     * [--confirm=<token>]
     * : The token the dry run printed. Required with --apply.
     *
     * [--user-id=<id>]
     * : The administrator running the backfill. Required with --apply;
     *   must hold manage_options.
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    public function backfill(array $args, array $assoc): void
    {
        $parsed = self::parse($args, $assoc);
        if ($parsed['ok'] === false) {
            self::fail($parsed['error'], self::EXIT_INVALID_ARGS);
        }

        $env     = self::environment();
        $service = Plugin::instance()->blockFollowBackfill();
        $plan    = $service->plan();
        if ($plan['ok'] === false) {
            self::fail($plan['error'] . ' (block rows scanned: ' . $plan['scanned'] . ')', self::EXIT_FAILED);
        }

        $token = BlockFollowBackfill::confirmationToken($env, $plan['pairs']);
        \WP_CLI::log(sprintf('Environment: %s', $env));
        \WP_CLI::log(sprintf('Block rows scanned: %d', $plan['scanned']));
        \WP_CLI::log(sprintf('Blocked pairs that still have a follow: %d', count($plan['pairs'])));

        if (!$parsed['apply']) {
            \WP_CLI::log('');
            \WP_CLI::log('DRY RUN — nothing was written.');
            if ($plan['pairs'] !== []) {
                \WP_CLI::log('To apply:');
                \WP_CLI::log(sprintf('  wp bcc-trust block-follows backfill --apply --confirm=%s --user-id=<administrator-id>', $token));
            }
            \WP_CLI::success('Plan computed.');
            return;
        }

        if ($parsed['confirm'] === null) {
            self::fail('--apply requires --confirm=' . $token . '. Run without --apply first and read the plan.', self::EXIT_INVALID_ARGS);
        }
        if (!hash_equals($token, $parsed['confirm'])) {
            self::fail(
                'Confirmation token does not match this environment and plan (expected --confirm=' . $token . '). '
                . 'A token from another environment, or from before the data moved, is refused — never downgraded to a dry run.',
                self::EXIT_INVALID_ARGS
            );
        }
        $operatorId = self::requireOperator($parsed['user_id']);

        $result = $service->apply($plan['pairs']);
        AuditLogger::log('block_follows_backfill', null, [
            'environment' => $env,
            'pairs'       => $result['pairs'],
            'severed'     => $result['severed'],
        ], 'system', $operatorId);

        \WP_CLI::log(sprintf('Pairs processed: %d · follows removed: %d', $result['pairs'], $result['severed']));
        \WP_CLI::success('Backfill complete.');
    }

    /**
     * The flags --apply, --confirm=<token> and --user-id=<id> (the shared
     * ApplyGuard rules every dry-run-first repair command uses).
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     * @return array{ok: true, apply: bool, confirm: string|null, user_id: string|null}|array{ok: false, error: string}
     */
    public static function parse(array $args, array $assoc): array
    {
        return ApplyGuard::parse($args, $assoc);
    }

    private static function requireOperator(?string $raw): int
    {
        return ApplyGuard::requireOperator($raw);
    }

    private static function environment(): string
    {
        return ApplyGuard::environment();
    }

    private static function fail(string $message, int $code): never
    {
        ApplyGuard::fail($message, $code);
    }
}
