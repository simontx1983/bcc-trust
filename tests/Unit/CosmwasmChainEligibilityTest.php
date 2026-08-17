<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmContractRepository;
use BCC\Trust\Onchain\Repositories\NftSpamContractRepository;
use BCC\Trust\Onchain\Support\ApiRetry;
use BCC\Trust\Onchain\Support\CosmwasmDiscoveryGate;
use BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Per-chain CosmWasm NFT-discovery eligibility.
 *
 * WHAT IS BEING PINNED. The scanner used to walk every ACTIVE COSMOS
 * chain — the type of the chain was the whole permission model. It now
 * walks the intersection of five conditions, and
 * {@see CosmwasmDiscoveryWorker::eligibleChainIds()} is the single place
 * that intersection is computed:
 *
 *     is_active = 1
 *     AND chain_type = 'cosmos'
 *     AND cosmwasm_nft_discovery_enabled = 1      (operator intent)
 *     AND checkpoint.cw_discovery_state != 'unsupported'  (measured)
 *     AND (allowlist undefined OR id IN allowlist)        (canary scope)
 *
 * THE FAILURE MODE THESE TESTS EXIST FOR is not "a disabled chain gets
 * scanned once". It is a guard whose NOT-CONFIGURED branch answers the
 * permissive way — the shape this codebase shipped as
 * `if (!defined(...)) return true;`. So every unsure branch below is
 * asserted to return FEWER chains: an absent column, a malformed
 * allowlist, a failed checkpoint read. A test that only proved the happy
 * path would pass against the broken version too.
 *
 * Isolation: the CosmwasmDiscoveryTest pattern. Repositories, the chain
 * registry, the fetcher factory and the transport are faked at their
 * PRODUCTION FQNs inside a subprocess. CI never touches a public RPC.
 */
#[CoversClass(CosmwasmDiscoveryWorker::class)]
#[CoversClass(CosmwasmDiscoveryGate::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CosmwasmChainEligibilityTest extends TestCase
{
    private const CHAIN_A = 8;
    private const CHAIN_B = 9;

    private const REST_A = 'https://a.example';
    private const REST_B = 'https://b.example';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/cosmwasm-discovery-stubs.php';

        ApiRetry::reset();
        CosmwasmCodeFamilyRepository::reset();
        CosmwasmContractRepository::reset();
        CollectionRepository::reset();
        ChainCheckpointRepository::reset();
        ChainRepository::reset();
        NftSpamContractRepository::reset();
        \BCC\Trust\Onchain\Support\OnchainCircuitBreaker::reset();
        \BCC\Core\Log\Logger::reset();
        \BccTestObjectCache::reset();
        \BccTestOptionStore::reset();
        \BccTestCronStore::reset();
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** Turn both environment gates on so only per-chain policy is in play. */
    private function enableEnvironment(): void
    {
        define('BCC_COSMWASM_DISCOVERY_ENABLED', true);
        define('BCC_COSMWASM_BACKFILL_ENABLED', true);
    }

    /** Drive every one of the four passes once. */
    private function runAllFourPasses(): void
    {
        CosmwasmDiscoveryWorker::runBackfillTick();
        CosmwasmDiscoveryWorker::runDailyDiscovery();
        CosmwasmDiscoveryWorker::runWeeklyRetry();
        CosmwasmDiscoveryWorker::runMetadataRefresh();
    }

    /**
     * Which chains the worker actually reached, by id.
     *
     * A chain is "reached" the moment a pass calls ensureExists() on it —
     * which every pass does before doing anything else with it, and which
     * an INELIGIBLE chain must never trigger. Using the checkpoint rows
     * rather than the transport log matters: a chain can be reached and
     * then decline to spend a request (paused, breaker open, budget gone),
     * and that is still a chain the eligibility filter let through.
     *
     * @return list<int>
     */
    private function reachedChainIds(): array
    {
        $ids = array_map('intval', array_keys(ChainCheckpointRepository::$rows));
        sort($ids);

        return array_values($ids);
    }

    /** @return list<string> distinct hosts the transport was pointed at. */
    private function contactedHosts(): array
    {
        $hosts = [];
        foreach (ApiRetry::$calls as $call) {
            $host = parse_url($call['url'], PHP_URL_HOST);
            if (is_string($host)) {
                $hosts[$host] = $host;
            }
        }
        sort($hosts);

        return array_values($hosts);
    }

    // ── (a) the control: eligibility is not vacuous ─────────────────────

    public function testAnOptedInChainIsScanned(): void
    {
        $this->enableEnvironment();
        ChainRepository::seed(self::CHAIN_A, 'cosmos', self::REST_A, 'cosmos', 1);

        $this->runAllFourPasses();

        self::assertSame([self::CHAIN_A], $this->reachedChainIds());
        self::assertSame(['a.example'], $this->contactedHosts());
    }

    // ── (b) operator intent ─────────────────────────────────────────────

    public function testADisabledChainIsNeverScannedByAnyOfTheFourPasses(): void
    {
        $this->enableEnvironment();
        ChainRepository::seed(self::CHAIN_A, 'cosmos', self::REST_A, 'cosmos', 0);

        CosmwasmDiscoveryWorker::runBackfillTick();
        self::assertSame([], $this->reachedChainIds(), 'backfill tick');
        self::assertSame([], ApiRetry::$calls, 'backfill tick spent a request');

        CosmwasmDiscoveryWorker::runDailyDiscovery();
        self::assertSame([], $this->reachedChainIds(), 'daily discovery');
        self::assertSame([], ApiRetry::$calls, 'daily discovery spent a request');

        CosmwasmDiscoveryWorker::runWeeklyRetry();
        self::assertSame([], $this->reachedChainIds(), 'weekly retry');
        self::assertSame([], ApiRetry::$calls, 'weekly retry spent a request');

        CosmwasmDiscoveryWorker::runMetadataRefresh();
        self::assertSame([], $this->reachedChainIds(), 'metadata refresh');
        self::assertSame([], ApiRetry::$calls, 'metadata refresh spent a request');

        // And nothing was stamped either — a disabled chain does not even
        // enter the least-recently-worked rotation.
        self::assertSame([], ChainCheckpointRepository::$discoveryTouches);
    }

    public function testTheDisabledChainIsSkippedWhileItsEnabledPeerRuns(): void
    {
        $this->enableEnvironment();
        ChainRepository::seed(self::CHAIN_A, 'cosmos', self::REST_A, 'cosmos', 1);
        ChainRepository::seed(self::CHAIN_B, 'osmosis', self::REST_B, 'cosmos', 0);

        $this->runAllFourPasses();

        self::assertSame([self::CHAIN_A], $this->reachedChainIds());
        self::assertSame(['a.example'], $this->contactedHosts(), 'b.example must never be contacted');
    }

    public function testANonCosmosChainStaysExcludedEvenWhenTheFlagIsOn(): void
    {
        $this->enableEnvironment();
        // chain_type is not something an operator can override with a flag:
        // an EVM chain has no wasmd endpoints to walk at all.
        ChainRepository::seed(self::CHAIN_A, 'ethereum', self::REST_A, 'evm', 1);

        $this->runAllFourPasses();

        self::assertSame([], $this->reachedChainIds());
        self::assertSame([], ApiRetry::$calls);
    }

    // ── (c) the migration has not run yet ───────────────────────────────

    public function testARowWithoutTheColumnIsIneligibleRatherThanUnfiltered(): void
    {
        $this->enableEnvironment();
        // The projection an install serves BEFORE the ALTER lands (or from
        // a transient written before it). The column is ABSENT, not 0.
        ChainRepository::seedWithoutDiscoveryColumn(self::CHAIN_A, 'cosmos', self::REST_A);

        $this->runAllFourPasses();

        // "The field is missing, so skip that filter" is the fail-OPEN
        // reading and would scan here.
        self::assertSame([], $this->reachedChainIds());
        self::assertSame([], ApiRetry::$calls);
    }

    public function testAMissingColumnDoesNotRaiseAWarningOnTheWayToSayingNo(): void
    {
        $this->enableEnvironment();
        ChainRepository::seedWithoutDiscoveryColumn(self::CHAIN_A, 'cosmos', self::REST_A);

        // Reading an undefined property emits a PHP warning; the suite runs
        // with failOnWarning="true", so a naive `$chain->flag ?? 0` read
        // would fail here rather than silently working.
        set_error_handler(static function (int $errno, string $message): bool {
            throw new \RuntimeException("PHP diagnostic during eligibility: {$message}");
        });
        try {
            CosmwasmDiscoveryWorker::runDailyDiscovery();
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $this->reachedChainIds());
    }

    // ── (d) measured capability ─────────────────────────────────────────

    public function testAChainMeasuredUnsupportedIsExcludedEvenWhenEnabled(): void
    {
        $this->enableEnvironment();
        ChainRepository::seed(self::CHAIN_A, 'cosmos', self::REST_A, 'cosmos', 1);
        ChainRepository::seed(self::CHAIN_B, 'osmosis', self::REST_B, 'cosmos', 1);

        // Chain B already answered the code listing with 501. Operator
        // intent cannot conjure a wasm module that is not there.
        ChainCheckpointRepository::ensureExists(self::CHAIN_B);
        ChainCheckpointRepository::$rows[self::CHAIN_B]->cw_discovery_state
            = ChainCheckpointRepository::CW_STATE_UNSUPPORTED;

        $this->runAllFourPasses();

        self::assertSame(['a.example'], $this->contactedHosts());
        self::assertNotContains(self::CHAIN_B, ChainCheckpointRepository::$discoveryTouches);
    }

    /**
     * OPERATOR PAUSE, AT THE SELECTOR.
     *
     * It used to be caught one layer down, in `prepareChain()`, so a
     * paused chain was resolved as eligible, locked, `ensureExists()`-ed
     * and STAMPED with a last-run time before anything noticed — and the
     * admin panel, reading the same eligibility rule, called it eligible
     * too. The selector now shares its verdict with the panel, and pause
     * is part of that verdict, so neither of them considers the chain at
     * all.
     *
     * The stamp is the observable half: `cw_last_discovery_at` moving on a
     * chain nothing ran for is how "the scanner touched it" and "the
     * scanner worked it" became indistinguishable.
     */
    public function testAPausedChainIsExcludedBeforeItIsEvenReached(): void
    {
        $this->enableEnvironment();
        ChainRepository::seed(self::CHAIN_A, 'cosmos', self::REST_A, 'cosmos', 1);
        ChainRepository::seed(self::CHAIN_B, 'osmosis', self::REST_B, 'cosmos', 1);

        ChainCheckpointRepository::ensureExists(self::CHAIN_B);
        ChainCheckpointRepository::$rows[self::CHAIN_B]->cw_discovery_state
            = ChainCheckpointRepository::CW_STATE_PAUSED;

        $this->runAllFourPasses();

        self::assertSame(['a.example'], $this->contactedHosts(), 'b.example is paused and must not be contacted');
        self::assertNotContains(
            self::CHAIN_B,
            ChainCheckpointRepository::$discoveryTouches,
            'a paused chain must not even be stamped — it is not in the rotation'
        );
    }

    /**
     * And the manual path still refuses it, which is why `prepareChain()`
     * keeps its own pause check: `runBackfillForChain()` is public, the
     * "Run backfill slice" button calls it with one chain id, and that
     * path never passes through the selector.
     */
    public function testThePauseIsStillEnforcedOnTheManualRunPath(): void
    {
        $this->enableEnvironment();
        ChainRepository::seed(self::CHAIN_A, 'cosmos', self::REST_A, 'cosmos', 1);

        ChainCheckpointRepository::ensureExists(self::CHAIN_A);
        ChainCheckpointRepository::$rows[self::CHAIN_A]->cw_discovery_state
            = ChainCheckpointRepository::CW_STATE_PAUSED;

        CosmwasmDiscoveryWorker::runBackfillForChain(self::CHAIN_A);

        self::assertSame([], ApiRetry::$calls);
    }

    public function testAChainWithNoCheckpointRowYetIsAllowedThrough(): void
    {
        $this->enableEnvironment();
        ChainRepository::seed(self::CHAIN_A, 'cosmos', self::REST_A, 'cosmos', 1);

        self::assertSame([], ChainCheckpointRepository::$rows, 'precondition: nothing measured yet');

        CosmwasmDiscoveryWorker::runDailyDiscovery();

        // Refusing an unmeasured chain would be a deadlock dressed up as
        // caution: the first pass is what CREATES the measurement, so a
        // chain that is never passed can never become supportable.
        self::assertSame([self::CHAIN_A], $this->reachedChainIds());
    }

    public function testAFailedCheckpointReadYieldsNoChains(): void
    {
        $this->enableEnvironment();
        ChainRepository::seed(self::CHAIN_A, 'cosmos', self::REST_A, 'cosmos', 1);

        // The checkpoint table is where "unsupported" lives. A read that did
        // not run is not evidence that a chain IS supported.
        ChainCheckpointRepository::$failGetAll = true;

        $this->runAllFourPasses();

        self::assertSame([], ApiRetry::$calls);
        self::assertSame([], ChainCheckpointRepository::$discoveryTouches);
    }

    // ── (e) the allowlist can only narrow ───────────────────────────────

    public function testAnUndefinedAllowlistImposesNoRestriction(): void
    {
        $this->enableEnvironment();
        self::assertFalse(defined('BCC_COSMWASM_CHAIN_ALLOWLIST'));
        self::assertNull(CosmwasmDiscoveryGate::chainAllowlist(), 'undefined is null, NOT []');

        ChainRepository::seed(self::CHAIN_A, 'cosmos', self::REST_A, 'cosmos', 1);
        ChainRepository::seed(self::CHAIN_B, 'osmosis', self::REST_B, 'cosmos', 1);

        CosmwasmDiscoveryWorker::runDailyDiscovery();

        self::assertSame([self::CHAIN_A, self::CHAIN_B], $this->reachedChainIds());
    }

    public function testTheAllowlistNarrowsToTheChainsItNames(): void
    {
        $this->enableEnvironment();
        define('BCC_COSMWASM_CHAIN_ALLOWLIST', (string) self::CHAIN_A);

        ChainRepository::seed(self::CHAIN_A, 'cosmos', self::REST_A, 'cosmos', 1);
        ChainRepository::seed(self::CHAIN_B, 'osmosis', self::REST_B, 'cosmos', 1);

        $this->runAllFourPasses();

        self::assertSame([self::CHAIN_A], $this->reachedChainIds());
        self::assertSame(['a.example'], $this->contactedHosts());
    }

    public function testAnAllowlistedButDisabledChainIsStillNotScanned(): void
    {
        $this->enableEnvironment();
        define('BCC_COSMWASM_CHAIN_ALLOWLIST', self::CHAIN_A . ',' . self::CHAIN_B);

        ChainRepository::seed(self::CHAIN_A, 'cosmos', self::REST_A, 'cosmos', 1);
        ChainRepository::seed(self::CHAIN_B, 'osmosis', self::REST_B, 'cosmos', 0);

        $this->runAllFourPasses();

        // Naming a chain in the canary scope is not a way to grant it
        // permission — the allowlist is an AND, never an OR.
        self::assertSame([self::CHAIN_A], $this->reachedChainIds());
    }

    public function testAnAllowlistedButUnsupportedChainIsStillNotScanned(): void
    {
        $this->enableEnvironment();
        define('BCC_COSMWASM_CHAIN_ALLOWLIST', (string) self::CHAIN_B);

        ChainRepository::seed(self::CHAIN_B, 'osmosis', self::REST_B, 'cosmos', 1);
        ChainCheckpointRepository::ensureExists(self::CHAIN_B);
        ChainCheckpointRepository::$rows[self::CHAIN_B]->cw_discovery_state
            = ChainCheckpointRepository::CW_STATE_UNSUPPORTED;

        $this->runAllFourPasses();

        self::assertSame([], ApiRetry::$calls);
        self::assertSame([], ChainCheckpointRepository::$discoveryTouches);
    }

    public function testAnAllowlistNamingAnUnknownChainScansNothing(): void
    {
        $this->enableEnvironment();
        define('BCC_COSMWASM_CHAIN_ALLOWLIST', '4242');

        ChainRepository::seed(self::CHAIN_A, 'cosmos', self::REST_A, 'cosmos', 1);

        $this->runAllFourPasses();

        self::assertSame([], $this->reachedChainIds());
    }

    public function testAnEmptyAllowlistScansNothingRatherThanEverything(): void
    {
        $this->enableEnvironment();
        define('BCC_COSMWASM_CHAIN_ALLOWLIST', '');

        self::assertSame([], CosmwasmDiscoveryGate::chainAllowlist(), 'defined-but-unusable is [], NOT null');

        ChainRepository::seed(self::CHAIN_A, 'cosmos', self::REST_A, 'cosmos', 1);
        ChainRepository::seed(self::CHAIN_B, 'osmosis', self::REST_B, 'cosmos', 1);

        $this->runAllFourPasses();

        self::assertSame([], $this->reachedChainIds());
        self::assertSame([], ApiRetry::$calls);
    }

    public function testAMalformedAllowlistScansNothingRatherThanEverything(): void
    {
        $this->enableEnvironment();
        define('BCC_COSMWASM_CHAIN_ALLOWLIST', 'cosmos, osmosis');

        ChainRepository::seed(self::CHAIN_A, 'cosmos', self::REST_A, 'cosmos', 1);
        ChainRepository::seed(self::CHAIN_B, 'osmosis', self::REST_B, 'cosmos', 1);

        $this->runAllFourPasses();

        // Slugs are the obvious operator mistake here, and the tempting
        // implementation ("nothing parsed, so no restriction") would scan
        // BOTH chains — a config typo silently widening the blast radius.
        self::assertSame([], $this->reachedChainIds());
    }

    public function testAnAllZerosAllowlistScansNothing(): void
    {
        $this->enableEnvironment();
        define('BCC_COSMWASM_CHAIN_ALLOWLIST', '0,0');

        ChainRepository::seed(self::CHAIN_A, 'cosmos', self::REST_A, 'cosmos', 1);

        $this->runAllFourPasses();

        self::assertSame([], $this->reachedChainIds());
    }

    public function testANonScalarAllowlistScansNothing(): void
    {
        $this->enableEnvironment();
        // PHP lets a constant be an array. That is "defined but unusable",
        // which is the narrow-to-nothing case, not the no-restriction case.
        define('BCC_COSMWASM_CHAIN_ALLOWLIST', [self::CHAIN_A]);

        self::assertSame([], CosmwasmDiscoveryGate::chainAllowlist());

        ChainRepository::seed(self::CHAIN_A, 'cosmos', self::REST_A, 'cosmos', 1);

        $this->runAllFourPasses();

        self::assertSame([], $this->reachedChainIds());
    }

    public function testPartlyGarbageAllowlistKeepsOnlyWhatParsed(): void
    {
        $this->enableEnvironment();
        define('BCC_COSMWASM_CHAIN_ALLOWLIST', self::CHAIN_A . ',osmosis,-3,9.5, ');

        self::assertSame([self::CHAIN_A], CosmwasmDiscoveryGate::chainAllowlist());

        ChainRepository::seed(self::CHAIN_A, 'cosmos', self::REST_A, 'cosmos', 1);
        ChainRepository::seed(self::CHAIN_B, 'osmosis', self::REST_B, 'cosmos', 1);

        $this->runAllFourPasses();

        // Dropping an unparseable entry can only make the set smaller, so
        // it can never become a widening bug. `9.5` must NOT become 9.
        self::assertSame([self::CHAIN_A], $this->reachedChainIds());
    }

    /** Whitespace and duplicates are operator ergonomics, not semantics. */
    public function testTheAllowlistToleratesSpacingAndDuplicates(): void
    {
        define('BCC_COSMWASM_CHAIN_ALLOWLIST', ' 9 , 8 ,8 ');

        self::assertSame([self::CHAIN_A, self::CHAIN_B], CosmwasmDiscoveryGate::chainAllowlist());
    }

    // ── (f) one chokepoint, structurally ────────────────────────────────

    /**
     * Executable statements only.
     *
     * Token-walked rather than regex-stripped for the same reason
     * subsystem-count-guard.php walks tokens: this file's docblocks NAME
     * the very calls being counted, so a naive substring count over the
     * raw source counts the prose as a call site.
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

    public function testEveryPassResolvesItsChainsThroughTheOneSelector(): void
    {
        $reflection = new \ReflectionClass(CosmwasmDiscoveryWorker::class);
        $file       = (string) $reflection->getFileName();
        $source     = (string) file_get_contents($file);
        $code       = $this->codeWithoutComments($source);

        // The chain registry is enumerated in exactly ONE place. A second
        // `getActive()` in this file is a second policy, and the two would
        // drift the first time one of them gained a condition.
        self::assertSame(
            1,
            substr_count($code, 'ChainRepository::getActive('),
            'the worker must enumerate chains in exactly one place'
        );

        $selector = $reflection->getMethod('eligibleChainIds');
        $lines    = explode("\n", $source);
        $body     = implode("\n", array_slice(
            $lines,
            $selector->getStartLine() - 1,
            $selector->getEndLine() - $selector->getStartLine() + 1
        ));
        self::assertStringContainsString('ChainRepository::getActive(', $body);

        // And the old name is gone, so nobody can call a "give me the
        // cosmos chains" helper that quietly bypasses the policy.
        self::assertFalse($reflection->hasMethod('cosmosChainIds'));
        self::assertStringNotContainsString('cosmosChainIds', $code);
    }

    public function testTheSelectorIsReachedFromAllFourPasses(): void
    {
        $reflection = new \ReflectionClass(CosmwasmDiscoveryWorker::class);
        $source     = (string) file_get_contents((string) $reflection->getFileName());
        $lines      = explode("\n", $source);

        /** @var array<string, bool> $reaches */
        $reaches = [];
        foreach (['runBackfillTick', 'runDailyDiscovery', 'runWeeklyRetry', 'runMetadataRefresh'] as $pass) {
            $method = $reflection->getMethod($pass);
            $body   = implode("\n", array_slice(
                $lines,
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1
            ));
            // Either directly, or via forEachChain — which is the only
            // other thing in this class that resolves a chain set.
            $reaches[$pass] = str_contains($body, 'self::eligibleChainIds(')
                || str_contains($body, 'self::forEachChain(');
        }

        self::assertSame(
            [
                'runBackfillTick'    => true,
                'runDailyDiscovery'  => true,
                'runWeeklyRetry'     => true,
                'runMetadataRefresh' => true,
            ],
            $reaches
        );

        $forEach = $reflection->getMethod('forEachChain');
        $body    = implode("\n", array_slice(
            $lines,
            $forEach->getStartLine() - 1,
            $forEach->getEndLine() - $forEach->getStartLine() + 1
        ));
        self::assertStringContainsString('self::eligibleChainIds(', $body);
    }
}
