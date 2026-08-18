<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Onchain\CLI\CosmwasmOneShotDiscoveryCommand;
use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmContractRepository;
use BCC\Trust\Onchain\Repositories\NftSpamContractRepository;
use BCC\Trust\Onchain\Support\ApiRetry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WP_CLI\Dispatcher\CommandFactory;
use WP_CLI\Dispatcher\Subcommand;
use WP_CLI\DocParser;
use WP_CLI\SynopsisParser;
use WP_CLI\SynopsisValidator;

/**
 * `wp bcc-trust cosmwasm run` — THE ARGUMENT LAYER ABOVE THE COMMAND.
 *
 * ── THE DEFECT THIS EXISTS FOR ──────────────────────────────────────────
 * {@see CosmwasmOneShotCliTest} calls `run()` directly, so it proves
 * everything about the command and NOTHING about whether WP-CLI will ever
 * call it. It did not, and the command was uninvokable on deployed
 * staging:
 *
 *     $ wp bcc-trust cosmwasm run --chain=17 --once
 *     Warning: The `wp bcc-trust cosmwasm run` command has an invalid
 *              synopsis part: --once
 *     Error: Parameter errors:
 *      unknown --once parameter
 *     EXIT=1
 *
 * WP-CLI's synopsis grammar has NO FORM for a mandatory bare flag.
 * {@see SynopsisParser::parse()} retypes any non-optional `flag` token to
 * `unknown`; {@see SynopsisValidator::unknown_assoc()} then builds its
 * known-parameter list from `assoc` and `flag` tokens only, so the
 * discarded `--once` came back out as an unrecognised parameter and the
 * process died at exit 1 before `run()` was entered. Because `--once` is
 * mandatory, EVERY real invocation failed. The command's own logic was
 * never at fault: `--chain=17` alone already produced
 * `Error: --once is required…` and exit 2.
 *
 * ── WHY THIS TEST USES WP-CLI'S REAL DISPATCHER ─────────────────────────
 * A test that re-implements the grammar cannot fail the way production
 * failed — it would only ever agree with itself. So the synopsis under
 * assertion is not written here and is not scraped out of the file with a
 * regex: it is whatever {@see CommandFactory::create()} hands back, built
 * from the real docblock by the same code path `wp` runs, and every
 * verdict comes from the real {@see SynopsisParser} and
 * {@see SynopsisValidator}. `wp-cli/wp-cli` is a require-dev at the
 * version staging runs. These classes are string-level: no WordPress, no
 * database, no network.
 *
 * ── THE TWO LAYERS, KEPT APART ──────────────────────────────────────────
 * "WP-CLI refused" and "the command refused" are different outcomes with
 * different exit codes, and conflating them is how the defect hid: the
 * refusal LOOKED like argument validation working. {@see self::dispatch()}
 * therefore runs WP-CLI's argument layer first and only enters the command
 * if that layer would have dispatched, and every test says which layer it
 * expects to be stopped by.
 */
#[CoversClass(CosmwasmOneShotDiscoveryCommand::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CosmwasmCliSynopsisTest extends TestCase
{
    /** How the command is registered in bcc-trust.php. */
    private const COMMAND = 'bcc-trust cosmwasm';

    /** The method WP-CLI turns into the subcommand. */
    private const SUBCOMMAND = 'run';

    /** Dungeon Chain — the planned first canary, and the runbook's example. */
    private const CHAIN = 17;
    private const SLUG  = 'dungeon';
    private const TOKEN = 'dungeon-17';
    private const REST  = 'https://dungeon.example';

    /** WP-CLI's own generic failure code, which it is left sole owner of. */
    private const WP_CLI_EXIT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/cosmwasm-cli-stubs.php';

        ApiRetry::reset();
        CosmwasmCodeFamilyRepository::reset();
        CosmwasmContractRepository::reset();
        CollectionRepository::reset();
        ChainCheckpointRepository::reset();
        ChainRepository::reset();
        NftSpamContractRepository::reset();
        AuditLogger::reset();
        \WP_CLI::reset();
        \BCC\Core\DB\AdvisoryLock::reset();
        \BCC\Trust\Onchain\Support\OnchainCircuitBreaker::reset();
        \BCC\Core\Log\Logger::reset();
        \BccTestObjectCache::reset();
        \BccTestOptionStore::reset();
        \BccTestCronStore::reset();
    }

    // ── reaching WP-CLI's real parser ───────────────────────────────────

    private function pluginRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * The `WP_CLI::add_command()` table, read out of bcc-trust.php.
     *
     * The registration is the only statement that says what an operator
     * types, so the test reads it rather than restating it. Comments are
     * not stripped because no comment in that file contains an
     * `add_command(` call — and if one ever did, the duplicate-name guard
     * in {@see self::subcommand()} would catch it rather than silently
     * pick the wrong class.
     *
     * @return array<string, list<string>> command name => registered classes
     */
    private function registrations(): array
    {
        $bootstrap = (string) file_get_contents($this->pluginRoot() . '/bcc-trust.php');

        preg_match_all(
            '/\\\\WP_CLI::add_command\(\s*\'([^\']+)\'\s*,\s*\\\\?([A-Za-z0-9_\\\\]+)::class/',
            $bootstrap,
            $matches,
            PREG_SET_ORDER
        );

        $table = [];
        foreach ($matches as $match) {
            $table[$match[1]][] = ltrim($match[2], '\\');
        }

        return $table;
    }

    /**
     * The REAL `WP_CLI\Dispatcher\Subcommand` for `bcc-trust cosmwasm run`.
     *
     * Built by WP-CLI's own {@see CommandFactory}, from the class the
     * bootstrap registers, exactly as `wp` builds it — which is what makes
     * `get_synopsis()` below WP-CLI's answer and not this test's.
     */
    private function subcommand(): Subcommand
    {
        $registrations = $this->registrations();

        self::assertArrayHasKey(
            self::COMMAND,
            $registrations,
            'bcc-trust.php must register `' . self::COMMAND . '`'
        );
        self::assertCount(
            1,
            $registrations[self::COMMAND],
            '`' . self::COMMAND . '` must be registered exactly once'
        );
        self::assertSame(
            CosmwasmOneShotDiscoveryCommand::class,
            $registrations[self::COMMAND][0]
        );

        // A stand-in for the `bcc-trust` composite this hangs off. The real
        // one needs WP-CLI's Runner; the only thing CompositeCommand asks a
        // parent for is its hook.
        $parent = new class {
            public function get_hook(): string
            {
                return 'after_wp_load';
            }

            public function get_name(): string
            {
                return 'bcc-trust';
            }

            public function get_parent(): mixed
            {
                return null;
            }
        };

        $composite = CommandFactory::create(
            'cosmwasm',
            $registrations[self::COMMAND][0],
            $parent
        );
        self::assertInstanceOf(\WP_CLI\Dispatcher\CompositeCommand::class, $composite);

        $subcommands = $composite->get_subcommands();
        self::assertArrayHasKey(
            self::SUBCOMMAND,
            $subcommands,
            'WP-CLI must expose a `' . self::SUBCOMMAND . '` subcommand'
        );

        $subcommand = $subcommands[self::SUBCOMMAND];
        self::assertInstanceOf(Subcommand::class, $subcommand);

        return $subcommand;
    }

    /** WP-CLI's synopsis for the command, as WP-CLI derived it. */
    private function synopsis(): string
    {
        return $this->subcommand()->get_synopsis();
    }

    /**
     * WP-CLI's own reading of the `run()` docblock.
     *
     * `Subcommand::get_longdesc()` cannot be used for this: it appends the
     * global parameter table, which needs WP-CLI's Configurator and so a
     * real `wp` runtime. {@see DocParser} is the class that produced the
     * longdesc in the first place, and it is string-level.
     */
    private function docParser(): DocParser
    {
        $doc = (new \ReflectionMethod(
            CosmwasmOneShotDiscoveryCommand::class,
            self::SUBCOMMAND
        ))->getDocComment();

        self::assertIsString($doc, 'the subcommand must carry a docblock at all');

        return new DocParser($doc);
    }

    /**
     * WP-CLI's argument layer, as {@see Subcommand::validate_args()} runs it.
     *
     * Same validator, same calls, same order. `validate_args()` itself
     * cannot be called here because it is private and its `get_path()`
     * helper lives in a file only WP-CLI's bootstrap loads — but every
     * VERDICT below is the real {@see SynopsisValidator}'s, not this
     * test's, and each one is mapped to what WP-CLI does with it.
     *
     * @param array<int, string>   $args
     * @param array<string, mixed> $assoc
     *
     * @return array{
     *     invalid_synopsis: list<string>,
     *     enough_positionals: bool,
     *     unknown_positionals: array<int, string>,
     *     fatal: array<string, string>,
     *     unknown_assoc: array<int|string, string>
     * }
     */
    private function wpCliArgumentLayer(array $args, array $assoc): array
    {
        $validator = new SynopsisValidator($this->synopsis());

        [$errors] = $validator->validate_assoc($assoc);

        return [
            // → WP_CLI::warning('… invalid synopsis part: X')
            'invalid_synopsis'    => $validator->get_unknown(),
            // → show_usage() + exit(1)
            'enough_positionals'  => $validator->enough_positionals($args),
            // → WP_CLI::error('Too many positional arguments: …')
            'unknown_positionals' => $validator->unknown_positionals($args),
            // → WP_CLI::error('Parameter errors: …')
            'fatal'               => $errors['fatal'],
            // → WP_CLI::error('unknown --X parameter')
            'unknown_assoc'       => (array) $validator->unknown_assoc($assoc),
        ];
    }

    /**
     * Would WP-CLI hand these arguments to the command at all?
     *
     * `invalid_synopsis` is deliberately NOT a rejection: WP-CLI only
     * warns about it. It is the upstream cause — a discarded declaration
     * is what makes a passed flag look unknown — which is why the two are
     * asserted separately rather than folded together.
     *
     * @param array<int, string>   $args
     * @param array<string, mixed> $assoc
     */
    private function wpCliWouldDispatch(array $args, array $assoc): bool
    {
        $layer = $this->wpCliArgumentLayer($args, $assoc);

        return $layer['enough_positionals']
            && $layer['unknown_positionals'] === []
            && $layer['fatal'] === []
            && $layer['unknown_assoc'] === [];
    }

    /**
     * WP-CLI's argument layer, and then — only if it would have — the command.
     *
     * @param array<int, string>   $args
     * @param array<string, mixed> $assoc
     *
     * @return array{layer: 'wp-cli'|'command', exit: int}
     */
    private function dispatch(array $args, array $assoc): array
    {
        if (!$this->wpCliWouldDispatch($args, $assoc)) {
            return ['layer' => 'wp-cli', 'exit' => self::WP_CLI_EXIT];
        }

        $command = new CosmwasmOneShotDiscoveryCommand();

        try {
            $command->run($args, $assoc);
        } catch (\BccTestCliHalt $halt) {
            return ['layer' => 'command', 'exit' => $halt->exitCode];
        }

        return ['layer' => 'command', 'exit' => CosmwasmOneShotDiscoveryCommand::EXIT_OK];
    }

    /** The default happy environment: WP-CLI, an allowlist, an opted-in chain. */
    private function arrange(): void
    {
        define('WP_CLI', true);
        define('BCC_COSMWASM_CHAIN_ALLOWLIST', (string) self::CHAIN);
        ChainRepository::seed(self::CHAIN, self::SLUG, self::REST, 'cosmos', 1);
    }

    // ── (1) the command WP-CLI actually registers ───────────────────────

    /**
     * `wp bcc-trust cosmwasm run` is a real, reachable command path.
     *
     * Composed from the two halves that decide it and nothing else: the
     * name bcc-trust.php passes to `add_command()`, and the subcommand
     * WP-CLI's own factory derives from the class's public methods.
     */
    public function testWpCliRegistersTheCommandAsBccTrustCosmwasmRun(): void
    {
        $subcommand = $this->subcommand();

        self::assertSame(self::SUBCOMMAND, $subcommand->get_name());
        self::assertSame(
            'bcc-trust cosmwasm run',
            self::COMMAND . ' ' . $subcommand->get_name(),
            'the operator-typed invocation'
        );

        // `run()` is the ONLY subcommand: there is no second way in.
        $registrations = $this->registrations();
        $composite     = CommandFactory::create(
            'cosmwasm',
            $registrations[self::COMMAND][0],
            new class {
                public function get_hook(): string
                {
                    return 'after_wp_load';
                }
            }
        );
        self::assertInstanceOf(\WP_CLI\Dispatcher\CompositeCommand::class, $composite);
        self::assertSame([self::SUBCOMMAND], array_keys($composite->get_subcommands()));

        // And WP-CLI read the `@when after_wp_load` tag off it, which is
        // what makes the command a post-WordPress-load one.
        self::assertContains('after_wp_load', \BccTestCliRunner::$earlyInvokes);
    }

    // ── (2) the invocation that used to die at WP-CLI ───────────────────

    /**
     * THE REGRESSION, END TO END.
     *
     * `--chain=17 --once` is the invocation staging refused. WP-CLI must
     * now accept it — no invalid synopsis part, no unknown parameter, no
     * parameter error — and the command must actually receive it, which is
     * observable as the dry-run plan it prints when no `--confirm` is
     * given.
     */
    public function testChainAndOnceAreAcceptedByWpCliAndReachTheCommand(): void
    {
        $this->arrange();

        $layer = $this->wpCliArgumentLayer([], ['chain' => (string) self::CHAIN, 'once' => true]);

        self::assertSame([], $layer['invalid_synopsis'], 'no declaration was discarded');
        self::assertSame([], $layer['unknown_assoc'], '--once must be a KNOWN parameter');
        self::assertSame([], $layer['fatal']);
        self::assertTrue($layer['enough_positionals']);

        $result = $this->dispatch([], ['chain' => (string) self::CHAIN, 'once' => true]);

        self::assertSame('command', $result['layer'], 'WP-CLI must hand it over');
        self::assertSame(CosmwasmOneShotDiscoveryCommand::EXIT_OK, $result['exit']);

        // It really got INTO the command: this text only exists past
        // argument validation, past eligibility and past the token check.
        $output = \WP_CLI::output();
        self::assertStringContainsString('DRY RUN', $output);
        self::assertStringContainsString('--confirm=' . self::TOKEN, $output);
        self::assertStringNotContainsString('--once is required', $output);
    }

    // ── (3) brackets are parsing, not policy ────────────────────────────

    /**
     * `[--once]` DID NOT MAKE `--once` OPTIONAL.
     *
     * The brackets exist because WP-CLI's grammar has no other way to
     * declare a bare flag. Omitting it is therefore syntactically fine —
     * WP-CLI raises nothing and dispatches — and it is the COMMAND that
     * refuses, with its own message and its own documented exit 2. Anyone
     * who "tidies" the runtime check away because the synopsis looks
     * optional breaks this test.
     */
    public function testOmittingOnceIsSyntacticallyValidAndTheCommandStillRefusesWithExitTwo(): void
    {
        $this->arrange();

        $layer = $this->wpCliArgumentLayer([], ['chain' => (string) self::CHAIN]);

        self::assertSame([], $layer['fatal'], 'WP-CLI must not treat a missing --once as a parameter error');
        self::assertSame([], $layer['unknown_assoc']);
        self::assertTrue(
            $this->wpCliWouldDispatch([], ['chain' => (string) self::CHAIN]),
            'the omission reaches the command rather than dying at the parser'
        );

        $result = $this->dispatch([], ['chain' => (string) self::CHAIN]);

        self::assertSame('command', $result['layer'], 'the refusal is the COMMAND\'s, not WP-CLI\'s');
        self::assertSame(CosmwasmOneShotDiscoveryCommand::EXIT_INVALID_ARGS, $result['exit']);
        self::assertSame(2, $result['exit'], 'the documented exit code, unchanged');
        self::assertStringContainsString('--once is required', \WP_CLI::output());
    }

    // ── (4) an unknown flag never reaches the command ───────────────────

    /**
     * A TYPO IS STILL WP-CLI'S TO REFUSE.
     *
     * `--confrim` is the copy-paste typo the confirmation token exists to
     * catch, and it must be stopped at the argument layer — the command is
     * never entered, so nothing it would have done can have happened. That
     * is the same mechanism (`unknown_assoc`) the bad synopsis used to
     * trip on `--once`, so this is also the proof that the fix did not buy
     * acceptance by making the parser permissive.
     */
    public function testAnUnknownFlagIsRejectedByWpCliBeforeTheCommandIsReached(): void
    {
        $this->arrange();

        $assoc = [
            'chain'   => (string) self::CHAIN,
            'once'    => true,
            'confrim' => self::TOKEN,
        ];

        $layer = $this->wpCliArgumentLayer([], $assoc);

        self::assertSame(
            ['confrim'],
            array_values($layer['unknown_assoc']),
            'WP-CLI must name the typo, not the flags it does know'
        );
        self::assertSame([], $layer['invalid_synopsis'], 'and not because a declaration was discarded');
        self::assertFalse($this->wpCliWouldDispatch([], $assoc));

        $result = $this->dispatch([], $assoc);

        self::assertSame('wp-cli', $result['layer']);
        self::assertSame(self::WP_CLI_EXIT, $result['exit']);
        // The command never ran, so it printed nothing and wrote nothing.
        self::assertSame('', \WP_CLI::output());
        self::assertSame([], ApiRetry::$calls);
        self::assertSame([], AuditLogger::$entries);
        self::assertSame([], \BCC\Core\DB\AdvisoryLock::$acquired);
    }

    // ── (5) the runbook line ────────────────────────────────────────────

    /**
     * The exact command an operator will paste, through the real parser.
     *
     *     wp bcc-trust cosmwasm run --chain=17 --once --confirm=dungeon-17
     *
     * Argument parsing only. Whether it is then ELIGIBLE to run is a
     * different question with a different exit code, answered by
     * {@see CosmwasmOneShotCliTest}; this is the layer that used to say no
     * before any of that was reached.
     */
    public function testTheConfirmedRunbookInvocationPassesArgumentParsing(): void
    {
        $assoc = [
            'chain'   => (string) self::CHAIN,
            'once'    => true,
            'confirm' => self::TOKEN,
        ];

        $layer = $this->wpCliArgumentLayer([], $assoc);

        self::assertSame([], $layer['invalid_synopsis']);
        self::assertSame([], $layer['unknown_assoc']);
        self::assertSame([], $layer['fatal']);
        self::assertSame([], $layer['unknown_positionals']);
        self::assertTrue($layer['enough_positionals']);
        self::assertTrue($this->wpCliWouldDispatch([], $assoc));

        // And it is the line the command's own docs tell operators to run.
        self::assertStringContainsString(
            'wp bcc-trust cosmwasm run --chain=17 --once --confirm=dungeon-17',
            $this->docParser()->get_longdesc()
        );
    }

    // ── (6) the mutation detector ───────────────────────────────────────

    /**
     * NO SYNOPSIS TOKEN MAY BE UNPARSEABLE. THIS IS THE ASSERTION THAT
     * FAILS IF `[--once]` IS REVERTED TO `--once`.
     *
     * {@see SynopsisParser::parse()} does not throw and does not drop a
     * token it cannot honour — it retypes it to `unknown` and carries on,
     * which is exactly why a broken synopsis shipped quietly. A bare
     * required flag is the only form that trips it here.
     *
     * The mutant is parsed alongside the real thing so the DETECTOR is
     * pinned too: if a future WP-CLI stopped marking the bare form
     * `unknown`, the second half fails and says so, instead of this test
     * passing forever while checking nothing.
     */
    public function testNoSynopsisTokenIsUnparseable(): void
    {
        $synopsis = $this->synopsis();
        $spec     = SynopsisParser::parse($synopsis);

        self::assertCount(3, $spec);

        // THE MECHANISM, ASSERTED FIRST so that a reverted `[--once]`
        // fails here — with the explanation — rather than on the
        // string-equality assertion below, which would only report that
        // two strings differ.
        $types = [];
        foreach ($spec as $token) {
            self::assertNotSame(
                'unknown',
                $token['type'],
                sprintf(
                    'WP-CLI cannot parse `%s`. A bare flag must be declared OPTIONAL — `[--once]`, '
                    . 'not `--once` — or WP-CLI discards the declaration and then refuses the flag '
                    . 'as unknown, making every invocation of this command fail.',
                    (string) $token['token']
                )
            );
            $types[(string) $token['name']] = $token['type'];
        }

        self::assertSame(
            ['chain' => 'assoc', 'once' => 'flag', 'confirm' => 'assoc'],
            $types
        );

        self::assertSame(
            '--chain=<id> [--once] [--confirm=<token>]',
            $synopsis,
            'WP-CLI\'s own reading of the ## OPTIONS block'
        );

        // THE DETECTOR ITSELF. The pre-fix declaration, parsed by the same
        // code, still has to come out `unknown` — otherwise the assertion
        // above is no longer watching anything.
        $mutant = SynopsisParser::parse('--chain=<id> --once [--confirm=<token>]');
        self::assertSame(
            ['--once'],
            (new SynopsisValidator('--chain=<id> --once [--confirm=<token>]'))->get_unknown(),
            'the bare required form is what WP-CLI rejects'
        );
        self::assertSame('unknown', $mutant[1]['type']);
        self::assertSame(
            ['once'],
            array_values((array) (new SynopsisValidator('--chain=<id> --once [--confirm=<token>]'))
                ->unknown_assoc(['chain' => '17', 'once' => true])),
            'and this is the `unknown --once parameter` staging printed'
        );
    }
}
