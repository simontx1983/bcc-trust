<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Onchain\CLI\SolanaGateIdentityRepairCommand;
use BCC\Trust\Onchain\Repair\SolanaGateIdentityManifest;
use BCC\Trust\Onchain\Repair\SolanaGateIdentityRepairService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The runner's refusal surface.
 *
 * These exercise `parseInvocation()` directly rather than through WP-CLI.
 * That is the honest boundary for a unit test: WP-CLI's own argument layer
 * is not under test here, and booting it would test the framework.
 *
 * The theme is that every refusal is EXPLICIT. The dangerous failure mode
 * for a repair command is not "it errored" — it is "it quietly did
 * something else", and the classic shape of that is a mistyped flag
 * silently degrading a mutation into a dry run, or a missing `--user-id`
 * defaulting to whoever WP-CLI happens to be.
 */
final class SolanaGateIdentityRepairCommandTest extends TestCase
{
    /**
     * @param array<string, mixed> $assoc
     * @param list<string>         $args
     * @return array<string, mixed>
     */
    private static function parse(array $assoc, array $args = []): array
    {
        $m = new ReflectionMethod(SolanaGateIdentityRepairCommand::class, 'parseInvocation');
        $m->setAccessible(true);

        /** @var array<string, mixed> $result */
        $result = $m->invoke(null, $args, $assoc);

        return $result;
    }

    // ── dry run is the default ──────────────────────────────────────────

    public function testNoFlagsIsAValidDryRun(): void
    {
        $parsed = self::parse([]);

        self::assertTrue($parsed['ok']);
        self::assertFalse($parsed['apply']);
        self::assertNull($parsed['confirm']);
        self::assertNull($parsed['user_id']);
    }

    // ── unknown flags are refused, never ignored ────────────────────────

    /**
     * The important case is `--confrim`: silently treating it as "no
     * confirmation given" turns an intended mutation into a dry run, and
     * the operator walks away believing the repair ran.
     */
    public function testAMisspelledFlagIsRefused(): void
    {
        foreach (['confrim', 'aply', 'user', 'userid', 'force', 'yes'] as $typo) {
            $parsed = self::parse([$typo => 'x']);

            self::assertFalse($parsed['ok'], "--{$typo} should be refused");
            self::assertStringContainsString('Unknown flag', $parsed['error']);
        }
    }

    public function testPositionalArgumentsAreRefused(): void
    {
        $parsed = self::parse([], ['all']);

        self::assertFalse($parsed['ok']);
        self::assertStringContainsString('no positional arguments', $parsed['error']);
    }

    // ── --apply must be a bare flag ─────────────────────────────────────

    public function testApplyMustBeABareFlag(): void
    {
        foreach (['yes', 'true', '1', ''] as $value) {
            $parsed = self::parse(['apply' => $value]);
            self::assertFalse($parsed['ok'], "--apply={$value} should be refused");
        }
    }

    public function testBareApplyIsAccepted(): void
    {
        $parsed = self::parse(['apply' => true, 'confirm' => 'x', 'user-id' => '7']);

        self::assertTrue($parsed['ok']);
        self::assertTrue($parsed['apply']);
        self::assertSame('x', $parsed['confirm']);
        self::assertSame('7', $parsed['user_id']);
    }

    // ── mutation flags without --apply are refused ──────────────────────

    /**
     * Accepting `--confirm` and `--user-id` on a dry run and ignoring them
     * would let an operator run a command that LOOKS like the apply command
     * and produces the dry-run outcome. Refusing makes the difference
     * impossible to miss.
     */
    public function testConfirmOrUserIdWithoutApplyIsRefused(): void
    {
        $a = self::parse(['confirm' => 'tok']);
        self::assertFalse($a['ok']);
        self::assertStringContainsString('only meaningful with --apply', $a['error']);

        $b = self::parse(['user-id' => '5']);
        self::assertFalse($b['ok']);
        self::assertStringContainsString('only meaningful with --apply', $b['error']);
    }

    public function testConfirmRequiresAValue(): void
    {
        $parsed = self::parse(['apply' => true, 'confirm' => true, 'user-id' => '5']);

        self::assertFalse($parsed['ok']);
        self::assertStringContainsString('--confirm requires a value', $parsed['error']);
    }

    // ── the confirmation token ──────────────────────────────────────────

    /**
     * The token is bound to the manifest checksum, so a token copied from
     * an older run cannot execute a changed table.
     */
    public function testTheExpectedTokenMatchesTheCurrentManifest(): void
    {
        $token = SolanaGateIdentityManifest::confirmationToken();

        self::assertNotSame('', $token);
        self::assertSame('solana-gate-identity-v1-646f502d2c76', $token);
    }

    public function testTokenComparisonIsExact(): void
    {
        $token = SolanaGateIdentityManifest::confirmationToken();

        // Near-misses that a loose comparison would let through.
        foreach ([
            strtoupper($token),
            $token . ' ',
            ' ' . $token,
            substr($token, 0, -1),
            'solana-gate-identity-v2-646f502d2c76',
        ] as $wrong) {
            self::assertFalse(hash_equals($token, $wrong), "'{$wrong}' must not be accepted");
        }

        self::assertTrue(hash_equals($token, SolanaGateIdentityManifest::confirmationToken()));
    }

    // ── operator id ─────────────────────────────────────────────────────

    /**
     * The `--user-id` pattern must reject `0` and every shape that would
     * cast to `0`. `(int) "abc"` is 0 and `(int) "0"` is 0, so a lax parse
     * turns a typo into "user 0" — which is precisely the ambient,
     * unaccountable identity the flag exists to replace.
     */
    public function testTheUserIdPatternRejectsZeroAndNonIntegers(): void
    {
        $pattern = '/^[1-9][0-9]{0,9}$/';

        foreach (['0', '00', '007', '-1', '1.0', '', ' 1', '1 ', 'abc', '1e3', '+1', '0x1'] as $bad) {
            self::assertSame(0, preg_match($pattern, $bad), "'{$bad}' must be rejected");
        }

        foreach (['1', '42', '4242', '999999999'] as $good) {
            self::assertSame(1, preg_match($pattern, $good), "'{$good}' must be accepted");
        }
    }

    // ── result vocabulary ───────────────────────────────────────────────

    /**
     * The runner prints only the five agreed result codes. A sixth would
     * be an undocumented outcome an operator has to interpret.
     */
    public function testOnlyTheFiveAgreedResultCodesExist(): void
    {
        $reflection = new \ReflectionClass(SolanaGateIdentityRepairService::class);

        $codes = [];
        foreach ($reflection->getConstants() as $name => $value) {
            if (str_starts_with($name, 'RESULT_')) {
                $codes[] = $value;
            }
        }

        sort($codes);

        self::assertSame(
            ['already_applied', 'failed_rolled_back', 'refused_precondition', 'repaired', 'would_repair'],
            $codes
        );
    }

    /**
     * The audit action must fit `wp_bcc_trust_activity.action`, which is
     * VARCHAR(50). A longer name would be silently truncated by MySQL in
     * non-strict mode, splitting the audit trail across two action names.
     */
    public function testTheAuditActionFitsTheColumn(): void
    {
        self::assertLessThanOrEqual(
            50,
            strlen(SolanaGateIdentityRepairService::AUDIT_ACTION),
            'audit action name exceeds the VARCHAR(50) column'
        );
        self::assertSame(
            'nft_collection_identity_repaired',
            SolanaGateIdentityRepairService::AUDIT_ACTION
        );
    }

    // ── run ids ─────────────────────────────────────────────────────────

    /**
     * Run ids must be unique and unpredictable. A timestamp plus a short
     * counter is neither — two invocations in the same second would
     * collide, and the audit trail would attribute rows to the wrong run.
     */
    public function testRunIdsAreUniqueAndLongEnough(): void
    {
        $m = new ReflectionMethod(SolanaGateIdentityRepairCommand::class, 'mintRunId');
        $m->setAccessible(true);

        $seen = [];
        for ($i = 0; $i < 500; $i++) {
            $id = $m->invoke(null);
            self::assertIsString($id);
            self::assertStringStartsWith('pr5b-', $id);
            // 128 bits of hex.
            self::assertSame(32, strlen(substr($id, 5)));
            self::assertMatchesRegularExpression('/^pr5b-[0-9a-f]{32}$/', $id);
            $seen[$id] = true;
        }

        self::assertCount(500, $seen, 'run ids collided');
    }

    // ── the runner reaches no provider ──────────────────────────────────

    /**
     * The repair must be structurally incapable of calling a provider.
     *
     * Asserted on the SOURCE of the whole repair surface rather than by
     * mocking, because a mock only proves the path the test happened to
     * take. A stray `FetcherFactory` import would be invisible to a
     * behavioural test that never triggers it — and the manifest exists
     * precisely so no lookup is needed.
     */
    public function testTheRepairSurfaceImportsNoProvider(): void
    {
        $root  = dirname(__DIR__, 2);
        $files = [
            'app/Domain/Onchain/Repair/SolanaGateIdentityManifest.php',
            'app/Domain/Onchain/Repair/SolanaGateIdentityRepairService.php',
            'app/Domain/Onchain/Repositories/GateIdentityRepairRepository.php',
            'app/Domain/Onchain/CLI/SolanaGateIdentityRepairCommand.php',
        ];

        $forbidden = [
            'FetcherFactory',
            'SolanaFetcher',
            'CosmosFetcher',
            'EvmFetcher',
            'HeliusEndpoint',
            'SolanaEndpoints',
            'AlchemyEndpoint',
            'StargazeMarketplaceApi',
            'HoldingsService',
            'wp_remote_get',
            'wp_remote_post',
            'wp_remote_request',
            'curl_init',
            'file_get_contents',
            'fsockopen',
        ];

        foreach ($files as $rel) {
            $path = $root . '/' . $rel;
            self::assertFileExists($path);

            $code = self::codeOnly((string) file_get_contents($path));

            foreach ($forbidden as $needle) {
                self::assertStringNotContainsString(
                    $needle,
                    $code,
                    "{$rel} references '{$needle}' — the repair must reach no provider"
                );
            }
        }
    }

    /**
     * Strip comments and string literals so the assertion above is about
     * EXECUTABLE code. Without this the guard would fire on the docblocks
     * that explain why no provider is called — i.e. it would punish
     * documenting the rule.
     */
    private static function codeOnly(string $source): string
    {
        $tokens = @token_get_all($source);
        $out    = '';

        foreach ($tokens as $token) {
            if (is_string($token)) {
                $out .= $token;
                continue;
            }

            switch ($token[0]) {
                case T_COMMENT:
                case T_DOC_COMMENT:
                case T_WHITESPACE:
                case T_CONSTANT_ENCAPSED_STRING:
                case T_ENCAPSED_AND_WHITESPACE:
                case T_INLINE_HTML:
                case T_OPEN_TAG:
                    break;
                default:
                    $out .= $token[1];
            }
        }

        return $out;
    }

    /**
     * Positive control for the guard above: it must actually be able to
     * fail. A source-scanning assertion that has never fired is not known
     * to work.
     */
    public function testTheProviderGuardCanActuallyFail(): void
    {
        $planted = '<?php class X { function go() { return FetcherFactory::make(); } }';

        self::assertStringContainsString('FetcherFactory', self::codeOnly($planted));
    }

    /**
     * ...and must not fire on a mention inside a comment or a string,
     * which is what the tokenizer pass is for.
     */
    public function testTheProviderGuardIgnoresCommentsAndStrings(): void
    {
        $documented = '<?php class X { /** never calls FetcherFactory */ function go() { $s = "FetcherFactory"; } }';

        self::assertStringNotContainsString('FetcherFactory', self::codeOnly($documented));
    }
}
