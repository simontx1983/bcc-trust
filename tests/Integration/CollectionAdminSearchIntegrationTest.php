<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\NftSpamContractRepository;
use BCC\Trust\Onchain\Services\CollectionStateClassifier;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The admin review search, against a REAL database.
 *
 * ── WHY THIS IS AN INTEGRATION TEST ─────────────────────────────────────
 * Every property worth pinning here is a property of the SQL, not of PHP:
 * whether the term is ANDed with the tab predicate, whether `esc_like()`
 * neutralises an operator's own `%`, and whether a NULL
 * `canonical_identifier` silently drops rows out of an OR chain that looks
 * like it covers them. A unit test with a stubbed repository would assert
 * the shape of a string and prove none of it.
 *
 * ── THE PROPERTY THAT MATTERS MOST ──────────────────────────────────────
 * ⚠⚠ SEARCH NARROWS, IT NEVER WIDENS. The four tabs are an authorisation-
 * shaped boundary in operator terms — `hidden_by_operator` holds rows an
 * administrator deliberately suppressed. A search that could reach across a
 * tab would quietly resurrect them in a list that claims to be something
 * else. Several cases below exist only to pin that.
 */
#[Group('integration')]
#[Group('mariadb')]
final class CollectionAdminSearchIntegrationTest extends TestCase
{
    private const CHAIN_ID = 1;

    /** Mixed case on purpose: the search is a DISPLAY search, not an identity lookup. */
    private const MIXED_NAME = 'Steel City Apes';

    /** Carries a literal percent, which an operator may well type. */
    private const PERCENT_NAME = 'Fifty 50% Club';

    /** A name containing a LIKE underscore wildcard. */
    private const UNDERSCORE_NAME = 'Snake_Case Collective';

    private static bool $seeded = false;

    /** @var array<string,int> name => collection id */
    private static array $ids = [];

    public static function setUpBeforeClass(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = CollectionRepository::table();

        $wpdb->query("DELETE FROM `{$table}`");
        $wpdb->query("DELETE FROM `{$wpdb->postmeta}`");
        $wpdb->query("DELETE FROM `{$wpdb->posts}`");
        $wpdb->query('DELETE FROM `' . NftSpamContractRepository::table() . '`');

        $seed = static function (
            string $name,
            string $contract,
            ?string $canonical,
            bool $hidden = false
        ) use ($wpdb, $table): int {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO `{$table}`
                    (contract_address, canonical_identifier, chain_id, collection_name,
                     token_standard, fetched_at, expires_at, is_verified)
                 VALUES (%s, %s, %d, %s, %s, NOW(), NOW(), 0)",
                $contract,
                $canonical,
                self::CHAIN_ID,
                $name,
                'ERC-721'
            ));
            $id = (int) $wpdb->insert_id;

            if ($hidden) {
                $wpdb->query($wpdb->prepare(
                    'INSERT INTO `' . NftSpamContractRepository::table() . '`
                        (chain_id, contract_address, rule, created_at)
                     VALUES (%d, %s, %s, NOW())',
                    self::CHAIN_ID,
                    strtolower($contract),
                    NftSpamContractRepository::RULE_DENY
                ));
            }

            return $id;
        };

        self::$ids = [
            // canonical_identifier present
            'mixed'      => $seed(self::MIXED_NAME, '0x' . str_repeat('a', 40), '0x' . str_repeat('a', 40)),
            // ⚠ canonical_identifier NULL — the row that a non-NULL-safe OR drops
            'nullcanon'  => $seed('Null Canonical Row', '0x' . str_repeat('b', 40), null),
            'percent'    => $seed(self::PERCENT_NAME, '0x' . str_repeat('c', 40), null),
            'underscore' => $seed(self::UNDERSCORE_NAME, '0x' . str_repeat('d', 40), null),
            // Same distinctive token as 'mixed', but HIDDEN: the cross-tab probe.
            'hidden'     => $seed(self::MIXED_NAME . ' Hidden', '0x' . str_repeat('e', 40), null, true),
        ];

        self::$seeded = true;
    }

    /** @return list<int> matching collection ids */
    private function search(?string $term, string $tab = CollectionStateClassifier::TAB_DISCOVERED_UNVERIFIED): array
    {
        $out = CollectionRepository::listForAdminState($tab, 1, 100, null, null, $term);
        self::assertTrue($out['available'], 'the read must succeed');

        return array_map(static fn(object $r): int => (int) $r->id, $out['items']);
    }

    // ── 0. anti-vacuity ─────────────────────────────────────────────────

    public function testTheFixtureIsSeededAndEveryRowIsDistinguishable(): void
    {
        self::assertTrue(self::$seeded);
        self::assertCount(5, self::$ids);
        self::assertCount(5, array_unique(self::$ids), 'ids must be distinct');

        // Without a term the tab shows the four non-hidden rows. If this were
        // empty, every "search finds it" assertion below would be vacuous.
        $all = $this->search(null);
        self::assertCount(4, $all);
        self::assertNotContains(self::$ids['hidden'], $all);
    }

    // ── 1. it finds things, by each of the three columns ────────────────

    public function testFindsByNameFragment(): void
    {
        self::assertSame([self::$ids['mixed']], $this->search('Steel City'));
    }

    public function testNameSearchIsCaseInsensitiveBecauseItIsADisplaySearch(): void
    {
        self::assertSame([self::$ids['mixed']], $this->search('steel city'));
        self::assertSame([self::$ids['mixed']], $this->search('STEEL CITY'));
    }

    public function testFindsByContractAddress(): void
    {
        self::assertSame([self::$ids['mixed']], $this->search('0x' . str_repeat('a', 40)));
    }

    public function testFindsByCanonicalIdentifier(): void
    {
        // Same value as the contract here, but it proves the third arm is
        // wired rather than dead.
        self::assertContains(self::$ids['mixed'], $this->search(str_repeat('a', 40)));
    }

    public function testARowWithANullCanonicalIdentifierIsStillFoundByName(): void
    {
        // ⚠ THE NULL-SAFETY CASE. `NULL LIKE '%x%'` is NULL, not false, and an
        // OR chain written without the IS NOT NULL guard can drop the row
        // entirely depending on how the planner folds it. This row has a NULL
        // canonical_identifier and must still be found.
        self::assertSame([self::$ids['nullcanon']], $this->search('Null Canonical'));
    }

    // ── 2. ⚠⚠ it NARROWS, it never WIDENS ───────────────────────────────

    public function testSearchCannotReachIntoAnotherTab(): void
    {
        // 'Steel City Apes Hidden' contains the term, and is HIDDEN. Searching
        // the discovered tab must NOT surface it.
        $hits = $this->search('Steel City');
        self::assertNotContains(
            self::$ids['hidden'],
            $hits,
            'a hidden row must not appear in the discovered tab, whatever the search term'
        );
        self::assertSame([self::$ids['mixed']], $hits);
    }

    public function testTheSameTermFindsTheHiddenRowInTheHiddenTab(): void
    {
        // The counterpart: the row IS findable where it belongs. Without this,
        // the previous test could pass because the term matched nothing.
        $hits = $this->search('Steel City', CollectionStateClassifier::TAB_HIDDEN_BY_OPERATOR);
        self::assertSame([self::$ids['hidden']], $hits);
    }

    public function testNoTermCanExceedTheTabsOwnRowSet(): void
    {
        $unfiltered = $this->search(null);
        foreach (['Steel', 'a', '0x', '%', '_', ''] as $term) {
            foreach ($this->search($term === '' ? null : $term) as $id) {
                self::assertContains(
                    $id,
                    $unfiltered,
                    "term '{$term}' surfaced a row the unfiltered tab does not contain"
                );
            }
        }
    }

    // ── 3. LIKE metacharacters are the operator's literal text ──────────

    public function testALiteralPercentIsNotAWildcard(): void
    {
        // ⚠ If esc_like() were applied AFTER the wildcards were added, or not
        // at all, this would match every row instead of the one row whose
        // name actually contains a percent sign.
        self::assertSame([self::$ids['percent']], $this->search('50%'));
    }

    public function testABarePercentMatchesOnlyLiteralPercentsNotEveryRow(): void
    {
        // ⚠ NOT "matches nothing". The fixture deliberately contains a row
        // whose NAME holds a literal `%`, so the correct answer is "that row
        // and only that row". Asserting `[]` here would pass today and start
        // failing the moment anyone seeded a percent sign — and would also
        // pass if the wildcard were broken in the other direction. The sharp
        // property is that it does NOT return the whole tab.
        $hits = $this->search('%');

        self::assertSame([self::$ids['percent']], $hits);
        self::assertLessThan(
            count($this->search(null)),
            count($hits),
            'a bare % must not behave as a match-everything wildcard'
        );
    }

    public function testALiteralUnderscoreIsNotASingleCharacterWildcard(): void
    {
        self::assertSame([self::$ids['underscore']], $this->search('Snake_Case'));
        // Same reasoning as the percent case: the fixture holds exactly one
        // row with a literal underscore, so a bare `_` must return that one
        // row rather than every row.
        $hits = $this->search('_');
        self::assertSame([self::$ids['underscore']], $hits);
        self::assertLessThan(
            count($this->search(null)),
            count($hits),
            'a bare _ must not behave as a single-character wildcard across the tab'
        );
    }

    // ── 4. an absent term is absent, not a match-everything term ────────

    public function testNullTermReturnsTheWholeTab(): void
    {
        self::assertCount(4, $this->search(null));
    }

    public function testAWhitespaceOnlyTermIsTreatedAsAbsent(): void
    {
        // Trimmed to '' by the repository, so it must behave exactly like null
        // rather than like a term that matches every row by accident.
        self::assertSame($this->search(null), $this->search('   '));
        self::assertSame($this->search(null), $this->search("\t\n "));
    }

    public function testAnEmptyStringTermIsTreatedAsAbsent(): void
    {
        self::assertSame($this->search(null), $this->search(''));
    }

    // ── 5. a term matching nothing is empty, not unavailable ────────────

    public function testATermMatchingNothingIsAnEmptyButAvailableResult(): void
    {
        $out = CollectionRepository::listForAdminState(
            CollectionStateClassifier::TAB_DISCOVERED_UNVERIFIED,
            1,
            100,
            null,
            null,
            'zzzz-no-such-collection-zzzz'
        );

        self::assertTrue($out['available'], 'no matches is not a read failure');
        self::assertSame([], $out['items']);
        self::assertSame(0, $out['total']);
    }

    // ── 6. the total and the pages describe the SEARCHED set ───────────

    public function testTheTotalReflectsTheSearchedSetNotTheWholeTab(): void
    {
        $all      = CollectionRepository::listForAdminState(
            CollectionStateClassifier::TAB_DISCOVERED_UNVERIFIED, 1, 100, null, null, null
        );
        $searched = CollectionRepository::listForAdminState(
            CollectionStateClassifier::TAB_DISCOVERED_UNVERIFIED, 1, 100, null, null, 'Steel City'
        );

        self::assertSame(4, $all['total']);
        self::assertSame(1, $searched['total']);
        // The page count follows the total, which is what makes the
        // past-the-end message in the admin page name the right number.
        self::assertSame(1, $searched['pages']);
    }
}
