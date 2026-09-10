<?php
/**
 * WP-CLI: repair the orphaned `member_owner` rows on existing Halls.
 *
 * ── THIS IS THE ONLY ENTRY POINT, DELIBERATELY ──────────────────────────
 * There is no migration entry, no activation hook, no cron hook, no REST
 * route, no admin-post handler and no AJAX action that reaches this repair.
 * It writes PeepSo's membership graph, so it runs when a named
 * administrator runs it and watches the output — never on a schedule and
 * never from a browser. `HallProvisionCronRetiredTest` and this command's
 * own test both assert that no other caller exists.
 *
 * ── WHAT IT FIXES ───────────────────────────────────────────────────────
 * Halls created by the retired `bcc_hall_provision` cron carry a membership
 * row `(gm_user_id = 0, member_owner)`, because PeepSo's `create()` owns
 * `get_current_user_id()` — 0 under cron. `post_author` is already correct,
 * so nothing in `wp_posts` changes; only the ledger row does.
 *
 * ── DRY RUN IS THE DEFAULT, AND A TYPO IS AN ERROR ──────────────────────
 * Running with no flags validates every guard and writes nothing. Mutation
 * needs three things together: `--apply`, the exact `--confirm` token, and
 * an explicit administrator `--user-id`. A misspelled flag is REFUSED
 * rather than ignored — `--aply` silently becoming a dry run is survivable,
 * but `--confrim` silently becoming one is how an operator concludes the
 * repair ran when it did not.
 *
 * ── THE TOKEN IS BOUND TO THE ENVIRONMENT AND THE PLAN ──────────────────
 * A token minted while reading staging's plan cannot apply on production:
 * it carries `BCC_ENV` and a checksum of the exact rows the dry run
 * proposed. Change the environment, or let the data move underneath, and
 * the token stops matching — which is the point. It is a confirmation
 * device, not a secret.
 *
 * ## EXAMPLES
 *
 *     # Dry run — validates everything, writes nothing, prints the token
 *     wp bcc-trust hall-ownership repair
 *
 *     # Apply
 *     wp bcc-trust hall-ownership repair --apply --confirm=<token> --user-id=<id>
 *
 * @package BCC\Trust\Onchain\CLI
 */

namespace BCC\Trust\Onchain\CLI;

use BCC\Trust\Onchain\Repair\HallOwnershipRepairService;

if (!defined('ABSPATH')) {
    exit;
}

final class HallOwnershipRepairCommand
{
    public const EXIT_OK           = 0;
    public const EXIT_INVALID_ARGS = 2;
    public const EXIT_NOT_ELIGIBLE = 3;
    public const EXIT_FAILED       = 6;

    /**
     * Accepted flags. Anything else is an error, not a shrug.
     *
     * `user-id`, not `user`: WP-CLI consumes a global `--user` before a
     * command sees it, so declaring `--user` here would collide with the
     * runtime's own flag and the value would never arrive.
     */
    private const ALLOWED_FLAGS = ['apply', 'confirm', 'user-id', 'artifact-dir'];

    private const REQUIRED_CAPABILITY = 'manage_options';

    /**
     * Repair orphaned Hall ownership rows.
     *
     * [--apply]
     * : Perform the repair. Without this the command is a dry run and writes
     *   nothing. Declared optional in the synopsis on purpose — WP-CLI's
     *   SynopsisParser retypes a non-optional `flag` token to `unknown` and
     *   the invocation dies before this method is entered.
     *
     * [--confirm=<token>]
     * : Exact confirmation token, bound to BCC_ENV and to a checksum of the
     *   planned rows. Printed by the dry run. Required with --apply. A wrong
     *   token is an error, never a downgrade to a dry run.
     *
     * [--user-id=<id>]
     * : Numeric WordPress user id of the administrator performing the
     *   repair. Required with --apply. Must exist and hold `manage_options`.
     *   There is no default and no implicit current user.
     *
     * [--artifact-dir=<path>]
     * : Directory for the verified backup and the rollback artifact.
     *   Defaults to the WordPress uploads directory. Must be writable.
     *
     * @when after_wp_load
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     */
    public function repair(array $args, array $assoc): void
    {
        if (!defined('WP_CLI') || !\WP_CLI) {
            self::fail('This command is WP-CLI only.', self::EXIT_INVALID_ARGS);
        }

        $parsed = self::parseInvocation($args, $assoc);
        if ($parsed['ok'] === false) {
            self::fail($parsed['error'], self::EXIT_INVALID_ARGS);
        }

        $runId = self::mintRunId();
        $env   = self::environment();

        // ── Who the repair would hand ownership to ──────────────────────
        $ownerId = HallOwnershipRepairService::resolveIntendedOwner();
        if ($ownerId === 0) {
            self::fail(
                'No active administrator could be resolved to own the Halls. '
                . 'Refusing rather than picking a fallback.',
                self::EXIT_NOT_ELIGIBLE
            );
        }

        $service = new HallOwnershipRepairService();
        $plan    = $service->plan($ownerId);

        $expectedToken = self::confirmationToken($env, $plan);

        self::printPreflight($runId, $env, $ownerId, $expectedToken, $parsed['apply']);
        self::printPlan($plan);

        $summary = self::summarise($plan);

        if (!$parsed['apply']) {
            self::printSummary([
                'command'          => 'bcc-trust hall-ownership repair',
                'run_id'           => $runId,
                'environment'      => $env,
                'mode'             => 'dry-run',
                'intended_owner'   => $ownerId,
                'results'          => $summary,
            ]);

            \WP_CLI::log('');
            \WP_CLI::log('DRY RUN — no row was written, no audit row was created, no artifact was produced.');
            \WP_CLI::log('To execute:');
            \WP_CLI::log(sprintf(
                '  wp bcc-trust hall-ownership repair --apply --confirm=%s --user-id=<administrator-id>',
                $expectedToken
            ));
            \WP_CLI::success('Plan validated.');
            return;
        }

        // ── Token ───────────────────────────────────────────────────────
        $given = $parsed['confirm'];
        if ($given === null) {
            self::fail(
                '--apply requires --confirm=' . $expectedToken . '. Run without --apply first and read the plan.',
                self::EXIT_INVALID_ARGS
            );
        }
        if (!hash_equals($expectedToken, $given)) {
            self::fail(
                'Confirmation token does not match this environment and plan. Expected --confirm='
                . $expectedToken
                . '. A token minted for another environment, or before the data moved, is refused — '
                . 'never downgraded to a dry run.',
                self::EXIT_INVALID_ARGS
            );
        }

        $operatorId = self::requireOperator($parsed['user_id']);
        $dir        = self::resolveArtifactDir($parsed['artifact_dir']);

        try {
            $run = $service->run(true, $ownerId, $operatorId, $runId, $dir);
        } catch (\Throwable $e) {
            // The backup gate is the expected reason to land here, and it
            // refuses the WHOLE apply — nothing was written.
            self::fail(
                'Repair refused before any write: ' . $e->getMessage(),
                self::EXIT_FAILED
            );
        }

        self::printPlan($run['results']);
        $applied = self::summarise($run['results']);

        self::printSummary([
            'command'          => 'bcc-trust hall-ownership repair',
            'run_id'           => $runId,
            'environment'      => $env,
            'mode'             => 'apply',
            'intended_owner'   => $ownerId,
            'operator_user_id' => $operatorId,
            'backup'           => $run['backup'],
            'rollback'         => $run['rollback'],
            'results'          => $applied,
        ]);

        $bad = $applied[HallOwnershipRepairService::RESULT_REFUSED_PRECONDITION]
             + $applied[HallOwnershipRepairService::RESULT_FAILED_ROLLED_BACK];

        if ($bad > 0) {
            \WP_CLI::warning(sprintf(
                '%d Hall(s) did not complete. Nothing was left half-applied — each failure rolled back on its own.',
                $bad
            ));
            \WP_CLI::halt(self::EXIT_FAILED);
        }

        \WP_CLI::success('Repair complete.');
    }

    // ──────────────────────────────────────────────────────────────────
    // Token
    // ──────────────────────────────────────────────────────────────────

    /**
     * A token bound to the ENVIRONMENT and to the exact planned rows.
     *
     * Public and pure so a test can prove two different plans, or two
     * different environments, never mint the same token — the property that
     * makes "I ran the staging token against production" impossible.
     *
     * @param list<array<string, mixed>> $plan
     */
    public static function confirmationToken(string $env, array $plan): string
    {
        $rows = [];
        foreach ($plan as $entry) {
            if (($entry['result'] ?? '') !== HallOwnershipRepairService::RESULT_WOULD_REPAIR) {
                continue;
            }
            $rows[] = (int) $entry['group_id'] . ':' . (int) $entry['chain_id'] . ':' . (int) $entry['row_id'];
        }
        sort($rows);

        $digest = hash('sha256', $env . '|' . count($rows) . '|' . implode(',', $rows));

        return 'HALL-OWNER-' . strtoupper($env) . '-' . substr($digest, 0, 16);
    }

    private static function environment(): string
    {
        $env = defined('BCC_ENV') ? (string) constant('BCC_ENV') : 'unknown';

        return preg_replace('/[^a-z0-9_-]/i', '', $env) ?: 'unknown';
    }

    // ──────────────────────────────────────────────────────────────────
    // Invocation parsing
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     * @return array{ok: true, apply: bool, confirm: string|null, user_id: string|null, artifact_dir: string|null}|array{ok: false, error: string}
     */
    private static function parseInvocation(array $args, array $assoc): array
    {
        if ($args !== []) {
            return ['ok' => false, 'error' => 'This command takes no positional arguments.'];
        }

        $unknown = array_values(array_diff(array_keys($assoc), self::ALLOWED_FLAGS));
        if ($unknown !== []) {
            $names = array_map(static fn(int|string $k): string => '--' . (string) $k, $unknown);
            return [
                'ok'    => false,
                'error' => 'Unknown flag(s): ' . implode(', ', $names)
                    . '. Accepted: --apply, --confirm=<token>, --user-id=<id>, --artifact-dir=<path>.',
            ];
        }

        $apply = false;
        if (array_key_exists('apply', $assoc)) {
            if ($assoc['apply'] !== true) {
                return ['ok' => false, 'error' => '--apply must be the bare flag (not --apply=<value>, not --no-apply).'];
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

        $dir = null;
        if (array_key_exists('artifact-dir', $assoc)) {
            if (!is_string($assoc['artifact-dir']) || $assoc['artifact-dir'] === '') {
                return ['ok' => false, 'error' => '--artifact-dir requires a value.'];
            }
            $dir = $assoc['artifact-dir'];
        }

        if (!$apply && ($confirm !== null || $userId !== null)) {
            return [
                'ok'    => false,
                'error' => '--confirm and --user-id are only meaningful with --apply. '
                    . 'Refusing rather than silently ignoring them.',
            ];
        }

        return ['ok' => true, 'apply' => $apply, 'confirm' => $confirm, 'user_id' => $userId, 'artifact_dir' => $dir];
    }

    /**
     * Is this raw `--user-id` value a usable WordPress user id?
     *
     * Strictly a positive integer: no leading zeros, no sign, no whitespace,
     * no float, no hex. `(int) "0"` and `(int) "abc"` both yield 0, so a lax
     * parse turns a typo into "user 0" — the exact unaccountable identity
     * this whole repair exists to remove from the ledger.
     *
     * Public and separate from {@see requireOperator()} because that method
     * terminates the process, and an untestable rule is one a mutation
     * control cannot prove is load-bearing.
     */
    public static function isValidOperatorId(string $raw): bool
    {
        return preg_match('/^[1-9][0-9]{0,9}$/', $raw) === 1;
    }

    /** Never returns on failure. */
    private static function requireOperator(?string $raw): int
    {
        if ($raw === null) {
            self::fail(
                '--user-id=<id> is required with --apply. There is no implicit current user: '
                . 'WP-CLI runs as user 0 unless told otherwise, and the audit row must name a real administrator.',
                self::EXIT_INVALID_ARGS
            );
        }

        if (!self::isValidOperatorId($raw)) {
            self::fail(
                '--user-id must be a positive integer user id (got "' . $raw . '"). User id 0 is never accepted.',
                self::EXIT_INVALID_ARGS
            );
        }

        $userId = (int) $raw;

        if (get_userdata($userId) === false) {
            self::fail('No user exists with id ' . $userId . '.', self::EXIT_NOT_ELIGIBLE);
        }

        if (!user_can($userId, self::REQUIRED_CAPABILITY)) {
            self::fail(
                'User ' . $userId . ' does not have the ' . self::REQUIRED_CAPABILITY . ' capability.',
                self::EXIT_NOT_ELIGIBLE
            );
        }

        return $userId;
    }

    private static function resolveArtifactDir(?string $given): string
    {
        if ($given !== null) {
            return $given;
        }

        $uploads = wp_upload_dir();

        return is_array($uploads) && isset($uploads['basedir']) && is_string($uploads['basedir'])
            ? $uploads['basedir']
            : sys_get_temp_dir();
    }

    // ──────────────────────────────────────────────────────────────────
    // Output — numeric ids only, never a name, email or username
    // ──────────────────────────────────────────────────────────────────

    private static function printPreflight(string $runId, string $env, int $ownerId, string $token, bool $apply): void
    {
        \WP_CLI::log('── PREFLIGHT ───────────────────────────────────────────────');
        \WP_CLI::log('run id           : ' . $runId);
        \WP_CLI::log('environment      : ' . $env);
        \WP_CLI::log('mode             : ' . ($apply ? 'APPLY (writes)' : 'DRY RUN (no writes)'));
        \WP_CLI::log('intended owner   : user id ' . $ownerId . ' (first ACTIVE administrator)');
        \WP_CLI::log('confirm token    : ' . $token);
        \WP_CLI::log('writes           : peepso_group_members.gm_user_id, and peepso_group_members_count meta');
        \WP_CLI::log('never writes     : wp_posts, Hall markers, chain rows, user meta, any other group kind');
        \WP_CLI::log('provider calls   : none');
        \WP_CLI::log('────────────────────────────────────────────────────────────');
    }

    /** @param list<array<string, mixed>> $plan */
    private static function printPlan(array $plan): void
    {
        \WP_CLI::log('');
        foreach ($plan as $p) {
            \WP_CLI::log(sprintf(
                '  hall %-6d chain %-5d row %-8d %-22s %s',
                (int) $p['group_id'],
                (int) $p['chain_id'],
                (int) ($p['row_id'] ?? 0),
                (string) $p['result'],
                ((string) ($p['detail'] ?? '')) !== '' ? '(' . (string) $p['detail'] . ')' : ''
            ));
        }
    }

    /**
     * @param  list<array<string, mixed>> $plan
     * @return array<string, int>
     */
    private static function summarise(array $plan): array
    {
        $summary = [
            HallOwnershipRepairService::RESULT_WOULD_REPAIR         => 0,
            HallOwnershipRepairService::RESULT_REPAIRED             => 0,
            HallOwnershipRepairService::RESULT_ALREADY_CORRECT      => 0,
            HallOwnershipRepairService::RESULT_REFUSED_PRECONDITION => 0,
            HallOwnershipRepairService::RESULT_FAILED_ROLLED_BACK   => 0,
        ];

        foreach ($plan as $p) {
            $r = (string) ($p['result'] ?? '');
            if (array_key_exists($r, $summary)) {
                $summary[$r]++;
            }
        }

        return $summary;
    }

    /** @param array<string, mixed> $payload */
    private static function printSummary(array $payload): void
    {
        \WP_CLI::log('');
        \WP_CLI::log('── SUMMARY (JSON) ──────────────────────────────────────────');
        \WP_CLI::log((string) wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        \WP_CLI::log('────────────────────────────────────────────────────────────');
    }

    /**
     * 128 bits from the CSPRNG. `random_bytes()` throws rather than
     * degrading, which is correct for an id that ties an audit trail
     * together — unlike `wp_generate_uuid4()`, which has an `mt_rand`
     * fallback branch.
     */
    private static function mintRunId(): string
    {
        return 'hallown-' . bin2hex(random_bytes(16));
    }

    private static function fail(string $message, int $code): never
    {
        \WP_CLI::error($message, false);
        \WP_CLI::halt($code);
    }
}
