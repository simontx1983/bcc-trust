<?php
/**
 * WP-CLI: repair the eight unresolved Solana holder-gate identities.
 *
 * ── THIS IS THE ONLY ENTRY POINT, DELIBERATELY ──────────────────────────
 * There is no REST route, no admin-post handler, no AJAX action and no
 * cron hook that reaches this repair. It writes to the identity column
 * that community gating is built on, so it runs when a named human runs
 * it, watching the output — never on a schedule and never from a browser.
 *
 * ── DRY RUN IS THE DEFAULT, AND A TYPO IS AN ERROR ──────────────────────
 * Running with no flags validates every precondition and writes nothing.
 * Mutation needs three things together: `--apply`, the exact `--confirm`
 * token, and an explicit administrator `--user`. A misspelled flag is
 * REFUSED rather than ignored — `--aply` silently becoming a dry run is
 * survivable, but `--confrim` silently becoming one is how an operator
 * concludes the repair ran when it did not.
 *
 * ── WHY --user IS MANDATORY ─────────────────────────────────────────────
 * WP-CLI runs as user 0 unless told otherwise, so `get_current_user_id()`
 * answers "nobody" on almost every real invocation. The other commands in
 * this plugin accept that and record an OS-level operator fingerprint,
 * because they do not need an authorisation decision. This one does: the
 * audit row must name a real accountable administrator, and the capability
 * check must be made against THAT user rather than against an ambient
 * identity that is usually absent.
 *
 * `manage_options` is the requirement. That is not a simplification —
 * there is no BCC-specific administrative capability anywhere in bcc-trust
 * or bcc-core (no `add_cap`, no `map_meta_cap`), and every comparable NFT
 * administration surface gates on `manage_options` alone. No capability is
 * created, seeded or enabled here.
 *
 * ## EXAMPLES
 *
 *     # Dry run — validates everything, writes nothing, prints the token
 *     wp bcc-trust gate-identity repair
 *
 *     # Apply
 *     wp bcc-trust gate-identity repair --apply --confirm=<token> --user-id=<id>
 *
 * @package BCC\Trust\Onchain\CLI
 * @since PR 5b — Solana holder-gate identity repair
 */

namespace BCC\Trust\Onchain\CLI;

use BCC\Trust\Onchain\Repair\SolanaGateIdentityManifest;
use BCC\Trust\Onchain\Repair\SolanaGateIdentityRepairService;

if (!defined('ABSPATH')) {
    exit;
}

final class SolanaGateIdentityRepairCommand
{
    public const EXIT_OK           = 0;
    public const EXIT_INVALID_ARGS = 2;
    public const EXIT_NOT_ELIGIBLE = 3;
    public const EXIT_FAILED       = 6;

    /**
     * Accepted flags. Anything else is an error, not a shrug.
     *
     * `user-id`, not `user`: WP-CLI consumes a global `--user` before a
     * command ever sees it, so declaring `--user` here would collide with
     * the runtime's own flag and the value would never arrive.
     */
    private const ALLOWED_FLAGS = ['apply', 'confirm', 'user-id'];

    /** The capability an operator must hold. See the class docblock. */
    private const REQUIRED_CAPABILITY = 'manage_options';

    /**
     * Repair the eight unresolved Solana holder-gate collection identities.
     *
     * [--apply]
     * : Perform the repair. Without this the command is a dry run and
     *   writes nothing. Declared optional in the synopsis on purpose —
     *   WP-CLI's SynopsisParser retypes a non-optional `flag` token to
     *   `unknown` and the invocation dies before this method is entered.
     *
     * [--confirm=<token>]
     * : Exact confirmation token, bound to manifest version and checksum.
     *   Printed by the dry run. Required with --apply. A wrong token is an
     *   error, never a downgrade to a dry run.
     *
     * [--user-id=<id>]
     * : Numeric WordPress user id of the administrator performing the
     *   repair. Required with --apply. Must exist and hold `manage_options`.
     *   There is no default and no implicit current user.
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

        // A fresh 128-bit CSPRNG id per invocation.
        $runId = self::mintRunId();

        $expectedToken = SolanaGateIdentityManifest::confirmationToken();

        self::printPreflight($runId, $expectedToken, $parsed['apply']);

        $operatorId = 0;

        if ($parsed['apply']) {
            // ── Token ───────────────────────────────────────────────────
            $given = $parsed['confirm'];
            if ($given === null) {
                self::fail(
                    '--apply requires --confirm=' . $expectedToken . '. Run without --apply first and read the plan.',
                    self::EXIT_INVALID_ARGS
                );
            }
            if (!hash_equals($expectedToken, $given)) {
                self::fail(
                    'Confirmation token does not match manifest version '
                    . SolanaGateIdentityManifest::VERSION
                    . '. Expected --confirm=' . $expectedToken
                    . '. A token minted for a different manifest is refused, not downgraded to a dry run.',
                    self::EXIT_INVALID_ARGS
                );
            }

            // ── Operator identity ───────────────────────────────────────
            $operatorId = self::requireOperator($parsed['user_id']);
        }

        $service = new SolanaGateIdentityRepairService();
        $results = $service->run($parsed['apply'], $operatorId, $runId);

        self::printResults($results);

        $summary = self::summarise($results);
        self::printSummary([
            'command'          => 'bcc-trust gate-identity repair',
            'run_id'           => $runId,
            'mode'             => $parsed['apply'] ? 'apply' : 'dry-run',
            'manifest_version' => SolanaGateIdentityManifest::VERSION,
            'manifest_count'   => SolanaGateIdentityManifest::count(),
            'manifest_sha256'  => SolanaGateIdentityManifest::checksum(),
            'operator_user_id' => $operatorId,
            'results'          => $summary,
        ]);

        $bad = $summary[SolanaGateIdentityRepairService::RESULT_REFUSED_PRECONDITION]
             + $summary[SolanaGateIdentityRepairService::RESULT_FAILED_ROLLED_BACK];

        if ($bad > 0) {
            \WP_CLI::warning(sprintf(
                '%d of %d mappings did not complete. Nothing was left half-applied — each failure rolled back on its own.',
                $bad,
                SolanaGateIdentityManifest::count()
            ));
            \WP_CLI::halt(self::EXIT_FAILED);
        }

        if (!$parsed['apply']) {
            \WP_CLI::log('');
            \WP_CLI::log('DRY RUN — no row was written, no audit row was created, no cache was invalidated.');
            \WP_CLI::log('To execute:');
            \WP_CLI::log(sprintf(
                '  wp bcc-trust gate-identity repair --apply --confirm=%s --user-id=<administrator-id>',
                $expectedToken
            ));
            \WP_CLI::success('Plan validated.');
            return;
        }

        \WP_CLI::success('Repair complete.');
    }

    // ──────────────────────────────────────────────────────────────────
    // Invocation parsing
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param list<string>         $args
     * @param array<string, mixed> $assoc
     * @return array{ok: true, apply: bool, confirm: string|null, user_id: string|null}|array{ok: false, error: string}
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
                    . '. Accepted: --apply, --confirm=<token>, --user-id=<id>.',
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

        if (!$apply && ($confirm !== null || $userId !== null)) {
            return [
                'ok'    => false,
                'error' => '--confirm and --user-id are only meaningful with --apply. '
                    . 'Refusing rather than silently ignoring them.',
            ];
        }

        return ['ok' => true, 'apply' => $apply, 'confirm' => $confirm, 'user_id' => $userId];
    }

    /**
     * Is this raw `--user-id` value a usable WordPress user id?
     *
     * Strictly a positive integer: no leading zeros, no sign, no
     * whitespace, no float, no hex. `(int) "0"` and `(int) "abc"` both
     * yield 0, so a lax parse turns a typo into "user 0" — the ambient,
     * unaccountable identity this flag exists to replace.
     *
     * Public and separate from {@see requireOperator()} on purpose:
     * `requireOperator()` terminates the process through WP_CLI, so the
     * rule itself would otherwise be untestable, and an untestable rule is
     * one a mutation control cannot prove is load-bearing.
     */
    public static function isValidOperatorId(string $raw): bool
    {
        return preg_match('/^[1-9][0-9]{0,9}$/', $raw) === 1;
    }

    /**
     * Validate the operator and return their id. Never returns on failure.
     */
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
                '--user-id must be a positive integer user id (got "' . $raw . '"). '
                . 'User id 0 is never accepted.',
                self::EXIT_INVALID_ARGS
            );
        }

        $userId = (int) $raw;
        $user   = get_userdata($userId);

        if ($user === false) {
            self::fail('No user exists with id ' . $userId . '.', self::EXIT_NOT_ELIGIBLE);
        }

        // Checked against the NAMED user, not the ambient one:
        // `current_user_can()` would answer for whoever WP-CLI happens to be
        // running as, which is exactly the identity this flag exists to
        // replace.
        if (!user_can($userId, self::REQUIRED_CAPABILITY)) {
            self::fail(
                'User ' . $userId . ' does not have the ' . self::REQUIRED_CAPABILITY . ' capability.',
                self::EXIT_NOT_ELIGIBLE
            );
        }

        return $userId;
    }

    // ──────────────────────────────────────────────────────────────────
    // Output
    // ──────────────────────────────────────────────────────────────────

    private static function printPreflight(string $runId, string $token, bool $apply): void
    {
        \WP_CLI::log('── PREFLIGHT ───────────────────────────────────────────────');
        \WP_CLI::log('run id            : ' . $runId);
        \WP_CLI::log('mode              : ' . ($apply ? 'APPLY (writes)' : 'DRY RUN (no writes)'));
        \WP_CLI::log('manifest version  : ' . SolanaGateIdentityManifest::VERSION);
        \WP_CLI::log('manifest mappings : ' . SolanaGateIdentityManifest::count());
        \WP_CLI::log('manifest sha256   : ' . SolanaGateIdentityManifest::checksum());
        \WP_CLI::log('confirm token     : ' . $token);
        \WP_CLI::log('chain             : resolved from slug "' . SolanaGateIdentityManifest::CHAIN_SLUG . '" at runtime');
        \WP_CLI::log('evidence          : ' . SolanaGateIdentityManifest::EVIDENCE);
        \WP_CLI::log('provider calls    : none — the manifest is a frozen constant table');
        \WP_CLI::log('────────────────────────────────────────────────────────────');
    }

    /**
     * @param list<array{collection_id: int, post_id: int, alias: string, result: string, detail: string}> $results
     */
    private static function printResults(array $results): void
    {
        \WP_CLI::log('');
        foreach ($results as $r) {
            \WP_CLI::log(sprintf(
                '  collection %3d  post %4d  %-15s  %s%s',
                $r['collection_id'],
                $r['post_id'],
                $r['alias'],
                $r['result'],
                $r['detail'] !== '' ? '  (' . $r['detail'] . ')' : ''
            ));
        }
    }

    /**
     * @param list<array{collection_id: int, post_id: int, alias: string, result: string, detail: string}> $results
     * @return array<string, int>
     */
    private static function summarise(array $results): array
    {
        $summary = [
            SolanaGateIdentityRepairService::RESULT_WOULD_REPAIR         => 0,
            SolanaGateIdentityRepairService::RESULT_REPAIRED             => 0,
            SolanaGateIdentityRepairService::RESULT_ALREADY_APPLIED      => 0,
            SolanaGateIdentityRepairService::RESULT_REFUSED_PRECONDITION => 0,
            SolanaGateIdentityRepairService::RESULT_FAILED_ROLLED_BACK   => 0,
        ];

        foreach ($results as $r) {
            if (array_key_exists($r['result'], $summary)) {
                $summary[$r['result']]++;
            }
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function printSummary(array $payload): void
    {
        \WP_CLI::log('');
        \WP_CLI::log('── SUMMARY (JSON) ──────────────────────────────────────────');
        \WP_CLI::log((string) wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        \WP_CLI::log('────────────────────────────────────────────────────────────');
    }

    // ──────────────────────────────────────────────────────────────────

    /**
     * 128 bits from the CSPRNG.
     *
     * NOT `wp_generate_uuid4()`, despite being the more obvious reuse: WP
     * core's implementation prefers `wp_rand`, falls back to `random_int`,
     * and has an `mt_rand` fallback branch — so it is not guaranteed
     * cryptographically strong. `random_bytes()` throws rather than
     * degrading, which is the correct behaviour for an identifier that ties
     * an audit trail together. Matches ModerationQueueService's precedent.
     */
    private static function mintRunId(): string
    {
        return 'pr5b-' . bin2hex(random_bytes(16));
    }

    private static function fail(string $message, int $code): never
    {
        \WP_CLI::error($message, false);
        \WP_CLI::halt($code);
    }
}
