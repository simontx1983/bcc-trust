<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Fetchers\PolkadotFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Polkadot's fetch returned the SAME validators many times over, and the
 * duplicates were ranked and written as if they were distinct.
 *
 * ── WHAT WAS MEASURED ───────────────────────────────────────────────────
 * Production logged the identical line 335 times, then stopped on
 * 2026-06-25:
 *
 *   Indexed Polkadot: 3000 total, 0 new, 2400 updated, 600 unchanged
 *
 * `total` is count($validators) inside ValidatorRepository::bulkUpsert(),
 * so it counts BATCH ENTRIES, not validators. `0 new` means every one of
 * the 3000 matched a row that already existed, and the table holds exactly
 * 600 rows - so the batch carried at most 600 distinct addresses.
 *
 * The damage is still in the production table: voting_power_rank runs to
 * 2820 across 600 rows, with only 269 distinct values. Rank is the array
 * INDEX (mapValidator's $rank + 1), so duplicates inflate it past the real
 * set size AND collide. bulkUpsert's change detection includes
 * voting_power_rank, which is where the 2400 spurious "updated" came from.
 *
 * ⛔ THIS FILE DOES NOT TEST WHY INDEXING STOPPED. That is a Subscan HTTP
 * 403 on every run since late August (435 occurrences) and no code change
 * fixes it. These tests cover the de-duplication only.
 */
#[CoversClass(PolkadotFetcher::class)]
final class PolkadotValidatorDeduplicationTest extends TestCase
{
    private const COPIES = 5;
    private const SET    = 600;

    protected function setUp(): void
    {
        parent::setUp();
        // PolkadotFetcher.php exits unless ABSPATH is defined, and the
        // constant has to exist BEFORE the autoloader reaches the file.
        if (!defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir() . '/');
        }
    }

    private static function fetcher(): PolkadotFetcher
    {
        return new PolkadotFetcher((object) [
            'id'         => 19,
            'slug'       => 'polkadot',
            'name'       => 'Polkadot',
            'chain_type' => 'polkadot',
            'decimals'   => 10,
            'rest_url'   => 'https://polkadot.api.subscan.io',
        ]);
    }

    /** @param list<mixed> $args */
    private static function call(string $method, array $args): mixed
    {
        $m = new ReflectionMethod(PolkadotFetcher::class, $method);
        $m->setAccessible(true);

        return $m->invoke($m->isStatic() ? null : self::fetcher(), ...$args);
    }

    /**
     * dedupe -> rank, exactly the order fetch_all_validators applies it.
     * Pinned against the real method by
     * testFetchAllValidatorsDeduplicatesBeforeItRanks, so this helper
     * cannot drift into testing a pipeline the fetcher does not run.
     *
     * @param list<array<string, mixed>> $raw
     * @return array<int, array<string, mixed>>
     */
    private static function pipeline(array $raw): array
    {
        /** @var array{rows: list<array<string, mixed>>, dropped: int, divergent: int} $d */
        $d = self::call('dedupeByStash', [$raw]);

        /** @var array<int, array<string, mixed>> $ranked */
        $ranked = self::call('rankActive', [$d['rows']]);

        return $ranked;
    }

    /**
     * One raw Subscan-shaped row. Plancks are integer STRINGS far beyond
     * float precision, which is why the sort uses bccomp().
     *
     * @return array<string, mixed>
     */
    private static function row(
        string $address,
        string $bonded,
        string $status = 'validator',
        ?string $display = null
    ): array {
        return [
            'stash_account_display' => [
                'address' => $address,
                'display' => $display ?? ('moniker-' . $address),
            ],
            'bonded_total'          => $bonded,
            'bonded_owner'          => '10000000000',
            'validator_prefs_value' => '50000000',
            'count_nominators'      => 7,
            'validator_status'      => $status,
            'is_active'             => true,
        ];
    }

    /**
     * 600 distinct validators, strictly descending stake, repeated $copies
     * times in an interleaved order so the fixture cannot pass by accident
     * through adjacency.
     *
     * @return list<array<string, mixed>>
     */
    private static function repeatedSet(int $copies): array
    {
        $raw = [];
        for ($copy = 0; $copy < $copies; $copy++) {
            for ($i = 0; $i < self::SET; $i++) {
                $raw[] = self::row(
                    sprintf('1stash%04d', $i),
                    // 20-digit Plancks: descending, and only distinguishable
                    // with arbitrary-precision comparison.
                    (string) (99999999999999999999 - $i)
                );
            }
        }

        return $raw;
    }

    // ── anti-vacuity ────────────────────────────────────────────────────

    public function testTheClassAndItsNewHelpersReallyExist(): void
    {
        self::assertTrue(class_exists(PolkadotFetcher::class));
        foreach (['dedupeByStash', 'rankActive', 'stashAddress', 'bondedTotal', 'copiesDiffer'] as $m) {
            self::assertTrue(
                method_exists(PolkadotFetcher::class, $m),
                "PolkadotFetcher::{$m}() must exist"
            );
        }
    }

    /**
     * ⚠ The structural pin for pipeline(). De-duplication has to happen
     * BEFORE ranking: ranking first would assign the inflated ranks this
     * whole change exists to prevent, and de-duplicating afterwards could
     * not undo them.
     */
    public function testFetchAllValidatorsDeduplicatesBeforeItRanks(): void
    {
        $src = file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Onchain/Fetchers/PolkadotFetcher.php'
        );
        self::assertNotFalse($src);

        $code = (string) preg_replace('!/\*.*?\*/!s', '', (string) $src);
        $code = (string) preg_replace('!//[^\r\n]*!', '', $code);

        $body = substr($code, (int) strpos($code, 'function fetch_all_validators'));
        $body = substr($body, 0, (int) strpos($body, 'private function rankActive'));

        $dedupeAt = strpos($body, 'self::dedupeByStash(');
        $rankAt   = strpos($body, '$this->rankActive(');

        self::assertNotFalse($dedupeAt, 'fetch_all_validators must de-duplicate');
        self::assertNotFalse($rankAt, 'fetch_all_validators must delegate ranking');
        self::assertLessThan(
            $rankAt,
            $dedupeAt,
            'de-duplication must run BEFORE ranking, not after'
        );

        // ⚠⚠ POSITION ALONE IS NOT ENOUGH, and a mutation control proved
        // it. Nesting the calls -
        //
        //     self::dedupeByStash($this->rankActive($validators))
        //
        // - still puts dedupeByStash( first in the text, so the ordering
        // assertion above passes while the real order is reversed. It is
        // not harmless: rankActive() returns MAPPED rows, which carry no
        // stash fields at all, so every row would key to '' and be
        // dropped and the fetch would silently return nothing.
        //
        // No behavioural test can reach this wiring - fetch_all_validators
        // needs live HTTP - so the guard pins the ARGUMENTS, not the
        // positions.
        $flat = (string) preg_replace('/\s+/', ' ', $body);

        self::assertStringContainsString(
            '$dedupe = self::dedupeByStash($validators);',
            $flat,
            'the RAW paginated list is what must be de-duplicated'
        );
        self::assertStringContainsString(
            "return \$this->rankActive(\$dedupe['rows']);",
            $flat,
            'ranking must consume the de-duplicated rows and nothing else'
        );
        self::assertSame(
            1,
            substr_count($flat, 'self::dedupeByStash('),
            'exactly one de-duplication site'
        );
        self::assertSame(
            1,
            substr_count($flat, '$this->rankActive('),
            'exactly one ranking site'
        );
        self::assertStringNotContainsString(
            'usort(',
            $body,
            'ranking belongs in rankActive(), so the raw list is never ranked here'
        );
    }

    // ── the three required cases ────────────────────────────────────────

    /**
     * ⭐ THE CASE THE PRODUCTION DATA DEMANDED: 5 copies of 600 in, 600
     * unique out, ranks 1..600 with no gap and no collision.
     */
    public function testRepeatedCopiesOfSixHundredCollapseToSixHundredRanksOneToSixHundred(): void
    {
        $raw = self::repeatedSet(self::COPIES);
        self::assertCount(self::COPIES * self::SET, $raw, 'the fixture must really be 3000 rows');

        $out = self::pipeline($raw);

        self::assertCount(self::SET, $out, '600 distinct validators must yield 600 rows');

        $ranks = array_column($out, 'voting_power_rank');
        self::assertSame(
            range(1, self::SET),
            $ranks,
            'ranks must be 1..600, contiguous and in order'
        );
        self::assertCount(
            self::SET,
            array_unique($ranks),
            'no two validators may share a rank'
        );
        self::assertCount(
            self::SET,
            array_unique(array_column($out, 'operator_address')),
            'every returned address must be distinct'
        );
        // The defect's signature: a rank above the real set size.
        self::assertLessThanOrEqual(
            self::SET,
            max($ranks),
            'no rank may exceed the number of validators (production holds 2820)'
        );
    }

    /**
     * Ordering is by bonded stake, descending, and survives both the
     * de-duplication and the 20-digit Planck values.
     */
    public function testBondedPowerOrderingIsPreserved(): void
    {
        $out = self::pipeline(self::repeatedSet(self::COPIES));

        // The fixture's stake descends with the index, so rank N must be
        // address N-1.
        self::assertSame('1stash0000', $out[0]['operator_address'], 'highest stake ranks first');
        self::assertSame(1, $out[0]['voting_power_rank']);
        self::assertSame('1stash0599', $out[self::SET - 1]['operator_address'], 'lowest stake ranks last');
        self::assertSame(self::SET, $out[self::SET - 1]['voting_power_rank']);

        for ($i = 1; $i < self::SET; $i++) {
            self::assertLessThanOrEqual(
                $out[$i - 1]['total_stake'],
                $out[$i]['total_stake'],
                "stake must not increase from rank {$i} to rank " . ($i + 1)
            );
        }
    }

    /**
     * ⚠ The no-op guarantee. A clean provider response must come through
     * untouched - otherwise this change is not a de-duplication, it is a
     * behaviour change applied to every future fetch.
     */
    public function testAnAlreadyUniqueResponseIsUnchanged(): void
    {
        $raw = self::repeatedSet(1);
        self::assertCount(self::SET, $raw);

        /** @var array{rows: list<array<string, mixed>>, dropped: int, divergent: int} $d */
        $d = self::call('dedupeByStash', [$raw]);

        self::assertSame(0, $d['dropped'], 'nothing may be dropped from a unique response');
        self::assertSame(0, $d['divergent'], 'and nothing may be reported as divergent');
        self::assertSame($raw, $d['rows'], 'the rows must be byte-for-byte the input');

        // And the ranked output matches the 5-copy case exactly, which is
        // the real claim: de-duplication changes the INPUT, never the result.
        self::assertSame(
            self::pipeline(self::repeatedSet(self::COPIES)),
            self::pipeline($raw),
            'a duplicated response must produce the same result as a clean one'
        );
    }

    // ── the keep rule ───────────────────────────────────────────────────

    /**
     * ⛔ THE DOCUMENTED RULE: greatest bonded_total wins. The copies here
     * differ, which the production rank distribution says really happens.
     */
    public function testTheKeepRuleTakesTheGreatestBondedTotal(): void
    {
        $raw = [
            self::row('1stashA', '100', 'validator', 'low-copy'),
            self::row('1stashA', '900', 'validator', 'high-copy'),
            self::row('1stashA', '500', 'validator', 'mid-copy'),
        ];

        /** @var array{rows: list<array<string, mixed>>, dropped: int, divergent: int} $d */
        $d = self::call('dedupeByStash', [$raw]);

        self::assertCount(1, $d['rows']);
        self::assertSame(2, $d['dropped']);
        self::assertSame(2, $d['divergent'], 'both dropped copies differed and must be reported');
        self::assertSame('900', $d['rows'][0]['bonded_total'], 'the greatest stake must win');
        self::assertSame('high-copy', $d['rows'][0]['stash_account_display']['display']);
    }

    /**
     * ⚠ A tie keeps the EARLIEST occurrence, which is what makes the rule
     * total: one response always yields exactly one result.
     */
    public function testATieKeepsTheEarliestOccurrence(): void
    {
        $raw = [
            self::row('1stashB', '400', 'validator', 'first'),
            self::row('1stashB', '400', 'validator', 'second'),
        ];

        /** @var array{rows: list<array<string, mixed>>, dropped: int, divergent: int} $d */
        $d = self::call('dedupeByStash', [$raw]);

        self::assertCount(1, $d['rows']);
        self::assertSame('first', $d['rows'][0]['stash_account_display']['display']);
        self::assertSame(1, $d['divergent'], 'the tie still carried a different moniker');
    }

    /**
     * ⚠ Determinism does not depend on input order. Greatest-stake-wins is
     * order-independent, which matters precisely because a paginator that
     * emits duplicates has already shown its ordering cannot be trusted.
     */
    public function testTheKeepRuleIsIndependentOfInputOrder(): void
    {
        $rows = [
            self::row('1stashC', '100', 'validator', 'low'),
            self::row('1stashC', '900', 'validator', 'high'),
            self::row('1stashC', '500', 'validator', 'mid'),
        ];

        foreach ([[0, 1, 2], [2, 1, 0], [1, 0, 2], [1, 2, 0]] as $order) {
            $permuted = array_map(static fn (int $i): array => $rows[$i], $order);

            /** @var array{rows: list<array<string, mixed>>, dropped: int, divergent: int} $d */
            $d = self::call('dedupeByStash', [$permuted]);

            self::assertSame(
                'high',
                $d['rows'][0]['stash_account_display']['display'],
                'order ' . implode(',', $order) . ' must still keep the greatest stake'
            );
        }
    }

    /**
     * ⚠ 20-digit Plancks exceed float precision. A float comparison would
     * read these as equal and the keep rule would silently become
     * first-wins.
     */
    public function testTheKeepRuleComparesWithArbitraryPrecision(): void
    {
        $lo = '99999999999999999998';
        $hi = '99999999999999999999';
        self::assertSame((float) $lo, (float) $hi, 'precondition: floats cannot tell these apart');

        /** @var array{rows: list<array<string, mixed>>, dropped: int, divergent: int} $d */
        $d = self::call('dedupeByStash', [[
            self::row('1stashD', $lo, 'validator', 'lower'),
            self::row('1stashD', $hi, 'validator', 'higher'),
        ]]);

        self::assertSame('higher', $d['rows'][0]['stash_account_display']['display']);
    }

    // ── keying ──────────────────────────────────────────────────────────

    /**
     * ⚠⚠ The de-duplication key must be the SAME string the row is stored
     * under. Keying on a different spelling would be worse than not
     * de-duplicating: it would collapse rows that are distinct in the table.
     */
    public function testTheDeduplicationKeyIsTheStoredOperatorAddress(): void
    {
        // display.address takes precedence
        $withDisplay = self::row('1stashE', '100');
        self::assertSame('1stashE', self::call('stashAddress', [$withDisplay]));

        // and stash_account is the documented fallback
        $fallback = ['stash_account' => '1stashF', 'bonded_total' => '100', 'validator_status' => 'validator'];
        self::assertSame('1stashF', self::call('stashAddress', [$fallback]));

        // the stored row agrees with the key, for both shapes
        $out = self::pipeline([$withDisplay, $fallback]);
        self::assertSame(
            ['1stashE', '1stashF'],
            array_column($out, 'operator_address'),
            'mapValidator must store exactly what dedupeByStash keyed on'
        );

        // ⚠⚠ PRECEDENCE, WITH BOTH FIELDS PRESENT AND DISAGREEING.
        // A mutation control that merely swapped the two ?? branches
        // SURVIVED the first version of this test, because no fixture
        // carried both keys at once.
        //
        // It is the opposite of cosmetic. mapValidator() shares this
        // helper, so re-ordering the branches moves the address that gets
        // STORED - and `uq_chain_operator` is (chain_id, operator_address),
        // so the next index would see 600 addresses it has never seen and
        // INSERT 600 new rows beside the 600 it already has.
        $both = [
            'stash_account_display' => ['address' => '1displayWins', 'display' => 'd'],
            'stash_account'         => '1rawLoses',
            'bonded_total'          => '100',
            'validator_status'      => 'validator',
        ];

        self::assertSame(
            '1displayWins',
            self::call('stashAddress', [$both]),
            'stash_account_display.address outranks stash_account'
        );

        $bothOut = self::pipeline([$both]);
        self::assertSame(
            '1displayWins',
            $bothOut[0]['operator_address'],
            'and the stored address follows the same precedence'
        );
    }

    public function testAddresslessRowsAreDroppedRatherThanCollapsedIntoOne(): void
    {
        /** @var array{rows: list<array<string, mixed>>, dropped: int, divergent: int} $d */
        $d = self::call('dedupeByStash', [[
            ['bonded_total' => '100', 'validator_status' => 'validator'],
            ['stash_account' => '', 'bonded_total' => '200', 'validator_status' => 'validator'],
            self::row('1stashG', '300'),
        ]]);

        self::assertCount(1, $d['rows'], 'only the addressable row survives');
        self::assertSame('1stashG', self::call('stashAddress', [$d['rows'][0]]));
        self::assertSame(2, $d['dropped']);
    }

    /**
     * ⚠ A non-numeric bonded_total used to reach bccomp() raw, which THROWS
     * on PHP 8 - a fatal inside the index cron. It now sorts as 0.
     */
    public function testANonNumericBondedTotalSortsAsZeroInsteadOfThrowing(): void
    {
        self::assertSame('0', self::call('bondedTotal', [['bonded_total' => 'not-a-number']]));
        self::assertSame('0', self::call('bondedTotal', [['bonded_total' => null]]));
        self::assertSame('0', self::call('bondedTotal', [['bonded_total' => ['nested']]]));
        self::assertSame('0', self::call('bondedTotal', [[]]));
        self::assertSame('42', self::call('bondedTotal', [['bonded_total' => 42]]));
        self::assertSame('42', self::call('bondedTotal', [['bonded_total' => '42']]));

        $out = self::pipeline([
            self::row('1stashH', 'garbage'),
            self::row('1stashI', '500'),
        ]);
        self::assertSame(['1stashI', '1stashH'], array_column($out, 'operator_address'));
    }

    // ── preserved behaviour ─────────────────────────────────────────────

    /**
     * ⚠⚠ PRE-EXISTING AND DELIBERATELY NOT CHANGED. The active-only filter
     * runs AFTER ranking, so a waiting validator consumes a rank position
     * and leaves a gap. Pinned here so the gap cannot be read as fallout
     * from de-duplication - and so that "fixing" it becomes a conscious
     * decision, since it would renumber every stored rank.
     */
    public function testWaitingValidatorsStillConsumeARankPositionAsBefore(): void
    {
        $out = self::pipeline([
            self::row('1stashJ', '900', 'validator'),
            self::row('1stashK', '800', 'waiting'),
            self::row('1stashL', '700', 'validator'),
        ]);

        self::assertCount(2, $out, 'the active-only filter still drops waiting validators');
        self::assertSame(['1stashJ', '1stashL'], array_column($out, 'operator_address'));
        self::assertSame(
            [1, 3],
            array_column($out, 'voting_power_rank'),
            'rank 2 is consumed by the waiting validator - unchanged behaviour'
        );
    }

    /**
     * The active filter is not weakened by de-duplication: duplicates of a
     * waiting validator collapse and the survivor is still excluded.
     */
    public function testDuplicatesOfAWaitingValidatorAreStillExcluded(): void
    {
        $out = self::pipeline([
            self::row('1stashM', '900', 'waiting'),
            self::row('1stashM', '900', 'waiting'),
            self::row('1stashM', '900', 'waiting'),
        ]);

        self::assertSame([], $out, 'a waiting validator must not become active by being duplicated');
    }
}
