<?php
/**
 * `wp bcc-trust attestations prune-orphans` — remove the attestation rows of
 * accounts that no longer exist: rows they cast, and rows cast against their
 * profile.
 *
 * Account deletion already does this on `delete_user`
 * (UserLifecycleService::purgeAttestationsFor). This command runs that SAME
 * cleanup for accounts whose rows survived — removed before the cleanup
 * existed, removed outside wp_delete_user, or by a delete that failed.
 *
 * THIS IS ITS ONLY ENTRY POINT: no REST route, cron hook, migration or admin
 * handler reaches it, and a deploy runs nothing. ApplyGuard's rules:
 *
 *     # Dry run — reads only, prints counts (never ids) and the token
 *     wp bcc-trust attestations prune-orphans
 *
 *     # Apply — needs --apply, the exact token and an administrator
 *     wp bcc-trust attestations prune-orphans --apply --confirm=<token> --user-id=<id>
 *
 * Idempotent — a re-run finds nothing left to prune.
 *
 * @package BCC\Trust\Core\CLI
 */

declare(strict_types=1);

namespace BCC\Trust\Core\CLI;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;

if (!defined('ABSPATH')) {
    exit;
}

final class AttestationOrphansCommand
{
    /** Gone accounts handled per run, at most. A re-run picks up the rest. */
    public const MAX_ACCOUNTS = 1000;

    /**
     * Remove the attestation rows of accounts that no longer exist.
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
     * : The administrator running the prune. Required with --apply; must
     *   hold manage_options.
     *
     * @subcommand prune-orphans
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    public function pruneOrphans(array $args, array $assoc): void
    {
        $parsed = ApplyGuard::parse($args, $assoc);
        if ($parsed['ok'] === false) {
            ApplyGuard::fail($parsed['error'], ApplyGuard::EXIT_INVALID_ARGS);
        }

        $plan = self::plan();
        if ($plan === null) {
            ApplyGuard::fail('The attestation read failed; nothing was planned or written. Try again.', ApplyGuard::EXIT_FAILED);
        }

        $env   = ApplyGuard::environment();
        $token = self::token($env, $plan['user_ids']);
        \WP_CLI::log(sprintf('Environment: %s', $env));
        foreach (self::summary($plan) as $line) {
            \WP_CLI::log($line);
        }

        if (!$parsed['apply']) {
            \WP_CLI::log('');
            \WP_CLI::log('DRY RUN — nothing was written.');
            if ($plan['user_ids'] !== []) {
                \WP_CLI::log('To apply:');
                \WP_CLI::log(sprintf('  wp bcc-trust attestations prune-orphans --apply --confirm=%s --user-id=<administrator-id>', $token));
            }
            \WP_CLI::success('Plan computed.');
            return;
        }

        if ($parsed['confirm'] === null) {
            ApplyGuard::fail('--apply requires --confirm=' . $token . '. Run without --apply first and read the plan.', ApplyGuard::EXIT_INVALID_ARGS);
        }
        if (!hash_equals($token, $parsed['confirm'])) {
            ApplyGuard::fail(
                'Confirmation token does not match this environment and plan (expected --confirm=' . $token . '). '
                . 'A token from another environment, or from before the data moved, is refused — never downgraded to a dry run.',
                ApplyGuard::EXIT_INVALID_ARGS
            );
        }
        $operatorId = ApplyGuard::requireOperator($parsed['user_id']);

        $result = self::apply($plan['user_ids']);
        AuditLogger::log('attestation_orphans_pruned', null, [
            'environment' => $env,
            'accounts'    => count($plan['user_ids']),
            'purged'      => $result['purged'],
            'failed'      => $result['failed'],
            'rows_before' => $plan['rows'],
        ], 'system', $operatorId);

        \WP_CLI::log(sprintf('Accounts purged: %d · failed: %d', $result['purged'], $result['failed']));
        if ($result['failed'] > 0) {
            ApplyGuard::fail('Some accounts could not be purged (logged). Re-run the dry run, then apply again.', ApplyGuard::EXIT_FAILED);
        }
        \WP_CLI::success('Prune complete.');
    }

    /**
     * The gone accounts with attestation rows, and the rows they hold.
     * Null when the read did not run: an unknown plan is never applied.
     *
     * @return array{user_ids: list<int>, rows: array{cast: int, targeted: int, active: int}}|null
     */
    public static function plan(): ?array
    {
        $repo = Plugin::instance()->attestationRepository();
        try {
            return [
                'user_ids' => $repo->findOrphanedUserIds(self::MAX_ACCOUNTS),
                'rows'     => $repo->countOrphanedRows(),
            ];
        } catch (RepositoryReadFailure $e) {
            return null;
        }
    }

    /**
     * Counts only — never an id, so the output can be pasted anywhere.
     *
     * @param array{user_ids: list<int>, rows: array{cast: int, targeted: int, active: int}} $plan
     * @return list<string>
     */
    public static function summary(array $plan): array
    {
        $lines = [
            sprintf('Accounts that no longer exist but hold attestation rows: %d', count($plan['user_ids'])),
            sprintf('Rows they cast: %d · rows cast against their profile: %d · still active: %d', $plan['rows']['cast'], $plan['rows']['targeted'], $plan['rows']['active']),
        ];
        if (count($plan['user_ids']) >= self::MAX_ACCOUNTS) {
            $lines[] = sprintf('Capped at %d accounts per run; re-run after applying for the rest.', self::MAX_ACCOUNTS);
        }
        return $lines;
    }

    /** @param list<int> $userIds */
    public static function token(string $env, array $userIds): string
    {
        return ApplyGuard::token($env, array_map('strval', $userIds));
    }

    /**
     * The SAME cleanup account deletion runs, once per gone account.
     *
     * @param list<int> $userIds
     * @return array{purged: int, failed: int}
     */
    public static function apply(array $userIds): array
    {
        $lifecycle = Plugin::instance()->userLifecycleService();
        $purged    = 0;
        $failed    = 0;
        foreach ($userIds as $userId) {
            if ($lifecycle->purgeAttestationsFor($userId)) {
                $purged++;
            } else {
                $failed++;
            }
        }
        return ['purged' => $purged, 'failed' => $failed];
    }
}
