<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Fetchers\IndexTimeBudgetSpent;
use BCC\Trust\Onchain\Services\ChainRefreshService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Two things the validator index run now tells apart, driven through the
 * real ChainRefreshService::index_validators() against the recording fakes
 * in tests/Stubs/index-run-stubs.php:
 *
 *  - a fetch ended by a PROVIDER REFUSAL (a non-429 4xx, which the transport
 *    notes on the receipt but does not charge) charges the breaker once,
 *    naming `refused` instead of reading back as "Not recorded";
 *  - a walk that stopped at its OWN TIME BUDGET is the chain's partial fetch
 *    but charges nothing: no provider did anything wrong.
 */
#[CoversClass(ChainRefreshService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class IndexRunRefusalAndTimeBudgetTest extends TestCase
{
    private const CHAIN_A = 11;
    private const CHAIN_B = 12;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/index-run-stubs.php';
        \IndexRunRecorder::reset();
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
        self::assertStringContainsString(
            'index-run-stubs.php',
            (string) (new \ReflectionClass(\BCC\Trust\Onchain\Support\OnchainCircuitBreaker::class))->getFileName()
        );
    }

    // ── A refusal names itself ──────────────────────────────────────────

    public function testARefusedPageThatFailsTheFetchChargesOnceAsRefused(): void
    {
        $this->chain(self::CHAIN_A, 'chain-a');
        \IndexRunRecorder::$refusals = [self::CHAIN_A];
        \IndexRunRecorder::$fetchResults[self::CHAIN_A] = new \RuntimeException('Polkadot validator page 0 failed');

        ChainRefreshService::index_validators();

        self::assertSame([[self::CHAIN_A, 'refused']], \IndexRunRecorder::callsOf('breaker.recordFailure.kind'));
        self::assertSame([], \IndexRunRecorder::callsOf('markDeadValidators'));
    }

    public function testARefusalThatLeftTheFetchEmptyChargesOnceAsRefused(): void
    {
        $this->chain(self::CHAIN_A, 'chain-a');
        \IndexRunRecorder::$refusals = [self::CHAIN_A];
        \IndexRunRecorder::$fetchResults[self::CHAIN_A] = [];

        ChainRefreshService::index_validators();

        self::assertSame([[self::CHAIN_A, 'refused']], \IndexRunRecorder::callsOf('breaker.recordFailure.kind'));
    }

    /** Control: with no refusal on the receipt, both sites still record no kind. */
    public function testWithoutARefusalTheDomainChargesStillNameNothing(): void
    {
        $this->chain(self::CHAIN_A, 'chain-a');
        $this->chain(self::CHAIN_B, 'chain-b');
        \IndexRunRecorder::$fetchResults[self::CHAIN_A] = new \RuntimeException('boom');
        \IndexRunRecorder::$fetchResults[self::CHAIN_B] = [];

        ChainRefreshService::index_validators();

        self::assertSame(
            [[self::CHAIN_A, null], [self::CHAIN_B, null]],
            \IndexRunRecorder::callsOf('breaker.recordFailure.kind')
        );
    }

    // ── The time budget is not a provider failure ───────────────────────

    public function testAWalkCutAtItsTimeBudgetIsAPartialThatChargesNothing(): void
    {
        $this->chain(self::CHAIN_A, 'chain-a');
        $this->chain(self::CHAIN_B, 'chain-b');
        \IndexRunRecorder::$fetchResults[self::CHAIN_A] = new IndexTimeBudgetSpent('Polkadot validator walk reached its 120s budget before page 9 (225 rows fetched).');
        \IndexRunRecorder::$fetchResults[self::CHAIN_B] = self::validators(40);

        ChainRefreshService::index_validators();

        self::assertSame([], \IndexRunRecorder::callsOf('breaker.recordFailure'), 'no provider did anything wrong');
        self::assertSame([self::CHAIN_B], \IndexRunRecorder::callsOf('breaker.recordSuccess'), 'no clean-index success for the cut chain');
        self::assertSame([40], \IndexRunRecorder::callsOf('validators.bulkUpsert'), 'nothing written for the cut chain; the next chain still indexes');
        self::assertSame([self::CHAIN_B], \IndexRunRecorder::callsOf('mintMissing'));
        self::assertSame([], \IndexRunRecorder::callsOf('markDeadValidators'), 'a cut walk is a partial: the run-wide sweep is withheld');
        self::assertSame(40, \IndexRunRecorder::$options['bcc_onchain_indexer_stats']['chain-a']['last_good_total'], 'its baseline is untouched');
        self::assertSame([], \IndexRunRecorder::callsOf('log.error'));

        $warnings = array_map('strval', \IndexRunRecorder::callsOf('log.warning'));
        self::assertCount(1, array_filter($warnings, static fn (string $w): bool => str_contains($w, 'Validator index for Chain-a stopped at its time budget')));
    }

    /** Even after a refusal: the budget, not the refusal, ended the walk. */
    public function testATimeBudgetCutNeverChargesEvenWithARefusalOnTheReceipt(): void
    {
        $this->chain(self::CHAIN_A, 'chain-a');
        \IndexRunRecorder::$refusals = [self::CHAIN_A];
        \IndexRunRecorder::$fetchResults[self::CHAIN_A] = new IndexTimeBudgetSpent('cut');

        ChainRefreshService::index_validators();

        self::assertSame([], \IndexRunRecorder::callsOf('breaker.recordFailure'));
    }
}
