<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\ChainRefreshService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-62: an incomplete bonded-validator walk reaches the index run as an EMPTY
 * fetch, and an empty fetch must skip EVERY destructive step of the run.
 *
 * CosmosFetcher::getBondedValidators() now returns [] rather than a truncated
 * set when it cannot read the bonded list to its end. That is only safe if the
 * index run treats [] as "could not index" everywhere. This test RUNS
 * ChainRefreshService::index_validators() against recording fakes
 * (tests/Stubs/index-run-stubs.php) and pins that an empty fetch reaches:
 *
 *   - no ValidatorRepository::bulkUpsert   (no row written or re-statused);
 *   - no indexer-stats write               (so no baseline can form from it);
 *   - no EnrichmentScheduler::markDeadValidators, even with the dead-marking
 *     switch ON — the one step that acts on ABSENCE and is global;
 *   - no page minting and no allowlist built from it;
 *   - no breaker success.
 *
 * The control cases prove the harness can see those steps: the same setup with
 * a clean fetch reaches every one of them.
 *
 * Delegator-group revocation is not on this path at all: ValidatorGroupRevokeService
 * decides from the live delegations endpoint (CosmosFetcher::fetch_delegations_result,
 * F-63), never from the bonded set or a validator's stored status.
 */
#[CoversClass(ChainRefreshService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class IndexRunPartialSkipsDestructiveStepsTest extends TestCase
{
    private const CHAIN_A = 11;
    private const CHAIN_B = 12;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/index-run-stubs.php';
        \IndexRunRecorder::reset();

        // Arm everything a clean run could do: dead-marking ON, and a fresh,
        // verified baseline so a clean fetch would also be allowed to mint.
        \IndexRunRecorder::$options['bcc_onchain_dead_marking_enabled'] = true;
        \IndexRunRecorder::$options['bcc_onchain_indexer_stats'] = [
            'chain-a' => ['last_good_total' => 40, 'last_good_at' => gmdate('Y-m-d H:i:s', time() - 3600)],
            'chain-b' => ['last_good_total' => 40, 'last_good_at' => gmdate('Y-m-d H:i:s', time() - 3600)],
        ];
    }

    private function chain(int $id, string $slug): void
    {
        \IndexRunRecorder::$chains[] = (object) [
            'id'         => $id,
            'name'       => ucfirst($slug),
            'slug'       => $slug,
            'chain_type' => 'cosmos',
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function validators(int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = ['operator_address' => 'cosmosvaloper1v' . $i, 'status' => 'active'];
        }
        return $out;
    }

    public function testTheFakesAreTheCollaboratorsInPlay(): void
    {
        // A real class loaded first would skip its fake and make every
        // assertion below vacuous.
        self::assertStringContainsString(
            'index-run-stubs.php',
            (string) (new \ReflectionClass(\BCC\Trust\Onchain\Repositories\ValidatorRepository::class))->getFileName()
        );
        self::assertStringContainsString(
            'index-run-stubs.php',
            (string) (new \ReflectionClass(\BCC\Trust\Onchain\Services\EnrichmentScheduler::class))->getFileName()
        );
    }

    public function testAnEmptyFetchSkipsEveryDestructiveStep(): void
    {
        $this->chain(self::CHAIN_A, 'chain-a');
        \IndexRunRecorder::$fetchResults[self::CHAIN_A] = [];

        ChainRefreshService::index_validators();

        self::assertSame([self::CHAIN_A], \IndexRunRecorder::callsOf('fetch_all_validators'), 'the run did reach the fetch');
        self::assertSame([], \IndexRunRecorder::callsOf('validators.bulkUpsert'));
        self::assertSame([], \IndexRunRecorder::callsOf('update_option'));
        self::assertSame([], \IndexRunRecorder::callsOf('markDeadValidators'));
        self::assertSame([], \IndexRunRecorder::callsOf('validators.idsForOperatorAddresses'));
        self::assertSame([], \IndexRunRecorder::callsOf('mintMissing'));
        self::assertSame([], \IndexRunRecorder::callsOf('breaker.recordSuccess'));
        // The baseline the next run is judged against is untouched.
        self::assertSame(40, \IndexRunRecorder::$options['bcc_onchain_indexer_stats']['chain-a']['last_good_total']);
        self::assertContains(
            '[Onchain] Skipped markDeadValidators — partial fetch detected in this run.',
            \IndexRunRecorder::callsOf('log.info')
        );
    }

    public function testTheSameSetupWithACleanFetchReachesEveryStep(): void
    {
        // Control: proves the assertions above can fail.
        $this->chain(self::CHAIN_A, 'chain-a');
        \IndexRunRecorder::$fetchResults[self::CHAIN_A] = self::validators(40);

        ChainRefreshService::index_validators();

        self::assertSame([40], \IndexRunRecorder::callsOf('validators.bulkUpsert'));
        self::assertSame(['bcc_onchain_indexer_stats'], \IndexRunRecorder::callsOf('update_option'));
        self::assertSame([[self::CHAIN_A]], \IndexRunRecorder::callsOf('markDeadValidators'));
        self::assertSame([self::CHAIN_A], \IndexRunRecorder::callsOf('mintMissing'));
        self::assertSame([self::CHAIN_A], \IndexRunRecorder::callsOf('breaker.recordSuccess'));
    }

    /**
     * F-157: a chain the run SKIPPED (breaker resting) is not in the sweep.
     * Its rows were not refreshed, so another chain's clean run must not
     * mark them dead.
     */
    public function testASkippedChainIsNotSweptByAnotherChainsCleanRun(): void
    {
        $this->chain(self::CHAIN_A, 'chain-a');
        $this->chain(self::CHAIN_B, 'chain-b');
        \IndexRunRecorder::$fetchResults[self::CHAIN_A] = self::validators(40);
        \IndexRunRecorder::$fetchResults[self::CHAIN_B] = self::validators(40);
        \IndexRunRecorder::$resting = [self::CHAIN_B];

        ChainRefreshService::index_validators();

        self::assertSame([[self::CHAIN_A]], \IndexRunRecorder::callsOf('markDeadValidators'));
    }

    public function testEveryIndexedChainIsInTheSweep(): void
    {
        $this->chain(self::CHAIN_A, 'chain-a');
        $this->chain(self::CHAIN_B, 'chain-b');
        \IndexRunRecorder::$fetchResults[self::CHAIN_A] = self::validators(40);
        \IndexRunRecorder::$fetchResults[self::CHAIN_B] = self::validators(40);

        ChainRefreshService::index_validators();

        self::assertSame([[self::CHAIN_A, self::CHAIN_B]], \IndexRunRecorder::callsOf('markDeadValidators'));
    }

    public function testOneEmptyChainWithholdsDeadMarkingForTheWholeRun(): void
    {
        // markDeadValidators() has no chain_id, so a chain whose set could not
        // be read must stop it for every chain — while the healthy chain is
        // still indexed and still mints for itself only.
        $this->chain(self::CHAIN_A, 'chain-a');
        $this->chain(self::CHAIN_B, 'chain-b');
        \IndexRunRecorder::$fetchResults[self::CHAIN_A] = self::validators(40);
        \IndexRunRecorder::$fetchResults[self::CHAIN_B] = [];

        ChainRefreshService::index_validators();

        self::assertSame([40], \IndexRunRecorder::callsOf('validators.bulkUpsert'), 'only the healthy chain is written');
        self::assertSame([], \IndexRunRecorder::callsOf('markDeadValidators'));
        self::assertSame([self::CHAIN_A], \IndexRunRecorder::callsOf('mintMissing'));
        self::assertSame([self::CHAIN_A], \IndexRunRecorder::callsOf('breaker.recordSuccess'));
        self::assertSame(40, \IndexRunRecorder::$options['bcc_onchain_indexer_stats']['chain-b']['last_good_total']);
    }

    // ── F-66: an Error from one chain is that chain's partial fetch ─────

    /** @return array<string, array{0: \Throwable}> */
    public static function errors(): array
    {
        return [
            'TypeError (typed mapper, non-array item)' => [new \TypeError('mapNode(): Argument #1 ($node) must be of type array, string given')],
            'ValueError (bccomp, non-numeric amount)'  => [new \ValueError('bccomp(): Argument #1 ($num1) is not well-formed')],
            'plain Error'                              => [new \Error('Cannot access offset of type string on string')],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('errors')]
    public function testAnErrorOnOneChainDoesNotAbortTheRunForTheNext(\Throwable $error): void
    {
        // Chain A throws an Error, not an Exception. Before F-66 the
        // per-chain catch was \Exception, so this escaped the loop and chain
        // B was never indexed.
        $this->chain(self::CHAIN_A, 'chain-a');
        $this->chain(self::CHAIN_B, 'chain-b');
        \IndexRunRecorder::$fetchResults[self::CHAIN_A] = $error;
        \IndexRunRecorder::$fetchResults[self::CHAIN_B] = self::validators(40);

        ChainRefreshService::index_validators();

        self::assertSame([self::CHAIN_A, self::CHAIN_B], \IndexRunRecorder::callsOf('fetch_all_validators'));
        self::assertSame([40], \IndexRunRecorder::callsOf('validators.bulkUpsert'), 'chain B is still indexed');
        self::assertSame([self::CHAIN_B], \IndexRunRecorder::callsOf('mintMissing'));
        // …and chain A counts as partial, so the global sweep is withheld.
        self::assertSame([], \IndexRunRecorder::callsOf('markDeadValidators'));
        self::assertSame([self::CHAIN_A], \IndexRunRecorder::callsOf('breaker.recordFailure'));
        self::assertSame(40, \IndexRunRecorder::$options['bcc_onchain_indexer_stats']['chain-a']['last_good_total']);
        $errors = \IndexRunRecorder::callsOf('log.error');
        self::assertCount(1, $errors);
        self::assertStringContainsString('Validator index failed for Chain-a', (string) $errors[0]);
    }

    public function testNoCatchInTheServiceNarrowsToException(): void
    {
        // refresh_collections() is not reachable through these fakes, so its
        // per-row catch is pinned here: every catch in the service must take
        // \Throwable. Comments are stripped so prose cannot satisfy or break it.
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Onchain/Services/ChainRefreshService.php');
        $code = '';
        foreach (token_get_all($src) as $tok) {
            if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($tok) ? $tok[1] : $tok;
        }

        preg_match_all('/catch\s*\(\s*([^\s$)]+)/', $code, $m);
        self::assertCount(3, $m[1], 'the service has exactly three catch sites');
        self::assertSame(['\\Throwable', '\\Throwable', '\\Throwable'], $m[1]);
    }
}
