<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmContractRepository;
use BCC\Trust\Onchain\Repositories\NftSpamContractRepository;
use BCC\Trust\Onchain\Services\CosmwasmDiscoveryHealthSnapshot;
use BCC\Trust\Onchain\Support\CosmwasmScanEligibility;
use BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * THE DASHBOARD AND THE WORKER MUST NOT DISAGREE.
 *
 * ── WHAT WENT WRONG, TWICE ──────────────────────────────────────────────
 * "Which chains will the scanner walk?" had two answers in this codebase:
 * one inside {@see CosmwasmDiscoveryWorker::eligibleChainIds()}, and one
 * written out again inside the admin panel's status arithmetic. They were
 * written to agree. They drifted anyway, in the same fortnight:
 *
 *   1. The panel counted every chain that was neither paused nor
 *      unsupported as eligible — INCLUDING chains nobody had opted in. A
 *      site whose only opt-in was an unsupported chain read GREEN off a
 *      chain the operator had deliberately switched off.
 *   2. With the opt-in fixed, the arithmetic still keyed on `paused ||
 *      unsupported` and never looked at the canary allowlist. An opted-in,
 *      supported chain OUTSIDE `BCC_COSMWASM_CHAIN_ALLOWLIST` therefore
 *      counted as scannable and could read GREEN — while
 *      `eligible_chain_count` on the SAME summary said 0 and the worker
 *      skipped the chain.
 *
 * Both were the same defect. Both were invisible to a test suite that
 * checked each side on its own.
 *
 * ── WHAT THIS FILE PINS ─────────────────────────────────────────────────
 * Every fixture below is run through BOTH sides and the two answers are
 * compared as SETS OF CHAIN IDS, not as totals — two different chains and
 * the same count is still a disagreement. The comparison calls the real
 * {@see CosmwasmDiscoveryWorker::eligibleChainIds()} (by reflection; it is
 * private, which is the point) rather than a restatement of its rules, so
 * this cannot pass by both sides being wrong the same way.
 *
 * The eleven fixtures are the owner's list, and they cover every route to
 * "not scannable": no opt-in, paused, unsupported, outside the allowlist,
 * a malformed allowlist, an undefined allowlist, an empty registry and a
 * failed read.
 *
 * ── ISOLATION ───────────────────────────────────────────────────────────
 * The cosmwasm-discovery stubs, in a subprocess, per test. Separate
 * processes are not decoration here: `BCC_COSMWASM_CHAIN_ALLOWLIST` and
 * `BCC_COSMWASM_DISCOVERY_ENABLED` are CONSTANTS, and a fixture that
 * defined one would otherwise poison every fixture after it.
 */
#[CoversClass(CosmwasmDiscoveryHealthSnapshot::class)]
#[CoversClass(CosmwasmScanEligibility::class)]
#[CoversClass(CosmwasmDiscoveryWorker::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CosmwasmScannerStatusParityTest extends TestCase
{
    /** Sentinel for "do not define the allowlist constant at all". */
    private const ALLOWLIST_UNDEFINED = '__undefined__';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/cosmwasm-discovery-stubs.php';

        CosmwasmCodeFamilyRepository::reset();
        CosmwasmContractRepository::reset();
        CollectionRepository::reset();
        ChainCheckpointRepository::reset();
        ChainRepository::reset();
        NftSpamContractRepository::reset();
        \BCC\Trust\Onchain\Support\OnchainCircuitBreaker::reset();
        \BCC\Core\Log\Logger::reset();
        \BccTestCronStore::reset();
    }

    // ═══════════════════════════════════════════════════════════════════
    //  THE FIXTURES
    // ═══════════════════════════════════════════════════════════════════

    /**
     * The owner's eleven cases. Each one is a whole site: a chain
     * registry, a checkpoint table, a gate and an allowlist.
     *
     * `state` is `null` for a chain with NO checkpoint row — an opted-in
     * chain nobody has measured yet, which is scannable on purpose (the
     * first pass is what creates the measurement).
     *
     * `age` is seconds since the last discovery stamp, `null` = never run.
     * IT IS LOAD-BEARING ON THE GREEN CASES: a chain with no stamp is
     * stale, and a fixture that forgot it would turn every green case
     * yellow and quietly stop testing what it says it tests.
     *
     * @return array<string, array{0: array{
     *     allowlist: string,
     *     gate: bool,
     *     fail_read: bool,
     *     chains: list<array{id: int, slug: string, opted_in: int, state: string|null, age: int|null, error: string|null}>,
     *     status: string,
     *     eligible: list<int>|null
     * }}>
     */
    public static function fixture(): array
    {
        $healthy = static fn(int $id, string $slug, int $optedIn = 1): array => [
            'id'       => $id,
            'slug'     => $slug,
            'opted_in' => $optedIn,
            'state'    => ChainCheckpointRepository::CW_STATE_BACKFILLED,
            'age'      => 600,
            'error'    => null,
        ];

        return [
            // 1 ── nobody pointed the scanner at anything. Not a fault.
            'zero opted-in chains' => [[
                'allowlist' => self::ALLOWLIST_UNDEFINED,
                'gate'      => true,
                'fail_read' => false,
                'chains'    => [$healthy(8, 'cosmos', 0), $healthy(9, 'juno', 0)],
                'status'    => CosmwasmDiscoveryHealthSnapshot::STATUS_IDLE,
                'eligible'  => [],
            ]],

            // 2 ── the control. Opted in, supported, unpaused, allowed.
            'one opted-in supported allowed chain' => [[
                'allowlist' => '8',
                'gate'      => true,
                'fail_read' => false,
                'chains'    => [$healthy(8, 'cosmos')],
                'status'    => CosmwasmDiscoveryHealthSnapshot::STATUS_GREEN,
                'eligible'  => [8],
            ]],

            // 3 ── THE HOLE THIS AMENDMENT CLOSES, first half. A paused
            // chain used to count as scannable in the panel's arithmetic
            // and as eligible in the worker's selector, and only got
            // dropped one layer down in prepareChain().
            'one opted-in paused chain' => [[
                'allowlist' => self::ALLOWLIST_UNDEFINED,
                'gate'      => true,
                'fail_read' => false,
                'chains'    => [[
                    'id'       => 8,
                    'slug'     => 'cosmos',
                    'opted_in' => 1,
                    'state'    => ChainCheckpointRepository::CW_STATE_PAUSED,
                    'age'      => 600,
                    'error'    => null,
                ]],
                'status'    => CosmwasmDiscoveryHealthSnapshot::STATUS_BLOCKED,
                'eligible'  => [],
            ]],

            // 4 ── the case #187 already covered, kept as the regression
            // fence around it.
            'one opted-in unsupported chain' => [[
                'allowlist' => self::ALLOWLIST_UNDEFINED,
                'gate'      => true,
                'fail_read' => false,
                'chains'    => [[
                    'id'       => 8,
                    'slug'     => 'cosmos',
                    'opted_in' => 1,
                    'state'    => ChainCheckpointRepository::CW_STATE_UNSUPPORTED,
                    'age'      => 600,
                    'error'    => null,
                ]],
                'status'    => CosmwasmDiscoveryHealthSnapshot::STATUS_BLOCKED,
                'eligible'  => [],
            ]],

            // 5 ── THE HOLE THIS AMENDMENT CLOSES, second half. Nothing is
            // wrong with this chain at all: opted in, supported, unpaused,
            // freshly stamped. It is simply outside the canary scope, and
            // the old arithmetic had no idea.
            'one opted-in supported chain outside the allowlist' => [[
                'allowlist' => '4321',
                'gate'      => true,
                'fail_read' => false,
                'chains'    => [$healthy(8, 'cosmos')],
                'status'    => CosmwasmDiscoveryHealthSnapshot::STATUS_BLOCKED,
                'eligible'  => [],
            ]],

            // 6 ── mixed. The excluded chain is deliberately NOISY — never
            // run, and carrying an error — so a verdict that folded it into
            // the arithmetic would come out yellow instead of green.
            'mixed eligible and allowlist-excluded' => [[
                'allowlist' => '8',
                'gate'      => true,
                'fail_read' => false,
                'chains'    => [
                    $healthy(8, 'cosmos'),
                    [
                        'id'       => 9,
                        'slug'     => 'juno',
                        'opted_in' => 1,
                        'state'    => ChainCheckpointRepository::CW_STATE_BACKFILLING,
                        'age'      => null,
                        'error'    => 'lcd 502',
                    ],
                ],
                'status'    => CosmwasmDiscoveryHealthSnapshot::STATUS_GREEN,
                'eligible'  => [8],
            ]],

            // 7 ── the whole selection is outside the canary scope.
            'all opted-in chains outside the allowlist' => [[
                'allowlist' => '4321',
                'gate'      => true,
                'fail_read' => false,
                'chains'    => [$healthy(8, 'cosmos'), $healthy(9, 'juno')],
                'status'    => CosmwasmDiscoveryHealthSnapshot::STATUS_BLOCKED,
                'eligible'  => [],
            ]],

            // 8 ── FAIL CLOSED. Slugs are the obvious operator typo, and
            // the tempting implementation ("nothing parsed, so no
            // restriction") would scan BOTH chains and read GREEN.
            'malformed allowlist' => [[
                'allowlist' => 'cosmos, osmosis',
                'gate'      => true,
                'fail_read' => false,
                'chains'    => [$healthy(8, 'cosmos'), $healthy(9, 'juno')],
                'status'    => CosmwasmDiscoveryHealthSnapshot::STATUS_BLOCKED,
                'eligible'  => [],
            ]],

            // 9 ── the other direction: undefined is NOT "scan nothing".
            'undefined allowlist' => [[
                'allowlist' => self::ALLOWLIST_UNDEFINED,
                'gate'      => true,
                'fail_read' => false,
                'chains'    => [$healthy(8, 'cosmos'), $healthy(9, 'juno')],
                'status'    => CosmwasmDiscoveryHealthSnapshot::STATUS_GREEN,
                'eligible'  => [8, 9],
            ]],

            // 10 ── not idle and not blocked: nobody DECLINED anything, the
            // registry is simply empty. Keeps the red it always had.
            'empty registry' => [[
                'allowlist' => self::ALLOWLIST_UNDEFINED,
                'gate'      => true,
                'fail_read' => false,
                'chains'    => [],
                'status'    => CosmwasmDiscoveryHealthSnapshot::STATUS_RED,
                'eligible'  => [],
            ]],

            // 11 ── "we could not look" outranks every tidy answer,
            // including the two calm ones.
            'required read failure' => [[
                'allowlist' => self::ALLOWLIST_UNDEFINED,
                'gate'      => true,
                'fail_read' => true,
                'chains'    => [$healthy(8, 'cosmos')],
                'status'    => CosmwasmDiscoveryHealthSnapshot::STATUS_UNAVAILABLE,
                'eligible'  => null,
            ]],
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  1-11 — the status each fixture must derive
    // ═══════════════════════════════════════════════════════════════════

    /**
     * @param array{
     *     allowlist: string,
     *     gate: bool,
     *     fail_read: bool,
     *     chains: list<array{id: int, slug: string, opted_in: int, state: string|null, age: int|null, error: string|null}>,
     *     status: string,
     *     eligible: list<int>|null
     * } $fixture
     */
    #[DataProvider('fixture')]
    public function test_the_panel_derives_the_expected_status(array $fixture): void
    {
        $this->arrange($fixture);

        $summary = CosmwasmDiscoveryHealthSnapshot::buildSummary();

        self::assertSame($fixture['status'], $summary['status']);

        // NEVER GREEN unless the fixture says green. Written out rather
        // than left implicit in assertSame() because "not green" is the
        // property that actually matters on eight of these eleven: the
        // defect being fenced off is a scanner that reports itself healthy
        // while it walks nothing at all.
        if ($fixture['status'] !== CosmwasmDiscoveryHealthSnapshot::STATUS_GREEN) {
            self::assertNotSame(
                CosmwasmDiscoveryHealthSnapshot::STATUS_GREEN,
                $summary['status'],
                'a scanner that cannot walk anything must never read as healthy'
            );
        }

        // And the count the table prints agrees with the badge above it.
        if ($fixture['eligible'] === null) {
            self::assertNull($summary['eligible_chain_count']);
        } else {
            self::assertSame(count($fixture['eligible']), $summary['eligible_chain_count']);
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  12 — THE BINDING INVARIANT, on every fixture above
    // ═══════════════════════════════════════════════════════════════════

    /**
     * The panel's eligible set and the worker's eligible set are the SAME
     * SET, for every fixture.
     *
     * Ids, not counts. "Two eligible chains" from each side is not
     * agreement if they are different two chains, and a count-only
     * assertion would pass on exactly the failure this whole amendment is
     * about: a panel that says a chain is covered while the worker skips
     * it and takes a different one instead.
     *
     * @param array{
     *     allowlist: string,
     *     gate: bool,
     *     fail_read: bool,
     *     chains: list<array{id: int, slug: string, opted_in: int, state: string|null, age: int|null, error: string|null}>,
     *     status: string,
     *     eligible: list<int>|null
     * } $fixture
     */
    #[DataProvider('fixture')]
    public function test_the_panel_and_the_worker_agree_on_which_chains_are_eligible(array $fixture): void
    {
        $this->arrange($fixture);

        $summary   = CosmwasmDiscoveryHealthSnapshot::buildSummary();
        $workerIds = $this->workerEligibleChainIds();
        $panelIds  = $this->panelEligibleChainIds($summary);

        self::assertSame(
            $workerIds,
            $panelIds,
            'the panel and the worker disagree about which chains the scanner will walk'
        );

        if ($summary['eligible_chain_count'] === null) {
            // "Nobody could work it out" is the panel's honest answer to a
            // failed read. The worker reaches the same conclusion by the
            // same route — its checkpoint read fails too — and must scan
            // NOTHING rather than fall through to every chain.
            self::assertSame(
                [],
                $workerIds,
                'a failed read must leave the worker with no chains, not with all of them'
            );
        } else {
            self::assertSame(count($workerIds), $summary['eligible_chain_count']);
        }

        // Finally, against the fixture's own expectation, so a change that
        // moved BOTH sides the same wrong way still fails.
        if ($fixture['eligible'] !== null) {
            self::assertSame($fixture['eligible'], $workerIds);
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  THE STRUCTURAL HALF — one predicate, not two that agree
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Agreement is maintained by CONSTRUCTION, not by hand.
     *
     * Both files resolve their own data — the worker from the database,
     * the panel from rows it already holds — and then ask the same
     * function. This asserts that the ASKING happens exactly once on each
     * side, and, more importantly, that the panel's status arithmetic
     * contains no second opinion: no `paused`, no `unsupported`, no
     * allowlist membership test. Every one of those, in that method, was a
     * shipped bug.
     */
    public function test_neither_side_carries_a_second_definition_of_scannable(): void
    {
        $worker   = new \ReflectionClass(CosmwasmDiscoveryWorker::class);
        $snapshot = new \ReflectionClass(CosmwasmDiscoveryHealthSnapshot::class);

        $workerCode   = $this->codeWithoutComments((string) file_get_contents((string) $worker->getFileName()));
        $snapshotCode = $this->codeWithoutComments((string) file_get_contents((string) $snapshot->getFileName()));

        self::assertSame(
            1,
            substr_count($workerCode, 'CosmwasmScanEligibility::verdict('),
            'the worker must reach the shared verdict in exactly one place'
        );
        self::assertSame(
            1,
            substr_count($snapshotCode, 'CosmwasmScanEligibility::verdict('),
            'the panel must reach the shared verdict in exactly one place'
        );

        // The arithmetic reads the stored verdict and nothing else.
        $status = $this->methodSource($snapshot, 'deriveStatus');
        foreach (["'paused'", "'unsupported'", 'in_array', 'CW_STATE_'] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $this->codeWithoutComments("<?php\n" . $status),
                "deriveStatus() must not re-derive scannability from {$forbidden} — that is the bug, twice over"
            );
        }
        self::assertStringContainsString('self::scannable(', $status);

        // And so does the summary's count, so the badge and the "N of M"
        // line cannot contradict each other.
        self::assertStringContainsString(
            'self::scannable($row)',
            $this->methodSource($snapshot, 'buildSummary')
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    //  helpers
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Build the whole site the fixture describes: constants first (they
     * cannot be un-defined), then the registry, then the checkpoints.
     *
     * @param array{
     *     allowlist: string,
     *     gate: bool,
     *     fail_read: bool,
     *     chains: list<array{id: int, slug: string, opted_in: int, state: string|null, age: int|null, error: string|null}>,
     *     status: string,
     *     eligible: list<int>|null
     * } $fixture
     */
    private function arrange(array $fixture): void
    {
        if ($fixture['gate']) {
            define('BCC_COSMWASM_DISCOVERY_ENABLED', true);
            define('BCC_COSMWASM_BACKFILL_ENABLED', true);
        }
        if ($fixture['allowlist'] !== self::ALLOWLIST_UNDEFINED) {
            define('BCC_COSMWASM_CHAIN_ALLOWLIST', $fixture['allowlist']);
        }

        foreach ($fixture['chains'] as $chain) {
            ChainRepository::seed(
                $chain['id'],
                $chain['slug'],
                'https://' . $chain['slug'] . '.example',
                'cosmos',
                $chain['opted_in']
            );

            if ($chain['state'] === null) {
                continue; // No checkpoint row: nobody has measured it yet.
            }

            ChainCheckpointRepository::ensureExists($chain['id']);
            $row                     = ChainCheckpointRepository::$rows[$chain['id']];
            $row->cw_discovery_state = $chain['state'];
            $row->cw_last_error      = $chain['error'];
            $row->cw_last_discovery_at = $chain['age'] === null
                ? null
                : gmdate('Y-m-d H:i:s', time() - $chain['age']);
        }

        // Armed LAST: ensureExists() above would have to run before it.
        if ($fixture['fail_read']) {
            ChainCheckpointRepository::$failGetAll = true;
        }
    }

    /**
     * The REAL selector, called through reflection because it is private
     * — which is exactly why this test may not restate it.
     *
     * @return list<int>
     */
    private function workerEligibleChainIds(): array
    {
        $method = new ReflectionMethod(CosmwasmDiscoveryWorker::class, 'eligibleChainIds');
        $method->setAccessible(true);

        /** @var list<int> $ids */
        $ids = $method->invoke(null);
        sort($ids);

        return array_values($ids);
    }

    /**
     * @param  array<string, mixed> $summary
     * @return list<int>
     */
    private function panelEligibleChainIds(array $summary): array
    {
        $ids = [];
        /** @var list<array<string, mixed>> $chains */
        $chains = is_array($summary['chains'] ?? null) ? $summary['chains'] : [];
        foreach ($chains as $row) {
            if (CosmwasmDiscoveryHealthSnapshot::scannable($row)) {
                $ids[] = (int) ($row['chain_id'] ?? 0);
            }
        }
        sort($ids);

        return array_values($ids);
    }

    /** @param \ReflectionClass<object> $class */
    private function methodSource(\ReflectionClass $class, string $method): string
    {
        $reflection = $class->getMethod($method);
        $lines      = explode("\n", (string) file_get_contents((string) $class->getFileName()));

        return implode("\n", array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
    }

    /**
     * Executable statements only.
     *
     * Token-walked rather than regex-stripped because the docblocks in
     * both files NAME the very things being counted — "paused",
     * "unsupported", the verdict call — so a substring count over raw
     * source would count the prose describing the bug as the bug.
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
}
