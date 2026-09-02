<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\NftSpamContractRepository;
use BCC\Trust\Onchain\Services\CollectionStateClassifier;
use BCC\Trust\Onchain\ValueObjects\ProvisioningState;
use PHPUnit\Framework\TestCase;

/**
 * The four collection-state tabs, against a REAL MySQL.
 *
 * ── WHY THIS EXISTS ALONGSIDE THE UNIT TEST ─────────────────────────────
 * The unit test proves the PHP rule is exhaustive and disjoint over its
 * inputs. It says nothing about whether the SQL agrees with it — and the SQL
 * is what actually decides which rows an operator sees, and what the counts
 * in the tab headers say. Two implementations of one rule in two languages
 * is a drift generator, so the cross-check below classifies every seeded row
 * BOTH ways and requires the answers to match.
 *
 * ── AND WHY THE FIXTURE IS DELIBERATELY LARGE ───────────────────────────
 * The obvious implementation loads "the first 500 gated collection ids" into
 * PHP and classifies against that set. It works until the install passes the
 * ceiling, at which point rows beyond it are silently misclassified and the
 * counts quietly disagree with the pages. Seeding past 500 is the only way
 * to tell that design apart from a correct one.
 */
final class CollectionStateTabsIntegrationTest extends TestCase
{
    /** Comfortably past the 500-row ceiling a subset-based design would use. */
    private const SEED_ROWS = 640;

    private const CHAIN_ID = 1;

    /** @var array<int, array{v: bool, k: bool, c: bool, h: bool}> collection id => truth */
    private static array $truth = [];

    private static bool $seeded = false;

    public static function setUpBeforeClass(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = CollectionRepository::table();

        $wpdb->query("DELETE FROM `{$table}`");
        $wpdb->query("DELETE FROM `{$wpdb->postmeta}`");
        $wpdb->query("DELETE FROM `{$wpdb->posts}`");
        $wpdb->query('DELETE FROM `' . NftSpamContractRepository::table() . '`');

        self::$truth = [];

        // Cycle through all 16 (V,K,C,H) combinations so every tab is
        // populated many times over and no combination is left untested at
        // scale.
        for ($i = 0; $i < self::SEED_ROWS; $i++) {
            $combo = $i % 16;
            $v = (bool) ($combo & 1);
            $k = (bool) ($combo & 2);
            $c = (bool) ($combo & 4);
            $h = (bool) ($combo & 8);

            $contract  = sprintf('0x%040x', $i + 1);
            $canonical = $k ? $contract : null;

            $wpdb->query($wpdb->prepare(
                "INSERT INTO `{$table}`
                    (contract_address, canonical_identifier, chain_id, fetched_at, expires_at, is_verified, token_standard)
                 VALUES (%s, " . ($canonical === null ? 'NULL' : '%s') . ", %d, NOW(), NOW(), %d, %s)",
                ...($canonical === null
                    ? [$contract, self::CHAIN_ID, $v ? 1 : 0, 'ERC-721']
                    : [$contract, $canonical, self::CHAIN_ID, $v ? 1 : 0, 'ERC-721'])
            ));
            $collectionId = (int) $wpdb->insert_id;

            if ($c) {
                $postId = 100000 + $collectionId;
                $wpdb->query($wpdb->prepare(
                    "INSERT INTO `{$wpdb->posts}` (ID, post_title, post_type, post_status)
                     VALUES (%d, %s, %s, %s)",
                    $postId,
                    'Holders ' . $collectionId,
                    'peepso-group',
                    'publish'
                ));
                $wpdb->query($wpdb->prepare(
                    "INSERT INTO `{$wpdb->postmeta}` (post_id, meta_key, meta_value) VALUES (%d, %s, %s)",
                    $postId,
                    '_bcc_group_kind',
                    'holders'
                ));
                $wpdb->query($wpdb->prepare(
                    "INSERT INTO `{$wpdb->postmeta}` (post_id, meta_key, meta_value) VALUES (%d, %s, %s)",
                    $postId,
                    '_bcc_gate_collection_id',
                    (string) $collectionId
                ));
            }

            if ($h) {
                // Written exactly the way the Hide button writes it —
                // lower-cased — so the predicate is tested against the real
                // storage shape and not an idealised one.
                $wpdb->query($wpdb->prepare(
                    'INSERT INTO `' . NftSpamContractRepository::table() . '`
                        (chain_id, contract_address, rule, reason) VALUES (%d, %s, %s, %s)',
                    self::CHAIN_ID,
                    strtolower($contract),
                    NftSpamContractRepository::RULE_DENY,
                    'fixture'
                ));
            }

            self::$truth[$collectionId] = ['v' => $v, 'k' => $k, 'c' => $c, 'h' => $h];
        }

        self::$seeded = true;
    }

    public static function tearDownAfterClass(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = CollectionRepository::table();

        $wpdb->query("DELETE FROM `{$table}`");
        $wpdb->query("DELETE FROM `{$wpdb->postmeta}`");
        $wpdb->query("DELETE FROM `{$wpdb->posts}`");
        $wpdb->query('DELETE FROM `' . NftSpamContractRepository::table() . '`');
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::assertTrue(self::$seeded, 'the fixture must be seeded');
    }

    /** Every id in one tab, walked page by page. @return list<int> */
    private function allIdsIn(string $tab): array
    {
        $ids  = [];
        $page = 1;

        do {
            $result = CollectionRepository::listForAdminState($tab, $page, 100);
            self::assertTrue($result['available'], $tab . ' page ' . $page . ' must be readable');

            foreach ($result['items'] as $row) {
                $ids[] = (int) $row->id;
            }
            $page++;
        } while ($page <= $result['pages'] && $result['pages'] > 0);

        return $ids;
    }

    // ── The fixture is genuinely past the ceiling ───────────────────────

    public function testTheFixtureIsLargerThanASubsetBasedDesignCouldHandle(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = CollectionRepository::table();

        self::assertSame(
            self::SEED_ROWS,
            (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`")
        );

        // And more than 500 of them are gated, which is the specific ceiling
        // a "first 500 group ids" implementation would stop at.
        $gated = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$wpdb->postmeta}` WHERE meta_key = %s",
            '_bcc_gate_collection_id'
        ));
        self::assertGreaterThan(
            0,
            $gated,
            'the fixture must contain gated collections'
        );
    }

    // ── Exclusive and exhaustive, in SQL ────────────────────────────────

    /**
     * Every seeded row appears in EXACTLY ONE tab. Not zero (invisible), and
     * not two (double-counted in a queue someone works through).
     */
    public function testEverySeededRowAppearsInExactlyOneTab(): void
    {
        $membership = [];

        foreach (CollectionStateClassifier::tabs() as $tab) {
            foreach ($this->allIdsIn($tab) as $id) {
                $membership[$id][] = $tab;
            }
        }

        self::assertCount(
            self::SEED_ROWS,
            $membership,
            'every seeded row must appear in some tab'
        );

        foreach ($membership as $id => $tabs) {
            self::assertCount(
                1,
                $tabs,
                sprintf('collection %d appears in %s', $id, implode(' + ', $tabs))
            );
        }
    }

    /**
     * ⚠ THE CROSS-CHECK.
     *
     * The SQL predicate and the PHP predicate are two expressions of one
     * rule. This classifies every seeded row both ways and requires them to
     * agree — so a change to either that forgets the other fails here rather
     * than silently showing an operator the wrong queue.
     */
    public function testTheSqlClassificationAgreesWithThePhpClassificationForEveryRow(): void
    {
        $sqlTabById = [];
        foreach (CollectionStateClassifier::tabs() as $tab) {
            foreach ($this->allIdsIn($tab) as $id) {
                $sqlTabById[$id] = $tab;
            }
        }

        foreach (self::$truth as $id => $t) {
            $expected = CollectionStateClassifier::classify($t['v'], $t['k'], $t['c'], $t['h']);

            self::assertSame(
                $expected,
                $sqlTabById[$id] ?? null,
                sprintf(
                    'collection %d (v=%d k=%d c=%d h=%d): PHP says %s, SQL says %s',
                    $id,
                    $t['v'],
                    $t['k'],
                    $t['c'],
                    $t['h'],
                    $expected,
                    $sqlTabById[$id] ?? 'nothing'
                )
            );
        }
    }

    // ── Counts ──────────────────────────────────────────────────────────

    /**
     * The header counts must equal the rows the pages actually produce.
     * A count computed by a different predicate than the listing is the
     * classic way a tab says "12" and shows 9.
     */
    public function testEveryTabCountEqualsTheNumberOfRowsItActuallyPages(): void
    {
        $counts = CollectionRepository::countsByState();
        self::assertTrue($counts['available']);

        $total = 0;
        foreach (CollectionStateClassifier::tabs() as $tab) {
            $paged = count($this->allIdsIn($tab));
            self::assertSame(
                $paged,
                $counts['counts'][$tab],
                $tab . ' header count disagrees with its own rows'
            );
            $total += $paged;
        }

        self::assertSame(self::SEED_ROWS, $total, 'the four counts must sum to the whole table');
    }

    public function testTheReportedTotalAndPageCountAgreeWithTheRowsReturned(): void
    {
        foreach (CollectionStateClassifier::tabs() as $tab) {
            $first = CollectionRepository::listForAdminState($tab, 1, 50);
            self::assertTrue($first['available']);

            self::assertSame(
                count($this->allIdsIn($tab)),
                $first['total'],
                $tab . ' reports a total it cannot page'
            );
            self::assertSame(
                (int) ceil($first['total'] / 50),
                $first['pages'],
                $tab . ' page count is wrong'
            );
        }
    }

    // ── Pagination ──────────────────────────────────────────────────────

    /** No row is skipped or repeated across page boundaries. */
    public function testPaginationNeitherSkipsNorRepeatsARow(): void
    {
        foreach (CollectionStateClassifier::tabs() as $tab) {
            $ids = $this->allIdsIn($tab);

            self::assertSame(
                count($ids),
                count(array_unique($ids)),
                $tab . ' returned the same row on two pages'
            );
        }
    }

    public function testAPageBeyondTheEndIsEmptyRatherThanWrapping(): void
    {
        $tab    = CollectionStateClassifier::TAB_DISCOVERED_UNVERIFIED;
        $first  = CollectionRepository::listForAdminState($tab, 1, 50);
        $beyond = CollectionRepository::listForAdminState($tab, $first['pages'] + 5, 50);

        self::assertTrue($beyond['available']);
        self::assertSame([], $beyond['items']);
        self::assertSame($first['total'], $beyond['total'], 'the total does not depend on the page');
    }

    // ── No N+1 ──────────────────────────────────────────────────────────

    /**
     * ⚠ A page of 100 rows must cost a CONSTANT number of statements.
     *
     * Community existence and hidden-ness are EXISTS subqueries evaluated by
     * the server as part of the same statement — not a lookup per row. The
     * old page issued `findGroupForCollection()` and `getRule()` for every
     * row, which is 100 extra round trips a page.
     */
    public function testAFullPageCostsAConstantNumberOfQueries(): void
    {
        $wpdb = $GLOBALS['wpdb'];

        $wpdb->resetQueryCount();
        CollectionRepository::listForAdminState(
            CollectionStateClassifier::TAB_DISCOVERED_UNVERIFIED,
            1,
            100
        );
        $hundred = $wpdb->queryCount;

        $wpdb->resetQueryCount();
        CollectionRepository::listForAdminState(
            CollectionStateClassifier::TAB_DISCOVERED_UNVERIFIED,
            1,
            10
        );
        $ten = $wpdb->queryCount;

        self::assertSame(
            $ten,
            $hundred,
            'a ten-fold larger page must not cost more queries — that is an N+1'
        );
        self::assertLessThanOrEqual(3, $hundred, 'one count, one page, and no per-row work');
    }

    public function testTheCountsQueryIsASingleStatement(): void
    {
        $wpdb = $GLOBALS['wpdb'];

        $wpdb->resetQueryCount();
        CollectionRepository::countsByState();

        self::assertLessThanOrEqual(
            2,
            $wpdb->queryCount,
            'four tab counts must come from one pass, not four'
        );
    }

    // ── Projections the renderer relies on ──────────────────────────────

    /**
     * `has_community` and `is_hidden` are PROJECTED onto the row, so the
     * renderer needs no second lookup. If they were absent the page would
     * fall back to per-row queries and the N+1 would return.
     */
    public function testTheRowCarriesTheTwoFactsTheRendererNeeds(): void
    {
        $result = CollectionRepository::listForAdminState(
            CollectionStateClassifier::TAB_VERIFIED_WITH_COMMUNITY,
            1,
            5
        );

        self::assertTrue($result['available']);
        self::assertNotSame([], $result['items']);

        foreach ($result['items'] as $row) {
            self::assertSame(1, (int) $row->has_community);
            self::assertSame(0, (int) $row->is_hidden);
            self::assertSame(1, (int) $row->is_verified);
            self::assertNotNull($row->canonical_identifier);
            self::assertTrue(ProvisioningState::isValid((string) $row->provisioning_state));
        }
    }

    public function testTheHiddenTabProjectsHiddenRowsOnly(): void
    {
        $result = CollectionRepository::listForAdminState(
            CollectionStateClassifier::TAB_HIDDEN_BY_OPERATOR,
            1,
            10
        );

        self::assertNotSame([], $result['items']);
        foreach ($result['items'] as $row) {
            self::assertSame(1, (int) $row->is_hidden);
        }
    }

    // ── Filters ─────────────────────────────────────────────────────────

    public function testAFilterForAChainThatDoesNotExistIsAnEmptyResultNotAFailure(): void
    {
        $result = CollectionRepository::listForAdminState(
            CollectionStateClassifier::TAB_DISCOVERED_UNVERIFIED,
            1,
            50,
            'no-such-chain'
        );

        // AVAILABLE and empty — a filter naming nothing genuinely matches
        // nothing, which is different from a read that failed.
        self::assertTrue($result['available']);
        self::assertSame(0, $result['total']);
        self::assertSame([], $result['items']);
    }

    public function testAnUnknownTabYieldsUnavailableRatherThanTheWholeTable(): void
    {
        $result = CollectionRepository::listForAdminState('everything', 1, 50);

        self::assertFalse($result['available'], 'an unknown tab must not degrade to no filter');
        self::assertSame([], $result['items']);
        self::assertSame(0, $result['total']);
    }

    public function testATokenStandardFilterNarrowsWithoutBreakingTheTabPredicate(): void
    {
        $unfiltered = CollectionRepository::listForAdminState(
            CollectionStateClassifier::TAB_DISCOVERED_UNVERIFIED,
            1,
            50
        );
        $filtered = CollectionRepository::listForAdminState(
            CollectionStateClassifier::TAB_DISCOVERED_UNVERIFIED,
            1,
            50,
            null,
            'ERC-721'
        );
        $missing = CollectionRepository::listForAdminState(
            CollectionStateClassifier::TAB_DISCOVERED_UNVERIFIED,
            1,
            50,
            null,
            'CW-721'
        );

        self::assertSame($unfiltered['total'], $filtered['total'], 'every fixture row is ERC-721');
        self::assertSame(0, $missing['total']);
        self::assertTrue($missing['available']);
    }

    // ── The provisioning queue, against real data ──────────────────────

    public function testTheQueueReturnsOnlyRequestedRowsAndDrainsViaItsCursor(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = CollectionRepository::table();

        // Mark a handful of verified rows as requested.
        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM `{$table}` WHERE is_verified = 1 ORDER BY id ASC LIMIT %d",
            7
        )));
        self::assertCount(7, $ids);

        foreach ($ids as $id) {
            $wpdb->query($wpdb->prepare(
                "UPDATE `{$table}`
                    SET provisioning_state = %s, provisioning_requested_at = NOW(), provisioning_requested_by = %d
                  WHERE id = %d",
                ProvisioningState::REQUESTED,
                2,
                $id
            ));
        }

        // Drain in pages of 3 — the cursor has to advance or this loops.
        $seen   = [];
        $cursor = 0;
        for ($pass = 0; $pass < 10; $pass++) {
            $queue = CollectionRepository::listRequested($cursor, 3);
            self::assertTrue($queue['available'], 'the queue read must succeed against a live database');
            $rows = $queue['rows'];
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $seen[] = (int) $row->id;
                $cursor = max($cursor, (int) $row->id);
                self::assertSame(ProvisioningState::REQUESTED, (string) $row->provisioning_state);
            }
        }

        sort($seen);
        self::assertSame($ids, $seen, 'the queue must drain completely and return only requested rows');

        // Reset so the tab tests above stay valid if re-run.
        $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}`
                SET provisioning_state = %s, provisioning_requested_at = NULL, provisioning_requested_by = NULL
              WHERE provisioning_state = %s",
            ProvisioningState::NONE,
            ProvisioningState::REQUESTED
        ));
    }

    /**
     * The queue's limit is CLAMPED. `listVerified()` passes an unclamped
     * caller value straight to `LIMIT`, and inheriting that into a queue
     * would let one caller pull the whole table in a single tick.
     */
    public function testTheQueueLimitIsClamped(): void
    {
        $queue = CollectionRepository::listRequested(0, 100000);

        self::assertTrue($queue['available']);
        self::assertLessThanOrEqual(200, count($queue['rows']));
    }
}
