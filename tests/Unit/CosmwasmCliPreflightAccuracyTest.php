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
use BCC\Trust\Onchain\Support\CosmwasmDiscoveryGate;
use BCC\Trust\Onchain\Support\CosmwasmTickBudget;
use BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The supervised one-shot must not LIE to the operator reading it.
 *
 * ── WHY THIS FILE EXISTS SEPARATELY FROM CosmwasmOneShotCliTest ─────────
 * That file asks "does the command refuse the things it must refuse".
 * This one asks a different question with a different failure mode: "does
 * the text an operator reads before pressing enter describe the run that
 * is about to happen". A safety command whose preflight is wrong is not
 * safe, it is quiet — the operator is still making a decision, just on
 * bad information, and nothing anywhere else in the suite would notice.
 *
 * The specific inaccuracies these tests were written to prevent
 * recurring, all of which shipped in the first version of the command:
 *
 *   - it named a 25-request canary limit that was never adopted, while
 *     the enforced ceiling was the canonical 50;
 *   - it implied a first pass inventories all ~179 Dungeon code families,
 *     when a fresh chain reads exactly ONE page and stops;
 *   - it costed a family at "a metadata read plus up to 3 probes",
 *     when one probe is 2-3 requests, so a family costs up to 10;
 *   - it stated a 20-second deadline without saying that it is
 *     cooperative and that one in-flight request extends the run to ~88s.
 *
 * ── THE TWO KINDS OF TEST HERE, AND WHY BOTH ARE NEEDED ─────────────────
 * TEXT tests pin what the operator is told. BEHAVIOUR tests pin what is
 * true. Either alone is worthless: a text-only suite passes happily while
 * the code does something else, and a behaviour-only suite passes while
 * the preflight describes a different program. They are asserted against
 * each other — {@see testAFreshChainWalkReadsExactlyOneCodePage()} is the
 * behavioural half of the claim
 * {@see testThePreflightCapsFirstPassInventoryAtTheNewestPage()} prints.
 *
 * Isolation is CosmwasmOneShotCliTest's: production FQNs faked inside a
 * subprocess, WP_CLI a recording double. NO TEST HERE TOUCHES A NETWORK.
 */
#[CoversClass(CosmwasmOneShotDiscoveryCommand::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CosmwasmCliPreflightAccuracyTest extends TestCase
{
    private const CHAIN = 17;
    private const SLUG  = 'dungeon';
    private const TOKEN = 'dungeon-17';
    private const REST  = 'https://dungeon.example';

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

    private function arrange(): void
    {
        define('WP_CLI', true);
        define('BCC_COSMWASM_CHAIN_ALLOWLIST', (string) self::CHAIN);
        ChainRepository::seed(self::CHAIN, self::SLUG, self::REST, 'cosmos', 1);
    }

    /**
     * Run the DRY path and hand back everything WP_CLI was told.
     *
     * Dry by default is what makes this file safe to run in CI at all: the
     * preflight is printed on both paths, so pinning it costs no request.
     */
    private function preflight(): string
    {
        $command = new CosmwasmOneShotDiscoveryCommand();

        try {
            $command->run([], ['chain' => (string) self::CHAIN, 'once' => true]);
        } catch (\BccTestCliHalt $halt) {
            self::fail('the dry run halted with exit ' . $halt->exitCode . ': ' . \WP_CLI::output());
        }

        return \WP_CLI::output();
    }

    /** @param array<string, mixed> $payload */
    private function queueJson(array $payload, int $code = 200): void
    {
        ApiRetry::$queue[] = ['code' => $code, 'body' => (string) json_encode($payload)];
    }

    /** Only the `/v1/code` LISTING calls — not `/code/{id}/contracts`. */
    private function codeListingCalls(): array
    {
        return array_values(array_filter(
            array_map(static fn(array $call): string => $call['url'], ApiRetry::$calls),
            static fn(string $url): bool => str_contains($url, '/cosmwasm/wasm/v1/code?')
        ));
    }

    /** The defining source of a class the test doubles shadow at its FQN. */
    private function sourceOf(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
    }

    private function intFromSource(string $source, string $pattern, string $what): int
    {
        self::assertSame(1, preg_match($pattern, $source, $m), 'could not read ' . $what);

        return (int) $m[1];
    }

    private function mirror(string $name): int
    {
        $constant = (new \ReflectionClass(CosmwasmOneShotDiscoveryCommand::class))->getConstant($name);
        self::assertIsInt($constant, $name . ' must be an int constant');

        return $constant;
    }

    // ── (1) the enforced budgets have NOT moved ─────────────────────────

    /**
     * THE LOAD-BEARING TEST OF THIS PR.
     *
     * The whole change is documentation. If any enforced number moved, the
     * change was not documentation. Every value the operator-facing text
     * describes is asserted here against the gate that enforces it, so a
     * future edit cannot quietly "fix" the docs by moving the code.
     */
    public function testTheEnforcedBudgetsAreUnchanged(): void
    {
        self::assertSame(50, CosmwasmDiscoveryGate::DEFAULT_REQUEST_BUDGET, 'canonical request ceiling');
        self::assertSame(50, CosmwasmDiscoveryGate::requestBudget(), 'resolved ceiling with no override');
        self::assertSame(20, CosmwasmDiscoveryGate::MAX_RUNTIME_SECONDS, 'wall clock');
        self::assertSame(100, CosmwasmDiscoveryGate::CODE_PAGE_SIZE, 'code page size');
        self::assertSame(5, CosmwasmDiscoveryGate::CODE_TAIL_MAX_PAGES, 'code tail pages');
        self::assertSame(3, CosmwasmDiscoveryGate::CONTRACT_TAIL_MAX_PAGES, 'contract tail pages');
        self::assertSame(3, CosmwasmDiscoveryGate::FAMILY_SAMPLE_SIZE, 'samples per family');
    }

    /** The budget object the command builds really does start at 50. */
    public function testTheDefaultTickBudgetIsTheCanonicalFifty(): void
    {
        self::assertSame(50, (new CosmwasmTickBudget())->remaining());
        self::assertSame(0, (new CosmwasmTickBudget())->spent());
    }

    /**
     * The command must not construct a budget of its own size.
     *
     * A CLI-only ceiling is the thing that was explicitly rejected: it
     * would make the supervised run observe something cron never does.
     * Asserted structurally, on executable tokens only, because the
     * docblock legitimately DISCUSSES the rejected 25-request limit and a
     * raw substring search would read the retraction as a violation.
     */
    public function testTheCommandBuildsTheDefaultBudgetWithNoOverride(): void
    {
        $source = $this->sourceOf('app/Domain/Onchain/CLI/CosmwasmOneShotDiscoveryCommand.php');

        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
                continue;
            }
            $code .= $token;
        }

        self::assertStringContainsString('new CosmwasmTickBudget()', $code, 'the default budget is built');
        self::assertSame(
            1,
            substr_count($code, 'new CosmwasmTickBudget'),
            'exactly one budget is constructed, and it takes no arguments'
        );
        self::assertStringNotContainsString(
            'BCC_COSMWASM_REQUEST_BUDGET',
            $code,
            'the command must not read, set or special-case the budget override'
        );
    }

    // ── (2) the four printed mirrors match their real sources ───────────

    /** `CosmosFetcher::$timeout` is private; the mirror must track it. */
    public function testTheHttpTimeoutMirrorMatchesTheRealFetcher(): void
    {
        $defaults = (new \ReflectionClass(\BCC\Trust\Onchain\Fetchers\CosmosFetcher::class))
            ->getDefaultProperties();

        self::assertSame(
            $defaults['timeout'],
            $this->mirror('HTTP_ATTEMPT_TIMEOUT_SECONDS'),
            'the preflight prints a per-attempt timeout the fetcher no longer uses'
        );
    }

    /**
     * `ApiRetry` is SHADOWED by the transport double at its production
     * FQN, so its real constants are unreachable by reflection here. Read
     * the defining file instead — the point is to fail when the real class
     * changes, not to admire the double.
     */
    public function testTheAttemptsPerRequestMirrorMatchesApiRetry(): void
    {
        $source     = $this->sourceOf('app/Domain/Onchain/Support/ApiRetry.php');
        $maxRetries = $this->intFromSource(
            $source,
            '/const\s+DEFAULT_MAX_RETRIES\s*=\s*(\d+)/',
            'ApiRetry::DEFAULT_MAX_RETRIES'
        );

        self::assertSame(
            $maxRetries + 1,
            $this->mirror('HTTP_ATTEMPTS_PER_LOGICAL_REQUEST'),
            'attempts per logical request is 1 + DEFAULT_MAX_RETRIES'
        );
    }

    /**
     * The 8-second figure is not declared anywhere — it is the SUM of
     * ApiRetry's capped backoff across a fully-retried call. Recompute it
     * from that class's own constants rather than trusting the comment,
     * because the comment is exactly what this PR is fixing elsewhere.
     */
    public function testTheRetryBackoffMirrorMatchesApiRetrysOwnFormula(): void
    {
        $source = $this->sourceOf('app/Domain/Onchain/Support/ApiRetry.php');

        $maxRetries = $this->intFromSource($source, '/const\s+DEFAULT_MAX_RETRIES\s*=\s*(\d+)/', 'max retries');
        $base       = $this->intFromSource($source, '/const\s+DEFAULT_BACKOFF_BASE\s*=\s*(\d+)/', 'backoff base');
        $max        = $this->intFromSource($source, '/const\s+DEFAULT_BACKOFF_MAX\s*=\s*(\d+)/', 'backoff max');
        $hardCap    = $this->intFromSource($source, '/\$delay\s*=\s*min\(\$delay,\s*(\d+)\);/', 'cron sleep cap');

        self::assertSame(1, preg_match('/const\s+BACKOFF_MULTIPLIER\s*=\s*([\d.]+)/', $source, $m));
        $multiplier = (float) $m[1];

        $total = 0;
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $total += min(min($max, (int) ($base * ($attempt ** $multiplier))), $hardCap);
        }

        self::assertSame(8, $total, 'precondition: the documented 2s + 3s + 3s');
        self::assertSame(
            $total,
            $this->mirror('RETRY_BACKOFF_SECONDS'),
            'the preflight prints a backoff total ApiRetry no longer produces'
        );
    }

    /** `FAMILIES_PER_PASS` is private on the worker; the mirror must track it. */
    public function testTheFamiliesPerPassMirrorMatchesTheWorker(): void
    {
        self::assertSame(
            (new \ReflectionClass(CosmwasmDiscoveryWorker::class))->getConstant('FAMILIES_PER_PASS'),
            $this->mirror('FAMILIES_PER_PASS_MIRROR')
        );
    }

    // ── (3) what the operator is actually told ──────────────────────────

    public function testThePreflightStatesTheCanonicalFiftyRequestCeiling(): void
    {
        $this->arrange();
        $out = $this->preflight();

        self::assertStringContainsString('50 LOGICAL requests', $out);
        self::assertStringContainsString('canonical scanner ceiling, not a canary-only limit', $out);
    }

    /**
     * THE RETIRED 25 MUST NOT COME BACK AS A PRINTED NUMBER.
     *
     * The docblock is allowed — required, even — to record that a
     * 25-request canary limit was proposed and rejected. What it may never
     * do again is tell an operator that 25 is what will run. So the
     * assertion is on the OUTPUT, and it is deliberately narrow: "25"
     * legitimately appears in the printed family cap.
     */
    public function testTheRetiredTwentyFiveRequestLimitIsNeverPresentedAsTheBudget(): void
    {
        $this->arrange();
        $out = $this->preflight();

        self::assertStringNotContainsString('25 LOGICAL requests', $out);
        self::assertStringNotContainsString('25 requests', $out);
        self::assertStringNotContainsString('BCC_COSMWASM_REQUEST_BUDGET=25', $out);

        // …and the source records the retraction rather than deleting it.
        $doc = $this->sourceOf('app/Domain/Onchain/CLI/CosmwasmOneShotDiscoveryCommand.php');
        self::assertStringContainsString('was NOT adopted', $doc);
    }

    public function testThePreflightSaysRetriesAreNotChargedSeparately(): void
    {
        $this->arrange();
        $out = $this->preflight();

        self::assertStringContainsString('attempts INSIDE a logical request', $out);
        self::assertStringContainsString('NOT charged against that count', $out);
        self::assertStringContainsString('up to 4', $out);
        self::assertStringContainsString('5xx/network only', $out);
    }

    public function testThePreflightSaysTheDeadlineIsCooperative(): void
    {
        $this->arrange();
        $out = $this->preflight();

        self::assertStringContainsString('COOPERATIVE, not a hard process timeout', $out);
        self::assertStringContainsString('Work already in flight is allowed to finish', $out);
    }

    /** The arithmetic is printed, not just the conclusion. */
    public function testThePreflightStatesTheEightyEightSecondWorstCase(): void
    {
        $this->arrange();
        $out = $this->preflight();

        self::assertStringContainsString('extend the run to ~88s', $out);
        self::assertStringContainsString('4 x 15s timeout + 8s backoff', $out);
        self::assertStringContainsString('20s mark', $out);
    }

    public function testThePreflightCapsFirstPassInventoryAtTheNewestPage(): void
    {
        $this->arrange();
        $out = $this->preflight();

        self::assertStringContainsString('AT MOST the newest 100 code families', $out);
        self::assertStringContainsString('NOT the whole chain', $out);
        self::assertStringContainsString('returns after ONE page while the watermark is 0', $out);
    }

    public function testThePreflightSaysOlderFamiliesStayUntouched(): void
    {
        $this->arrange();
        $out = $this->preflight();

        self::assertStringContainsString('stays UNTOUCHED', $out);
        self::assertStringContainsString('Only the historical backfill reaches them, and it is disabled', $out);
    }

    public function testThePreflightStatesTheClassificationRangeAndPerFamilyCost(): void
    {
        $this->arrange();
        $out = $this->preflight();

        self::assertStringContainsString('roughly 5-25 families', $out);
        self::assertStringContainsString('capped at 25', $out);
        self::assertStringContainsString('2-3 per sampled contract', $out);
        self::assertStringContainsString('a family costs 1 to 10', $out);
    }

    public function testThePreflightWarnsContractRowsAreBulkInsertedAndUnbounded(): void
    {
        $this->arrange();
        $out = $this->preflight();

        self::assertStringContainsString('NOT request-bounded', $out);
        self::assertStringContainsString('~1,700 rows', $out);
    }

    /** The two results an operator will misread in opposite directions. */
    public function testThePreflightDistinguishesZeroCollectionsFromZeroContracts(): void
    {
        $this->arrange();
        $out = $this->preflight();

        self::assertStringContainsString('collections     : 0 is EXPECTED and is a healthy first pass', $out);
        self::assertStringContainsString('contracts       : 0 is NOT the expected result', $out);
    }

    public function testThePreflightLimitsItsOwnScopeClaim(): void
    {
        $this->arrange();
        $out = $this->preflight();

        self::assertStringContainsString('NORMAL INCREMENTAL DISCOVERY PATH', $out);
        self::assertStringContainsString('does not prove complete historical NFT coverage', $out);
    }

    /**
     * The one-page cap belongs to a FRESH chain, not to the walk.
     *
     * Printing "at most the newest 100" against a resumed chain would be
     * the same lie in the other direction, so the branch is pinned from
     * both sides.
     */
    public function testAResumedChainIsToldAboutTheTailWalkInstead(): void
    {
        $this->arrange();
        ChainCheckpointRepository::ensureExists(self::CHAIN);
        ChainCheckpointRepository::$rows[self::CHAIN]->cw_max_code_id = '140';

        $out = $this->preflight();

        self::assertStringContainsString('incremental reverse tail from watermark 140, up to 5 pages of 100', $out);
        self::assertStringNotContainsString('AT MOST the newest 100 code families', $out);
        self::assertStringContainsString('owned by the historical backfill', $out);
    }

    // ── (4) …and the behaviour those claims describe ────────────────────

    /**
     * THE BEHAVIOURAL HALF of "at most the newest 100".
     *
     * The node is given a FULL page WITH a next_key — everything the walk
     * would need in order to keep going, and a five-page budget to do it
     * with. It must still stop at one, because
     * {@see \BCC\Trust\Onchain\Services\CosmwasmDiscoveryService::ingestNewCodeFamilies()}
     * returns immediately on a zero watermark and hands the rest of
     * history to the backfill.
     *
     * Delete that early return and this test walks to page two.
     */
    public function testAFreshChainWalkReadsExactlyOneCodePage(): void
    {
        $this->arrange();

        $this->queueJson([
            'code_infos' => [
                ['code_id' => '179', 'data_hash' => 'AA'],
                ['code_id' => '178', 'data_hash' => 'BB'],
            ],
            'pagination' => ['next_key' => 'MORE=='],
        ]);
        // A second page IS available. Reading it would be the bug.
        $this->queueJson([
            'code_infos' => [['code_id' => '177', 'data_hash' => 'CC']],
            'pagination' => ['next_key' => null],
        ]);

        $command = new CosmwasmOneShotDiscoveryCommand();
        try {
            $command->run([], ['chain' => (string) self::CHAIN, 'once' => true, 'confirm' => self::TOKEN]);
        } catch (\BccTestCliHalt $halt) {
            self::fail('expected a clean pass, got exit ' . $halt->exitCode . ': ' . \WP_CLI::output());
        }

        self::assertCount(
            1,
            $this->codeListingCalls(),
            'a fresh chain reads ONE code page; the rest of history belongs to the backfill'
        );
        self::assertNull(
            CosmwasmCodeFamilyRepository::find(self::CHAIN, 177),
            'the second page must not have been ingested'
        );
        self::assertNotNull(CosmwasmCodeFamilyRepository::find(self::CHAIN, 179));
    }

    /**
     * THE CONTROL that stops the test above passing vacuously.
     *
     * Same fixture shape, same page budget — only the watermark differs.
     * A resumed chain DOES keep walking, so "one request" above is the
     * zero-watermark early return and not an artefact of the queue, the
     * budget or the fake.
     */
    public function testAResumedChainWalkKeepsReadingPastTheFirstPage(): void
    {
        $this->arrange();
        ChainCheckpointRepository::ensureExists(self::CHAIN);
        ChainCheckpointRepository::$rows[self::CHAIN]->cw_max_code_id = '100';

        $this->queueJson([
            'code_infos' => [
                ['code_id' => '179', 'data_hash' => 'AA'],
                ['code_id' => '178', 'data_hash' => 'BB'],
            ],
            'pagination' => ['next_key' => 'MORE=='],
        ]);
        $this->queueJson([
            'code_infos' => [['code_id' => '99', 'data_hash' => 'CC']],
            'pagination' => ['next_key' => null],
        ]);

        $command = new CosmwasmOneShotDiscoveryCommand();
        try {
            $command->run([], ['chain' => (string) self::CHAIN, 'once' => true, 'confirm' => self::TOKEN]);
        } catch (\BccTestCliHalt $halt) {
            self::fail('expected a clean pass, got exit ' . $halt->exitCode . ': ' . \WP_CLI::output());
        }

        self::assertCount(
            2,
            $this->codeListingCalls(),
            'a resumed chain walks until it meets its watermark'
        );
    }
}
