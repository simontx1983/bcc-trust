<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Onchain\CLI\HallOwnershipRepairCommand;
use BCC\Trust\Onchain\Repair\HallOwnershipRepairService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The runner's refusal surface, and the confirmation token.
 *
 * `parseInvocation()` is exercised directly rather than through WP-CLI:
 * WP-CLI's own argument layer is not under test, and booting it would test
 * the framework.
 *
 * The dangerous failure mode for a repair command is not "it errored" — it
 * is "it quietly did something else". The classic shapes are a mistyped
 * flag silently degrading a mutation into a dry run, a missing `--user-id`
 * defaulting to whoever WP-CLI happens to be, and a token minted against
 * one environment being accepted by another.
 */
#[CoversClass(HallOwnershipRepairCommand::class)]
final class HallOwnershipRepairCommandTest extends TestCase
{
    /**
     * @param  array<string, mixed> $assoc
     * @param  list<string>         $args
     * @return array<string, mixed>
     */
    private static function parse(array $assoc, array $args = []): array
    {
        $m = new ReflectionMethod(HallOwnershipRepairCommand::class, 'parseInvocation');
        $m->setAccessible(true);

        /** @var array<string, mixed> $result */
        $result = $m->invoke(null, $args, $assoc);

        return $result;
    }

    // ── Dry run is the default ───────────────────────────────────────────

    public function testNoFlagsIsAValidDryRun(): void
    {
        $parsed = self::parse([]);

        self::assertTrue($parsed['ok']);
        self::assertFalse($parsed['apply'], 'the default must never write');
        self::assertNull($parsed['confirm']);
        self::assertNull($parsed['user_id']);
    }

    public function testPositionalArgumentsAreRefused(): void
    {
        $parsed = self::parse([], ['production']);

        self::assertFalse($parsed['ok']);
        self::assertStringContainsString('no positional arguments', $parsed['error']);
    }

    // ── A typo is an error, never a silent downgrade ─────────────────────

    /**
     * `--confrim` is the case that matters. Treating it as "no confirmation
     * given" would turn an intended mutation into a dry run, and the
     * operator would walk away believing the repair ran.
     *
     * @return list<list<string>>
     */
    public static function typoProvider(): array
    {
        return [['confrim'], ['aply'], ['user_id'], ['userid'], ['force'], ['yes'], ['dry-run']];
    }

    #[DataProvider('typoProvider')]
    public function testAMistypedFlagIsRefused(string $flag): void
    {
        $parsed = self::parse([$flag => true]);

        self::assertFalse($parsed['ok'], "--{$flag} must be refused, not ignored");
        self::assertStringContainsString('Unknown flag', $parsed['error']);
    }

    public function testApplyMustBeTheBareFlag(): void
    {
        foreach ([['apply' => 'yes'], ['apply' => '1'], ['apply' => false]] as $assoc) {
            $parsed = self::parse($assoc);
            self::assertFalse($parsed['ok'], 'a valued --apply must be refused: ' . json_encode($assoc));
        }
    }

    public function testConfirmRequiresAValue(): void
    {
        $parsed = self::parse(['apply' => true, 'confirm' => true]);

        self::assertFalse($parsed['ok']);
        self::assertStringContainsString('--confirm requires a value', $parsed['error']);
    }

    /**
     * Passing --confirm or --user-id WITHOUT --apply is refused rather than
     * ignored: it almost always means the operator believed they were
     * applying.
     */
    public function testConfirmOrUserIdWithoutApplyIsRefused(): void
    {
        foreach ([['confirm' => 'x'], ['user-id' => '1'], ['confirm' => 'x', 'user-id' => '1']] as $assoc) {
            $parsed = self::parse($assoc);
            self::assertFalse($parsed['ok'], json_encode($assoc));
            self::assertStringContainsString('only meaningful with --apply', $parsed['error']);
        }
    }

    public function testAFullyFormedApplyInvocationParses(): void
    {
        $parsed = self::parse(['apply' => true, 'confirm' => 'HALL-OWNER-STAGING-abc', 'user-id' => '4']);

        self::assertTrue($parsed['ok']);
        self::assertTrue($parsed['apply']);
        self::assertSame('HALL-OWNER-STAGING-abc', $parsed['confirm']);
        self::assertSame('4', $parsed['user_id']);
    }

    // ── The operator id ──────────────────────────────────────────────────

    /**
     * `(int) "0"` and `(int) "abc"` are both 0, so a lax parse turns a typo
     * into "user 0" — the exact unaccountable identity this whole repair
     * exists to remove from the ledger.
     *
     * @return list<list<string>>
     */
    public static function badOperatorIdProvider(): array
    {
        return [['0'], ['-1'], ['abc'], [''], ['01'], [' 1'], ['1 '], ['1.0'], ['0x1'], ['1e3'], ['+1']];
    }

    #[DataProvider('badOperatorIdProvider')]
    public function testInvalidOperatorIdsAreRejected(string $raw): void
    {
        self::assertFalse(
            HallOwnershipRepairCommand::isValidOperatorId($raw),
            var_export($raw, true) . ' must not be accepted as a user id'
        );
    }

    /** @return list<list<string>> */
    public static function goodOperatorIdProvider(): array
    {
        return [['1'], ['4'], ['49'], ['1234567890']];
    }

    #[DataProvider('goodOperatorIdProvider')]
    public function testValidOperatorIdsAreAccepted(string $raw): void
    {
        self::assertTrue(HallOwnershipRepairCommand::isValidOperatorId($raw));
    }

    // ── The confirmation token ───────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private static function planOf(array ...$rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'group_id' => $r[0],
                'chain_id' => $r[1],
                'row_id'   => $r[2],
                'result'   => $r[3] ?? HallOwnershipRepairService::RESULT_WOULD_REPAIR,
                'detail'   => '',
            ];
        }
        return $out;
    }

    public function testTheTokenIsStableForTheSameEnvironmentAndPlan(): void
    {
        $plan = self::planOf([6703, 10, 9001], [6704, 8, 9002]);

        self::assertSame(
            HallOwnershipRepairCommand::confirmationToken('production', $plan),
            HallOwnershipRepairCommand::confirmationToken('production', $plan)
        );
    }

    /**
     * ⚠ THE PROPERTY THAT MATTERS: a token read off staging's dry run must
     * not apply on production.
     */
    public function testTheTokenIsBoundToTheEnvironment(): void
    {
        $plan = self::planOf([6703, 10, 9001]);

        $staging    = HallOwnershipRepairCommand::confirmationToken('staging', $plan);
        $production = HallOwnershipRepairCommand::confirmationToken('production', $plan);

        self::assertNotSame($staging, $production);
        self::assertStringContainsString('STAGING', $staging);
        self::assertStringContainsString('PRODUCTION', $production);

        // ⚠ THE DIGEST ITSELF must differ, not merely the human-readable
        // prefix. Comparing whole strings would pass even if the environment
        // were dropped from the hash — and then a token whose prefix an
        // operator hand-edited would be accepted.
        self::assertNotSame(
            substr($staging, strrpos($staging, '-') + 1),
            substr($production, strrpos($production, '-') + 1),
            'the environment must feed the hash, not just the label'
        );
    }

    /**
     * And a token minted before the data moved must stop matching, so an
     * operator cannot apply a plan they never read.
     */
    public function testTheTokenIsBoundToThePlannedRows(): void
    {
        $a = HallOwnershipRepairCommand::confirmationToken('staging', self::planOf([6703, 10, 9001]));
        $b = HallOwnershipRepairCommand::confirmationToken('staging', self::planOf([6703, 10, 9001], [6704, 8, 9002]));
        $c = HallOwnershipRepairCommand::confirmationToken('staging', self::planOf([6703, 10, 9999]));

        self::assertNotSame($a, $b, 'an extra Hall changes the token');
        self::assertNotSame($a, $c, 'a different row id changes the token');
    }

    /**
     * Only rows that would actually be repaired feed the token — otherwise
     * an unrelated refusal elsewhere would invalidate a correct token and
     * train operators to re-read tokens they should have questioned.
     */
    public function testRefusedRowsDoNotContributeToTheToken(): void
    {
        $clean = self::planOf([6703, 10, 9001]);
        $noisy = self::planOf(
            [6703, 10, 9001],
            [6704, 8, 0, HallOwnershipRepairService::RESULT_REFUSED_PRECONDITION],
            [6705, 9, 0, HallOwnershipRepairService::RESULT_ALREADY_CORRECT],
        );

        self::assertSame(
            HallOwnershipRepairCommand::confirmationToken('staging', $clean),
            HallOwnershipRepairCommand::confirmationToken('staging', $noisy)
        );
    }

    public function testAnEmptyPlanStillMintsAnEnvironmentBoundToken(): void
    {
        $token = HallOwnershipRepairCommand::confirmationToken('staging', []);

        self::assertStringStartsWith('HALL-OWNER-STAGING-', $token);
        self::assertNotSame($token, HallOwnershipRepairCommand::confirmationToken('production', []));
    }

    // ── The command is the ONLY entry point ──────────────────────────────

    /**
     * No migration entry, no activation hook, no cron hook, no REST route,
     * no admin-post handler, no AJAX action. Asserted over the whole tree
     * because a future contributor adding one would otherwise turn a
     * supervised repair into an automatic one.
     */
    public function testNothingButTheCliReachesTheRepairService(): void
    {
        $root    = dirname(__DIR__, 2);
        $callers = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/app', \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            if (str_contains($src, 'HallOwnershipRepairService')) {
                $callers[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            }
        }

        sort($callers);

        self::assertSame(
            [
                'app/Domain/Onchain/CLI/HallOwnershipRepairCommand.php',
                'app/Domain/Onchain/Repair/HallOwnershipRepairService.php',
            ],
            $callers,
            'only the CLI command may reach the repair service'
        );
    }

    public function testTheRepairIsNotRegisteredAsAMigration(): void
    {
        $runner = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/database/migration-runner.php');

        self::assertStringNotContainsString('hall_ownership', $runner);
        self::assertStringNotContainsString('HallOwnershipRepair', $runner);
    }

    public function testTheRepairIsRegisteredOnlyAsAWpCliCommand(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/bcc-trust.php');

        // Strip comments: the registration block explains the rule in prose
        // that names the very things it must not do.
        $code = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($t) ? $t[1] : $t;
        }

        self::assertStringContainsString("'bcc-trust hall-ownership'", $code);
        self::assertSame(
            1,
            substr_count($code, 'HallOwnershipRepairCommand'),
            'exactly one registration, and it is the WP-CLI one'
        );
        self::assertSame(0, preg_match_all('/add_action\([^)]*HallOwnership/', $code));
        self::assertSame(0, preg_match_all('/register_rest_route\([^)]*HallOwnership/', $code));
    }
}
