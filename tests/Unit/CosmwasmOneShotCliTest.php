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
use BCC\Trust\Onchain\Support\CosmwasmPassReport;
use BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * `wp bcc-trust cosmwasm run` — the supervised one-shot.
 *
 * ── WHAT THESE TESTS ARE FOR ────────────────────────────────────────────
 * The command bypasses exactly ONE thing —
 * `BCC_COSMWASM_DISCOVERY_ENABLED` — and every test here is about the
 * things it must NOT bypass. The failure mode being guarded against is
 * not "the command is broken"; it is "the command quietly became a way
 * around a control that an operator believes is holding". So the
 * assertions are overwhelmingly negative: nothing was requested, nothing
 * was written, nothing was stamped, and the process exited non-zero with
 * the documented code.
 *
 * Two structural tests carry the load a behavioural test cannot:
 * {@see testTheCommandIsUnreachableFromAnyWebBootstrap()} proves there is
 * no HTTP/REST/AJAX/cron path to the class at all, and
 * {@see testTheCommandNamesNoOtherPass()} proves the backfill, the weekly
 * retry and the monthly refresh are not reachable from it — both of which
 * are properties of the WIRING, not of any single invocation.
 *
 * Isolation: the CosmwasmDiscoveryTest pattern. Every repository, the
 * chain registry, the fetcher factory, the audit logger and the transport
 * are faked at their PRODUCTION FQNs inside a subprocess, and WP_CLI is a
 * recording double whose halt() throws. CI NEVER TOUCHES A PUBLIC RPC.
 */
#[CoversClass(CosmwasmOneShotDiscoveryCommand::class)]
#[CoversClass(CosmwasmDiscoveryWorker::class)]
#[CoversClass(CosmwasmPassReport::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CosmwasmOneShotCliTest extends TestCase
{
    /** Dungeon Chain — the planned first canary. */
    private const CHAIN = 17;
    private const SLUG  = 'dungeon';
    private const TOKEN = 'dungeon-17';
    private const REST  = 'https://dungeon.example';

    /** A second opted-in chain, for "against another chain". */
    private const CHAIN_B = 18;
    private const SLUG_B  = 'juno';
    private const REST_B  = 'https://juno.example';

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

    // ── helpers ─────────────────────────────────────────────────────────

    /** The default happy environment: WP-CLI, an allowlist, an opted-in chain. */
    private function arrange(): void
    {
        define('WP_CLI', true);
        define('BCC_COSMWASM_CHAIN_ALLOWLIST', (string) self::CHAIN);
        ChainRepository::seed(self::CHAIN, self::SLUG, self::REST, 'cosmos', 1);
    }

    /**
     * Invoke the command and return the process exit code.
     *
     * 0 means the command returned normally; anything else is the code it
     * halted with. A `BccTestCliHalt` is what `exit()` looks like from in
     * here, so a test that expects a refusal is genuinely observing that
     * execution stopped, not that a message was printed and the command
     * carried on.
     *
     * @param array<string, mixed> $assoc
     * @param array<int, string>   $args
     */
    private function invoke(array $assoc, array $args = []): int
    {
        $command = new CosmwasmOneShotDiscoveryCommand();

        try {
            $command->run($args, $assoc);
        } catch (\BccTestCliHalt $halt) {
            return $halt->exitCode;
        }

        return 0;
    }

    /** The full, valid, EXECUTING invocation. */
    private function executeArgs(): array
    {
        return ['chain' => (string) self::CHAIN, 'once' => true, 'confirm' => self::TOKEN];
    }

    /** @param array<string, mixed> $payload */
    private function queueJson(array $payload, int $code = 200): void
    {
        ApiRetry::$queue[] = ['code' => $code, 'body' => (string) json_encode($payload)];
    }

    /**
     * A code listing whose lowest id meets a zero watermark, so the walk
     * is authoritative and one pass completes cleanly.
     */
    private function queueOneCleanCodePage(): void
    {
        $this->queueJson([
            'code_infos' => [
                ['code_id' => '3', 'data_hash' => 'AA11'],
                ['code_id' => '2', 'data_hash' => 'BB22'],
                ['code_id' => '1', 'data_hash' => 'CC33'],
            ],
            'pagination' => ['next_key' => null],
        ]);
    }

    /** @return list<string> every URL the transport fake saw. */
    private function urls(): array
    {
        return array_map(static fn(array $call): string => $call['url'], ApiRetry::$calls);
    }

    /** Nothing was requested and nothing was written. The refusal assertion. */
    private function assertInertRun(string $because): void
    {
        self::assertSame([], ApiRetry::$calls, $because . ' — a request was made');
        self::assertSame([], ChainCheckpointRepository::$rows, $because . ' — a checkpoint row was written');
        self::assertSame([], ChainCheckpointRepository::$discoveryTouches, $because . ' — the chain was stamped');
        self::assertSame([], CollectionRepository::$upserted, $because . ' — a collection was emitted');
        self::assertSame([], AuditLogger::$entries, $because . ' — an audit row was written');
        self::assertSame([], \BCC\Core\DB\AdvisoryLock::$acquired, $because . ' — the lock was taken');
    }

    /**
     * Executable statements only.
     *
     * Token-walked rather than regex-stripped because this command's
     * docblock deliberately NAMES the passes it must not reach ("NOT
     * invoked: backfill, weekly retry, metadata refresh"), so a substring
     * search over the raw source would count the prose as a call site and
     * the test would fail on its own documentation.
     */
    private function codeWithoutComments(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }

        return $out;
    }

    private function pluginRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    // ── (a) it is not reachable from the web at all ─────────────────────

    /**
     * THE WIRING, not an invocation.
     *
     * The class must be named in exactly ONE place in the whole plugin
     * outside its own file and this test: inside bcc-trust.php's
     * `if (defined('WP_CLI') && WP_CLI)` block. No add_action, no
     * register_rest_route, no admin_post, no wp_ajax, no cron hook.
     */
    public function testTheCommandIsUnreachableFromAnyWebBootstrap(): void
    {
        $root      = $this->pluginRoot();
        $bootstrap = $this->codeWithoutComments((string) file_get_contents($root . '/bcc-trust.php'));

        self::assertSame(
            1,
            substr_count($bootstrap, 'CosmwasmOneShotDiscoveryCommand'),
            'the command must be registered exactly once'
        );

        // Brace-match the WP-CLI guard and prove the registration is inside it.
        $guard = "if (defined('WP_CLI') && WP_CLI) {";
        $start = strpos($bootstrap, $guard);
        self::assertIsInt($start, 'the WP-CLI guard block must exist verbatim');

        $depth = 0;
        $end   = null;
        for ($i = $start + strlen($guard) - 1, $len = strlen($bootstrap); $i < $len; $i++) {
            if ($bootstrap[$i] === '{') {
                $depth++;
            } elseif ($bootstrap[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }
        self::assertIsInt($end, 'the WP-CLI guard block must close');

        $block = substr($bootstrap, $start, $end - $start + 1);
        self::assertStringContainsString(
            'CosmwasmOneShotDiscoveryCommand',
            $block,
            'the ONLY registration must sit inside `if (defined(\'WP_CLI\') && WP_CLI)`'
        );

        // And nothing else in the plugin mentions it — that is what rules
        // out a REST controller, an AJAX handler or a cron callback
        // reaching it by another route.
        $offenders = [];
        $iterator  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/app', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            if (str_ends_with($path, 'CLI/CosmwasmOneShotDiscoveryCommand.php')) {
                continue; // Its own definition.
            }
            if (str_contains((string) file_get_contents($path), 'CosmwasmOneShotDiscoveryCommand')) {
                $offenders[] = $path;
            }
        }
        self::assertSame([], $offenders, 'no application code may reference the CLI command');
    }

    /**
     * And it refuses at RUNTIME too, not only by wiring.
     *
     * `WP_CLI` the constant is not defined in this test — which is what a
     * web request looks like — so even a direct call from some future
     * controller refuses instead of walking a chain on an HTTP request.
     */
    public function testTheCommandRefusesWhenNotRunningUnderWpCli(): void
    {
        self::assertFalse(defined('WP_CLI'), 'precondition: this process is not WP-CLI');
        define('BCC_COSMWASM_CHAIN_ALLOWLIST', (string) self::CHAIN);
        ChainRepository::seed(self::CHAIN, self::SLUG, self::REST, 'cosmos', 1);

        $code = $this->invoke($this->executeArgs());

        self::assertSame(CosmwasmOneShotDiscoveryCommand::EXIT_INVALID_ARGS, $code);
        $this->assertInertRun('invoked outside WP-CLI');
    }

    // ── (b) arguments ───────────────────────────────────────────────────

    public function testItRefusesWithoutOnce(): void
    {
        $this->arrange();

        $code = $this->invoke(['chain' => (string) self::CHAIN, 'confirm' => self::TOKEN]);

        self::assertSame(CosmwasmOneShotDiscoveryCommand::EXIT_INVALID_ARGS, $code);
        self::assertStringContainsString('--once is required', \WP_CLI::output());
        $this->assertInertRun('--once was omitted');
    }

    public function testItRefusesAValuedOnceFlag(): void
    {
        $this->arrange();

        $code = $this->invoke(['chain' => (string) self::CHAIN, 'once' => '1', 'confirm' => self::TOKEN]);

        self::assertSame(CosmwasmOneShotDiscoveryCommand::EXIT_INVALID_ARGS, $code);
        $this->assertInertRun('--once=1 is not the bare flag');
    }

    public function testItRefusesWithoutAChain(): void
    {
        $this->arrange();

        $code = $this->invoke(['once' => true, 'confirm' => self::TOKEN]);

        self::assertSame(CosmwasmOneShotDiscoveryCommand::EXIT_INVALID_ARGS, $code);
        self::assertStringContainsString('--chain=<id> is required', \WP_CLI::output());
        $this->assertInertRun('--chain was omitted');
    }

    /**
     * Every shape that is not one unambiguous positive integer.
     *
     * A silent `(int)` cast turns "17abc", " 17" and "17.9" into 17 and
     * "all" into 0 — which is how the wrong chain, or every chain, gets
     * walked by a command that looked like it validated its input.
     */
    public function testItRefusesEveryMalformedChainId(): void
    {
        $this->arrange();

        foreach (['0', '-1', '17.9', ' 17', '17abc', 'all', '*', '17,18', '', '017', '1e3'] as $raw) {
            \WP_CLI::reset();
            $code = $this->invoke(['chain' => $raw, 'once' => true, 'confirm' => self::TOKEN]);
            self::assertSame(
                CosmwasmOneShotDiscoveryCommand::EXIT_INVALID_ARGS,
                $code,
                sprintf('--chain=%s must be refused', var_export($raw, true))
            );
        }

        $this->assertInertRun('every chain id was malformed');
    }

    public function testItRefusesAnUnknownFlag(): void
    {
        $this->arrange();

        $code = $this->invoke([
            'chain'   => (string) self::CHAIN,
            'once'    => true,
            'confrim' => self::TOKEN, // the typo a confirmation exists to catch
        ]);

        self::assertSame(CosmwasmOneShotDiscoveryCommand::EXIT_INVALID_ARGS, $code);
        self::assertStringContainsString('Unknown flag', \WP_CLI::output());
        $this->assertInertRun('an unknown flag was passed');
    }

    public function testItRefusesPositionalArguments(): void
    {
        $this->arrange();

        $code = $this->invoke($this->executeArgs(), ['17']);

        self::assertSame(CosmwasmOneShotDiscoveryCommand::EXIT_INVALID_ARGS, $code);
        $this->assertInertRun('a positional argument was passed');
    }

    // ── (c) confirmation ────────────────────────────────────────────────

    public function testWithoutConfirmationItIsADryRunThatTouchesNothing(): void
    {
        $this->arrange();
        $this->queueOneCleanCodePage();

        $code = $this->invoke(['chain' => (string) self::CHAIN, 'once' => true]);

        self::assertSame(CosmwasmOneShotDiscoveryCommand::EXIT_OK, $code, 'a clean dry run exits 0');
        self::assertStringContainsString('DRY RUN', \WP_CLI::output());
        self::assertStringContainsString('--confirm=dungeon-17', \WP_CLI::output());
        $this->assertInertRun('no --confirm was given');
        // The queued page is still sitting there unread.
        self::assertCount(1, ApiRetry::$queue);
    }

    /**
     * A WRONG token is an error, not a downgrade to a dry run.
     *
     * The case being caught is a command line copied from another chain's
     * runbook with `--chain` edited. Silently dry-running it would teach
     * an operator that the confirmation is optional.
     */
    public function testAConfirmationForAnotherChainIsRefused(): void
    {
        $this->arrange();
        ChainRepository::seed(self::CHAIN_B, self::SLUG_B, self::REST_B, 'cosmos', 1);

        $code = $this->invoke([
            'chain'   => (string) self::CHAIN,
            'once'    => true,
            'confirm' => 'juno-18',
        ]);

        self::assertSame(CosmwasmOneShotDiscoveryCommand::EXIT_INVALID_ARGS, $code);
        self::assertStringContainsString('Expected --confirm=dungeon-17', \WP_CLI::output());
        $this->assertInertRun('the token named a different chain');
    }

    public function testAnAlmostRightTokenIsRefused(): void
    {
        $this->arrange();

        foreach (['dungeon-18', 'dungeon', '17', 'DUNGEON-17', 'dungeon-17 '] as $token) {
            \WP_CLI::reset();
            $code = $this->invoke(['chain' => (string) self::CHAIN, 'once' => true, 'confirm' => $token]);
            self::assertSame(
                CosmwasmOneShotDiscoveryCommand::EXIT_INVALID_ARGS,
                $code,
                sprintf('--confirm=%s must be refused', $token)
            );
        }

        $this->assertInertRun('every token was wrong');
    }

    // ── (d) eligibility — everything it must NOT bypass ─────────────────

    public function testItRefusesAChainThatIsNotOptedIn(): void
    {
        define('WP_CLI', true);
        define('BCC_COSMWASM_CHAIN_ALLOWLIST', (string) self::CHAIN);
        // In the allowlist, correct token, correct flags — and the operator
        // opt-in column is 0. The allowlist is an AND, never an OR.
        ChainRepository::seed(self::CHAIN, self::SLUG, self::REST, 'cosmos', 0);

        $code = $this->invoke($this->executeArgs());

        self::assertSame(CosmwasmOneShotDiscoveryCommand::EXIT_NOT_ELIGIBLE, $code);
        self::assertStringContainsString('not in the scanner\'s eligible set', \WP_CLI::output());
        $this->assertInertRun('the chain is not opted in');
    }

    /**
     * THE DELIBERATE ASYMMETRY.
     *
     * For the SCHEDULED passes an undefined allowlist means "no
     * restriction". For this command it means REFUSE. That is not an
     * oversight to be normalised — it is the second independent statement
     * of "yes, this chain, on this environment", and a supervised run with
     * the master gate bypassed has only been authorised once without it.
     */
    public function testItFailsClosedWhenTheAllowlistIsUndefined(): void
    {
        define('WP_CLI', true);
        self::assertFalse(defined('BCC_COSMWASM_CHAIN_ALLOWLIST'));
        ChainRepository::seed(self::CHAIN, self::SLUG, self::REST, 'cosmos', 1);

        // Proof that this chain WOULD be scanned by cron: the scheduled
        // policy treats the undefined allowlist as no restriction.
        self::assertTrue(CosmwasmDiscoveryWorker::isChainScannable(self::CHAIN));

        $code = $this->invoke($this->executeArgs());

        self::assertSame(CosmwasmOneShotDiscoveryCommand::EXIT_NOT_ELIGIBLE, $code);
        self::assertStringContainsString('BCC_COSMWASM_CHAIN_ALLOWLIST is not defined', \WP_CLI::output());
        $this->assertInertRun('the allowlist is undefined');
    }

    public function testItRefusesAChainOutsideTheAllowlist(): void
    {
        define('WP_CLI', true);
        define('BCC_COSMWASM_CHAIN_ALLOWLIST', (string) self::CHAIN_B);
        ChainRepository::seed(self::CHAIN, self::SLUG, self::REST, 'cosmos', 1);

        $code = $this->invoke($this->executeArgs());

        self::assertSame(CosmwasmOneShotDiscoveryCommand::EXIT_NOT_ELIGIBLE, $code);
        self::assertStringContainsString('not named in BCC_COSMWASM_CHAIN_ALLOWLIST', \WP_CLI::output());
        $this->assertInertRun('the chain is outside the allowlist');
    }

    /** Running "against another chain": the allowlist scopes the canary. */
    public function testItRefusesAnotherChainWhileTheCanaryChainIsAllowed(): void
    {
        define('WP_CLI', true);
        define('BCC_COSMWASM_CHAIN_ALLOWLIST', (string) self::CHAIN);
        ChainRepository::seed(self::CHAIN, self::SLUG, self::REST, 'cosmos', 1);
        ChainRepository::seed(self::CHAIN_B, self::SLUG_B, self::REST_B, 'cosmos', 1);

        $code = $this->invoke([
            'chain'   => (string) self::CHAIN_B,
            'once'    => true,
            'confirm' => 'juno-18',
        ]);

        self::assertSame(CosmwasmOneShotDiscoveryCommand::EXIT_NOT_ELIGIBLE, $code);
        $this->assertInertRun('chain 18 is outside the canary allowlist');
    }

    public function testItRefusesAnUnsupportedChain(): void
    {
        $this->arrange();
        ChainCheckpointRepository::ensureExists(self::CHAIN);
        ChainCheckpointRepository::$rows[self::CHAIN]->cw_discovery_state
            = ChainCheckpointRepository::CW_STATE_UNSUPPORTED;

        $code = $this->invoke($this->executeArgs());

        self::assertSame(CosmwasmOneShotDiscoveryCommand::EXIT_NOT_ELIGIBLE, $code);
        self::assertSame([], ApiRetry::$calls, 'a chain with no wasm module must not be probed again');
        self::assertSame([], ChainCheckpointRepository::$discoveryTouches);
        self::assertSame([], \BCC\Core\DB\AdvisoryLock::$acquired);
        self::assertSame([], AuditLogger::$entries);
    }

    public function testItRefusesAPausedChain(): void
    {
        $this->arrange();
        ChainCheckpointRepository::ensureExists(self::CHAIN);
        ChainCheckpointRepository::$rows[self::CHAIN]->cw_discovery_state
            = ChainCheckpointRepository::CW_STATE_PAUSED;

        $code = $this->invoke($this->executeArgs());

        self::assertSame(CosmwasmOneShotDiscoveryCommand::EXIT_NOT_ELIGIBLE, $code);
        self::assertSame([], ApiRetry::$calls, 'an operator pause must hold against a terminal too');
        self::assertSame([], ChainCheckpointRepository::$discoveryTouches);
        self::assertSame([], \BCC\Core\DB\AdvisoryLock::$acquired);
    }

    public function testItRefusesAnUnknownChainId(): void
    {
        define('WP_CLI', true);
        define('BCC_COSMWASM_CHAIN_ALLOWLIST', '4242');

        $code = $this->invoke(['chain' => '4242', 'once' => true, 'confirm' => 'chain-4242']);

        self::assertSame(CosmwasmOneShotDiscoveryCommand::EXIT_NOT_ELIGIBLE, $code);
        self::assertStringContainsString('not in wp_bcc_chains', \WP_CLI::output());
        $this->assertInertRun('the chain id is not in the registry');
    }

    public function testItRefusesANonCosmosChain(): void
    {
        define('WP_CLI', true);
        define('BCC_COSMWASM_CHAIN_ALLOWLIST', (string) self::CHAIN);
        ChainRepository::seed(self::CHAIN, 'ethereum', self::REST, 'evm', 1);

        $code = $this->invoke(['chain' => (string) self::CHAIN, 'once' => true, 'confirm' => 'ethereum-17']);

        self::assertSame(CosmwasmOneShotDiscoveryCommand::EXIT_NOT_ELIGIBLE, $code);
        $this->assertInertRun('an EVM chain has no wasmd endpoints');
    }

    /** A fail-closed repository read is not evidence that a chain is eligible. */
    public function testItRefusesWhenTheCheckpointReadFails(): void
    {
        $this->arrange();
        ChainCheckpointRepository::$failGetAll = true;

        $code = $this->invoke($this->executeArgs());

        self::assertSame(CosmwasmOneShotDiscoveryCommand::EXIT_NOT_ELIGIBLE, $code);
        self::assertSame([], ApiRetry::$calls);
        self::assertSame([], \BCC\Core\DB\AdvisoryLock::$acquired);
    }

    // ── (e) the two scheduling gates: ARMED means refuse ────────────────

    public function testItRefusesWhileTheHistoricalBackfillIsArmed(): void
    {
        $this->arrange();
        define('BCC_COSMWASM_DISCOVERY_ENABLED', true);
        define('BCC_COSMWASM_BACKFILL_ENABLED', true);

        $code = $this->invoke($this->executeArgs());

        self::assertSame(CosmwasmOneShotDiscoveryCommand::EXIT_NOT_ELIGIBLE, $code);
        self::assertStringContainsString('BCC_COSMWASM_BACKFILL_ENABLED is on', \WP_CLI::output());
        $this->assertInertRun('the backfill is armed');
    }

    /**
     * THE INVERSION, AND THE REASON THE LOCK IS NOT ENOUGH.
     *
     * `BCC_COSMWASM_DISCOVERY_ENABLED` is the ONE constant this command
     * bypasses — while it is OFF. Armed, it means the three scheduled
     * hooks are LIVE, and then a supervised run is no longer supervising
     * anything it can attribute.
     *
     * The per-chain advisory lock does NOT close this. It excludes
     * SIMULTANEOUS execution and only that; a cron tick that fires a
     * second before this process acquires the lock, or a second after it
     * releases, is ordinary armed-cron behaviour and is invisible in this
     * command's summary. The operator would read one JSON object and
     * attribute to it the writes, the request spend and the watermark
     * movement of a pass they never saw. So the command refuses BEFORE
     * the first request and BEFORE the first write.
     *
     * Note the constants: the backfill constant is NOT defined here, so
     * this refusal cannot be the backfill one wearing a different hat.
     */
    public function testItRefusesWhileTheScheduledDiscoveryGateIsArmed(): void
    {
        $this->arrange();
        $this->queueOneCleanCodePage();
        define('BCC_COSMWASM_DISCOVERY_ENABLED', true);
        self::assertFalse(defined('BCC_COSMWASM_BACKFILL_ENABLED'), 'precondition: only the discovery gate is armed');

        // Proof this is NOT an eligibility problem in disguise: with the
        // gate armed the scheduled passes would happily walk this chain.
        self::assertTrue(CosmwasmDiscoveryWorker::isChainScannable(self::CHAIN));

        $code = $this->invoke($this->executeArgs());

        self::assertSame(CosmwasmOneShotDiscoveryCommand::EXIT_NOT_ELIGIBLE, $code);

        $output = \WP_CLI::output();
        self::assertStringContainsString('BCC_COSMWASM_DISCOVERY_ENABLED is on', $output);
        // And it says WHY the lock does not make this safe, because the
        // next operator to hit it will reach for exactly that argument.
        self::assertStringContainsString('immediately before', $output);
        self::assertStringNotContainsString('BCC_COSMWASM_BACKFILL_ENABLED is on', $output);

        $this->assertInertRun('the scheduled discovery gate is armed');
        // The queued page is still unread: the refusal beat the network.
        self::assertCount(1, ApiRetry::$queue);
    }

    /**
     * Both gates refuse, and each names ITSELF.
     *
     * `backfillEnabled()` is AND-ed with `discoveryEnabled()`, so an armed
     * backfill always implies an armed discovery gate. Checking discovery
     * first would make the backfill branch unreachable — the message an
     * operator needs ("turn the backfill off") would never print. This
     * pins the order.
     */
    public function testTheBackfillRefusalIsNotShadowedByTheDiscoveryRefusal(): void
    {
        $this->arrange();
        define('BCC_COSMWASM_DISCOVERY_ENABLED', true);
        define('BCC_COSMWASM_BACKFILL_ENABLED', true);

        $code = $this->invoke($this->executeArgs());

        self::assertSame(CosmwasmOneShotDiscoveryCommand::EXIT_NOT_ELIGIBLE, $code);
        $output = \WP_CLI::output();
        self::assertStringContainsString('BCC_COSMWASM_BACKFILL_ENABLED is on', $output);
        self::assertStringNotContainsString(
            'BCC_COSMWASM_DISCOVERY_ENABLED is on',
            $output,
            'the more specific refusal wins, so the operator is told about the expensive pass'
        );
        $this->assertInertRun('both gates are armed');
    }

    /**
     * THE WIRING again: the command cannot INVOKE another pass, because it
     * does not name one.
     */
    public function testTheCommandNamesNoOtherPass(): void
    {
        $file = $this->pluginRoot() . '/app/Domain/Onchain/CLI/CosmwasmOneShotDiscoveryCommand.php';
        $code = $this->codeWithoutComments((string) file_get_contents($file));

        foreach ([
            'runBackfillTick',
            'runBackfillForChain',
            'runWeeklyRetry',
            'runMetadataRefresh',
            'BACKFILL_HOOK',
            'WEEKLY_HOOK',
            'METADATA_HOOK',
            'DAILY_HOOK',
        ] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $code,
                sprintf('the supervised command must not reach %s', $forbidden)
            );
        }

        // The one entry point it DOES use.
        self::assertStringContainsString('runSupervisedSingleChainPass', $code);
    }

    // ── (f) concurrency ─────────────────────────────────────────────────

    public function testItRefusesWhileAPeerHoldsTheChainLock(): void
    {
        $this->arrange();
        $this->queueOneCleanCodePage();

        // A cron tick (or another operator) already holds
        // bcc_cosmwasm_chain_17.
        \BCC\Core\DB\AdvisoryLock::$acquirable = false;

        $code = $this->invoke($this->executeArgs());

        self::assertSame(CosmwasmOneShotDiscoveryCommand::EXIT_LOCK_CONTENDED, $code);
        self::assertSame([], ApiRetry::$calls, 'a contended run does no work at all');
        self::assertSame([], ChainCheckpointRepository::$rows, 'and writes nothing, not even a checkpoint row');
        self::assertSame([], ChainCheckpointRepository::$discoveryTouches);
        self::assertStringContainsString('"stop_reason": "lock_contended"', \WP_CLI::output());
    }

    /**
     * THE LOCK IS RELEASED ON EVERY OUTCOME THAT TOOK IT.
     *
     * A `bcc_cosmwasm_chain_<id>` that is still held after the process
     * exits is a chain that cron cannot scan again until the MySQL session
     * dies — so "the unhappy paths release it too" is the assertion that
     * matters, not the happy one. The release and the
     * `cw_last_discovery_at` stamp both live in the `finally` of
     * {@see CosmwasmDiscoveryWorker::runChainPass()}, and this drives two
     * different unhappy outcomes through it in one process:
     *
     *   1. THE CHAIN REFUSED TO PREPARE (breaker open) — an early
     *      `return` from INSIDE the try, which is the shape a `finally`
     *      exists to catch and the shape a plain trailing release would
     *      miss.
     *   2. THE PASS THREW — a repository read fails after the lock is
     *      held.
     *
     * Success is covered by {@see testItRunsExactlyOnePassPerInvocation()},
     * budget exhaustion by
     * {@see testABudgetExhaustedPassExitsWithItsOwnCode()}, and the
     * never-acquired case by
     * {@see testItRefusesWhileAPeerHoldsTheChainLock()}.
     */
    public function testTheChainLockIsReleasedWhenTheChainRefusesToPrepareAndWhenThePassThrows(): void
    {
        $this->arrange();

        // ── 1. prepareChain() refuses: an early return inside the try ───
        \BCC\Trust\Onchain\Support\OnchainCircuitBreaker::$open = true;

        $code = $this->invoke($this->executeArgs());

        self::assertSame(CosmwasmOneShotDiscoveryCommand::EXIT_EXECUTION_FAILED, $code);
        self::assertSame(['bcc_cosmwasm_chain_17'], \BCC\Core\DB\AdvisoryLock::$acquired);
        self::assertSame(
            ['bcc_cosmwasm_chain_17'],
            \BCC\Core\DB\AdvisoryLock::$released,
            'an early return from inside the try must still release the lock'
        );
        self::assertSame([self::CHAIN], ChainCheckpointRepository::$discoveryTouches);
        self::assertSame([], ApiRetry::$calls, 'a chain that refused to prepare made no request');
        self::assertStringContainsString('"stop_reason": "chain_refused_to_prepare"', \WP_CLI::output());

        // ── 2. the pass THROWS, with the lock held ──────────────────────
        \WP_CLI::reset();
        ApiRetry::reset();
        ChainCheckpointRepository::reset();
        CosmwasmCodeFamilyRepository::reset();
        \BCC\Core\DB\AdvisoryLock::reset();
        \BCC\Trust\Onchain\Support\OnchainCircuitBreaker::reset();
        AuditLogger::reset();

        // The first statement of the pass body fails its read. That is
        // AFTER acquire() and INSIDE the try.
        CosmwasmCodeFamilyRepository::$failReads = ['requeueForClassifierVersion'];
        $this->queueOneCleanCodePage();

        $code = $this->invoke($this->executeArgs());

        self::assertSame(CosmwasmOneShotDiscoveryCommand::EXIT_EXECUTION_FAILED, $code);
        self::assertSame(['bcc_cosmwasm_chain_17'], \BCC\Core\DB\AdvisoryLock::$acquired);
        self::assertSame(
            ['bcc_cosmwasm_chain_17'],
            \BCC\Core\DB\AdvisoryLock::$released,
            'a throw must not strand the chain lock'
        );
        self::assertSame([self::CHAIN], ChainCheckpointRepository::$discoveryTouches);
        self::assertSame([], ApiRetry::$calls, 'it threw before the first request');

        $output = \WP_CLI::output();
        self::assertStringContainsString('"stop_reason": "execution_failed"', $output);
        self::assertStringContainsString('"exit_code": 6', $output);
        // The throw is durable too: the outcome is in the audit action.
        self::assertSame(
            ['cosmwasm_cli_pass_started', 'cosmwasm_cli_pass_failed'],
            AuditLogger::actions()
        );
    }

    // ── (g) exactly one pass ────────────────────────────────────────────

    public function testItRunsExactlyOnePassPerInvocation(): void
    {
        $this->arrange();
        $this->queueOneCleanCodePage();

        $code = $this->invoke($this->executeArgs());

        self::assertSame(CosmwasmOneShotDiscoveryCommand::EXIT_OK, $code);

        // ONE lock acquire, ONE release, ONE last-run stamp. A second pass
        // inside the same invocation would double every one of them.
        self::assertSame(['bcc_cosmwasm_chain_17'], \BCC\Core\DB\AdvisoryLock::$acquired);
        self::assertSame(['bcc_cosmwasm_chain_17'], \BCC\Core\DB\AdvisoryLock::$released);
        self::assertSame([self::CHAIN], ChainCheckpointRepository::$discoveryTouches);

        // And exactly one code-listing walk.
        $listings = array_values(array_filter(
            $this->urls(),
            static fn(string $url): bool => str_contains($url, '/cosmwasm/wasm/v1/code?')
                || str_ends_with($url, '/cosmwasm/wasm/v1/code')
        ));
        self::assertCount(1, $listings, 'exactly one code-listing page per invocation');
    }

    // ── (h) the run itself ──────────────────────────────────────────────

    public function testAConfirmedRunWalksTheChainAndPrintsAMachineReadableSummary(): void
    {
        $this->arrange();
        $this->queueOneCleanCodePage();

        $code = $this->invoke($this->executeArgs());

        self::assertSame(CosmwasmOneShotDiscoveryCommand::EXIT_OK, $code);
        self::assertNotSame([], ApiRetry::$calls, 'the pass actually walked the chain');

        // The three families the listing carried are now inventoried.
        self::assertNotNull(CosmwasmCodeFamilyRepository::find(self::CHAIN, 3));
        self::assertSame(
            [['chain_id' => self::CHAIN, 'max' => 3]],
            ChainCheckpointRepository::$watermarkAdvances
        );

        // The summary is one parseable JSON object.
        $output = \WP_CLI::output();
        $start  = strpos($output, '{');
        self::assertIsInt($start);
        $json = substr($output, $start, (int) strrpos($output, '}') - $start + 1);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);

        self::assertSame(self::CHAIN, $decoded['chain_id']);
        self::assertSame(self::SLUG, $decoded['chain_slug']);
        self::assertSame('daily_incremental', $decoded['pass']);
        self::assertSame('ran', $decoded['outcome']);
        self::assertSame(0, $decoded['exit_code']);
        self::assertArrayHasKey('elapsed_seconds', $decoded);
        self::assertArrayHasKey('errors', $decoded);
        self::assertArrayHasKey('stop_reason', $decoded);

        self::assertIsArray($decoded['requests']);
        self::assertGreaterThan(0, $decoded['requests']['used']);
        self::assertIsArray($decoded['pages_fetched']);
        self::assertSame(1, $decoded['pages_fetched']['code']);
        self::assertIsArray($decoded['code_families']);
        self::assertSame(3, $decoded['code_families']['inserted']);
        self::assertIsArray($decoded['watermark']);
        self::assertSame(3, $decoded['watermark']['moved']);
    }

    /**
     * ZERO COLLECTIONS IS NOT A FAILURE.
     *
     * The documented expectation for a first Dungeon pass: the inventory
     * lands, classification barely starts, nothing is emitted, and the
     * command still exits 0.
     */
    public function testAPassThatDiscoversNoCollectionsStillSucceeds(): void
    {
        $this->arrange();
        $this->queueOneCleanCodePage();
        // Every family probe refuses decisively — nothing is a CW-721.
        for ($i = 0; $i < 12; $i++) {
            ApiRetry::$queue[] = [
                'code' => 400,
                'body' => (string) json_encode([
                    'code'    => 3,
                    'message' => 'Error parsing into type intra_mint::msg::QueryMsg: unknown variant `num_tokens`',
                ]),
            ];
        }

        $code = $this->invoke($this->executeArgs());

        self::assertSame(CosmwasmOneShotDiscoveryCommand::EXIT_OK, $code);
        self::assertSame([], CollectionRepository::$upserted, 'nothing was emitted');
        self::assertStringContainsString('Zero discovered collections is not a failure', \WP_CLI::output());
    }

    // ── (i) the audit trail ─────────────────────────────────────────────

    public function testAConfirmedRunLeavesAnAuditTrailAndNoSecrets(): void
    {
        $this->arrange();
        $this->queueOneCleanCodePage();

        $this->invoke($this->executeArgs());

        self::assertSame(
            ['cosmwasm_cli_pass_started', 'cosmwasm_cli_pass_ran'],
            AuditLogger::actions(),
            'start and outcome are both durable, because the audit table stores the action and not the meta'
        );
        foreach (AuditLogger::$entries as $entry) {
            self::assertSame(self::CHAIN, $entry['target_id']);
            self::assertSame('chain', $entry['target_type']);
            $serialised = (string) json_encode($entry);
            self::assertStringNotContainsString(self::TOKEN, $serialised, 'the confirmation token is not an audit field');
            self::assertStringNotContainsString('password', strtolower($serialised));
        }
    }

    public function testADryRunWritesNoAuditRow(): void
    {
        $this->arrange();

        $this->invoke(['chain' => (string) self::CHAIN, 'once' => true]);

        self::assertSame([], AuditLogger::$entries, 'a dry run writes NOTHING, including audit rows');
    }

    // ── (j) the preflight ───────────────────────────────────────────────

    public function testThePreflightIsPrintedBeforeAnyNetworkRequest(): void
    {
        $this->arrange();
        $this->queueOneCleanCodePage();

        $this->invoke(['chain' => (string) self::CHAIN, 'once' => true]);

        $output = \WP_CLI::output();
        foreach ([
            'PREFLIGHT',
            'environment',
            'site url',
            'database',
            'fingerprint',
            'slug=dungeon',
            'operator opt-in   : yes',
            'allowlist         : 17 (chain present)',
            'BCC_COSMWASM_DISCOVERY_ENABLED=undefined',
            'BCC_COSMWASM_BACKFILL_ENABLED=undefined',
            'checkpoint        : state=',
            // The budget wording is pinned in detail by
            // CosmwasmCliPreflightAccuracyTest; these two are here so that
            // "the preflight was printed at all" keeps covering them.
            'request budget    : 50 LOGICAL requests',
            'runtime deadline  : 20s — COOPERATIVE',
            'dailyChainStep',
            'emitCollections',
            'NOT invoked       : backfill, weekly retry, metadata refresh',
        ] as $needle) {
            self::assertStringContainsString($needle, $output, sprintf('preflight must state: %s', $needle));
        }

        // Printed on the DRY path, so it cannot have followed a request.
        self::assertSame([], ApiRetry::$calls);
    }

    // ── (k) parity with the scheduled worker ────────────────────────────

    /**
     * THE POINT OF THE WHOLE EXERCISE.
     *
     * An operator watches this command in order to learn what cron will
     * do. If the two diverge, the observation is worthless — so the same
     * fixtures through the supervised path and through
     * {@see CosmwasmDiscoveryWorker::runDailyDiscovery()} must produce the
     * same request sequence and the same rows.
     *
     * The comparison is a SEQUENCE, not a count: two paths that make the
     * same NUMBER of requests in a different ORDER are not the same pass.
     *
     * ── WHY THE GATE IS DEFINED HALFWAY THROUGH ─────────────────────────
     * Each half runs under the gate setting its own caller REQUIRES, and
     * they are opposites:
     *
     *   supervised → `BCC_COSMWASM_DISCOVERY_ENABLED` must be OFF (with it
     *                on, cron is live and the command refuses — see
     *                {@see testItRefusesWhileTheScheduledDiscoveryGateIsArmed()});
     *   scheduled  → it must be ON, or `runDailyDiscovery()` returns
     *                immediately and compares nothing.
     *
     * A constant cannot be undefined, so the supervised half runs FIRST
     * and the constant is defined between the two. That is not a
     * workaround — it is the real-world comparison: this is exactly the
     * pair of environments the two callers run in, and the pass still has
     * to be identical across them.
     */
    public function testTheSupervisedPassAndTheScheduledPassBehaveIdentically(): void
    {
        define('WP_CLI', true);
        define('BCC_COSMWASM_CHAIN_ALLOWLIST', (string) self::CHAIN);

        $fixtures = function (): void {
            $this->queueJson([
                'code_infos' => [
                    ['code_id' => '3', 'data_hash' => 'AA11'],
                    ['code_id' => '2', 'data_hash' => 'BB22'],
                    ['code_id' => '1', 'data_hash' => 'CC33'],
                ],
                'pagination' => ['next_key' => null],
            ]);
            // Code 3 is a CW-721: num_tokens answers, contract_info answers.
            $this->queueJson(['data' => ['count' => 55]]);
            $this->queueJson(['data' => ['name' => 'AshFall', 'symbol' => 'ASH']]);
        };

        // ── the supervised path, with the scheduled gate OFF ────────────
        self::assertFalse(defined('BCC_COSMWASM_DISCOVERY_ENABLED'), 'the supervised half runs with cron inert');
        ChainRepository::seed(self::CHAIN, self::SLUG, self::REST, 'cosmos', 1);
        $fixtures();
        self::assertSame(
            CosmwasmOneShotDiscoveryCommand::EXIT_OK,
            $this->invoke($this->executeArgs())
        );

        $cliUrls       = $this->urls();
        $cliFamilies   = json_encode(CosmwasmCodeFamilyRepository::$families);
        $cliContracts  = json_encode(CosmwasmContractRepository::$contracts);
        $cliCheckpoint = json_encode(ChainCheckpointRepository::$rows);
        $cliUpserts    = json_encode(CollectionRepository::$upserted);

        // ── the scheduled path, from the same starting state ────────────
        ApiRetry::reset();
        CosmwasmCodeFamilyRepository::reset();
        CosmwasmContractRepository::reset();
        CollectionRepository::reset();
        ChainCheckpointRepository::reset();
        ChainRepository::reset();
        \BCC\Core\DB\AdvisoryLock::reset();
        \BCC\Trust\Onchain\Support\OnchainCircuitBreaker::reset();

        // NOW arm the gate — the scheduled pass needs it, and the
        // supervised half above has already run without it.
        define('BCC_COSMWASM_DISCOVERY_ENABLED', true);

        ChainRepository::seed(self::CHAIN, self::SLUG, self::REST, 'cosmos', 1);
        $fixtures();
        CosmwasmDiscoveryWorker::runDailyDiscovery();

        self::assertSame($cliUrls, $this->urls(), 'the request SEQUENCE must be identical');
        self::assertNotSame([], $cliUrls, 'precondition: the fixtures actually drove requests');
        self::assertSame($cliFamilies, json_encode(CosmwasmCodeFamilyRepository::$families));
        self::assertSame($cliContracts, json_encode(CosmwasmContractRepository::$contracts));
        self::assertSame($cliCheckpoint, json_encode(ChainCheckpointRepository::$rows));
        self::assertSame($cliUpserts, json_encode(CollectionRepository::$upserted));
        self::assertSame(['bcc_cosmwasm_chain_17'], \BCC\Core\DB\AdvisoryLock::$acquired);
    }

    /**
     * And the two share the code, rather than merely agreeing today.
     *
     * Agreement that is maintained by hand is agreement that drifts. Both
     * entry points must route through the SAME per-chain envelope and the
     * SAME step body.
     */
    public function testBothPathsRouteThroughTheSameSharedMethods(): void
    {
        $reflection = new \ReflectionClass(CosmwasmDiscoveryWorker::class);
        $source     = (string) file_get_contents((string) $reflection->getFileName());
        $lines      = explode("\n", $source);

        $bodyOf = static function (string $method) use ($reflection, $lines): string {
            $m = $reflection->getMethod($method);

            return implode("\n", array_slice(
                $lines,
                $m->getStartLine() - 1,
                $m->getEndLine() - $m->getStartLine() + 1
            ));
        };

        // The scheduled loop and the supervised one-shot both call the
        // shared envelope, and neither owns a lock/finally of its own.
        self::assertStringContainsString('self::runChainPass(', $bodyOf('forEachChain'));
        self::assertStringContainsString('self::runChainPass(', $bodyOf('runSupervisedSingleChainPass'));

        // The step body is the daily one in both cases.
        self::assertStringContainsString('self::dailyChainStep(', $bodyOf('runDailyDiscovery'));
        self::assertStringContainsString('self::dailyChainStep(', $bodyOf('runSupervisedSingleChainPass'));

        // Neither caller owns a lock, a try/finally or a stamp of its own —
        // all three live in runChainPass(), which is what makes "the loop
        // and the operator run cannot diverge on them" a structural fact
        // rather than a promise.
        //
        // NOTE the deliberate asymmetry with runBackfillForChain(): that is
        // a DIFFERENT pass on a different (manual, operator-button) path
        // and it keeps its own lock. This assertion is about the two
        // callers of the INCREMENTAL envelope, not about the file having
        // exactly one AdvisoryLock call.
        foreach (['forEachChain', 'runSupervisedSingleChainPass', 'runDailyDiscovery'] as $caller) {
            $body = $bodyOf($caller);
            self::assertStringNotContainsString('AdvisoryLock::acquire', $body, $caller . ' must not take its own lock');
            self::assertStringNotContainsString('AdvisoryLock::release', $body, $caller . ' must not release a lock');
            self::assertStringNotContainsString('touchCwDiscovery', $body, $caller . ' must not stamp its own last-run time');
        }

        $envelope = $bodyOf('runChainPass');
        self::assertStringContainsString('AdvisoryLock::acquire($lockKey, 0)', $envelope);
        self::assertStringContainsString('ChainCheckpointRepository::touchCwDiscovery($chainId);', $envelope);
        self::assertStringContainsString('AdvisoryLock::release($lockKey);', $envelope);
        self::assertStringContainsString('} finally {', $envelope);

        // And emission is reachable from the supervised pass, because it
        // is inseparable from the canonical one.
        self::assertStringContainsString(
            'CosmwasmDiscoveryService::emitCollections(',
            $bodyOf('classifyAndEnumerate')
        );
        self::assertStringContainsString('self::classifyAndEnumerate(', $bodyOf('dailyChainStep'));
    }

    // ── (l) the exit-code contract ──────────────────────────────────────

    public function testTheExitCodesAreDistinctAndDocumented(): void
    {
        $codes = [
            CosmwasmOneShotDiscoveryCommand::EXIT_OK,
            CosmwasmOneShotDiscoveryCommand::EXIT_INVALID_ARGS,
            CosmwasmOneShotDiscoveryCommand::EXIT_NOT_ELIGIBLE,
            CosmwasmOneShotDiscoveryCommand::EXIT_LOCK_CONTENDED,
            CosmwasmOneShotDiscoveryCommand::EXIT_BUDGET_EXHAUSTED,
            CosmwasmOneShotDiscoveryCommand::EXIT_EXECUTION_FAILED,
        ];
        self::assertSame($codes, array_values(array_unique($codes)), 'every exit code is distinct');
        self::assertNotContains(1, $codes, '1 stays reserved for WP-CLI\'s own generic failure');
        self::assertSame([0, 2, 3, 4, 5, 6], $codes);

        // The contract is written down where an operator will find it.
        $file = $this->pluginRoot() . '/app/Domain/Onchain/CLI/CosmwasmOneShotDiscoveryCommand.php';
        $doc  = (string) file_get_contents($file);
        self::assertStringContainsString('EXIT CODES', $doc);
        foreach (['LOCK CONTENDED', 'BUDGET EXHAUSTED', 'EXECUTION FAILED', 'ELIGIBILITY REFUSED', 'INVALID INVOCATION'] as $documented) {
            self::assertStringContainsString($documented, $doc);
        }
    }

    /** A pass cut short by its request budget says so, and says it non-zero. */
    public function testABudgetExhaustedPassExitsWithItsOwnCode(): void
    {
        $this->arrange();
        define('BCC_COSMWASM_REQUEST_BUDGET', 1);

        // One page, more after it — so the walk spends its single request
        // and stops without reaching the watermark.
        $this->queueJson([
            'code_infos' => [['code_id' => '900', 'data_hash' => 'AA']],
            'pagination' => ['next_key' => 'MORE=='],
        ]);
        ChainCheckpointRepository::ensureExists(self::CHAIN);
        ChainCheckpointRepository::$rows[self::CHAIN]->cw_max_code_id = '100';

        $code = $this->invoke($this->executeArgs());

        self::assertSame(CosmwasmOneShotDiscoveryCommand::EXIT_BUDGET_EXHAUSTED, $code);
        self::assertStringContainsString('"stop_reason": "request_budget_exhausted"', \WP_CLI::output());
        // Progress is still durable — a cut-short pass resumes, it does not restart.
        self::assertSame([self::CHAIN], ChainCheckpointRepository::$discoveryTouches);
        // And a pass that ran out of budget still gives the lock back —
        // otherwise the next cron tick finds the chain held.
        self::assertSame(['bcc_cosmwasm_chain_17'], \BCC\Core\DB\AdvisoryLock::$acquired);
        self::assertSame(['bcc_cosmwasm_chain_17'], \BCC\Core\DB\AdvisoryLock::$released);
    }

    /**
     * A CUT-SHORT PASS REPORTS WHAT IT ACTUALLY WROTE.
     *
     * Exit 5 is "there is more to do", not "nothing happened" — rows DID
     * land before the budget ran out, and they are durable. The failure
     * mode being guarded against is a summary that rounds an interrupted
     * pass down to zero, or (worse) advances the watermark as though the
     * walk had been authoritative: an operator who believes either one
     * re-runs against a chain whose state is not what they were told.
     *
     * So the summary must say, on the same run: these families were
     * inserted, the watermark did NOT move, and here is the reason the
     * pass could not settle. Each number is checked against the
     * repository state rather than against itself.
     */
    public function testPartialWritesBeforeBudgetExhaustionAreReportedHonestly(): void
    {
        $this->arrange();
        define('BCC_COSMWASM_REQUEST_BUDGET', 1);

        // One page of three NEW code ids, and more behind it — so the
        // single request inserts three rows and still cannot reach the
        // watermark at 100.
        $this->queueJson([
            'code_infos' => [
                ['code_id' => '900', 'data_hash' => 'AA'],
                ['code_id' => '899', 'data_hash' => 'BB'],
                ['code_id' => '898', 'data_hash' => 'CC'],
            ],
            'pagination' => ['next_key' => 'MORE=='],
        ]);
        ChainCheckpointRepository::ensureExists(self::CHAIN);
        ChainCheckpointRepository::$rows[self::CHAIN]->cw_max_code_id = '100';

        $code = $this->invoke($this->executeArgs());

        self::assertSame(CosmwasmOneShotDiscoveryCommand::EXIT_BUDGET_EXHAUSTED, $code);

        // The writes are REAL: three families are in the inventory.
        foreach ([900, 899, 898] as $codeId) {
            self::assertNotNull(
                CosmwasmCodeFamilyRepository::find(self::CHAIN, $codeId),
                sprintf('code family %d was written before the budget ran out', $codeId)
            );
        }

        $output = \WP_CLI::output();
        $start  = strpos($output, '{');
        self::assertIsInt($start);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(substr($output, $start, (int) strrpos($output, '}') - $start + 1), true);
        self::assertIsArray($decoded);

        // …and the summary says so, in the same numbers.
        self::assertSame('request_budget_exhausted', $decoded['stop_reason']);
        self::assertSame(5, $decoded['exit_code']);
        self::assertSame('ran', $decoded['outcome'], 'the pass RAN — it was cut short, it did not fail');
        self::assertIsArray($decoded['code_families']);
        self::assertSame(3, $decoded['code_families']['inserted'], 'the partial write is reported, not rounded to zero');
        self::assertSame(3, $decoded['code_families']['after']);

        // The watermark did NOT move, because the walk never reached it —
        // and the summary must not claim otherwise.
        self::assertIsArray($decoded['watermark']);
        self::assertSame(100, $decoded['watermark']['before']);
        self::assertSame(100, $decoded['watermark']['after']);
        self::assertSame(0, $decoded['watermark']['moved']);
        self::assertSame([], ChainCheckpointRepository::$watermarkAdvances);

        // And the reason is stated rather than left to be inferred.
        self::assertIsArray($decoded['errors']);
        self::assertNotSame([], $decoded['errors'], 'an interrupted walk must say why it could not settle');
        self::assertStringContainsString('watermark', strtolower((string) $decoded['errors'][0]));
        self::assertIsArray($decoded['requests']);
        self::assertSame(1, $decoded['requests']['used']);
        self::assertSame(0, $decoded['requests']['remaining']);
        self::assertIsArray($decoded['collections']);
        self::assertSame(0, $decoded['collections']['emitted']);
    }

    /** The docblock has to carry the expectation an operator will misread. */
    public function testTheDocblockDocumentsTheZeroCollectionExpectation(): void
    {
        $file = $this->pluginRoot() . '/app/Domain/Onchain/CLI/CosmwasmOneShotDiscoveryCommand.php';
        $doc  = (string) file_get_contents($file);

        self::assertStringContainsString('179 code families', $doc);
        self::assertStringContainsString('ZERO EMITTED COLLECTIONS IS EXPECTED', $doc);

        // The two claims this test USED to pin were wrong and were
        // corrected: the canary does not run under a 25-request budget
        // (the canonical 50 was adopted) and it does not reach "about FIVE
        // families" (5-25, depending on per-family cost). Pinning the
        // retracted wording here is what would let it come back.
        self::assertStringNotContainsString('25-request budget this canary is planned', $doc);
        self::assertStringContainsString('~5-25 FAMILIES', $doc);
    }
}
